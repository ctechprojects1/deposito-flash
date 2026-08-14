# Guia de Deploy — HostGator (cPanel) em subdomínio

Sistema de Endereçamento de Estoque · Laravel 11 (API) + React/Vite (SPA)

**Arquitetura em produção:** um único subdomínio. O Laravel serve tanto a API
(`/api/*`) quanto o React já compilado (todas as outras rotas). Como front e API
ficam no mesmo domínio, **não há CORS** em produção.

---

## 1. Compilar o React e integrar ao Laravel

No seu PC (Laragon), na raiz do projeto:

```powershell
.\deploy-build.ps1
```

O script faz:
1. `npm install` + `npm run build` (gera `frontend/dist`).
2. Copia `frontend/dist/assets` → `public/assets`.
3. Copia `frontend/dist/index.html` → `resources/views/spa.blade.php`.

> **Por que a index.html vira uma view Blade?** Assim o Laravel a devolve na rota
> `fallback` (`routes/web.php`) para qualquer URL que não seja `/api/*`. É isso que
> faz o roteamento do React funcionar mesmo com F5 / deep link. Os `assets/` (JS/CSS
> com hash) são servidos direto pelo Apache, sem passar pelo PHP.

Se preferir manual: `cd frontend && npm run build`, depois copie `dist/assets/*`
para `public/assets/` e `dist/index.html` para `resources/views/spa.blade.php`.

---

## 2. Preparar o pacote e subir para a HostGator

**Composer:** a HostGator compartilhada costuma ter pouca memória para rodar
`composer install`. O mais seguro é rodar **localmente** e subir a pasta `vendor/`:

```powershell
composer install --optimize-autoloader --no-dev
```

Suba para o servidor (via **Gerenciador de Arquivos do cPanel** ou FTP/FileZilla)
**tudo, menos**: `node_modules/`, `frontend/` (o build já está em `public`),
`tests/`, `.git/`.

### Onde colocar os arquivos — 2 cenários

**Cenário A (recomendado, mais seguro): document root aponta para `/public`.**
No cPanel → **Domínios** → seu subdomínio → **Document Root** → aponte para
`.../estoque/public`. Nesse caso você **NÃO precisa** do `.htaccess` da raiz —
só o `public/.htaccess` (que já vai junto). As pastas `app/`, `config/`, `.env`
etc. ficam fora da web = protegidas por padrão.

**Cenário B: não dá para mudar o document root** (fica na pasta do subdomínio).
Suba o projeto inteiro para a pasta do subdomínio. O `.htaccess` da **raiz**
(incluído neste projeto) redireciona tudo para `/public` e bloqueia o acesso
direto a `app/`, `config/`, `.env`, etc.

---

## 3. Banco de dados MySQL no cPanel

No cPanel → **Bancos de Dados MySQL**:

1. **Criar banco** → o cPanel prefixa: `cpaneluser_estoque`.
2. **Criar usuário** → vira: `cpaneluser_estuser`.
3. **Adicionar o usuário ao banco** com **TODOS OS PRIVILÉGIOS**.

### `config/database.php` precisa mudar?

**Não.** Ele já lê tudo do `.env`. Só confirme que a conexão `mysql` está com
`utf8mb4` (padrão do Laravel 11):

```php
'charset'   => env('DB_CHARSET', 'utf8mb4'),
'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
```

O único ajuste de código relacionado ao MySQL antigo da HostGator **já está feito**
no `app/Providers/AppServiceProvider.php` (`Schema::defaultStringLength(191)`), que
evita o erro `Specified key was too long` nas migrations.

### `.env` de produção

Copie `.env.production.example` para `.env` no servidor e ajuste:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://estoque.seudominio.com.br

DB_CONNECTION=mysql
DB_HOST=localhost            # na HostGator use "localhost", não 127.0.0.1
DB_PORT=3306
DB_DATABASE=cpaneluser_estoque
DB_USERNAME=cpaneluser_estuser
DB_PASSWORD=sua_senha
```

---

## 4. Comandos finais (via Terminal do cPanel ou SSH)

```bash
php artisan key:generate          # gera a APP_KEY (só na 1ª vez)
php artisan migrate --force       # --force é obrigatório em produção
php artisan db:seed --force       # cria os usuários padrão (id 1 solicitante, id 2 separador)
php artisan storage:link          # cria o link público para os anexos das notas
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Sobre o `db:seed`:** enquanto não há login, o sistema opera com usuários fixos
> criados pelo `UserSeeder` — o solicitante (id 1) e o separador (id 2) que o
> frontend usa. **Rode o seed em banco vazio na 1ª vez** para garantir que os IDs
> saiam 1 e 2 na ordem certa.

> **`storage:link` não funcionou?** Alguns planos bloqueiam symlink. Alternativa:
> crie manualmente uma pasta `public/storage` apontando para `storage/app/public`,
> ou use o Gerenciador de Arquivos para criar o symlink. Sem isso, as imagens/PDFs
> das notas não abrem pela URL.

Se tiver acesso SSH, rode a importação inicial do estoque:

```bash
php artisan stock:import storage/app/carga_inicial.csv --delimiter=";"
```

---

## 5. Checklist pós-deploy

- [ ] `https://estoque.seudominio.com.br` abre a SPA (mapa carrega).
- [ ] `https://estoque.seudominio.com.br/api/locations` retorna JSON.
- [ ] Upload da Nota funciona e o arquivo abre (storage:link OK).
- [ ] `APP_DEBUG=false` (nunca deixe telas de erro do Laravel expostas).
- [ ] `.env` **não** acessível pela URL (teste abrir `/.env` → deve dar 403).
- [ ] SSL ativo (cadeado verde); HTTP redireciona para HTTPS.

---

## Ao atualizar o sistema depois

1. `.\deploy-build.ps1` (se mexeu no front).
2. Suba os arquivos alterados.
3. No servidor: `php artisan migrate --force` (se houver migration nova) e
   `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
