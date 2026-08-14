<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integração com a API do ERP Linx Microvix.
 *
 * Responsável por consultar um produto pelo seu código e informar se ele
 * é válido, retornando também o nome cadastrado no ERP.
 *
 * Toda a configuração (URL, credenciais, timeout) vem do .env via config/services.php.
 */
class MicrovixService
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 15,
    ) {
    }

    /**
     * Consulta um produto no Microvix pelo código.
     *
     * @return array{valido: bool, nome: ?string, codigo: string, mensagem: ?string}
     */
    public function validarProduto(string $codigo): array
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            return $this->resposta(false, null, $codigo, 'Código não informado.');
        }

        $baseUrl = $this->baseUrl ?? config('services.microvix.url');
        $apiKey  = $this->apiKey ?? config('services.microvix.key');

        if (blank($baseUrl)) {
            Log::warning('MicrovixService: URL da API não configurada (.env MICROVIX_API_URL).');
            return $this->resposta(false, null, $codigo, 'Integração Microvix não configurada.');
        }

        // Cache curto evita bater na API do ERP repetidamente para o mesmo código.
        $cacheKey = 'microvix:produto:' . md5($codigo);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($baseUrl, $apiKey, $codigo) {
            try {
                // throw: false -> uma resposta 4xx/5xx NÃO lança exceção; tratamos abaixo.
                $request = Http::timeout($this->timeout)
                    ->retry(2, 200, throw: false)
                    ->acceptJson();

                if (filled($apiKey)) {
                    $request = $request->withToken($apiKey);
                }

                $response = $request->get($baseUrl, [
                    'codigo' => $codigo,
                ]);

                if ($response->failed()) {
                    Log::warning('MicrovixService: resposta com erro', [
                        'codigo' => $codigo,
                        'status' => $response->status(),
                    ]);

                    return $this->resposta(false, null, $codigo, 'Erro ao consultar o ERP (HTTP ' . $response->status() . ').');
                }

                return $this->interpretarResposta($response->json(), $codigo);
            } catch (\Throwable $e) {
                // Qualquer falha (conexão, timeout, protocolo SOAP não implementado,
                // resposta inesperada) NUNCA deve derrubar o endpoint. Loga e segue.
                Log::error('MicrovixService: falha na consulta', ['erro' => $e->getMessage()]);
                return $this->resposta(false, null, $codigo, 'Consulta ao Microvix indisponível no momento.');
            }
        });
    }

    /**
     * Normaliza o corpo da resposta do ERP para o nosso contrato interno.
     *
     * A API do Microvix pode variar o formato; tratamos as chaves mais comuns
     * e caímos para "inválido" quando nada é reconhecido.
     */
    private function interpretarResposta(mixed $body, string $codigo): array
    {
        if (! is_array($body)) {
            return $this->resposta(false, null, $codigo, 'Resposta inesperada do ERP.');
        }

        // Suporta payloads no formato { "data": {...} } ou direto na raiz.
        $data = $body['data'] ?? $body;

        // Se vier uma lista, considera o primeiro item.
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? [];
        }

        $nome = $data['nome']
            ?? $data['descricao']
            ?? $data['produto']
            ?? $data['nome_produto']
            ?? null;

        $valido = filled($nome)
            || ($data['valido'] ?? $data['existe'] ?? false) === true;

        return $this->resposta(
            (bool) $valido,
            $nome ? trim((string) $nome) : null,
            $codigo,
            $valido ? null : 'Produto não encontrado no Microvix.'
        );
    }

    /**
     * @return array{valido: bool, nome: ?string, codigo: string, mensagem: ?string}
     */
    private function resposta(bool $valido, ?string $nome, string $codigo, ?string $mensagem): array
    {
        return [
            'valido'   => $valido,
            'nome'     => $nome,
            'codigo'   => $codigo,
            'mensagem' => $mensagem,
        ];
    }
}
