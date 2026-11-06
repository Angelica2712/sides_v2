<?php

namespace Tests\Feature;

use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesPedrenOperacion;
use Illuminate\Support\Carbon;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class InicioTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        // El almacén en vivo trae los camiones del andén (módulo Guías).
        $this->crearTablasRutas();
        $this->crearTablasGuias();
        Carbon::setTestNow('2026-11-04 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_promedia_aceptar_picking_y_packing_de_los_pedidos_que_terminaron_en_el_periodo(): void
    {
        $this->crearCfg();
        // Aceptar 10 min, picking 20 min, packing 5 min.
        $this->crearPedido(['id' => 1, 'estado' => 'PEND-FACTURA', 'fecrecibido' => '2026-11-04 08:00:00', 'fecpicking' => '2026-11-04 08:10:00', 'fecpacking' => '2026-11-04 08:30:00', 'feccompletado' => '2026-11-04 08:35:00']);
        // Aceptar 20 min, picking 40 min, packing 15 min.
        $this->crearPedido(['id' => 2, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-11-04 09:00:00', 'fecpicking' => '2026-11-04 09:20:00', 'fecpacking' => '2026-11-04 10:00:00', 'feccompletado' => '2026-11-04 10:15:00']);
        // Quedó abierto de un día para otro: no entra al promedio de aceptar.
        $this->crearPedido(['id' => 3, 'estado' => 'PICKING', 'fecrecibido' => '2026-11-01 08:00:00', 'fecpicking' => '2026-11-04 11:00:00']);
        // De otra sucursal y anulado: no cuentan.
        $this->crearPedido(['id' => 4, 'estado' => 'FACTURADO', 'codisb' => 'OTRA', 'fecrecibido' => '2026-11-04 08:00:00', 'fecpicking' => '2026-11-04 12:00:00']);
        $this->crearPedido(['id' => 5, 'estado' => 'ANULADO', 'fecrecibido' => '2026-11-04 08:00:00', 'fecpicking' => '2026-11-04 12:00:00']);

        $datos = $this->actingAs($this->supervisor())->getJson('/home/indicadores?periodo=hoy')->assertOk()->json();

        $this->assertSame(['segundos' => 900, 'pedidos' => 2, 'anterior' => null, 'referencia' => null], $datos['tiempos']['aceptar']);
        $this->assertSame(1800, $datos['tiempos']['picking']['segundos']);
        $this->assertSame(600, $datos['tiempos']['packing']['segundos']);
        $this->assertSame(3300, $datos['tiempos']['total']['segundos']);
        $this->assertSame(2, $datos['desempeno']['pedidos']);
        $this->assertSame(1, $datos['cola']['picking']);
    }

    public function test_compara_con_el_periodo_anterior_y_mide_el_desempeno(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-11-03 08:00:00', 'fecpicking' => '2026-11-03 08:30:00']);
        $this->crearPedido(['id' => 2, 'estado' => 'PEND-FACTURA', 'fecrecibido' => '2026-11-04 08:00:00', 'fecpicking' => '2026-11-04 08:15:00', 'feccompletado' => '2026-11-04 09:00:00']);
        $this->crearRenglon(2, 1, ['cantidad' => 8]);
        $this->crearRenglon(2, 2, ['cantidad' => 2]);
        SidesPedrenOperacion::query()->create(['id_pedido' => 2, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 8]);
        SidesPedrenOperacion::query()->create(['id_pedido' => 2, 'item' => 2, 'codisb' => '505094939', 'cantdesp' => 1]);
        SidesLogpicking::query()->create(['id_pedido' => 2, 'usuario' => 'jefe@example.com', 'numren' => 2, 'numund' => 9, 'tiempo_picking' => 1800, 'fecha_del_picking' => '2026-11-04 08:45:00', 'descripcion' => '']);

        $datos = $this->actingAs($this->supervisor())->getJson('/home/indicadores?periodo=hoy')->assertOk()->json();

        $this->assertSame(1800, $datos['tiempos']['aceptar']['anterior']);
        $this->assertEquals(90.0, $datos['desempeno']['servicio']);
        $this->assertEquals(4.0, $datos['desempeno']['renglonesHoraPicking']);
        $this->assertSame([['nombre' => 'Jefe', 'pedidos' => 1, 'renglones' => 2, 'unidades' => 9]], $datos['despachadores']);
    }

    public function test_sin_pedidos_en_el_periodo_da_el_promedio_del_ultimo_dia_con_pedidos(): void
    {
        $this->crearCfg();
        // Último día con actividad: 30/10, aceptar de 10 y 30 min.
        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-10-30 08:00:00', 'fecpicking' => '2026-10-30 08:10:00']);
        $this->crearPedido(['id' => 2, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-10-30 09:00:00', 'fecpicking' => '2026-10-30 09:30:00']);
        // Más nuevo pero de más de 24 horas: no sirve de referencia, se busca el anterior.
        $this->crearPedido(['id' => 4, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-10-28 08:00:00', 'fecpicking' => '2026-11-02 08:00:00']);
        // Un día antes no cuenta para la referencia.
        $this->crearPedido(['id' => 3, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-10-29 08:00:00', 'fecpicking' => '2026-10-29 10:00:00']);

        $datos = $this->actingAs($this->supervisor())->getJson('/home/indicadores?periodo=hoy')->assertOk()->json();

        $this->assertSame(0, $datos['tiempos']['aceptar']['pedidos']);
        $this->assertSame(['fecha' => '30/10/2026', 'segundos' => 1200, 'pedidos' => 2], $datos['tiempos']['aceptar']['referencia']);
        $this->assertNull($datos['tiempos']['picking']['referencia']);
    }

    public function test_el_inicio_muestra_los_indicadores_al_supervisor_y_no_al_operario(): void
    {
        $this->crearCfg();

        $this->actingAs($this->supervisor())->get('/home')->assertOk()
            ->assertSee('Tiempos promedio del almacén')
            ->assertSee('Almacén en vivo')
            ->assertSee('Desempeño del almacén');

        $operario = $this->crearUsuario(['email' => 'picker@example.com', 'activarPicking' => 1]);
        $this->actingAs($operario)->get('/home')->assertOk()->assertDontSee('Tiempos promedio del almacén');
        $this->actingAs($operario)->getJson('/home/indicadores')->assertForbidden();
    }

    public function test_los_indicadores_son_del_permiso_propio_y_no_del_monitor(): void
    {
        $this->crearCfg();

        $supervisorTurno = $this->crearUsuario(['email' => 'turno@example.com', 'activarMonitor' => 1, 'activarResumen' => 1, 'activarInformes' => 1, 'activarConfig' => 1]);
        $this->actingAs($supervisorTurno)->get('/home')->assertOk()->assertDontSee('Tiempos promedio del almacén');
        $this->actingAs($supervisorTurno)->getJson('/home/indicadores')->assertForbidden();
    }

    public function test_el_jefe_con_solo_indicadores_se_queda_en_el_inicio_con_el_almacen_en_vivo(): void
    {
        $this->crearCfg();
        $jefe = $this->crearUsuario(['email' => 'jefe.ops@example.com', 'activarIndicadores' => 1]);

        $this->actingAs($jefe)->get('/home')->assertOk()
            ->assertSee('Tiempos promedio del almacén')
            ->assertSee('Pantalla completa');
        $this->actingAs($jefe)->getJson('/home/indicadores')->assertOk();
        $this->actingAs($jefe)->getJson('/monitor/picking/datos')->assertOk();
        // Ver no es operar: no entra al Monitor ni al picking.
        $this->actingAs($jefe)->get('/monitor')->assertForbidden();
        $this->actingAs($jefe)->get('/picking')->assertForbidden();
    }

    private function supervisor()
    {
        return $this->crearUsuario(['name' => 'Jefe', 'email' => 'jefe@example.com', ...$this->todosLosPermisos()]);
    }
}
