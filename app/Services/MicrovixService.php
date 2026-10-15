<?php

namespace App\Services;

use App\Models\MicrovixBarra;
use App\Models\MicrovixProduto;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integração com a WebAPI do Linx Microvix (XML), mesma usada no PRODID.
 *
 * Gotchas (validados com dado real):
 *  - A API NÃO filtra por código de barras: LinxProdutosCodBar só pagina por
 *    timestamp (5000 por página). Por isso mantemos a tabela local
 *    microvix_barras (cod_barra -> cod_produto), sincronizada em lotes.
 *  - Em LinxProdutos, dt_update_fim precisa ser AMANHÃ; com "hoje" os produtos
 *    alterados hoje ficam de fora.
 */
class MicrovixService
{
    private const PAGINA = 5000;

    private string $url;
    private string $usuario;
    private string $senha;
    private string $chave;
    private string $cnpj;
    private int $idPortal;
    private int $timeout;

    public function __construct()
    {
        $this->url      = (string) config('services.microvix.url');
        $this->usuario  = (string) config('services.microvix.user', 'linx_export');
        $this->senha    = (string) config('services.microvix.pass', 'linx_export');
        $this->chave    = (string) config('services.microvix.chave');
        $this->cnpj     = (string) config('services.microvix.cnpj');
        $this->idPortal = (int) config('services.microvix.id_portal');
        $this->timeout  = (int) config('services.microvix.timeout', 20);
    }

    public function configurado(): bool
    {
        return $this->url !== '' && $this->chave !== '' && $this->cnpj !== '';
    }

    /* ======================================================================
     |  Consulta de produto (usada no "Adicionar produto" do endereço)
     ====================================================================== */

    /**
     * Consulta um código bipado/digitado: código de barras ou código interno.
     *
     * @return array{encontrado: bool, produto: ?array, sync_pendente: bool, mensagem: ?string}
     */
    public function consultar(string $codigo): array
    {
        $codigo = trim($codigo);
        $resp = ['encontrado' => false, 'produto' => null, 'sync_pendente' => false, 'mensagem' => null];

        if ($codigo === '') {
            return $resp;
        }
        if (! $this->configurado()) {
            $resp['mensagem'] = 'Integração Microvix não configurada.';
            return $resp;
        }

        // 1) Código de barras -> tabela local -> dados do produto.
        $codProduto = $this->codProdutoPorBarra($codigo);

        // 2) Não achou e a base já foi sincronizada: pode ser código novo no
        //    Microvix. Sincroniza só o que mudou (trava de 3 min) e re-checa.
        $baseVazia = MicrovixBarra::query()->doesntExist();
        if (! $codProduto && ! $baseVazia && Cache::add('microvix_sync_on_miss', 1, 180)) {
            try {
                $this->sincronizarBarras(2);
                if (MicrovixProduto::query()->exists()) {
                    $this->sincronizarProdutos(1);
                }
            } catch (\Throwable $e) {
                Log::warning('Microvix sync on-miss falhou: ' . $e->getMessage());
            }
            $codProduto = $this->codProdutoPorBarra($codigo);
        }

        $produto = $codProduto ? $this->buscarPorCodigo($codProduto) : null;
        if ($produto) {
            // O código bipado é a fonte da verdade para o código de barras.
            $produto['cod_barra'] = $codigo;
        }

        // 3) O valor digitado pode ser o código interno.
        if (! $produto && ctype_digit($codigo) && strlen($codigo) <= 9) {
            $produto = $this->buscarPorCodigo($codigo);
        }

        // 4) Último recurso: a referência (SKU) exata do Microvix.
        if (! $produto && ($codRef = MicrovixProduto::where('referencia', $codigo)->value('cod_produto'))) {
            $produto = $this->buscarPorCodigo((string) $codRef);
        }

        if ($produto) {
            return [
                'encontrado'    => true,
                'produto'       => [
                    'nome'        => trim((string) ($produto['nome'] ?? '')),
                    'cod_produto' => (string) ($produto['cod_produto'] ?? ''),
                    'cod_barra'   => (string) ($produto['cod_barra'] ?? ''),
                    'sku'         => trim((string) ($produto['referencia'] ?? '')),
                    'desativado'  => strtoupper((string) ($produto['desativado'] ?? '')) === 'S',
                ],
                'sync_pendente' => false,
                'mensagem'      => null,
            ];
        }

        $resp['sync_pendente'] = $baseVazia;
        $resp['mensagem'] = $baseVazia
            ? 'A base de códigos de barras do Microvix ainda não foi sincronizada.'
            : 'Produto não encontrado. Validar dados digitados ou consultar manualmente no Microvix.';

        return $resp;
    }

