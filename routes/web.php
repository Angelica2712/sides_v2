<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BatchPickingController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\DespachoController;
use App\Http\Controllers\EtiquetasController;
use App\Http\Controllers\GuiasController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ModuloPendienteController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\PedidosController;
use App\Http\Controllers\PickingController;
use App\Http\Controllers\RutasController;
use App\Http\Controllers\UsuariosController;
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

    Route::prefix('pedidos')->name('pedidos.')->middleware('permiso:pedidos')
        ->controller(PedidosController::class)->whereNumber('pedido')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{pedido}', 'show')->name('show');
            Route::get('{pedido}/modificar', 'edit')->name('edit');
            Route::put('{pedido}', 'update')->name('update');
            Route::post('{pedido}/resetear', 'resetear')->name('resetear');
            Route::post('{pedido}/anular', 'anular')->name('anular');
        });

    Route::prefix('configuracion')->name('configuracion.')->middleware('permiso:configuracion')
        ->controller(ConfiguracionController::class)
        ->group(function () {
            Route::get('/', 'edit')->name('index');
            Route::put('/', 'update')->name('update');
        });

    Route::prefix('usuarios')->name('usuarios.')->middleware('permiso:usuarios')
        ->controller(UsuariosController::class)->whereNumber('usuario')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('nuevo', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{usuario}', 'edit')->name('edit');
            Route::put('{usuario}', 'update')->name('update');
            Route::put('{usuario}/clave', 'clave')->middleware('throttle:20,1')->name('clave');
            Route::delete('{usuario}', 'destroy')->name('destroy');
        });

    Route::prefix('rutas')->name('rutas.')->middleware('permiso:rutas')
        ->controller(RutasController::class)->whereNumber(['ruta', 'item'])
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('excel', 'importar')->name('importar');
            Route::post('seped', 'desdeSeped')->name('seped');
            Route::post('sincronizar', 'sincronizar')->name('sincronizar');
            Route::get('{ruta}', 'show')->name('show');
            Route::put('{ruta}', 'update')->name('update');
            Route::delete('{ruta}', 'destroy')->name('destroy');
            Route::put('{ruta}/zona', 'zona')->name('zona');
            Route::get('{ruta}/excel', 'descargar')->name('descargar');
            Route::get('{ruta}/clientes/agregar', 'agregar')->name('agregar');
            Route::post('{ruta}/clientes', 'agregarClientes')->name('clientes.store');
            Route::put('{ruta}/clientes/{item}', 'actualizarCliente')->name('clientes.update');
            Route::delete('{ruta}/clientes/{item}', 'quitarCliente')->name('clientes.destroy');
        });

    Route::prefix('guias')->name('guias.')->middleware('permiso:guias')
        ->controller(GuiasController::class)->whereNumber(['guia', 'pedido'])
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('nueva', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('{guia}', 'show')->name('show');
            Route::get('{guia}/modificar', 'edit')->name('edit');
            Route::put('{guia}', 'update')->name('update');
            Route::delete('{guia}', 'destroy')->name('destroy');
            Route::post('{guia}/separar', 'separar')->name('separar');
            Route::post('{guia}/pedidos', 'agregarPedidos')->name('pedidos.store');
            Route::delete('{guia}/pedidos/{pedido}', 'quitarPedido')->name('pedidos.destroy');
            Route::delete('{guia}/clientes', 'quitarCliente')->name('clientes.destroy');
            Route::post('{guia}/entregar', 'entregar')->name('entregar');
            Route::post('{guia}/reiniciar', 'reiniciar')->name('reiniciar');
            Route::get('{guia}/imprimir', 'imprimir')->name('imprimir');
            Route::get('{guia}/excel', 'excel')->name('excel');
        });

    Route::prefix('despacho')->name('despacho.')->middleware('permiso:despacho')
        ->controller(DespachoController::class)->whereNumber('guia')->whereIn('fase', ['carga', 'descarga'])
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{guia}/{fase}', 'show')->name('show');
            Route::post('{guia}/carga', 'cargar')->name('cargar');
            Route::post('{guia}/descarga', 'entregar')->name('entregar');
        });

    // Módulos aún no portados: al portar uno, se registra arriba con su controlador real
    // (mismo nombre "{clave}.index" y middleware permiso:{clave}) y se agrega a $portados.
    $portados = ['monitor', 'picking', 'packing', 'batch', 'admin', 'etiquetas', 'pedidos', 'configuracion', 'usuarios', 'rutas', 'guias', 'despacho'];
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
