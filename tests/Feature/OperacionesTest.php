<?php

namespace Tests\Feature;

use App\Models\Sides\SidesPedrenOperacion;
use Illuminate\Support\Carbon;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class OperacionesTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-11-04 15:00:00');
        $this->crearCfg();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tiempos_por_dia_y_por_hora_segun_cuando_llego_el_pedido(): void
    {
        // Llegan a las 8: aceptar 10 y 30 min (promedio 20). Llega a las 10: aceptar 5 min.
        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-11-03 08:00:00', 'fecpicking' => '2026-11-03 08:10:00', 'fecpacking' => '2026-11-03 08:40:00', 'feccompletado' => '2026-11-03 08:50:00']);
        $this->crearPedido(['id' => 2, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-11-04 08:15:00', 'fecpicking' => '2026-11-04 08:45:00']);
        $this->crearPedido(['id' => 3, 'estado' => 'PICKING', 'fecrecibido' => '2026-11-04 10:00:00', 'fecpicking' => '2026-11-04 10:05:00']);
        $this->crearPedido(['id' => 4, 'estado' => 'ANULADO', 'fecrecibido' => '2026-11-04 08:00:00', 'fecpicking' => '2026-11-04 12:00:00']);

        $vista = $this->actingAs($this->jefe())->get('/reportes/tiempos?desde=2026-11-03&hasta=2026-11-04')->assertOk();
        $datos = $vista->viewData('datos');

        $this->assertSame(3, $datos['totales']['pedidos']);
        $this->assertSame(['08:00', '10:00'], array_column($datos['horas'], 'etiqueta'));
        $this->assertSame(1200, $datos['horas'][0]['tiempos']['aceptar']);
        $this->assertSame(300, $datos['horas'][1]['tiempos']['aceptar']);
        $this->assertSame(['2026-11-03', '2026-11-04'], array_column($datos['dias'], 'etiqueta'));
        $this->assertSame(1800, $datos['dias'][0]['tiempos']['picking']);
        $this->assertSame(3000, $datos['dias'][0]['tiempos']['total']);
        $vista->assertSee('20 min');

        $this->actingAs($this->jefe())->get('/reportes/tiempos/excel?desde=2026-11-03&hasta=2026-11-04')->assertOk()->assertDownload('reporte_tiempos_2026-11-03_2026-11-04.xlsx');
    }

    public function test_clientes_y_rutas_con_su_nivel_de_servicio(): void
    {
        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO', 'codcli' => 'C1', 'nomcli' => 'FARMACIA UNO', 'ruta' => 'NORTE', 'fecrecibido' => '2026-11-04 08:00:00', 'feccompletado' => '2026-11-04 09:00:00']);
        $this->crearPedido(['id' => 2, 'estado' => 'PEND-FACTURA', 'codcli' => 'C1', 'nomcli' => 'FARMACIA UNO', 'ruta' => 'NORTE', 'fecrecibido' => '2026-11-04 10:00:00', 'feccompletado' => '2026-11-04 10:30:00']);
        $this->crearPedido(['id' => 3, 'estado' => 'FACTURADO', 'codcli' => 'C2', 'nomcli' => 'FARMACIA DOS', 'ruta' => 'NORTE', 'fecrecibido' => '2026-11-04 08:00:00']);
        // Sin despacho cerrado: no cuenta.
        $this->crearPedido(['id' => 4, 'estado' => 'PICKING', 'codcli' => 'C2', 'nomcli' => 'FARMACIA DOS', 'ruta' => 'NORTE', 'fecrecibido' => '2026-11-04 08:00:00']);
        $this->crearRenglon(1, 1, ['cantidad' => 10]);
        $this->crearRenglon(1, 2, ['cantidad' => 10]);
        SidesPedrenOperacion::query()->create(['id_pedido' => 1, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 10]);
        SidesPedrenOperacion::query()->create(['id_pedido' => 1, 'item' => 2, 'codisb' => '505094939', 'cantdesp' => 5]);
        $this->crearRenglon(2, 1, ['cantidad' => 5, 'cantdesp' => 5]);
        $this->crearRenglon(3, 1, ['cantidad' => 4, 'cantdesp' => 4]);

        $filas = $this->actingAs($this->jefe())->get('/reportes/clientes?desde=2026-11-04&hasta=2026-11-04')->assertOk()
            ->assertSee('FARMACIA UNO')->viewData('filas')->items();

        $this->assertSame('C1', $filas[0]['clave']);
        $this->assertSame(2, $filas[0]['pedidos']);
        $this->assertSame(3, $filas[0]['renglones']);
        $this->assertSame(25, $filas[0]['pedidas']);
        $this->assertSame(20, $filas[0]['despachadas']);
        $this->assertSame(1, $filas[0]['fallas']);
        $this->assertEquals(80.0, $filas[0]['servicio']);
        $this->assertSame(2700, $filas[0]['tiempo']);

        $rutas = $this->actingAs($this->jefe())->get('/reportes/clientes?desde=2026-11-04&hasta=2026-11-04&agrupar=ruta')->assertOk()->viewData('filas')->items();
        $this->assertCount(1, $rutas);
        $this->assertSame(['NORTE', 2, 3], [$rutas[0]['nombre'], $rutas[0]['clientes'], $rutas[0]['pedidos']]);

        $this->actingAs($this->jefe())->get('/reportes/clientes/excel?desde=2026-11-04&hasta=2026-11-04&agrupar=ruta')->assertOk()->assertDownload();
    }

    public function test_pedidos_demorados_tambien_los_que_siguen_en_la_etapa(): void
    {
        // Aceptar de 45 min: demorado.
        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-11-04 08:00:00', 'fecpicking' => '2026-11-04 08:45:00', 'fecpacking' => '2026-11-04 08:50:00', 'feccompletado' => '2026-11-04 08:55:00']);
        // Lleva 2 horas en picking (ahora son las 15:00): demorado y en curso, con su despachador.
        $this->crearPedido(['id' => 2, 'estado' => 'PICKING', 'fecrecibido' => '2026-11-04 12:55:00', 'fecpicking' => '2026-11-04 13:00:00'], ['despachador' => 'Ana']);
        // Todo rápido: no sale.
        $this->crearPedido(['id' => 3, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-11-04 09:00:00', 'fecpicking' => '2026-11-04 09:05:00', 'fecpacking' => '2026-11-04 09:20:00', 'feccompletado' => '2026-11-04 09:25:00']);

        $vista = $this->actingAs($this->jefe())->get('/reportes/demorados?desde=2026-11-04&hasta=2026-11-04&minutos=30')->assertOk();
        $filas = $vista->viewData('filas')->items();

        $this->assertSame([2, 1], array_column($filas, 'id'));
        $this->assertSame(['picking', 7200, true, 'Ana'], [$filas[0]['etapa'], $filas[0]['segundos'], $filas[0]['enCurso'], $filas[0]['responsable']]);
        $this->assertSame(['aceptar', 2700, false], [$filas[1]['etapa'], $filas[1]['segundos'], $filas[1]['enCurso']]);
        $this->assertSame(1, $vista->viewData('enCurso'));
        $vista->assertSee('En curso');

        $soloPicking = $this->actingAs($this->jefe())->get('/reportes/demorados?desde=2026-11-04&hasta=2026-11-04&minutos=30&etapa=picking')->viewData('filas')->items();
        $this->assertSame([2], array_column($soloPicking, 'id'));

        $this->actingAs($this->jefe())->get('/reportes/demorados/excel?desde=2026-11-04&hasta=2026-11-04')->assertOk()->assertDownload();
    }

    public function test_resumen_del_mes_compara_con_los_mismos_dias_del_mes_anterior(): void
    {
        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO', 'codcli' => 'C1', 'nomcli' => 'FARMACIA UNO', 'fecrecibido' => '2026-11-02 08:00:00', 'fecpicking' => '2026-11-02 08:10:00', 'feccompletado' => '2026-11-02 09:00:00']);
        // Del 2 de octubre: entra en la comparación (mismos días). El 20 de octubre no.
        $this->crearPedido(['id' => 2, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-10-02 08:00:00', 'fecpicking' => '2026-10-02 08:20:00', 'feccompletado' => '2026-10-02 09:00:00']);
        $this->crearPedido(['id' => 3, 'estado' => 'FACTURADO', 'fecrecibido' => '2026-10-20 08:00:00', 'fecpicking' => '2026-10-20 10:00:00', 'feccompletado' => '2026-10-20 11:00:00']);

        $vista = $this->actingAs($this->jefe())->get('/reportes/mensual')->assertOk()
            ->assertSee('noviembre 2026')
            ->assertSee('FARMACIA UNO')
            ->assertSee('▼ 50 % vs mes anterior');

        $this->assertSame(600, $vista->viewData('actual')['tiempos']['aceptar']['segundos']);
        $this->assertSame(1200, $vista->viewData('previo')['tiempos']['aceptar']['segundos']);

        // Octubre completo se compara con septiembre completo.
        $octubre = $this->actingAs($this->jefe())->get('/reportes/mensual?mes=2026-10')->assertOk();
        $this->assertSame(2, $octubre->viewData('actual')['desempeno']['pedidos']);
    }

    public function test_los_ve_el_jefe_de_operaciones_o_quien_tiene_informes_y_nadie_mas(): void
    {
        $this->actingAs($this->jefe())->get('/reportes')->assertOk()->assertSee('Pedidos demorados');
        $this->actingAs($this->jefe())->get('/home')->assertOk()->assertSee('Ver reportes de operaciones');

        $informes = $this->crearUsuario(['email' => 'informes@example.com', 'activarInformes' => 1]);
        $this->actingAs($informes)->get('/reportes/tiempos')->assertOk();

        $operario = $this->crearUsuario(['email' => 'picker@example.com', 'activarPicking' => 1]);
        $this->actingAs($operario)->get('/reportes')->assertForbidden();
        $this->actingAs($operario)->get('/reportes/mensual')->assertForbidden();
    }

    private $jefe = null;

    private function jefe()
    {
        return $this->jefe ??= $this->crearUsuario(['name' => 'Jefe', 'email' => 'jefe.ops@example.com', 'activarIndicadores' => 1]);
    }
}
