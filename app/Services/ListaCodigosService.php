<?php

namespace App\Services;

/**
 * Lê uma lista em texto para a Nova Solicitação: uma linha por item, no
 * formato codigo;quantidade. Não usa IA: o texto já vem estruturado.
 */
class ListaCodigosService
{
    /**
     * @return array{itens: array<int, array{codigo:string, quantidade:float}>, ignoradas: array<int, string>}
     *         `ignoradas` = "linha N: conteúdo" do que não está no formato.
     */
    public function ler(string $conteudo): array
    {
        $linhas = preg_split('/\r\n|\r|\n/', $this->utf8($conteudo));
        $itens = [];
        $ignoradas = [];

        foreach ($linhas as $i => $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            $partes = array_map(fn ($p) => trim($p, " \t\"'"), explode(';', $linha));
            $codigo = $partes[0];
            $qtd = $partes[1] ?? '';

            // Decimal com vírgula ou ponto, até 2 casas. "1.000" fica de fora de
            // propósito: pode ser mil ou um, e aí é melhor o operador ver a linha.
            if ($codigo === '' || ! preg_match('/^\d+([.,]\d{1,2})?$/', $qtd) || (float) str_replace(',', '.', $qtd) <= 0) {
                // A 1ª linha pode ser o cabeçalho ("codigo;quantidade").
                if (! ($i === 0 && ! preg_match('/\d/', $qtd))) {
                    $ignoradas[] = 'linha ' . ($i + 1) . ': ' . mb_substr($linha, 0, 60);
                }
                continue;
            }

            // Código repetido soma: o checklist tem uma linha por código.
            $itens[$codigo] = ($itens[$codigo] ?? 0) + (float) str_replace(',', '.', $qtd);
        }

        return [
            'itens'     => array_map(
                fn ($c, $q) => ['codigo' => (string) $c, 'quantidade' => round($q, 2)],
                array_keys($itens),
                array_values($itens)
            ),
            'ignoradas' => $ignoradas,
        ];
    }

    /** O Bloco de Notas pode salvar em ANSI ou em "Unicode" (UTF-16), com ou sem BOM. */
    private function utf8(string $s): string
    {
        if (str_starts_with($s, "\xFF\xFE")) {
            return mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($s, "\xFE\xFF")) {
            return mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            return substr($s, 3);
        }

        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
