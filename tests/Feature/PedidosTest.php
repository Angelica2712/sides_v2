<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesAlcabalaLote;
use App\Models\Sides\SidesAlcabalaLotePedido;
use App\Models\Sides\SidesEtiquetaPedido;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class PedidosTest extends TestCase
{
    use TablasSides;

    private SidesUsers $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->crearCfg();
        $this->usuario = $this->crearUsuario(['name' => 'Ana Admin', 'email' => 'ana@example.com', 'activarPedido' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jefe(): SidesUsers
    {
        return $this->crearUsuario(['name' => 'Jefe', 'email' => 'jefe@example.com', 'activarPedido' => 1, 'activarResetear' => 1, 'eliminarPedido' => 1]);
    }

    private function lote(string $estado, int $pedidoId): SidesAlcabalaLote
    {
        $lote = SidesAlcabalaLote::query()->create([
            'codisb' => '505094939', 'estado' => $estado, 'origen_creacion' => 'MANUAL', 'fecha_creacion' => '2026-09-15 08:00:00',
        ]);
        SidesAlcabalaLotePedido::query()->create(['id_lote' => $lote->id, 'numped' => $pedidoId, 'fecha_agregado' => '2026-09-15 08:00:00']);

        return $lote;
    }

    public function test_lista_filtra_por_texto_estado_y_fecha_de_envio(): void
    {
        $this->crearPedido(['id' => 101, 'estado' => 'RECIBIDO', 'nomcli' => 'FARMACIA SOL', 'fecenviado' => '2026-09-10 08:00:00']);
        $this->crearPedido(['id' => 102, 'estado' => 'FACTURADO', 'nomcli' => 'FARMACIA LUNA', 'fecenviado' => '2026-09-14 08:00:00', 'documento' => 'FAC-778']);
        $this->crearPedido(['id' => 103, 'estado' => 'FACTURADO', 'nomcli' => 'BOTICA CENTRAL', 'fecenviado' => '2026-09-15 08:00:00']);
        $this->crearPedido(['id' => 104, 'estado' => 'RECIBIDO', 'nomcli' => 'DE OTRA SUCURSAL', 'codisb' => 'OTRA']);
        $this->actingAs($this->usuario);

        $this->get('/pedidos')->assertOk()
            ->assertSeeInOrder(['#103', '#102', '#101'])
            ->assertSee('Doc. FAC-778')
            ->assertDontSee('DE OTRA SUCURSAL');

        $this->get('/pedidos?buscar=farmacia')->assertSee('#101')->assertSee('#102')->assertDontSee('#103');
        $this->get('/pedidos?estado=FACTURADO')->assertSee('#102')->assertSee('#103')->assertDontSee('#101');
        $this->get('/pedidos?desde=2026-09-14&hasta=2026-09-14')->assertSee('#102')->assertDontSee('#101')->assertDontSee('#103');
    }

    public function test_al_abrir_la_lista_anula_los_facturando_viejos(): void
    {
        $this->crearPedido(['id' => 201, 'estado' => 'FACTURANDO', 'fecprocesado' => '2026-08-01 08:00:00']);
        $this->crearPedido(['id' => 202, 'estado' => 'FACTURANDO', 'fecprocesado' => '2026-09-10 08:00:00']);
        $this->crearPedido(['id' => 203, 'estado' => 'FACTURANDO', 'fecprocesado' => '2026-03-01 08:00:00']);

        $this->actingAs($this->usuario)->get('/pedidos')->assertOk();

        $anulado = Pedido::query()->find(201);
        $this->assertSame(['ANULADO', '(AUTOMATICO) PEDIDO ESTUBO MUCHO TIEMPO EN ESPERA POR FACTURAR'], [$anulado->estado, $anulado->observacion]);
        $this->assertSame(['FACTURANDO', 'FACTURANDO'], [Pedido::query()->find(202)->estado, Pedido::query()->find(203)->estado]);
    }

    public function test_detalle_muestra_datos_fechas_y_lo_despachado(): void
    {
        $this->crearPedido(['id' => 300, 'estado' => 'PACKING', 'fecpicking' => '2026-09-15 09:00:00'], [
            'recipiente' => 'C4', 'despachador' => 'Pedro Picker', 'cantBultos' => '2',
        ]);
        $this->crearRenglon(300, 1, ['desprod' => 'ACETAMINOFEN 500MG', 'cantidad' => 3]);
        $this->crearRenglon(300, 2, ['desprod' => 'IBUPROFENO 400MG', 'cantidad' => 2]);
        SidesPedrenOperacion::query()->insert([
            ['id_pedido' => 300, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 3, 'despachador' => 'Pedro Picker'],
            ['id_pedido' => 300, 'item' => 2, 'codisb' => '505094939', 'cantdesp' => -1, 'despachador' => null],
        ]);
        $this->crearPedido(['id' => 301, 'estado' => 'PACKING', 'codisb' => 'OTRA']);
        $this->actingAs($this->usuario);

        $this->get('/pedidos/300')->assertOk()
            ->assertSee('Pedido #300')
            ->assertSee('C4')
            ->assertSee('15-09-2026 09:00')
            ->assertSeeInOrder(['ACETAMINOFEN 500MG', 'Pedro Picker', 'IBUPROFENO 400MG'])
            ->assertDontSee('Resetear')
            ->assertDontSee('Anular pedido');

        $this->get('/pedidos/301')->assertNotFound();
    }

    public function test_modificar_estado_fechas_recipiente_y_observacion(): void
    {
        $this->crearPedido(['id' => 400, 'estado' => 'PACKING']);
        $this->actingAs($this->usuario);

        $this->get('/pedidos/400/modificar')->assertOk()->assertSee('Modificar pedido #400');

        $this->put('/pedidos/400', [
            'estado' => 'FACTURADO', 'fecrecibido' => '', 'fecpicking' => '2026-09-15T08:30', 'fecpacking' => '',
            'feccompletado' => '', 'fecfacturado' => '2026-09-15T09:45', 'recipiente' => 'C9', 'observacion' => 'Revisado por Ana',
        ])->assertRedirect(route('pedidos.show', 400))->assertSessionHas('mensaje', 'Pedido #400 modificado.');

        $pedido = Pedido::query()->find(400);
        $this->assertSame(
            ['FACTURADO', '2026-09-15 08:30:00', '2026-09-15 09:45:00', '2020-01-01 00:00:00', 'Revisado por Ana'],
            [$pedido->estado, (string) $pedido->fecpicking, (string) $pedido->fecfacturado, (string) $pedido->fecrecibido, $pedido->observacion]
        );
        $this->assertSame('C9', SidesPedidoOperacion::query()->find(400)->recipiente);

        $this->put('/pedidos/400', ['estado' => 'INVENTADO'])->assertSessionHasErrors(['estado' => 'Elige un estado de la lista.']);
    }

    public function test_resetear_devuelve_el_pedido_a_recibido_y_limpia_el_trabajo_de_sides(): void
    {
        $this->crearPedido(['id' => 500, 'estado' => 'PEND-FACTURA', 'observacion' => 'Nota de SEPED'], [
            'recipiente' => 'C1', 'despachador' => 'Pedro', 'embalador' => 'Eva',
        ]);
        $this->crearRenglon(500, 1, ['cantdesp' => 2, 'estado_desp' => 'FACTURADO']);
        SidesPedrenOperacion::query()->insert(['id_pedido' => 500, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 2, 'chequeado' => 2, 'packing' => 1]);
        SidesLogpicking::query()->create(['id_pedido' => 500, 'usuario' => 'pedro', 'numren' => 1, 'numund' => 2, 'tiempo_picking' => 60, 'fecha_del_picking' => '2026-09-15 09:00:00', 'descripcion' => 'tiempo completo']);
        SidesLogpacking::query()->create(['id_pedido' => 500, 'usuario' => 'eva', 'numren' => 1, 'numund' => 2, 'tiempo_packing' => 60, 'fecha_del_packing' => '2026-09-15 09:30:00']);
        $this->lote('TERMINADO', 500);
        SidesEtiquetaPedido::query()->insert(['numepedi' => '500', 'etiqueta' => '500-01', 'codcli' => 'C001', 'nomcli' => 'CLIENTE', 'ruta' => 'RUTA 1', 'estado' => 'NUEVO']);

        $this->actingAs($this->jefe())->post('/pedidos/500/resetear')
            ->assertRedirect(route('pedidos.show', 500))
            ->assertSessionHas('mensaje', 'Pedido #500 reseteado: volvió a RECIBIDO.');

        $pedido = Pedido::query()->find(500);
        $this->assertSame(['RECIBIDO', 'Nota de SEPED'], [$pedido->estado, $pedido->observacion]);
        $renglon = DB::table('pedren')->where('id', 500)->first();
        $this->assertSame([0, null], [(int) $renglon->cantdesp, $renglon->estado_desp]);
        $this->assertSame(
            [0, 0, 0, 0, 0, 0],
            [
                SidesPedidoOperacion::query()->count(), SidesPedrenOperacion::query()->count(), SidesLogpicking::query()->count(),
                SidesLogpacking::query()->count(), SidesAlcabalaLotePedido::query()->count(), SidesEtiquetaPedido::query()->count(),
            ]
        );
    }

    public function test_resetear_y_anular_exigen_permiso_y_respetan_lotes_en_curso_y_guias(): void
    {
        $this->crearPedido(['id' => 600, 'estado' => 'PICKING']);
        $lote = $this->lote('CONFIRMADO', 600);
        $this->crearPedido(['id' => 700, 'estado' => 'FACTURADO']);
        SidesEtiquetaPedido::query()->insert(['numepedi' => '700', 'etiqueta' => '700-01', 'codcli' => 'C001', 'nomcli' => 'CLIENTE', 'ruta' => 'RUTA 1', 'estado' => 'EN GUIA', 'guia' => 12]);

        $this->actingAs($this->usuario);
        $this->post('/pedidos/700/resetear')->assertSessionHas('error', 'No tienes permiso para resetear pedidos.');
        $this->post('/pedidos/700/anular')->assertSessionHas('error', 'No tienes permiso para anular pedidos.');

        $this->actingAs($this->jefe());
        $enLote = "El pedido #600 está en el lote #{$lote->id} de Batch Picking, que sigue en curso. Anula o termina el lote primero.";
        $this->post('/pedidos/600/resetear')->assertSessionHas('error', $enLote);
        $this->post('/pedidos/600/anular')->assertSessionHas('error', $enLote);
        $this->put('/pedidos/600', ['estado' => 'RECIBIDO'])->assertSessionHas('error', $enLote);
        $this->assertSame('PICKING', Pedido::query()->find(600)->estado);

        $this->post('/pedidos/700/resetear')->assertSessionHas('error', 'El pedido #700 ya está cargado en la guía #12: no se puede resetear.');

        $this->get('/pedidos/700')->assertSee('Resetear')->assertSee('Anular pedido');
        $this->post('/pedidos/700/anular')->assertSessionHas('mensaje', 'Pedido #700 anulado.');
        $this->assertSame('ANULADO', Pedido::query()->find(700)->estado);
        $this->post('/pedidos/700/anular')->assertSessionHas('error', 'El pedido #700 ya está anulado.');
    }
}
