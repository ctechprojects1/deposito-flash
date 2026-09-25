<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Lê uma nota (documento interno / DANFE) ou um pedido de venda do Microvix
 * em PDF e devolve os itens. Mesma abordagem do Rastreador do PRODID: o PDF vai
 * para o Claude como bloco "document" (funciona até em PDF impresso com
 * "Microsoft Print to PDF", que não tem camada de texto).
 */
class NotaExtractorService
{
    private string $endpoint = 'https://api.anthropic.com/v1/messages';

    public function configurado(): bool
    {
        return (string) config('services.anthropic.key') !== '';
    }

    /**
     * @return array{tipo:?string, numero:?string, destino:?string,
     *               itens: array<int, array{codigo:string, descricao:string, quantidade:float}>,
     *               erro:?string}
     */
    public function extrair(string $pdfBase64): array
    {
        $vazio = ['tipo' => null, 'numero' => null, 'destino' => null, 'itens' => []];

        if (! $this->configurado()) {
            return $vazio + ['erro' => 'Leitura de notas não configurada (ANTHROPIC_API_KEY ausente no .env).'];
        }

        $prompt = <<<'PROMPT'
Este PDF é um documento do ERP Linx Microvix de uma loja brasileira. Pode ser:
- um PEDIDO DE VENDA (tabela ITENS com colunas "Código", "Referência", "Descrição", "Qtde"...);
- uma NF-e / DANFE (tabela "DADOS DOS PRODUTOS / SERVIÇOS" com "CÓDIGO", "DESCRIÇÃO", "QUANT."...);
- um DOCUMENTO INTERNO de nota de transferência/entrada (colunas "Código", descrição, "Qtd.").

Retorne APENAS um JSON válido (sem markdown, sem texto extra) com esta estrutura:

{"tipo":"pedido","numero":"10941/2","destino":"Ana Paula Alves Barroso","itens":[["33954","ASPIRADOR PO AP-40-B 220V/60Hz",1],["28942","CERVEJA AMSTEL ULTRA LAGER 269ML",6]]}

- "tipo": "pedido", "nfe" ou "documento_interno".
- "numero": número do pedido/nota como impresso (ex.: "NF 10941/2" -> "10941/2"; "No. 000010941" -> "10941"), ou null.
- "destino": nome do cliente/destinatário (ou da loja de destino, numa transferência), ou null.
- Cada item é [codigo, descricao, quantidade]:
  - "codigo" = código INTERNO do produto (coluna Código/CÓDIGO, normalmente 4 a 8 dígitos). NÃO use o código de barras EAN de 13 dígitos, NÃO use a coluna Referência, NÃO use o NCM.
  - "quantidade" = número da coluna de quantidade (Qtde/QUANT./Qtd.), com ponto decimal. Ignore a unidade ("UN").
  - "descricao" = descrição como impressa (pode abreviar se muito longa).

Regras:
- Percorra TODAS as páginas e liste TODOS os itens na ordem em que aparecem. Não pule nenhum.
- Ignore valores, preços, descontos, impostos, CST, CFOP, NCM e as linhas "Total aproximado dos tributos".
- Não invente itens nem códigos. Campo ilegível = null.
PROMPT;

        $content = [
            [
                'type'   => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $pdfBase64],
            ],
            ['type' => 'text', 'text' => $prompt],
        ];

        $dados = $this->chamarClaude($content, 16000);
        if (isset($dados['erro'])) {
            return $vazio + ['erro' => $dados['erro']];
        }

        // Normaliza e junta linhas repetidas do mesmo código.
        $porCodigo = [];
        foreach ((array) ($dados['itens'] ?? []) as $it) {
            $it = (array) $it;
            $lista = array_is_list($it);
            $cod  = trim((string) ($lista ? ($it[0] ?? '') : ($it['codigo'] ?? '')));
            $desc = trim((string) ($lista ? ($it[1] ?? '') : ($it['descricao'] ?? '')));
            $qtd  = $lista ? ($it[2] ?? null) : ($it['quantidade'] ?? null);

            if ($cod === '' || strtolower($cod) === 'null') {
                continue;
            }
            $qtd = is_numeric($qtd) ? (float) $qtd : 0.0;

            if (isset($porCodigo[$cod])) {
                $porCodigo[$cod]['quantidade'] += $qtd;
            } else {
                $porCodigo[$cod] = [
                    'codigo'     => $cod,
                    'descricao'  => mb_substr($desc, 0, 255) ?: 'Sem descrição',
                    'quantidade' => $qtd,
                ];
            }
        }

        $itens = array_values($porCodigo);
        $tipo = in_array($dados['tipo'] ?? null, ['pedido', 'nfe', 'documento_interno'], true) ? $dados['tipo'] : null;

        return [
            'tipo'    => $tipo,
            'numero'  => ! empty($dados['numero']) ? mb_substr(trim((string) $dados['numero']), 0, 100) : null,
            'destino' => ! empty($dados['destino']) ? mb_substr(trim((string) $dados['destino']), 0, 255) : null,
            'itens'   => $itens,
            'erro'    => empty($itens) ? 'Nenhum item foi encontrado no PDF. Confira se é a nota ou o pedido certo.' : null,
        ];
    }

    private function chamarClaude(array $content, int $maxTokens): array
    {
        @set_time_limit(0); // leitura pode passar de 30s em documento grande

        try {
            $response = Http::timeout(240)
                ->withHeaders([
                    'x-api-key'         => (string) config('services.anthropic.key'),
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post($this->endpoint, [
                    'model'      => (string) config('services.anthropic.model', 'claude-sonnet-5'),
                    'max_tokens' => $maxTokens,
                    'messages'   => [['role' => 'user', 'content' => $content]],
                ]);

            if ($response->failed()) {
                Log::error('NotaExtractor HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500));
                return ['erro' => 'Falha na leitura do documento (HTTP ' . $response->status() . '). Tente de novo.'];
            }

            $json = $response->json();

            // O modelo pode mandar um bloco "thinking" antes do texto: junta só os blocos de texto.
            $texto = '';
            foreach (($json['content'] ?? []) as $bloco) {
                if (($bloco['type'] ?? '') === 'text') {
                    $texto .= $bloco['text'] ?? '';
                }
            }

            if (($json['stop_reason'] ?? null) === 'max_tokens') {
                return ['erro' => 'O documento tem itens demais para ler de uma vez. Divida o PDF em partes menores.'];
            }

            $texto = trim(preg_replace('/```json|```/i', '', $texto));
            preg_match('/\{.*\}/s', $texto, $m);
            $dados = json_decode($m[0] ?? '', true);

            if (! is_array($dados)) {
                Log::error('NotaExtractor: JSON inválido: ' . mb_substr($texto, 0, 500));
                return ['erro' => 'A leitura do documento não retornou dados válidos. Tente de novo.'];
            }

            return $dados;
        } catch (\Throwable $e) {
            Log::error('NotaExtractor: ' . $e->getMessage());
            return ['erro' => 'Erro ao ler o documento: ' . $e->getMessage()];
        }
    }
}
