<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Busca de produtos tolerante a erro: acha pelo código Microvix, código de
 * barras ou descrição, e quando o operador erra/não sabe tudo sugere os
 * mais parecidos.
 *
 *  - "15445" acha o 15444 (um dígito trocado)
 *  - "amaciante glicerina" acha "AMACIANTE DE ROUPA GLICERINA & CAMOMILA"
 *  - "manteguera" acha "Manteigueira" (erro de digitação, sem acento)
 *
 * Só considera produtos com estoque no CD atual.
 */
class BuscaProdutos
{
    /** A partir desta nota o resultado é "encontrado"; abaixo é "parecido". */
    public const NOTA_EXATA = 75;
    private const NOTA_MINIMA = 45;

    /**
     * @return array{exatos: Collection<int, array{product: Product, nota: int}>, parecidos: Collection<int, array{product: Product, nota: int}>}
     */
    public function buscar(string $termo, int $maxExatos = 50, int $maxParecidos = 10): array
    {
        $termo = trim($termo);
        $vazio = ['exatos' => collect(), 'parecidos' => collect()];
        if ($termo === '') {
            return $vazio;
        }

        $t = $this->normalizar($termo);
        $tokens = array_values(array_filter(explode(' ', $t), fn ($x) => $x !== ''));
        $codigoBusca = preg_replace('/\s+/', '', $t);

        $notas = $this->candidatos()
            ->map(fn (Product $p) => ['product' => $p, 'nota' => $this->nota($p, $t, $tokens, $codigoBusca)])
            ->filter(fn ($r) => $r['nota'] >= self::NOTA_MINIMA)
            ->sortByDesc('nota')
            ->values();

        return [
            'exatos'    => $notas->filter(fn ($r) => $r['nota'] >= self::NOTA_EXATA)->take($maxExatos)->values(),
            'parecidos' => $notas->filter(fn ($r) => $r['nota'] < self::NOTA_EXATA)->take($maxParecidos)->values(),
        ];
    }

    /**
     * Para um item da nota que não bateu com o depósito: os produtos mais
     * parecidos pelo código e pela descrição (no máximo $max).
     */
    public function sugerirParaItem(string $codigo, string $descricao, int $max = 3): Collection
    {
        $todos = collect();
        foreach ([$codigo, $descricao] as $termo) {
            $r = $this->buscar($termo, $max, $max);
            $todos = $todos->merge($r['exatos'])->merge($r['parecidos']);
        }

        return $todos->sortByDesc('nota')->unique(fn ($r) => $r['product']->id)->take($max)->values();
    }

    /** Formato usado pelas telas (mapa, relatório, solicitação, movimentação). */
    public function formatar(array $item): array
    {
        /** @var Product $p */
        $p = $item['product'];
        $p->loadMissing('stocks.location');
        $locs = $p->stocks
            ->where('quantidade', '>', 0)
            ->sortByDesc('quantidade')
            ->map(fn ($s) => [
                'location_id' => $s->location_id,
                'endereco'    => $s->location?->nome,
                'time'        => $s->location?->corredor,
                'posicao'     => $s->location?->esteira,
                'quantidade'  => (float) $s->quantidade,
            ])
            ->values();

        return [
            'product_id'      => $p->id,
            'nome'            => $p->nome,
            'codigo_microvix' => $p->codigo_microvix,
            'codigo_barras'   => $p->codigo_barras,
            'total'           => (float) $p->stocks->sum('quantidade'),
            'localizacoes'    => $locs,
            'semelhanca'      => $item['nota'],
        ];
    }

    private ?Collection $cacheCandidatos = null;

    /** Produtos com estoque no CD atual (carregados uma vez por requisição). */
    private function candidatos(): Collection
    {
        return $this->cacheCandidatos ??= Product::query()
            ->whereHas('stocks')
            ->get(['id', 'nome', 'codigo_microvix', 'codigo_barras']);
    }

    private function nota(Product $p, string $t, array $tokens, string $codigoBusca): int
    {
        $melhor = 0;

        // ---- Códigos (Microvix / barras)
        foreach ([$p->codigo_microvix, $p->codigo_barras] as $codigo) {
            $c = $this->normalizar((string) $codigo);
            $c = preg_replace('/\s+/', '', $c);
            if ($c === '' || $codigoBusca === '') {
                continue;
            }
            if ($c === $codigoBusca || ltrim($c, '0') === ltrim($codigoBusca, '0')) {
                return 100;
            }
            if (str_starts_with($c, $codigoBusca) && strlen($codigoBusca) >= 2) {
                $melhor = max($melhor, 90);
            } elseif (str_contains($c, $codigoBusca) && strlen($codigoBusca) >= 3) {
                $melhor = max($melhor, 80);
            } elseif (strlen($codigoBusca) >= 3 && abs(strlen($c) - strlen($codigoBusca)) <= 2) {
                // Código digitado errado: dígitos diferentes, invertidos ou faltando.
                $dist = $this->distancia($c, $codigoBusca);
                $limite = strlen($codigoBusca) >= 5 ? 2 : 1;
                if ($dist <= $limite) {
                    $melhor = max($melhor, 70 - ($dist - 1) * 12);
                }
            }
        }

        // ---- Descrição
        $nome = $this->normalizar((string) $p->nome);
        if ($nome !== '' && $tokens) {
            if (str_contains($nome, $t)) {
                $melhor = max($melhor, 95);
            }

            $palavras = array_values(array_filter(explode(' ', $nome), fn ($x) => $x !== ''));
            $soma = 0;
            $todasContidas = true;
            foreach ($tokens as $tok) {
                if (str_contains($nome, $tok)) {
                    $soma += 1;
                    continue;
                }
                $todasContidas = false;
                $soma += $this->parecidoComAlguma($tok, $palavras);
            }
            $media = $soma / count($tokens);

            if ($todasContidas) {
                $melhor = max($melhor, 85);
            } else {
                // Parte das palavras bate ou é parecida (erro de digitação).
                $melhor = max($melhor, (int) round($media * 72));
            }
        }

        return $melhor;
    }

    /** 0..1: quão parecida a palavra digitada é da mais parecida do nome. */
    private function parecidoComAlguma(string $tok, array $palavras): float
    {
        if (strlen($tok) < 3) {
            return 0;
        }
        $max = 0;
        foreach ($palavras as $w) {
            if (strlen($w) < 3) {
                continue;
            }
            if (str_starts_with($w, $tok) || str_starts_with($tok, $w)) {
                $max = max($max, 0.9);
                continue;
            }
            $dist = levenshtein($tok, $w);
            $sim = 1 - $dist / max(strlen($tok), strlen($w));
            if ($sim >= 0.7) {
                $max = max($max, $sim);
            }
        }

        return $max;
    }

    /**
     * Distância de edição contando inversão de dois caracteres vizinhos como
     * 1 erro só (15444 x 15454 = 1; 10177 x 10771 = 2).
     */
    private function distancia(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $custo = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $custo);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$la][$lb];
    }

    private function normalizar(string $s): string
    {
        $s = Str::lower(Str::ascii($s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
