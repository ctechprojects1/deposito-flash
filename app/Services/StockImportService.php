<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Importa estoque a partir de um CSV (Time, Codigo_Produto, Nome, Quantidade).
 *
 * Lógica única compartilhada pelo comando de terminal (ImportInitialStock)
 * e pelo endpoint web de upload (ImportController).
 */
class StockImportService
{
    /** Colunas esperadas no cabeçalho (case-insensitive). */
    private const COLUNAS = ['time', 'codigo_produto', 'nome', 'quantidade'];

    /**
     * Executa a importação a partir de um caminho de arquivo.
     *
     * @return array{importados:int, ignorados:int, locais:int, produtos:int, erros:array<string>}
     */
    public function importFromPath(string $caminho, ?string $delimiter = null, bool $somar = false): array
    {
        if (! is_file($caminho) || ! is_readable($caminho)) {
            throw new RuntimeException('Arquivo não encontrado ou sem permissão de leitura.');
        }

        $delimiter ??= $this->detectDelimiter($caminho);

        $handle = fopen($caminho, 'r');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo.');
        }

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('Arquivo vazio.');
        }

        $mapa = $this->mapearColunas($header);
        if ($mapa === null) {
            fclose($handle);
            throw new RuntimeException('Cabeçalho inválido. Esperado: Time, Codigo_Produto, Nome, Quantidade.');
        }

        $cacheLocais   = [];
        $cacheProdutos = [];
        $linha      = 1;
        $importados = 0;
        $ignorados  = 0;
        $erros      = [];

        while (($registro = fgetcsv($handle, 0, $delimiter)) !== false) {
            $linha++;

            // Ignora linhas totalmente em branco.
            if (count(array_filter($registro, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $time      = trim($registro[$mapa['time']] ?? '');
            $codigo    = trim($registro[$mapa['codigo_produto']] ?? '');
            $nome      = trim($registro[$mapa['nome']] ?? '');
            $quantBrut = trim($registro[$mapa['quantidade']] ?? '');

            if ($time === '' || $codigo === '') {
                $ignorados++;
                $erros[] = "Linha {$linha}: 'Time' ou 'Codigo_Produto' vazio.";
                continue;
            }

            $quantidade = $this->normalizarQuantidade($quantBrut);

            try {
                DB::transaction(function () use (
                    $time, $codigo, $nome, $quantidade, $somar,
                    &$cacheLocais, &$cacheProdutos
                ) {
                    $location = $cacheLocais[$time]
                        ??= Location::firstOrCreate(['nome' => $time]);

                    $product = $cacheProdutos[$codigo] ??= Product::firstOrCreate(
                        ['codigo_microvix' => $codigo],
                        ['nome' => $nome !== '' ? $nome : $codigo]
                    );

                    if ($nome !== '' && $product->nome !== $nome) {
                        $product->update(['nome' => $nome]);
                    }

                    $stock = Stock::firstOrNew([
                        'location_id' => $location->id,
                        'product_id'  => $product->id,
                    ]);

                    $stock->quantidade = $somar
                        ? (float) $stock->quantidade + $quantidade
                        : $quantidade;

                    $stock->save();
                });

                $importados++;
            } catch (\Throwable $e) {
                $ignorados++;
                $erros[] = "Linha {$linha}: {$e->getMessage()}";
            }
        }

        fclose($handle);

        return [
            'importados' => $importados,
            'ignorados'  => $ignorados,
            'locais'     => count($cacheLocais),
            'produtos'   => count($cacheProdutos),
            'erros'      => $erros,
        ];
    }

    /**
     * Detecta o delimitador olhando a primeira linha (vírgula x ponto-e-vírgula).
     * Excel em PT-BR costuma salvar com ";".
     */
    public function detectDelimiter(string $caminho): string
    {
        $primeira = '';
        if (($h = fopen($caminho, 'r')) !== false) {
            $primeira = (string) fgets($h);
            fclose($h);
        }

        return substr_count($primeira, ';') > substr_count($primeira, ',') ? ';' : ',';
    }

    /**
     * Mapeia os nomes das colunas do cabeçalho para índices (tolerante).
     *
     * @return array<string,int>|null
     */
    private function mapearColunas(array $header): ?array
    {
        // Remove BOM eventual do primeiro campo (arquivos salvos como UTF-8 BOM).
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $normalizado = array_map(
            fn ($c) => str_replace(' ', '_', strtolower(trim((string) $c))),
            $header
        );

        $mapa = [];
        foreach (self::COLUNAS as $coluna) {
            $indice = array_search($coluna, $normalizado, true);
            if ($indice === false) {
                return null;
            }
            $mapa[$coluna] = $indice;
        }

        return $mapa;
    }

    /**
     * Converte quantidade textual ("1.000,50" ou "1000.50") para float.
     */
    private function normalizarQuantidade(string $valor): float
    {
        if ($valor === '') {
            return 0.0;
        }

        if (str_contains($valor, ',')) {
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
        }

        return max(0.0, (float) $valor);
    }
}
