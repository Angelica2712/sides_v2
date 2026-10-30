<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesFacturaGrupoRen;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesRetencion;
use App\Models\Sides\SidesRetencionRen;
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
        $this->crearRenglon($id, 1, ['cantidad' => 2, 'barra' => '0771']);
        SidesPedrenOperacion::query()->insert(['id_pedido' => $id, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 2, 'chequeado' => 2]);
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

    public function test_anexar_y_liberar_juntos_quedan_en_un_grupo(): void
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

        $this->assertSame(['PEND-FACTURA', 'PEND-FACTURA', 'PACKING'], Pedido::query()->orderBy('id')->whereIn('id', [800, 801, 802])->pluck('estado')->all());
        $this->assertSame(2, (int) DB::table('pedren')->where('id', 800)->value('cantdesp'));
        $this->assertSame('FACTURADO', DB::table('pedren')->where('id', 801)->value('estado_desp'));
        $this->assertSame('3', DB::table('sides_pedido_operacion')->where('id_pedido', 800)->value('cantBultos'));
        $this->assertSame([800, 801], SidesFacturaGrupoRen::query()->orderBy('id_pedido')->pluck('id_pedido')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('LIBERADA', $retencion->fresh()->estado);
        $this->assertNotNull($retencion->fresh()->id_grupo);
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
        $this->assertSame(0, SidesFacturaGrupoRen::query()->count());

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
