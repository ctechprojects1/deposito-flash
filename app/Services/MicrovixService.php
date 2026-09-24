<?php

namespace App\Services;

use App\Models\MicrovixBarra;
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

        // 3) Último recurso: o valor digitado pode ser o código interno.
        if (! $produto && ctype_digit($codigo) && strlen($codigo) <= 9) {
            $produto = $this->buscarPorCodigo($codigo);
        }

        if ($produto) {
            return [
                'encontrado'    => true,
                'produto'       => [
                    'nome'        => trim((string) ($produto['nome'] ?? '')),
                    'cod_produto' => (string) ($produto['cod_produto'] ?? ''),
                    'cod_barra'   => (string) ($produto['cod_barra'] ?? ''),
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
