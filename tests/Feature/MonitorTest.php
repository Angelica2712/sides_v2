<?php

namespace Tests\Feature;

use App\Events\MonitorActualizado;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesMonitor;
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

    public function test_la_paginacion_del_fragmento_apunta_a_la_pantalla_del_monitor(): void
    {
        $this->crearCfg();
        foreach (range(1, 101) as $n) {
            $this->crearPedido(['id' => 92000 + $n, 'estado' => 'RECIBIDO']);
        }

        // Si los enlaces llevaran a monitor/contenido, la página 2 abriría el fragmento sin estilos.
        $this->actingAs($this->operador())->get('/monitor/contenido')
            ->assertOk()
            ->assertViewHas('pedidos', fn ($pedidos) => $pedidos->url(2) === route('monitor.index').'?page=2')
            ->assertDontSee('monitor/contenido?', false);
    }

    public function test_contenido_pide_el_mismo_permiso_que_el_monitor(): void
    {
        $this->crearCfg();

        $this->actingAs($this->operador(['activarPicking' => 1]))->get('/monitor/contenido')->assertForbidden();
    }

    public function test_solo_quien_administra_la_drogueria_cambia_la_letra_de_la_tabla_desde_el_monitor(): void
    {
        $this->crearCfg(['TamLetraMonitor' => 18]);
        $this->crearPedido(['id' => 91001, 'estado' => 'RECIBIDO']);
        Event::fake([MonitorActualizado::class]);
        $letra = fn () => (int) SidesCfg::query()->find('505094939')->TamLetraMonitor;

        // El operario ve la tabla con la letra de la droguería, sin los botones, y no puede cambiarla.
        $this->actingAs($this->operador())->get('/monitor')
            ->assertSee('font-size: 18px', false)
            ->assertDontSee('Tamaño de la letra de la tabla');
        $this->putJson('/monitor/letra', ['letra' => 24])->assertForbidden();
        $this->assertSame(18, $letra());

        // El encargado (permiso de Configuración) la cambia para todas las pantallas.
        $encargado = $this->operador(['activarMonitor' => 1, 'activarConfig' => 1, 'email' => 'encargado@example.com']);
        $this->actingAs($encargado)->get('/monitor')->assertSee('Tamaño de la letra de la tabla');
        $this->putJson('/monitor/letra', ['letra' => 24])->assertOk()->assertExactJson(['letra' => 24]);
        $this->assertSame(24, $letra());
        Event::assertDispatched(MonitorActualizado::class, fn (MonitorActualizado $evento) => $evento->motivo === 'monitor.letra');
        $this->actingAs($this->operador(['activarMonitor' => 1, 'email' => 'otro@example.com']))->get('/monitor/contenido')->assertSee('font-size: 24px', false);

        // Fuera del rango o de los pasos de 2 no se guarda.
        $this->actingAs($encargado)->putJson('/monitor/letra', ['letra' => 60])->assertUnprocessable();
        $this->putJson('/monitor/letra', ['letra' => 25])->assertUnprocessable();
        $this->assertSame(24, $letra());
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

    private function pedidosConRutas(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 1, 'estado' => 'RECIBIDO', 'ruta' => 'Caracas Este', 'nomcli' => 'CLIENTE CARACAS']);
        $this->crearPedido(['id' => 2, 'estado' => 'PICKING', 'ruta' => 'LOS TEQUES', 'nomcli' => 'CLIENTE TEQUES']);
        $this->crearPedido(['id' => 3, 'estado' => 'PACKING', 'ruta' => 'CIUDAD GUAYANA', 'nomcli' => 'CLIENTE GUAYANA']);
        $this->crearPedido(['id' => 4, 'estado' => 'RECIBIDO', 'ruta' => null, 'nomcli' => 'CLIENTE SIN RUTA']);
        $this->crearPedido(['id' => 5, 'estado' => 'FACTURADO', 'ruta' => 'CARACAS', 'nomcli' => 'CLIENTE FACTURADO']);
    }

    public function test_sin_filtros_no_muestra_pestanas(): void
    {
        $this->pedidosConRutas();

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertViewHas('pestanas', [])
            ->assertDontSee('Filtros del monitor');
    }

    public function test_pestanas_con_conteo_y_filtro_por_fragmento_de_ruta(): void
    {
        $this->pedidosConRutas();
        $centro = SidesMonitor::query()->create(['descrip' => 'Centro', 'criterio' => 'caracas, TEQUES', 'codisb' => '505094939']);
        SidesMonitor::query()->create(['descrip' => 'Oriente', 'criterio' => 'GUAYANA', 'codisb' => '505094939']);
        SidesMonitor::query()->create(['descrip' => 'De otra sucursal', 'criterio' => 'CARACAS', 'codisb' => '999999999']);

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertViewHas('pestanas', fn (array $pestanas) => array_column($pestanas, 'total', 'nombre') === [
                'Todos' => 4, 'Centro' => 2, 'Oriente' => 1,
            ])
            ->assertDontSee('De otra sucursal');

        $this->get("/monitor?filtro={$centro->id}")
            ->assertOk()
            ->assertViewHas('filtro', fn ($filtro) => $filtro->is($centro))
            ->assertViewHas('pedidos', fn ($pedidos) => $pedidos->pluck('id')->sort()->values()->all() === [1, 2])
            ->assertDontSee('CLIENTE GUAYANA')
            ->assertDontSee('CLIENTE SIN RUTA');

        // El fragmento que refresca en vivo respeta el filtro elegido.
        $this->get("/monitor/contenido?filtro={$centro->id}")
            ->assertOk()
            ->assertSee('CLIENTE TEQUES')
            ->assertDontSee('CLIENTE GUAYANA');
    }

    public function test_filtro_de_otra_sucursal_o_inexistente_muestra_todos(): void
    {
        $this->pedidosConRutas();
        $ajeno = SidesMonitor::query()->create(['descrip' => 'Ajeno', 'criterio' => 'GUAYANA', 'codisb' => '999999999']);

        $this->actingAs($this->operador());

        foreach ([$ajeno->id, 9999, 'abc'] as $valor) {
            $this->get("/monitor?filtro={$valor}")
                ->assertOk()
                ->assertViewHas('filtro', null)
                ->assertViewHas('pedidos', fn ($pedidos) => $pedidos->count() === 4);
        }
    }

    public function test_filtro_sin_pedidos_explica_el_criterio(): void
    {
        $this->pedidosConRutas();
        $filtro = SidesMonitor::query()->create(['descrip' => 'Occidente', 'criterio' => 'MARACAIBO', 'codisb' => '505094939']);

        $this->actingAs($this->operador())->get("/monitor?filtro={$filtro->id}")
            ->assertOk()
            ->assertSee('No hay pedidos en proceso en «Occidente»')
            ->assertSee('MARACAIBO');
    }

    public function test_marca_los_pedidos_que_caen_en_un_filtro(): void
    {
        $this->pedidosConRutas();
        SidesMonitor::query()->create(['descrip' => 'Centro', 'criterio' => 'CARACAS', 'caracterLogo' => 'CTR', 'codisb' => '505094939']);
        SidesMonitor::query()->create(['descrip' => 'Oriente', 'criterio' => 'GUAYANA', 'caracterLogo' => 'N/A', 'codisb' => '505094939']);

        $this->actingAs($this->operador())->get('/monitor')
            ->assertOk()
            ->assertViewHas('marcas', fn (array $marcas) => $marcas[1] === [['texto' => 'CTR', 'filtro' => 'Centro']]
                && $marcas[2] === [] && $marcas[3] === [] && $marcas[4] === [])
            ->assertSee('title="Filtro Centro"', false);
    }
}
