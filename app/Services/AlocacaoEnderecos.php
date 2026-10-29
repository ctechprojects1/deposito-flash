<?php

namespace App\Services;

use App\Models\Deposito;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;

/**
 * Decide de quais endereços (do CD atual) sai a quantidade de um produto.
 * Usado pela solicitação a partir da nota e pela separação das vendas.
 */
class AlocacaoEnderecos
{
    /**
     * Endereços com saldo do produto, do menor para o maior saldo.
     *
     * @return array<int, array{location_id:int, nome:?string, quantidade:float}>
     */
    public function locaisComSaldo(Product $product): array
    {
        $product->loadMissing('stocks.location');

        return $product->stocks
            ->where('quantidade', '>', 0)
            ->sortBy('quantidade')
            ->map(fn ($st) => [
                'location_id' => $st->location_id,
                'nome'        => $st->location?->nome,
                'quantidade'  => (float) $st->quantidade,
            ])
            ->values()
            ->all();
    }

    /**
     * Endereços com saldo do produto nos CDs do mesmo lugar físico (ex.: na
     * separação do CD - Commerce, os de Goiânia). Não entram na alocação
     * automática: o separador escolhe na lista se o item estiver lá.
     *
     * @return array<int, array{location_id:int, nome:?string, quantidade:float, cd:string}>
     */
    public function locaisOutrosCds(Product $product): array
    {
        $vizinhos = Deposito::vizinhos();
        if (! $vizinhos) {
            return [];
        }

        return Stock::withoutGlobalScope('deposito')
            ->where('product_id', $product->id)
            ->where('quantidade', '>', 0)
            ->whereIn('location_id', Location::withoutGlobalScope('deposito')->whereIn('deposito_id', array_keys($vizinhos))->select('id'))
            ->with(['location' => fn ($q) => $q->withoutGlobalScope('deposito')])
            ->orderByDesc('quantidade')
            ->get()
            ->map(fn (Stock $st) => [
                'location_id' => $st->location_id,
                'nome'        => $st->location?->nome,
                'quantidade'  => (float) $st->quantidade,
                'cd'          => $vizinhos[$st->location?->deposito_id] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Divide a quantidade entre endereços:
     *  - se um endereço comporta tudo: o de MENOR saldo que comporte (esvazia os pequenos);
     *  - senão: tira tudo do maior e repete com o que falta (ex.: 20 un. com
     *    endereços de 10 -> 10 + 10), usando o mínimo de endereços.
     * Se o CD não tem saldo suficiente, a falta fica na última parte (o
     * separador vê o aviso de saldo), ou sem endereço se não há saldo nenhum.
     *
     * @param  array<int, float>  $reservado  location_id => qtd já usada nesta separação
     * @return array<int, array{location_id:?int, quantidade:float}>
     */
    public function alocar(Product $product, float $qtd, array $reservado = []): array
    {
        $livres = [];
        foreach ($this->locaisComSaldo($product) as $l) { // menor -> maior
            $q = round($l['quantidade'] - ($reservado[$l['location_id']] ?? 0), 2);
            if ($q > 0) {
                $livres[] = ['location_id' => $l['location_id'], 'quantidade' => $q];
            }
        }
        usort($livres, fn ($a, $b) => $a['quantidade'] <=> $b['quantidade']);

        $partes = [];
        $restante = round($qtd, 2);
        while ($restante > 0 && $livres) {
            foreach ($livres as $l) {
                if ($l['quantidade'] >= $restante) {
                    $partes[] = ['location_id' => $l['location_id'], 'quantidade' => $restante];
                    return $partes;
                }
            }
            $maior = array_pop($livres);
            $partes[] = $maior;
            $restante = round($restante - $maior['quantidade'], 2);
        }

        if ($restante > 0) {
            if ($partes) {
                $partes[count($partes) - 1]['quantidade'] = round($partes[count($partes) - 1]['quantidade'] + $restante, 2);
            } else {
                $partes[] = ['location_id' => null, 'quantidade' => $restante];
            }
        }

        return $partes;
    }
}
