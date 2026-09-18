<?php

namespace Tests\Feature;

use App\Events\MonitorActualizado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function operador(array $permisos = ['activarMonitor' => 1])
    {
        return $this->crearUsuario($permisos);
    }

    public function test_muestra_solo_pedidos_en_proceso_de_la_sucursal_en_orden_de_envio(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 90003, 'estado' => 'PACKING', 'nomcli' => 'CLIENTE PACKING', 'fecenviado' => '2026-09-15 07:00:00', 'fecpicking' => '2026-09-15 08:40:00', 'fecpacking' => '2026-09-15 09:30:00']);
        $this->crearPedido(['id' => 90001, 'estado' => 'RECIBIDO', 'nomcli' => 'CLIENTE RECIBIDO', 'fecenviado' => '2026-09-15 09:00:00']);
        $this->crearPedido(['id' => 90002, 'estado' => 'PICKING', 'nomcli' => 'CLIENTE PICKING', 'fecenviado' => '2026-09-15 08:00:00', 'fecpicking' => '2026-09-15 08:40:00']);
        $this->crearPedido(['id' => 90004, 'estado' => 'FACTURADO', 'nomcli' => 'CLIENTE FACTURADO']);
        $this->crearPedido(['id' => 90005, 'estado' => 'POR-APROBAR', 'nomcli' => 'CLIENTE EN ALCABALA']);
        $this->crearPedido(['id' => 90006, 'estado' => 'RECIBIDO', 'nomcli' => 'CLIENTE OTRA SUCURSAL', 'codisb' => '999999999']);

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertViewHas('pedidos', fn ($pedidos) => $pedidos->pluck('id')->all() === [90003, 90002, 90001])
            ->assertViewHas('columnas', fn (array $columnas) => array_map(fn ($lista) => $lista->pluck('id')->all(), $columnas) === [
                'RECIBIDO' => [90001],
                'PICKING' => [90002],
                'PACKING' => [90003],
            ])
            ->assertDontSee('CLIENTE FACTURADO')
            ->assertDontSee('CLIENTE EN ALCABALA')
            ->assertDontSee('CLIENTE OTRA SUCURSAL');
    }

    public function test_indicadores_y_tiempos(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 1, 'estado' => 'RECIBIDO', 'fecprocesado' => '2026-09-15 09:15:00']);
        $this->crearPedido(['id' => 2, 'estado' => 'PICKING', 'fecprocesado' => '2026-09-15 09:00:00', 'fecpicking' => '2026-09-15 09:20:00']);
        $this->crearPedido(['id' => 3, 'estado' => 'POR-APROBAR']);
        $this->crearPedido(['id' => 4, 'estado' => 'FACTURADO', 'fecfacturado' => '2026-09-15 07:00:00']);
        $this->crearPedido(['id' => 5, 'estado' => 'FACTURADO', 'fecfacturado' => '2026-09-14 18:00:00']);

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertViewHas('indicadores', fn (array $indicadores) => array_column($indicadores, 'valor', 'etiqueta') === [
                'Alcabala' => 1,
                'Recibidos' => 1,
                'Picking' => 1,
                'Packing' => 0,
                'Facturados hoy' => 1,
            ])
            ->assertViewHas('tiempos', fn (array $tiempos) => $tiempos[1] === ['espera' => 45, 'picking' => 0, 'packing' => 0]
                && $tiempos[2] === ['espera' => 20, 'picking' => 40, 'packing' => 0]);
    }

    public function test_columnas_opcionales_segun_configuracion(): void
    {
        $this->crearCfg([
            'activarPacking' => 0,
            'activarVerOperadorMonitor' => 1,
            'mostrarObsMonitor' => 0,
            'mostrarTranMonitor' => 1,
        ]);
        $this->crearPedido(['id' => 7, 'estado' => 'PICKING', 'fecpicking' => '2026-09-15 09:00:00', 'codtransp' => 'MOTO-04'], [
            'despachador' => 'JUAN PEREZ',
            'recipiente' => 'CESTA 12',
        ]);

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertSee('Despachador')
            ->assertSee('JUAN PEREZ')
            ->assertSee('CESTA 12')
            ->assertSee('Transporte')
            ->assertSee('MOTO-04')
            ->assertDontSee('Observación')
            ->assertDontSee('Packing (min)')
            ->assertDontSee('En packing')
            ->assertViewHas('columnas', fn (array $columnas) => array_keys($columnas) === ['RECIBIDO', 'PICKING']);
    }

    public function test_tablero_muestra_tiempo_legible_y_semaforo(): void
    {
        $this->crearCfg();
        // Procesado 08:30 y ahora son las 10:00: 90 minutos esperando picking.
        $this->crearPedido(['id' => 8, 'estado' => 'RECIBIDO', 'fecprocesado' => '2026-09-15 08:30:00']);

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertSee('Recibidos')
            ->assertSee('#8')
            ->assertSee('1 h 30 min')
            ->assertSee('Demorado');
    }

    public function test_sin_pedidos_muestra_estado_vacio(): void
    {
        $this->crearCfg();

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertSee('No hay pedidos en proceso');
    }

    public function test_no_se_refresca_por_tiempo_sino_por_el_canal_de_la_sucursal(): void
    {
        $this->crearCfg();

        $respuesta = $this->actingAs($this->operador())->get('/monitor')->assertOk();

        // Escucha su canal en vez de recargarse sola.
        $respuesta->assertSee('sides-monitor.505094939')
            ->assertSee('monitorEnVivo')
            ->assertSee('En vivo');

        // Nada del refresco por tiempo que esto reemplaza.
        $respuesta->assertDontSee('location.reload')
            ->assertDontSee('setInterval')
            ->assertDontSee('Pausar');
    }

    public function test_contenido_devuelve_solo_el_fragmento_del_monitor(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 91001, 'estado' => 'RECIBIDO', 'nomcli' => 'CLIENTE RECIBIDO']);

        $respuesta = $this->actingAs($this->operador())->get('/monitor/contenido')->assertOk();

        // Trae los pedidos...
        $respuesta->assertSee('CLIENTE RECIBIDO')
            ->assertSee('#91001');

        // ...pero no la página entera: ni layout, ni barra de herramientas.
        $respuesta->assertDontSee('<body', false)
            ->assertDontSee('Pantalla completa')
            ->assertDontSee('Pedidos en proceso');
    }

    public function test_contenido_pide_el_mismo_permiso_que_el_monitor(): void
    {
        $this->crearCfg();

        $this->actingAs($this->operador(['activarPicking' => 1]))->get('/monitor/contenido')->assertForbidden();
    }

    public function test_avisa_al_monitor_de_la_sucursal_cuando_un_operario_toma_un_pedido(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 91002, 'estado' => 'RECIBIDO']);
        $this->crearRenglon(91002, 1);

        Event::fake([MonitorActualizado::class]);

        $this->actingAs($this->operador(['activarPicking' => 1]))->post('/picking/91002/tomar', ['recipiente' => 'CESTA 5']);

        Event::assertDispatched(
            MonitorActualizado::class,
            fn (MonitorActualizado $evento) => $evento->codisb === '505094939'
                && $evento->motivo === 'picking.tomar'
                && (int) $evento->pedidoId === 91002
                && $evento->broadcastOn()[0]->name === 'private-sides-monitor.505094939'
                && $evento->broadcastAs() === 'actualizado'
        );
    }

    public function test_no_avisa_si_la_accion_falla(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 91003, 'estado' => 'PACKING']);

        Event::fake([MonitorActualizado::class]);

        $this->actingAs($this->operador(['activarPicking' => 1]))->post('/picking/91003/tomar', ['recipiente' => 'CESTA 5']);

        Event::assertNotDispatched(MonitorActualizado::class);
    }

    public function test_al_canal_de_una_sucursal_solo_entra_quien_ve_su_monitor(): void
    {
        $this->crearCfg();
        $this->crearCfg(['codisb' => '999999999', 'nombre' => 'OTRA DROGUERIA']);

        // El broadcaster "null" que usan las pruebas autoriza cualquier canal sin consultar
        // routes/channels.php, así que acá se levanta el de verdad. Broadcast::channel registra
        // en el driver activo al momento de llamarlo, por eso el archivo se vuelve a cargar
        // después de cambiar la configuración.
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'clave-de-prueba',
            'secret' => 'secreto-de-prueba',
            'app_id' => '1',
            'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ]]);
        require base_path('routes/channels.php');

        $autorizar = fn ($usuario, string $canal) => $this->actingAs($usuario)
            ->post('/broadcasting/auth', ['channel_name' => $canal, 'socket_id' => '1234.5678']);

        $autorizar($this->operador(), 'private-sides-monitor.505094939')->assertOk();

        $autorizar($this->operador(['email' => 'sinmonitor@example.com', 'activarPicking' => 1]), 'private-sides-monitor.505094939')
            ->assertForbidden();

        $autorizar($this->operador(['email' => 'otra@example.com']), 'private-sides-monitor.999999999')
            ->assertForbidden();
    }

    public function test_sin_permiso_de_monitor_no_entra(): void
    {
        $this->crearCfg();

        $this->actingAs($this->operador(['activarPicking' => 1]))->get('/monitor')->assertForbidden();
    }
}
