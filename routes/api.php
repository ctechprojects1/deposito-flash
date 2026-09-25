<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InventoryCountController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MicrovixController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WithdrawalRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da API — Sistema de Endereçamento de Estoque
|--------------------------------------------------------------------------
| Público: login. Todo o resto exige token (auth.token) + permissão (perm:*).
*/

// ---- Público ----
Route::post('/login', [AuthController::class, 'login']);

// ---- Autenticado ----
Route::middleware('auth.token')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Tudo abaixo é separado por CD (header X-Deposito; ver DepositoAtual).
    Route::middleware('deposito')->group(function () {
        // Mapa / endereços
        Route::get('/locations', [LocationController::class, 'index'])->middleware('perm:ver_mapa');
        Route::get('/locations/{location}', [LocationController::class, 'show'])->middleware('perm:ver_mapa');
        Route::post('/locations', [LocationController::class, 'store'])->middleware('perm:gerenciar_enderecos');
        Route::post('/locations/{location}/zerar', [LocationController::class, 'zerar'])->middleware('perm:gerenciar_enderecos');
        Route::post('/locations/{location}/produtos', [LocationController::class, 'adicionarProduto'])->middleware('perm:gerenciar_enderecos');
        Route::put('/locations/{location}/produtos/{stock}', [LocationController::class, 'atualizarSaldo'])->middleware('perm:gerenciar_enderecos');
        Route::post('/locations/{location}/replicar', [LocationController::class, 'replicar'])->middleware('perm:gerenciar_enderecos');
        Route::delete('/locations/{location}/produtos/{stock}', [LocationController::class, 'removerProduto'])->middleware('perm:gerenciar_enderecos');

        // Estoque
        Route::post('/stock/entrada', [StockController::class, 'entrada'])->middleware('perm:movimentar');
        Route::post('/stock/baixa', [StockController::class, 'baixa'])->middleware('perm:movimentar');
        Route::post('/stock/import', [ImportController::class, 'importar'])->middleware('perm:importar');
        Route::post('/stock/zerar-tudo', [StockController::class, 'zerarTudo'])->middleware('perm:gerenciar_enderecos');

        // Movimentação entre endereços + relatório
        Route::get('/movements', [MovementController::class, 'index'])->middleware('perm:movimentar');
        Route::post('/movements', [MovementController::class, 'store'])->middleware('perm:movimentar');

        // Produtos / Microvix (usado pela solicitação)
        Route::get('/products/validar-microvix', [ProductController::class, 'validarMicrovix'])->middleware('perm:solicitar');
        Route::get('/products/{product}/locais', [ProductController::class, 'locais'])->middleware('perm:solicitar');

        // Microvix (consulta de produto + base de códigos de barras)
        Route::middleware('perm:gerenciar_enderecos')->group(function () {
            Route::get('/microvix/consultar', [MicrovixController::class, 'consultar']);
            Route::post('/microvix/sincronizar', [MicrovixController::class, 'sincronizar']);
            Route::get('/microvix/status', [MicrovixController::class, 'status']);
        });

        // Relatórios
        Route::get('/reports/produto-localizacao', [ReportController::class, 'produtoLocalizacao'])->middleware('perm:relatorios');

        // Contagem / Inventário
        Route::middleware('perm:contar')->group(function () {
            Route::get('/counts', [InventoryCountController::class, 'index']);
            Route::post('/counts', [InventoryCountController::class, 'store']);
            Route::get('/counts/{count}', [InventoryCountController::class, 'show']);
            Route::post('/counts/{count}/itens', [InventoryCountController::class, 'salvarItens']);
            Route::get('/counts/{count}/divergencias', [InventoryCountController::class, 'divergencias']);
            Route::post('/counts/{count}/finalizar', [InventoryCountController::class, 'finalizar']);
            Route::post('/counts/{count}/cancelar', [InventoryCountController::class, 'cancelar']);
        });

        // Solicitações de retirada
        // Solicitante: lê a nota/pedido e envia os itens marcados
        Route::post('/withdrawal-requests/extrair', [WithdrawalRequestController::class, 'extrair'])->middleware('perm:solicitar');
        Route::post('/withdrawal-requests', [WithdrawalRequestController::class, 'store'])->middleware('perm:solicitar');

        // Separador: fila e checklist
        Route::middleware('perm:separar')->group(function () {
            Route::get('/withdrawal-requests', [WithdrawalRequestController::class, 'index']);
            Route::get('/withdrawal-requests/{withdrawalRequest}', [WithdrawalRequestController::class, 'show']);
            Route::get('/withdrawal-requests/{withdrawalRequest}/documento', [WithdrawalRequestController::class, 'documento']);
            Route::post('/withdrawal-requests/{withdrawalRequest}/iniciar', [WithdrawalRequestController::class, 'iniciar']);
            Route::post('/withdrawal-requests/{withdrawalRequest}/pausar', [WithdrawalRequestController::class, 'pausar']);
            Route::put('/withdrawal-requests/{withdrawalRequest}/itens/{item}', [WithdrawalRequestController::class, 'atualizarItem']);
            Route::post('/withdrawal-requests/{withdrawalRequest}/finalizar', [WithdrawalRequestController::class, 'finalizar']);
        });

        // Admin: reabrir separação finalizada (com estorno do estoque)
        Route::post('/withdrawal-requests/{withdrawalRequest}/reabrir', [WithdrawalRequestController::class, 'reabrir'])->middleware('perm:admin');
    });

    // Administração de usuários (só admin)
    Route::middleware('perm:admin')->group(function () {
        Route::get('/permissoes', [UserController::class, 'permissoes']);
        Route::get('/depositos', [UserController::class, 'depositos']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});
