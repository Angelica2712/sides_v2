<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesRetencion;
use App\Models\Sides\SidesRetencionRen;
use App\Services\Siad\SiadPedidosService;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class RetenidosTest extends TestCase
{
    use TablasSides;

    private SidesUsers $empacador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-10-30 10:00:00');

        $this->crearCfg();
        $this->empacador = $this->crearUsuario(['name' => 'Eva Empaca', 'email' => 'eva@example.com', 'activarPacking' => 1, 'activarMonitor' => 1, 'activarPedido' => 1, 'eliminarPedido' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedidoListo(int $id, string $codcli = 'C1', string $estado = 'PACKING'): void
    {
        $this->crearPedido(['id' => $id, 'estado' => $estado, 'codcli' => $codcli, 'nomcli' => "CLIENTE {$codcli}"]);
        // Como en la base real, el item no se repite entre pedidos.
        $this->crearRenglon($id, $id, ['cantidad' => 2, 'barra' => '0771']);
        SidesPedrenOperacion::query()->insert(['id_pedido' => $id, 'item' => $id, 'codisb' => '505094939', 'cantdesp' => 2, 'chequeado' => 2]);
    }

    private function terminarRetenido(int $id): void
    {
        $this->actingAs($this->empacador)->get("/packing/{$id}")->assertOk();
        $this->post("/packing/{$id}/terminar", ['cantBultos' => '2', 'retener' => 1])->assertSessionHas('mensaje', "Pedido #{$id} retenido en la alcabala de packing.");
    }

    public function test_retener_no_envia_a_facturar_ni_escribe_en_seped(): void
    {
        $this->pedidoListo(800);
        $this->terminarRetenido(800);

        $this->assertSame('PACKING', Pedido::query()->find(800)->estado);
        $this->assertSame(0, (int) DB::table('pedren')->where('id', 800)->value('cantdesp'));
        $this->assertSame(1, SidesLogpacking::query()->where('id_pedido', 800)->count());

        // Sale de Packing y del monitor, y nadie lo puede volver a abrir.
        $this->get('/packing')->assertOk()->assertDontSee('CLIENTE C1');
        $this->get('/monitor')->assertOk()->assertDontSee('CLIENTE C1');
        $this->get('/packing/800')->assertRedirect(route('packing.index'))
            ->assertSessionHas('error', 'El pedido #800 ya terminó el packing y está retenido en la alcabala.');
        $this->get('/retenidos')->assertOk()->assertSee('CLIENTE C1')->assertSee('#800');
    }

    public function test_anexar_y_liberar_juntos_funde_los_pedidos_en_uno(): void
    {
        $this->pedidoListo(800);
        $this->pedidoListo(801);
        $this->pedidoListo(802, 'C2');
        $this->terminarRetenido(800);

        $this->get('/retenidos')->assertSee('#801 (PACKING)');
        $this->get('/packing/801')->assertOk()->assertSee('Anexar a los retenidos');
        $this->terminarRetenido(801);
        $this->terminarRetenido(802);

        $this->assertSame(2, SidesRetencion::query()->count());
        $retencion = SidesRetencion::query()->where('codcli', 'C1')->first();
        $this->assertSame(2, SidesRetencionRen::query()->where('id_retencion', $retencion->id)->count());

        $this->post("/retenidos/{$retencion->id}/liberar", ['bultos' => [800 => 3, 801 => 1]])->assertRedirect(route('retenidos.index'));

        $this->assertSame(['PEND-FACTURA', 'ANULADO', 'PACKING'], Pedido::query()->orderBy('id')->whereIn('id', [800, 801, 802])->pluck('estado')->all());
        $this->assertSame([800, 801], DB::table('pedren')->where('id', 800)->orderBy('item')->pluck('item')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(0, DB::table('pedren')->where('id', 801)->count());
        $this->assertSame(['FACTURADO', 'FACTURADO'], DB::table('pedren')->where('id', 800)->pluck('estado_desp')->all());
        $this->assertSame(2, (int) DB::table('pedren')->where('item', 801)->value('cantdesp'));
        $this->assertSame(2, DB::table('sides_pedren_operacion')->where('id_pedido', 800)->count());
        $this->assertSame('4', DB::table('sides_pedido_operacion')->where('id_pedido', 800)->value('cantBultos'));
        $this->assertStringStartsWith('Unido al pedido #800.', Pedido::query()->find(801)->observacion);
        $this->assertSame('[801]', DB::table('sides_pedido_union')->where('id_unido', 801)->value('items'));
        $this->assertSame('LIBERADA', $retencion->fresh()->estado);
    }

    public function test_el_pedido_fundido_suma_la_cabecera_y_el_siad_lo_recibe_completo(): void
    {
        $this->pedidoListo(800);
        $this->pedidoListo(801);
        Pedido::query()->whereKey(800)->update(['numren' => 1, 'numund' => 2, 'total' => 100, 'subtotal' => 90]);
        Pedido::query()->whereKey(801)->update(['numren' => 1, 'numund' => 2, 'total' => 50.5, 'subtotal' => 40]);
        $this->terminarRetenido(800);
        $this->terminarRetenido(801);
        $retencion = SidesRetencion::query()->first();

        $this->post("/retenidos/{$retencion->id}/liberar")
            ->assertSessionHas('mensaje', 'Los pedidos #801 se unieron al #800, que se envió a facturar.');

        $pedido = Pedido::query()->find(800);
        $this->assertSame([2, 4, 150.5, 130.0], [(int) $pedido->numren, (int) $pedido->numund, (float) $pedido->total, (float) $pedido->subtotal]);
        $this->assertSame('4', DB::table('sides_pedido_operacion')->where('id_pedido', 800)->value('cantBultos'));

        $entrega = app(SiadPedidosService::class)->pedido('505094939', 'PEND-FACTURA');
        $this->assertSame(800, $entrega[0]['id']);
        $this->assertCount(2, $entrega[0]['pedren']);
        $this->assertSame([], app(SiadPedidosService::class)->idsEnEstado('505094939', 'PACKING'));
    }

    public function test_no_funde_pedidos_con_distintas_condiciones_pero_se_pueden_enviar_por_separado(): void
    {
        $this->pedidoListo(800);
        $this->pedidoListo(801);
        Pedido::query()->whereKey(801)->update(['dcredito' => 30]);
        $this->terminarRetenido(800);
        $this->terminarRetenido(801);
        $retencion = SidesRetencion::query()->first();

        $this->post("/retenidos/{$retencion->id}/liberar")
            ->assertSessionHas('error', 'Los pedidos #800 y #801 no se pueden unir: tienen distinto días de crédito. Envíalos a facturar uno por uno.');
        $this->assertSame(1, DB::table('pedren')->where('id', 801)->count());
        $this->assertSame('PACKING', Pedido::query()->find(800)->estado);

        $this->post("/retenidos/{$retencion->id}/liberar", ['pedido' => 801])->assertSessionHas('mensaje', 'Pedido #801 enviado a facturar.');
    }

    public function test_liberar_uno_solo_deja_los_demas_retenidos(): void
    {
        $this->pedidoListo(800);
        $this->pedidoListo(801);
        $this->terminarRetenido(800);
        $this->terminarRetenido(801);
        $retencion = SidesRetencion::query()->firstOrFail();

        $this->post("/retenidos/{$retencion->id}/liberar", ['pedido' => 800])->assertSessionHas('mensaje', 'Pedido #800 enviado a facturar.');

        $this->assertSame('PEND-FACTURA', Pedido::query()->find(800)->estado);
        $this->assertSame('PACKING', Pedido::query()->find(801)->estado);
        $this->assertSame('ABIERTA', $retencion->fresh()->estado);
        $this->assertSame(0, DB::table('sides_pedido_union')->count());

        $this->post("/retenidos/{$retencion->id}/liberar")->assertSessionHas('mensaje', 'Pedido #801 enviado a facturar.');
        $this->assertSame('LIBERADA', $retencion->fresh()->estado);
    }

    public function test_el_pedido_que_cambia_de_estado_sale_de_la_retencion(): void
    {
        $this->pedidoListo(800);
        $this->pedidoListo(801);
        $this->terminarRetenido(800);
        $this->terminarRetenido(801);

        // Anulado desde SIDES.
        $this->post('/pedidos/800/anular')->assertSessionHas('mensaje');
        $this->assertFalse(SidesRetencionRen::query()->where('id_pedido', 800)->exists());

        // Movido por SEPED (devuelto a su alcabala): sale al leer, y la retención vacía se cierra.
        Pedido::query()->whereKey(801)->update(['estado' => 'POR-APROBAR']);
        $this->get('/retenidos')->assertOk()->assertSee('No hay pedidos retenidos.');
        $this->assertSame('LIBERADA', SidesRetencion::query()->first()->estado);
    }

    public function test_alcabala_es_de_la_sucursal_y_requiere_el_modulo(): void
    {
        $this->pedidoListo(800);
        $this->terminarRetenido(800);
        $retencion = SidesRetencion::query()->firstOrFail();

        $this->crearCfg(['codisb' => 'OTRA']);
        $ajeno = $this->crearUsuario(['email' => 'otra@example.com', 'codisb' => 'OTRA', 'activarPacking' => 1]);
        $this->actingAs($ajeno)->get('/retenidos')->assertOk()->assertDontSee('CLIENTE C1');
        $this->post("/retenidos/{$retencion->id}/liberar")->assertSessionHas('error', 'Esa retención ya no está abierta.');
        $this->assertSame('PACKING', Pedido::query()->find(800)->estado);

        // Sin el módulo, el botón no aparece y un POST con retener envía a facturar como siempre.
        DB::table('sides_modulo_sucursal')->where('modulo', 'retenidos')->update(['activo' => 0]);
        $this->pedidoListo(801, 'C9');
        $this->actingAs($this->empacador->fresh())->get('/retenidos')->assertForbidden();
        $this->get('/packing/801')->assertOk()->assertDontSee('Retener en alcabala');
        $this->post('/packing/801/terminar', ['cantBultos' => '1', 'retener' => 1])->assertSessionHas('mensaje', 'Pedido #801 enviado a facturar.');
        $this->assertSame('PEND-FACTURA', Pedido::query()->find(801)->estado);
    }
}
