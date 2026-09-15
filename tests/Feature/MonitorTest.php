<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
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

    public function test_sin_permiso_de_monitor_no_entra(): void
    {
        $this->crearCfg();

        $this->actingAs($this->operador(['activarPicking' => 1]))->get('/monitor')->assertForbidden();
    }
}
