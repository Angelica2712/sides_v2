<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BatchPickingController;
use App\Http\Controllers\EtiquetasController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ModuloPendienteController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PickingController;
use App\Support\MenuSides;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'home' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('home', HomeController::class)->name('home');

    Route::get('monitor', MonitorController::class)->name('monitor.index')->middleware('permiso:monitor');

    Route::prefix('picking')->name('picking.')->middleware('permiso:picking')
        ->controller(PickingController::class)->whereNumber('pedido')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{pedido}', 'show')->name('show');
            Route::post('{pedido}/tomar', 'tomar')->name('tomar');
            Route::post('{pedido}/cantidad', 'cantidad')->name('cantidad');
            Route::post('{pedido}/alerta', 'alerta')->name('alerta');
            Route::post('{pedido}/terminar', 'terminar')->name('terminar');
            Route::post('{pedido}/liberar', 'liberar')->name('liberar');
        });

    Route::prefix('admin')->name('admin.')->middleware('permiso:admin')
        ->controller(AdminController::class)
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('droguerias/nueva', 'create')->name('create');
            Route::post('droguerias', 'store')->name('store');
            Route::get('droguerias/{codisb}', 'edit')->name('edit');
            Route::put('droguerias/{codisb}', 'update')->name('update');
        });

    Route::prefix('batch-picking')->name('batch.')->middleware('permiso:batch')
        ->controller(BatchPickingController::class)->whereNumber('lote')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('agrupar', 'agrupar')->name('agrupar');
            Route::post('liberar', 'liberar')->name('liberar');
            Route::get('lotes/{lote}', 'show')->name('show');
            Route::post('lotes/{lote}/iniciar', 'iniciar')->name('iniciar');
            Route::post('lotes/{lote}/anular', 'anular')->name('anular');
            Route::get('lotes/{lote}/picking', 'picking')->name('picking');
            Route::post('lotes/{lote}/cantidad', 'cantidad')->name('cantidad');
            Route::post('lotes/{lote}/terminar', 'terminar')->name('terminar');
        });

    Route::prefix('packing')->name('packing.')->middleware('permiso:packing')
        ->controller(PackingController::class)->whereNumber('pedido')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{pedido}', 'show')->name('show');
            Route::post('{pedido}/escanear', 'escanear')->name('escanear');
            Route::post('{pedido}/ajustar', 'ajustar')->middleware('throttle:20,1')->name('ajustar');
            Route::post('{pedido}/clave', 'clave')->middleware('throttle:10,1')->name('clave');
            Route::post('{pedido}/lote', 'lote')->name('lote');
            Route::post('{pedido}/terminar', 'terminar')->name('terminar');
            Route::post('{pedido}/liberar', 'liberar')->name('liberar');
        });

    Route::prefix('etiquetas')->name('etiquetas.')->middleware('permiso:etiquetas')
        ->controller(EtiquetasController::class)->whereNumber('pedido')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('{pedido}/generar', 'generar')->name('generar');
            Route::get('{pedido}/imprimir', 'imprimir')->name('imprimir');
            Route::get('{pedido}/ticket', 'ticket')->name('ticket');
        });

    // Módulos aún no portados: al portar uno, se registra arriba con su controlador real
    // (mismo nombre "{clave}.index" y middleware permiso:{clave}) y se agrega a $portados.
    $portados = ['monitor', 'picking', 'packing', 'batch', 'admin', 'etiquetas'];
    foreach (MenuSides::MODULOS as $clave => [, $uri]) {
        if (in_array($clave, $portados, true)) {
            continue;
        }

        Route::get($uri, ModuloPendienteController::class)
            ->name("{$clave}.index")
            ->middleware("permiso:{$clave}")
            ->defaults('clave', $clave);
    }
});
