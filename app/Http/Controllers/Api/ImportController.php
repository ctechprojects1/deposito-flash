<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function __construct(private readonly StockImportService $service)
    {
    }

    /**
     * Importa estoque a partir de um CSV enviado pela tela (upload).
     *
     * POST /api/stock/import   (multipart/form-data)
     *   - arquivo: o CSV (Time, Codigo_Produto, Nome, Quantidade)
     *   - somar:   1 = soma na quantidade existente | 0 = sobrescreve (padrão)
     */
    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'arquivo' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
            'somar'   => ['sometimes', 'boolean'],
        ], [
            'arquivo.mimes' => 'Envie um arquivo .csv (ou .txt) com as colunas corretas.',
            'arquivo.max'   => 'O arquivo não pode exceder 10 MB.',
        ]);

        try {
            $resultado = $this->service->importFromPath(
                $request->file('arquivo')->getRealPath(),
                null, // detecta o delimitador automaticamente
                $request->boolean('somar'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Importação concluída.',
            'data'    => $resultado,
        ]);
    }
}
