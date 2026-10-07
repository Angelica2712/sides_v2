<?php

namespace Tests\Feature;

use App\Models\Sides\SidesLogInacPicking;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesUsers;
use App\Support\Duracion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class InformesTest extends TestCase
{
    use TablasSides;

    private SidesUsers $jefe;

    private SidesUsers $ana;

    private SidesUsers $luis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearCfg();
        Carbon::setTestNow('2026-09-29 10:00:00');

        $this->jefe = $this->crearUsuario(['email' => 'jefe@example.com', 'name' => 'Jefe', 'activarInformes' => 1]);
        $this->ana = $this->crearUsuario(['email' => 'ana@example.com', 'name' => 'Ana Pérez']);
        $this->luis = $this->crearUsuario(['email' => 'luis@example.com', 'name' => 'Luis Gómez']);

        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO', 'nomcli' => 'FARMACIA UNO']);
        $this->crearPedido(['id' => 2, 'estado' => 'FACTURADO', 'nomcli' => 'FARMACIA DOS']);
        $this->crearPedido(['id' => 3, 'estado' => 'FACTURADO', 'nomcli' => 'FARMACIA TRES']);
        $this->crearPedido(['id' => 9, 'estado' => 'FACTURADO', 'nomcli' => 'DE OTRA SUCURSAL', 'codisb' => '999999999']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function picking(int $pedido, SidesUsers $usuario, int $renglones, int $unidades, int $segundos, string $fecha, string $descripcion = 'tiempo completo'): void
    {
        SidesLogpicking::query()->create([
            'id_pedido' => $pedido, 'usuario' => $usuario->email, 'numren' => $renglones, 'numund' => $unidades,
            'tiempo_picking' => $segundos, 'fecha_del_picking' => $fecha, 'descripcion' => $descripcion,
        ]);
    }

    public function test_sin_permiso_de_informes_no_entra(): void
    {
        $this->actingAs($this->ana)->get('/informes')->assertForbidden();
        $this->get('/informes/picking/productividad')->assertForbidden();
    }

    public function test_inicio_muestra_los_cuatro_informes(): void
    {
        $this->actingAs($this->jefe)->get('/informes')
            ->assertOk()
            ->assertSee('Productividad en Picking')
            ->assertSee('Inactividad en Picking')
            ->assertSee('Productividad en Packing')
            ->assertSee('Inactividad en Packing');
    }

    public function test_ranking_de_productividad_ordenado_por_tiempo_por_renglon_y_solo_de_la_sucursal(): void
    {
        // Ana: 20 renglones en 600 s = 30 s/renglón. Luis: 10 renglones en 600 s = 60 s/renglón.
        $this->picking(1, $this->ana, 12, 40, 400, '2026-09-28 09:00:00');
        $this->picking(2, $this->ana, 8, 20, 200, '2026-09-28 10:00:00');
        $this->picking(3, $this->luis, 10, 30, 600, '2026-09-27 11:00:00');
        // De otra sucursal y fuera del rango por defecto (30 días): no cuentan.
        $this->picking(9, $this->luis, 100, 100, 1, '2026-09-28 12:00:00');
        $this->picking(3, $this->ana, 50, 50, 1, '2026-08-01 12:00:00');

        $this->actingAs($this->jefe)->get('/informes/picking/productividad')
            ->assertOk()
            ->assertViewHas('ranking', function ($ranking) {
                [$primero, $segundo] = $ranking->all();

                return $ranking->count() === 2
                    && $primero->usuario === 'ana@example.com' && (int) $primero->pedidos === 2
                    && (int) $primero->renglones === 20 && (int) $primero->unidades === 60
                    && (float) $primero->por_renglon === 30.0 && (float) $primero->promedio === 300.0
                    && $segundo->usuario === 'luis@example.com' && (float) $segundo->por_renglon === 60.0;
            })
            ->assertSeeInOrder(['Ana Pérez', 'Luis Gómez'])
            ->assertDontSee('DE OTRA SUCURSAL');
    }

    public function test_rango_de_fechas_elegido_es_inclusivo(): void
    {
        $this->picking(1, $this->ana, 1, 1, 60, '2026-09-01 00:00:00');
        $this->picking(2, $this->ana, 1, 1, 60, '2026-09-02 23:59:59');
        $this->picking(3, $this->ana, 1, 1, 60, '2026-09-03 00:00:00');

        $this->actingAs($this->jefe)->get('/informes/picking/productividad?desde=2026-09-01&hasta=2026-09-02')
            ->assertOk()
            ->assertViewHas('ranking', fn ($ranking) => (int) $ranking->first()->pedidos === 2);
    }

    public function test_fechas_invalidas_se_rechazan(): void
    {
        $this->actingAs($this->jefe)->from('/informes')
            ->get('/informes/picking/productividad?desde=2026-09-10&hasta=2026-09-01')
            ->assertRedirect('/informes')
            ->assertSessionHasErrors('hasta');

        $this->get('/informes/picking/productividad?desde=2024-01-01&hasta=2026-09-01')
            ->assertSessionHasErrors('desde');
    }

    public function test_detalle_de_un_operario_y_no_de_otra_sucursal(): void
    {
        $this->picking(1, $this->ana, 12, 40, 400, '2026-09-28 09:00:00', 'tiempo parcial');
        $this->picking(2, $this->luis, 8, 20, 200, '2026-09-28 10:00:00');
        $ajeno = $this->crearUsuario(['email' => 'ajeno@example.com', 'codisb' => '999999999']);

        $this->actingAs($this->jefe)->get("/informes/picking/productividad/operario/{$this->ana->id}")
            ->assertOk()
            ->assertSee('Ana Pérez')
            ->assertSee('FARMACIA UNO')
            ->assertSee('Parcial (liberado)')
            ->assertSee(Duracion::texto(400))
            ->assertDontSee('FARMACIA DOS');

        $this->get("/informes/picking/productividad/operario/{$ajeno->id}")->assertNotFound();
    }

    public function test_inactividad_y_quitar_un_registro_justificado(): void
    {
        $registro = SidesLogInacPicking::query()->create([
            'id_pedido' => 2, 'id_pedido_anterior' => 1, 'usuario' => $this->ana->email, 'numren' => 3,
            'numund' => 9, 'tiempo_picking_inac' => 3600, 'fecha_del_picking' => '2026-09-28 13:00:00',
        ]);
        $otro = SidesLogInacPicking::query()->create([
            'id_pedido' => 2, 'id_pedido_anterior' => 1, 'usuario' => $this->luis->email, 'numren' => 3,
            'numund' => 9, 'tiempo_picking_inac' => 120, 'fecha_del_picking' => '2026-09-28 13:00:00',
        ]);
        $ajeno = SidesLogInacPicking::query()->create([
            'id_pedido' => 9, 'usuario' => $this->ana->email, 'numren' => 1,
            'numund' => 1, 'tiempo_picking_inac' => 50, 'fecha_del_picking' => '2026-09-28 13:00:00',
        ]);

        $this->actingAs($this->jefe)->get('/informes/picking/inactividad')
            ->assertOk()
            ->assertSeeInOrder(['Luis Gómez', 'Ana Pérez'])
            ->assertSee('1h 00m 00s');

        $this->get("/informes/picking/inactividad/operario/{$this->ana->id}")
            ->assertOk()
            ->assertSee('#1')
            ->assertSee('Quitar');

        $this->delete("/informes/picking/inactividad/{$registro->id}")
            ->assertSessionHas('mensaje', 'Registro de inactividad quitado del informe.');

        // Solo ese registro: el de Luis sobre el mismo pedido sigue (el legacy borraba por pedido).
        $this->assertModelMissing($registro);
        $this->assertModelExists($otro);

        // Un registro de otra sucursal no se puede quitar.
        $this->delete("/informes/picking/inactividad/{$ajeno->id}")->assertSessionHas('error');
        $this->assertModelExists($ajeno);
    }

    public function test_packing_lee_sus_propios_registros(): void
    {
        SidesLogpacking::query()->create([
            'id_pedido' => 1, 'usuario' => $this->luis->email, 'numren' => 5, 'numund' => 10,
            'tiempo_packing' => 250, 'fecha_del_packing' => '2026-09-28 09:00:00',
        ]);
        $this->picking(2, $this->ana, 8, 20, 200, '2026-09-28 10:00:00');

        $this->actingAs($this->jefe)->get('/informes/packing/productividad')
            ->assertOk()
            ->assertSee('Productividad en Packing')
            ->assertSee('Luis Gómez')
            ->assertDontSee('Ana Pérez');
    }

    public function test_sin_registros_explica_de_donde_salen(): void
    {
        $this->actingAs($this->jefe)->get('/informes/packing/inactividad')
            ->assertOk()
            ->assertSee('No hay registros en estas fechas')
            ->assertSee('desde el segundo pedido');
    }

    public function test_excel_con_hojas_de_resumen_y_detalle(): void
    {
        $this->picking(1, $this->ana, 12, 40, 400, '2026-09-28 09:00:00');
        $this->picking(3, $this->luis, 10, 30, 600, '2026-09-27 11:00:00');

        $respuesta = $this->actingAs($this->jefe)->get('/informes/picking/productividad/excel?desde=2026-09-01&hasta=2026-09-29');
        $respuesta->assertOk()->assertDownload('informe_productividad_picking_2026-09-01_2026-09-29.xlsx');

        $hojas = $this->leerExcel($respuesta->baseResponse->getFile()->getPathname());
        $this->assertSame(['Resumen', 'Detalle'], array_keys($hojas));
        $this->assertSame(['RANKING', 'OPERARIO', 'CORREO', 'PEDIDOS', 'UNIDADES', 'RENGLONES', 'PROMEDIO POR UNIDAD', 'PROMEDIO POR RENGLON', 'TIEMPO PROMEDIO', 'TIEMPO ACUMULADO'], $hojas['Resumen'][0]);
        $this->assertSame([1, 'Ana Pérez', 'ana@example.com', 1, 40, 12, '10s', '33s', '6m 40s', '6m 40s'], $hojas['Resumen'][1]);
        $this->assertSame('Luis Gómez', $hojas['Resumen'][2][1]);
        $this->assertCount(3, $hojas['Detalle']);
        $this->assertSame(['28-09-2026 09:00:00', 'Ana Pérez', 1, 'FARMACIA UNO', 40, 12, '6m 40s', 'Completo'], $hojas['Detalle'][1]);
    }

    public function test_excel_de_un_operario(): void
    {
        $this->picking(1, $this->ana, 12, 40, 400, '2026-09-28 09:00:00');
        $this->picking(3, $this->luis, 10, 30, 600, '2026-09-27 11:00:00');

        $respuesta = $this->actingAs($this->jefe)->get("/informes/picking/productividad/operario/{$this->ana->id}/excel?desde=2026-09-01&hasta=2026-09-29");
        $respuesta->assertOk()->assertDownload('informe_productividad_picking_ana_perez_2026-09-01_2026-09-29.xlsx');

        $hojas = $this->leerExcel($respuesta->baseResponse->getFile()->getPathname());
        $this->assertCount(2, $hojas['Detalle']);
        $this->assertSame(['FECHA', 'PEDIDO', 'CLIENTE', 'UNIDADES', 'RENGLONES', 'TIEMPO', 'TIPO'], $hojas['Detalle'][0]);
    }

    /** Pedido 1: faltan 6 de P1. Pedido 2: faltan 6 de P1 y 1 de P7. Lo demás salió completo o no cuenta. */
    private function fallas(): void
    {
        $this->crearRenglon(1, 1, ['codprod' => 'P1', 'desprod' => 'ACETAMINOFEN 500MG', 'marcamodelo' => 'GENVEN', 'cantidad' => 10, 'cantdesp' => 4]);
        $this->crearRenglon(1, 2, ['codprod' => 'P2', 'desprod' => 'IBUPROFENO 400MG', 'cantidad' => 5, 'cantdesp' => 5]);
        $this->crearRenglon(2, 1, ['codprod' => 'P1', 'desprod' => 'ACETAMINOFEN 500MG', 'marcamodelo' => 'GENVEN', 'cantidad' => 6, 'cantdesp' => 0]);
        $this->crearRenglon(2, 2, ['codprod' => 'P7', 'desprod' => 'LORATADINA 10MG', 'cantidad' => 3, 'cantdesp' => 0]);
        // Lo que SIDES tiene en su tabla de operación manda sobre pedren.
        DB::table('sides_pedren_operacion')->insert(['id_pedido' => 2, 'item' => 2, 'codisb' => '505094939', 'cantdesp' => 2]);
        $this->crearRenglon(3, 1, ['codprod' => 'P3', 'desprod' => 'COMPLETO', 'cantidad' => 4, 'cantdesp' => 0]);
        DB::table('sides_pedren_operacion')->insert(['id_pedido' => 3, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 4]);
        // No cuentan: otra sucursal y un pedido que todavía está en picking.
        $this->crearRenglon(9, 1, ['codprod' => 'P1', 'desprod' => 'DE OTRA SUCURSAL', 'cantidad' => 9, 'cantdesp' => 1, 'codisb' => '999999999']);
        $this->crearPedido(['id' => 4, 'estado' => 'PICKING', 'nomcli' => 'FARMACIA EN PICKING']);
        $this->crearRenglon(4, 1, ['codprod' => 'P8', 'desprod' => 'SIN RECOGER', 'cantidad' => 7, 'cantdesp' => 0]);
    }

    public function test_fallas_por_producto_suma_lo_que_falto_y_solo_de_pedidos_cerrados_de_la_sucursal(): void
    {
        $this->fallas();
        $this->actingAs($this->ana)->get('/informes/fallas')->assertForbidden();

        $this->actingAs($this->jefe)->get('/informes')->assertSee('Fallas');
        $this->get('/informes/fallas')
            ->assertOk()
            ->assertViewHas('totales', fn ($t) => [(int) $t->productos, (int) $t->pedidos, (int) $t->solicitado, (int) $t->despachado, (int) $t->faltante] === [2, 2, 19, 6, 13])
            ->assertViewHas('filas', fn ($filas) => $filas->map(fn ($f) => [$f->codprod, (int) $f->pedidos, (int) $f->solicitado, (int) $f->despachado, (int) $f->faltante])->all()
                === [['P1', 2, 16, 4, 12], ['P7', 1, 3, 2, 1]])
            ->assertSeeInOrder(['ACETAMINOFEN 500MG', 'GENVEN', 'LORATADINA 10MG'])
            ->assertDontSee('IBUPROFENO 400MG')
            ->assertDontSee('COMPLETO')
            ->assertDontSee('DE OTRA SUCURSAL')
            ->assertDontSee('SIN RECOGER');
    }

    public function test_fallas_por_pedido_con_busqueda_y_fechas(): void
    {
        $this->fallas();
        $this->actingAs($this->jefe);

        $this->get('/informes/fallas?vista=pedido')
            ->assertOk()
            ->assertViewHas('filas', fn ($filas) => $filas->map(fn ($f) => [(int) $f->id, $f->codprod, (int) $f->solicitado, (int) $f->despachado, (int) $f->faltante])->all()
                === [[2, 'P1', 6, 0, 6], [2, 'P7', 3, 2, 1], [1, 'P1', 10, 4, 6]])
            ->assertSeeInOrder(['#2', 'FARMACIA DOS', '#1', 'FARMACIA UNO']);

        $this->get('/informes/fallas?vista=pedido&buscar=loratadina')->assertSee('LORATADINA 10MG')->assertDontSee('ACETAMINOFEN 500MG');
        $this->get('/informes/fallas?vista=pedido&buscar=FARMACIA+UNO')->assertSee('#1')->assertDontSee('#2');
        $this->get('/informes/fallas?desde=2026-09-16&hasta=2026-09-29')->assertSee('No hay fallas en estas fechas');
    }

    public function test_excel_de_fallas_con_hoja_por_producto_y_por_pedido(): void
    {
        $this->fallas();

        $respuesta = $this->actingAs($this->jefe)->get('/informes/fallas/excel?desde=2026-09-01&hasta=2026-09-29');
        $respuesta->assertOk()->assertDownload('informe_fallas_2026-09-01_2026-09-29.xlsx');

        $hojas = $this->leerExcel($respuesta->baseResponse->getFile()->getPathname());
        $this->assertSame(['Por producto', 'Por pedido'], array_keys($hojas));
        $this->assertSame(['P1', 'ACETAMINOFEN 500MG', '771', 'GENVEN', 2, 16, 4, 12], $hojas['Por producto'][1]);
        $this->assertCount(4, $hojas['Por pedido']);
        $this->assertSame([2, '15-09-2026 08:30', 'FACTURADO', 'C001', 'FARMACIA DOS', 'RUTA 1', '', 'P1', 'ACETAMINOFEN 500MG', '771', 'GENVEN', 6, 0, 6], $hojas['Por pedido'][1]);
    }

    /** @return array<string, list<list<mixed>>> */
    private function leerExcel(string $archivo): array
    {
        $reader = new Reader();
        $reader->open($archivo);
        $hojas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $hojas[$hoja->getName()][] = $fila->toArray();
            }
        }
        $reader->close();

        return $hojas;
    }
}
