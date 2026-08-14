<?php

use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InventoryCountController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\WithdrawalRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas da API — Sistema de Endereçamento de Estoque
|--------------------------------------------------------------------------
|
| No Laravel 11 este arquivo só é carregado se a aplicação tiver o roteamento
| de API habilitado. Caso ainda não esteja, rode:  php artisan install:api
| (isso registra o prefixo /api e o middleware correspondente em bootstrap/app.php).
|
*/

// Mapa / endereços
Route::get('/locations', [LocationController::class, 'index']);
Route::get('/locations/{location}', [LocationController::class, 'show']);

// Movimentação de estoque
Route::post('/stock/entrada', [StockController::class, 'entrada']);
Route::post('/stock/baixa', [StockController::class, 'baixa']);
Route::post('/stock/import', [ImportController::class, 'importar']); // upload de CSV

// Produtos / integração Microvix
Route::get('/products/validar-microvix', [ProductController::class, 'validarMicrovix']);
Route::get('/products/{product}/locais', [ProductController::class, 'locais']);

// Contagem / Inventário (balanço)
Route::get('/counts', [InventoryCountController::class, 'index']);
Route::post('/counts', [InventoryCountController::class, 'store']);
Route::get('/counts/{count}', [InventoryCountController::class, 'show']);
Route::post('/counts/{count}/itens', [InventoryCountController::class, 'salvarItens']);
Route::get('/counts/{count}/divergencias', [InventoryCountController::class, 'divergencias']);
Route::post('/counts/{count}/finalizar', [InventoryCountController::class, 'finalizar']);
Route::post('/counts/{count}/cancelar', [InventoryCountController::class, 'cancelar']);

// Solicitações de retirada
Route::get('/withdrawal-requests', [WithdrawalRequestController::class, 'index']);
Route::get('/withdrawal-requests/{withdrawalRequest}', [WithdrawalRequestController::class, 'show']);
Route::post('/withdrawal-requests', [WithdrawalRequestController::class, 'store']); // com upload da Nota
Route::post('/withdrawal-requests/{withdrawalRequest}/iniciar', [WithdrawalRequestController::class, 'iniciar']);
Route::post('/withdrawal-requests/{withdrawalRequest}/confirmar', [WithdrawalRequestController::class, 'confirmar']);
