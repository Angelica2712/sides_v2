<?php

use App\Http\Controllers\MonitorTvApiController;
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

/*
 * API de los televisores de la droguería (la app de TV del compañero, otra aplicación), con el
 * contrato que tenía en el SIDES de mastranto. La TV se vincula con un código de 6 caracteres que
 * se aprueba en SIDES → Televisores; después entra con `Authorization: Bearer {token}`.
 * Ver App\Http\Controllers\MonitorTvApiController.
 */
Route::controller(MonitorTvApiController::class)->name('monitorTv.')->group(function () {
    Route::post('/monitor_solicitar_codigo', 'solicitarCodigo')->name('solicitarCodigo');
    Route::post('/monitor_estado', 'estado')->middleware('throttle:60,1')->name('estado');

    Route::middleware(['monitor.tv', 'throttle:monitor-tv'])->group(function () {
        Route::get('/monitor', 'monitor')->name('monitor');
        Route::get('/monitor_config', 'config')->name('config');
        Route::get('/monitor_alertas', 'alertas')->name('alertas');
        Route::get('/monitor_picking', 'picking')->name('picking');
        Route::get('/monitor_plano', 'plano')->name('plano');
        Route::post('/monitor_canal', 'canal')->name('canal');
    });
});