    /**
     * Compatibilidade com a validação da Nova Solicitação (ProductController).
     *
     * @return array{valido: bool, nome: ?string, codigo: string, mensagem: ?string}
     */
    public function validarProduto(string $codigo): array
    {
        $r = $this->consultar($codigo);

        return [
            'valido'   => $r['encontrado'],
            'nome'     => $r['produto']['nome'] ?? null,
            'codigo'   => $codigo,
            'mensagem' => $r['mensagem'],
        ];
    }

    public function codProdutoPorBarra(string $codBarra): ?string
    {
        $cod = MicrovixBarra::where('cod_barra', $codBarra)->value('cod_produto');
        return $cod !== null ? (string) $cod : null;
    }

    /** Dados do produto (LinxProdutos) pelo código interno. */
    public function buscarPorCodigo(string $codProduto): ?array
    {
        $regs = $this->chamar('LinxProdutos', [
            'dt_update_inicio' => now()->subYears(30)->format('Y-m-d'),
            'dt_update_fim'    => now()->addDay()->format('Y-m-d'),
            'cod_produto'      => $codProduto,
        ]);

        return $regs[0] ?? null;
    }

    /* ======================================================================
     |  Sincronização da base de códigos de barras
     ====================================================================== */

    /**
     * Sincroniza um lote de páginas de LinxProdutosCodBar a partir do último
     * timestamp gravado. Chamado em lotes pela tela (a hospedagem limita o
     * tempo de cada requisição), até retornar concluido=true.
     *
     * @return array{paginas:int, upserts:int, concluido:bool, total:int}
     */
    public function sincronizarBarras(int $maxPaginas = 3): array
    {
        @set_time_limit(120);

        $ts = (int) MicrovixBarra::max('ts');
        $paginas = 0;
        $upserts = 0;
        $concluido = false;

        while ($paginas < $maxPaginas) {
            $regs = $this->chamar('LinxProdutosCodBar', ['timestamp' => (string) $ts], 30);
            if (empty($regs)) {
                $concluido = true;
                break;
            }
            $paginas++;

            $agora = now();
            $linhas = [];
            $maxTs = $ts;
            foreach ($regs as $r) {
                $cb = trim((string) ($r['cod_barra'] ?? ''));
                $cp = trim((string) ($r['cod_produto'] ?? ''));
                $rts = (int) ($r['timestamp'] ?? 0);
                $maxTs = max($maxTs, $rts);
                if ($cb !== '' && $cp !== '') {
                    $linhas[$cb] = [
                        'cod_barra'   => $cb,
                        'cod_produto' => $cp,
                        'ts'          => $rts,
                        'created_at'  => $agora,
                        'updated_at'  => $agora,
                    ];
                }
            }

            foreach (array_chunk(array_values($linhas), 1000) as $bloco) {
                MicrovixBarra::upsert($bloco, ['cod_barra'], ['cod_produto', 'ts', 'updated_at']);
            }
            $upserts += count($linhas);

            // Página incompleta = fim do catálogo.
            if (count($regs) < self::PAGINA || $maxTs <= $ts) {
                $concluido = true;
                break;
            }
            $ts = $maxTs;
        }

        return [
            'paginas'   => $paginas,
            'upserts'   => $upserts,
            'concluido' => $concluido,
            'total'     => MicrovixBarra::count(),
        ];
    }

