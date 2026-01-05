<?php

declare(strict_types=1);

use Famiq\ActiveDirectoryUser\Http\Controllers\RedmineBridge\RedmineBridgeController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/redmine')->group(function (): void {
    Route::post('clientes/buscar', [RedmineBridgeController::class, 'buscarCliente']);
    Route::post('clientes', [RedmineBridgeController::class, 'upsertCliente']);
    Route::post('tickets', [RedmineBridgeController::class, 'crearTicket']);
    Route::get('tickets', [RedmineBridgeController::class, 'listarTickets']);
    Route::post('tickets/{id}/mensajes', [RedmineBridgeController::class, 'crearMensaje']);
    Route::post('tickets/{id}/adjuntos', [RedmineBridgeController::class, 'crearAdjunto']);
});
