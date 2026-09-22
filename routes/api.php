<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InventoryCountController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\ProductController;
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

    // Mapa / endereços
    Route::get('/locations', [LocationController::class, 'index'])->middleware('perm:ver_mapa');
    Route::get('/locations/{location}', [LocationController::class, 'show'])->middleware('perm:ver_mapa');
    Route::post('/locations', [LocationController::class, 'store'])->middleware('perm:gerenciar_enderecos');
    Route::post('/locations/{location}/zerar', [LocationController::class, 'zerar'])->middleware('perm:gerenciar_enderecos');

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
    Route::post('/withdrawal-requests', [WithdrawalRequestController::class, 'store'])->middleware('perm:solicitar');
    Route::get('/withdrawal-requests', [WithdrawalRequestController::class, 'index'])->middleware('perm:separar');
    Route::get('/withdrawal-requests/{withdrawalRequest}', [WithdrawalRequestController::class, 'show'])->middleware('perm:separar');
    Route::post('/withdrawal-requests/{withdrawalRequest}/iniciar', [WithdrawalRequestController::class, 'iniciar'])->middleware('perm:separar');
    Route::post('/withdrawal-requests/{withdrawalRequest}/confirmar', [WithdrawalRequestController::class, 'confirmar'])->middleware('perm:separar');

    // Administração de usuários (só admin)
    Route::middleware('perm:admin')->group(function () {
        Route::get('/permissoes', [UserController::class, 'permissoes']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});