    /**
     * Sincroniza um lote de páginas de LinxProdutos (nome + referência) a
     * partir do último timestamp gravado. Mesmo esquema de lotes das barras.
     *
     * @return array{paginas:int, upserts:int, concluido:bool, total:int}
     */
    public function sincronizarProdutos(int $maxPaginas = 3): array
    {
        @set_time_limit(120);

        $ts = (int) MicrovixProduto::max('ts');
        $paginas = 0;
        $upserts = 0;
        $concluido = false;

        while ($paginas < $maxPaginas) {
            $regs = $this->chamar('LinxProdutos', [
                'dt_update_inicio' => '2000-01-01',
                'dt_update_fim'    => now()->addDay()->format('Y-m-d'),
                'timestamp'        => (string) $ts,
            ], 60);
            if (empty($regs)) {
                $concluido = true;
                break;
            }
            $paginas++;

            $agora = now();
            $linhas = [];
            $maxTs = $ts;
            foreach ($regs as $r) {
                $cp = trim((string) ($r['cod_produto'] ?? ''));
                $rts = (int) ($r['timestamp'] ?? 0);
                $maxTs = max($maxTs, $rts);
                if ($cp !== '') {
                    $linhas[$cp] = [
                        'cod_produto' => $cp,
                        'nome'        => mb_substr(trim((string) ($r['nome'] ?? '')), 0, 255),
                        'referencia'  => mb_substr(trim((string) ($r['referencia'] ?? '')), 0, 120) ?: null,
                        'desativado'  => strtoupper((string) ($r['desativado'] ?? '')) === 'S',
                        'ts'          => $rts,
                        'created_at'  => $agora,
                        'updated_at'  => $agora,
                    ];
                }
            }

            foreach (array_chunk(array_values($linhas), 500) as $bloco) {
                MicrovixProduto::upsert($bloco, ['cod_produto'], ['nome', 'referencia', 'desativado', 'ts', 'updated_at']);
            }
            $upserts += count($linhas);

            if (count($regs) < self::PAGINA || $maxTs <= $ts) {
                $concluido = true;
                break;
            }
            $ts = $maxTs;
        }

        if ($upserts > 0) {
            $this->atualizarReferencias();
        }

        return [
            'paginas'   => $paginas,
            'upserts'   => $upserts,
            'concluido' => $concluido,
            'total'     => MicrovixProduto::count(),
        ];
    }

    /**
     * Leva a referência atual do Microvix para os produtos já endereçados
     * (campo sku). Acha o produto do Microvix pelo código interno ou, para
     * itens que vieram da base de apoio, pelo código de barras — e nesse
     * caso também grava o código interno, para ficarem ligados ao Microvix.
     */
    public function atualizarReferencias(): int
    {
        $produtos = Product::query()->get(['id', 'codigo_microvix', 'codigo_barras', 'sku']);
        $mvx = MicrovixProduto::query()
            ->whereIn('cod_produto', $produtos->pluck('codigo_microvix')->filter()->all())
            ->pluck('referencia', 'cod_produto');
        $porBarra = MicrovixBarra::query()
            ->whereIn('cod_barra', $produtos->pluck('codigo_barras')->filter()->all())
            ->pluck('cod_produto', 'cod_barra');
        $refPorBarra = MicrovixProduto::query()
            ->whereIn('cod_produto', $porBarra->values()->unique()->all())
            ->pluck('referencia', 'cod_produto');
        $codigosEmUso = $produtos->pluck('codigo_microvix')->filter()->flip();

        $alterados = 0;
        foreach ($produtos as $p) {
            if ($p->codigo_microvix && $mvx->has($p->codigo_microvix)) {
                $ref = $mvx[$p->codigo_microvix];
            } elseif ($p->codigo_barras && ($cod = $porBarra[$p->codigo_barras] ?? null) && $refPorBarra->has($cod)) {
                $ref = $refPorBarra[$cod];
                if (! $codigosEmUso->has($cod)) {
                    $p->codigo_microvix = $cod;
                    $codigosEmUso[$cod] = true;
                }
            } else {
                continue;
            }

            if ($ref && $p->sku !== $ref) {
                $p->sku = $ref;
            }
            if ($p->isDirty()) {
                $p->save();
                $alterados++;
            }
        }

        return $alterados;
    }

