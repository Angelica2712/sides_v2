<?php

use App\Http\Controllers\SiadApiController;
use Illuminate\Support\Facades\Route;

/*
 * API de pedidos para el SIAD, con el contrato del ApiController del SIDES legacy: los SIAD ya
 * instalados solo cambian su dominioapi. Dos entradas a las mismas cuatro rutas:
 * - /api/siad/{token}/...  con el token de config/siad.php (producción).
 * - /api/...               sin token, solo con SIAD_API_ABIERTA=true (pruebas).
 */
$rutasSiad = function (string $nombre) {
    Route::get('/get_pedido', [SiadApiController::class, 'getPedido'])->name("{$nombre}.getPedido");
    Route::get('/get_pedido_recibido', [SiadApiController::class, 'getPedidoRecibido'])->name("{$nombre}.getPedidoRecibido");
    Route::get('/get_pedido_facturando', [SiadApiController::class, 'getPedidoFacturando'])->name("{$nombre}.getPedidoFacturando");
    Route::post('/upd_pedido', [SiadApiController::class, 'updPedido'])->name("{$nombre}.updPedido");
};

Route::middleware('siad.token')->prefix('siad/{token}')->group(fn () => $rutasSiad('siad'));
Route::middleware('siad.abierta')->group(fn () => $rutasSiad('siadAbierta'));