    /**
     * Busca na base local do Microvix por nome ou referência. Pré-filtra no
     * banco pelas palavras digitadas e ranqueia com a busca tolerante a erro.
     */
    public function buscarTexto(string $termo, BuscaProdutos $busca, int $max = 15): array
    {
        $palavras = array_values(array_filter(
            preg_split('/\s+/', trim($termo)),
            fn ($w) => mb_strlen($w) >= 3
        ));
        if (! $palavras) {
            return ['exatos' => collect(), 'parecidos' => collect()];
        }

        $candidatos = MicrovixProduto::query()
            ->where(function ($q) use ($palavras, $termo) {
                $q->where('referencia', trim($termo));
                foreach ($palavras as $w) {
                    $q->orWhere('nome', 'like', "%{$w}%")->orWhere('referencia', 'like', "%{$w}%");
                }
            })
            ->limit(3000)
            ->get(['cod_produto', 'nome', 'referencia', 'desativado']);

        return $busca->ranquear(
            $candidatos,
            fn (MicrovixProduto $m) => trim("{$m->nome} {$m->referencia}"),
            fn (MicrovixProduto $m) => [$m->cod_produto, $m->referencia],
            $termo,
            $max,
            10
        );
    }

    /* ======================================================================
     |  Transporte
     ====================================================================== */

    /**
     * Executa um comando da WebAPI e devolve as linhas como arrays associativos.
     */
    private function chamar(string $metodo, array $parametros = [], ?int $timeout = null): array
    {
        $params = '';
        foreach ($parametros as $id => $valor) {
            $params .= '<Parameter id="' . $id . '">' . htmlspecialchars((string) $valor, ENT_XML1) . '</Parameter>';
        }

        $xml = '<?xml version="1.0" encoding="utf-8" ?>'
            . '<LinxMicrovix>'
            . '<Authentication user="' . htmlspecialchars($this->usuario, ENT_XML1) . '" password="' . htmlspecialchars($this->senha, ENT_XML1) . '" />'
            . '<ResponseFormat>xml</ResponseFormat>'
            . '<IdPortal>' . $this->idPortal . '</IdPortal>'
            . '<Command><Name>' . $metodo . '</Name><Parameters>'
            . '<Parameter id="chave">' . htmlspecialchars($this->chave, ENT_XML1) . '</Parameter>'
            . '<Parameter id="cnpjEmp">' . htmlspecialchars($this->cnpj, ENT_XML1) . '</Parameter>'
            . $params
            . '</Parameters></Command></LinxMicrovix>';

        try {
            $response = Http::withBody($xml, 'text/xml')
                ->timeout($timeout ?? $this->timeout)
                ->post($this->url);

            if ($response->failed()) {
                Log::error("Microvix [{$metodo}]: HTTP {$response->status()}");
                return [];
            }

            return $this->parseXml($response->body(), $metodo);
        } catch (\Throwable $e) {
            Log::error("Microvix [{$metodo}]: " . $e->getMessage());
            return [];
        }
    }

    private function parseXml(string $xml, string $metodo): array
    {
        $obj = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (! $obj) {
            Log::error("Microvix [{$metodo}]: resposta não é XML válido");
            return [];
        }
        if ((string) $obj->ResponseResult->ResponseSuccess !== 'True') {
            Log::error("Microvix [{$metodo}]: " . (string) $obj->ResponseResult->ResponseError->Message);
            return [];
        }
        if (! isset($obj->ResponseData->C)) {
            return [];
        }

        $colunas = [];
        foreach ($obj->ResponseData->C->D as $col) {
            $colunas[] = (string) $col;
        }

        $linhas = [];
        foreach ($obj->ResponseData->R as $row) {
            $reg = [];
            $i = 0;
            foreach ($row->D as $val) {
                $reg[$colunas[$i] ?? $i] = (string) $val;
                $i++;
            }
            $linhas[] = $reg;
        }

        return $linhas;
    }
}
