<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesLogInacPicking;
use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use App\Services\Picking\PickingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class PickingTest extends TestCase
{
    use TablasSides;

    private SidesUsers $operario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->crearCfg(['ordenPedSides' => 'UBICACION']);
        $this->operario = $this->crearUsuario(['name' => 'Ana Operaria', 'email' => 'ana@example.com', 'activarPicking' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedidoRecibido(int $id = 500): Pedido
    {
        $pedido = $this->crearPedido(['id' => $id, 'estado' => 'RECIBIDO', 'nomcli' => "CLIENTE {$id}"]);
        $this->crearRenglon($id, 1, ['ubicacion' => 'B-02', 'cantidad' => 3]);
        $this->crearRenglon($id, 2, ['ubicacion' => 'A-01', 'cantidad' => 2]);

        return $pedido;
    }

    private function tomado(int $id = 500): void
    {
        $this->pedidoRecibido($id);
        $this->actingAs($this->operario)->post("/picking/{$id}/tomar", ['recipiente' => 'CESTA 5']);
    }

    private function otroOperario(): SidesUsers
    {
        return $this->crearUsuario(['name' => 'Beto', 'email' => 'beto@example.com', 'activarPicking' => 1]);
    }

    public function test_lista_muestra_recibidos_y_en_picking_de_la_sucursal(): void
    {
        $this->pedidoRecibido(500);
        $this->crearPedido(['id' => 501, 'estado' => 'PICKING', 'nomcli' => 'CLIENTE EN PICKING']);
        $this->crearPedido(['id' => 502, 'estado' => 'PACKING', 'nomcli' => 'CLIENTE EN PACKING']);
        $this->crearPedido(['id' => 503, 'estado' => 'RECIBIDO', 'nomcli' => 'CLIENTE OTRA SUCURSAL', 'codisb' => '999']);

        $this->actingAs($this->operario)->get('/picking')
            ->assertOk()
            ->assertSee('CLIENTE 500')
            ->assertSee('CLIENTE EN PICKING')
            ->assertDontSee('CLIENTE EN PACKING')
            ->assertDontSee('CLIENTE OTRA SUCURSAL')
            ->assertSee('Tomar pedido');
    }

    public function test_tomar_pedido_lo_pasa_a_picking_y_prepara_los_renglones(): void
    {
        $this->pedidoRecibido();

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5'])
            ->assertRedirect(route('picking.show', 500));

        $pedido = Pedido::query()->find(500);
        $this->assertSame('PICKING', $pedido->estado);
        $this->assertSame('2026-09-15 10:00:00', (string) $pedido->fecpicking);

        $operacion = SidesPedidoOperacion::query()->find(500);
        $this->assertSame('Ana Operaria', $operacion->despachador);
        $this->assertSame('CESTA 5', $operacion->recipiente);
        $this->assertSame(1, (int) $operacion->despasignado);

        $renglones = SidesPedrenOperacion::query()->where('id_pedido', 500)->get();
        $this->assertCount(2, $renglones);
        $this->assertTrue($renglones->every(fn ($r) => (int) $r->cantdesp === -1 && $r->recipiente === 'CESTA 5' && $r->codisb === '505094939'));
    }

    public function test_tomar_requiere_recipiente(): void
    {
        $this->pedidoRecibido();

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => ''])
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('error', 'Escribe el código del recipiente.');

        $this->assertSame('RECIBIDO', Pedido::query()->find(500)->estado);
    }

    public function test_no_se_puede_tomar_un_pedido_de_otro_ni_dos_a_la_vez(): void
    {
        $this->tomado(500);
        $this->pedidoRecibido(600);

        $this->actingAs($this->otroOperario())->post('/picking/500/tomar', ['recipiente' => 'X'])
            ->assertSessionHas('error', 'El pedido #500 ya lo tomó Ana Operaria.');

        $this->actingAs($this->operario)->post('/picking/600/tomar', ['recipiente' => 'CESTA 6'])
            ->assertSessionHas('error', 'Ya tienes el pedido #500 en picking. Termínalo o libéralo antes de tomar otro.');
        $this->assertSame('RECIBIDO', Pedido::query()->find(600)->estado);
    }

    public function test_pantalla_de_picking_es_solo_de_quien_lo_tomo_y_ordena_por_ubicacion(): void
    {
        $this->tomado(500);

        $this->actingAs($this->otroOperario())->get('/picking/500')
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('error', 'No tienes asignado el pedido #500. Lo tiene Ana Operaria.');

        $this->actingAs($this->operario)->get('/picking/500')
            ->assertOk()
            ->assertSee('Pedido #500')
            ->assertViewHas('renglones', fn (array $renglones) => array_column($renglones, 'ubicacion') === ['A-01', 'B-02']);

        $this->assertSame('2026-09-15 10:00:00', (string) SidesPedidoOperacion::query()->find(500)->fecpicking2);
    }

    public function test_guardar_cantidad_valida_el_rango_y_la_clave_de_supervisor(): void
    {
        $this->tomado(500);

        $this->actingAs($this->operario)->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 4])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'La cantidad debe estar entre 0 y 3.']);

        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2])
            ->assertOk()
            ->assertJson(['cantdesp' => 2]);
        $this->assertSame(2, (int) SidesPedrenOperacion::query()->where('id_pedido', 500)->where('item', 1)->value('cantdesp'));

        DB::table('sides_cfg')->update(['activarValPicking' => 1, 'claveValPicking' => '9999']);

        $this->postJson('/picking/500/cantidad', ['item' => 2, 'cantidad' => 2, 'manual' => true, 'clave' => '1111'])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'La clave de autorización no es correcta.']);

        $this->postJson('/picking/500/cantidad', ['item' => 2, 'cantidad' => 2, 'manual' => true, 'clave' => '9999'])
            ->assertOk();
    }

    public function test_terminar_con_productos_sin_revisar_no_avanza(): void
    {
        $this->tomado(500);
        $this->actingAs($this->operario)->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 3]);

        $this->post('/picking/500/terminar')
            ->assertSessionHas('error', 'Quedan productos sin revisar. Revísalos todos antes de terminar.');

        $this->assertSame('PICKING', Pedido::query()->find(500)->estado);
    }

    public function test_terminar_pasa_a_packing_y_registra_tiempo_e_inactividad(): void
    {
        $this->tomado(500);
        // Picking anterior del mismo operario hoy, terminado a las 09:20.
        SidesLogpicking::query()->create([
            'id_pedido' => 400, 'usuario' => 'ana@example.com', 'numren' => 1, 'numund' => 1,
            'tiempo_picking' => 60, 'fecha_del_picking' => '2026-09-15 09:20:00', 'descripcion' => PickingService::LOG_COMPLETO,
        ]);

        Carbon::setTestNow('2026-09-15 09:30:00');
        $this->actingAs($this->operario)->get('/picking/500');

        Carbon::setTestNow('2026-09-15 10:00:00');
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 3]);
        $this->postJson('/picking/500/cantidad', ['item' => 2, 'cantidad' => 1]);

        $this->post('/picking/500/terminar')
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('mensaje', 'Pedido #500 enviado a packing.');

        $pedido = Pedido::query()->find(500);
        $this->assertSame('PACKING', $pedido->estado);
        $this->assertSame('2026-09-15 10:00:00', (string) $pedido->fecpacking);

        $log = SidesLogpicking::query()->where('id_pedido', 500)->sole();
        $this->assertSame([PickingService::LOG_COMPLETO, 2, 4, 1800], [$log->descripcion, (int) $log->numren, (int) $log->numund, (int) $log->tiempo_picking]);

        $inactividad = SidesLogInacPicking::query()->where('id_pedido', 500)->sole();
        $this->assertSame([600, 400], [(int) $inactividad->tiempo_picking_inac, (int) $inactividad->id_pedido_anterior]);

        // SEPED pedren no se toca cuando hay packing: lo escribe el cierre del packing.
        $this->assertSame(0, (int) DB::table('pedren')->where('id', 500)->where('item', 1)->value('cantdesp'));
    }

    public function test_terminar_sin_unidades_anula_el_pedido(): void
    {
        $this->tomado(500);
        $this->actingAs($this->operario)->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 0]);
        $this->postJson('/picking/500/cantidad', ['item' => 2, 'cantidad' => 0]);

        $this->post('/picking/500/terminar')
            ->assertSessionHas('mensaje', 'Pedido #500 anulado automáticamente: no tiene unidades para despachar.');

        $this->assertSame('ANULADO', Pedido::query()->find(500)->estado);
        $this->assertSame(0, SidesLogpicking::query()->count());
    }

    public function test_sin_packing_el_picking_cierra_y_escribe_el_despacho_en_seped(): void
    {
        DB::table('sides_cfg')->update(['activarPacking' => 0]);
        $this->crearPedido(['id' => 700, 'estado' => 'RECIBIDO']);
        $this->crearRenglon(700, 1, ['cantidad' => 3]);
        $this->crearRenglon(700, 2, ['cantidad' => 2]);
        $this->crearRenglon(700, 3, ['cantidad' => 5]);

        $this->actingAs($this->operario)->post('/picking/700/tomar', ['recipiente' => 'C1']);
        $this->postJson('/picking/700/cantidad', ['item' => 1, 'cantidad' => 3]);
        $this->postJson('/picking/700/cantidad', ['item' => 2, 'cantidad' => 1]);
        $this->postJson('/picking/700/cantidad', ['item' => 3, 'cantidad' => 0]);

        $this->post('/picking/700/terminar')->assertSessionHas('mensaje', 'Pedido #700 enviado a facturar.');

        $pedido = Pedido::query()->find(700);
        $this->assertSame(['PEND-FACTURA', '2026-09-15 10:00:00'], [$pedido->estado, (string) $pedido->feccompletado]);
        $this->assertSame(
            [[3, 'FACTURADO'], [1, 'PARCIAL'], [0, 'NO FACTURADO']],
            DB::table('pedren')->where('id', 700)->orderBy('item')->get(['cantdesp', 'estado_desp'])
                ->map(fn ($r) => [(int) $r->cantdesp, $r->estado_desp])->all()
        );
    }

    public function test_liberar_registra_parcial_y_el_cierre_posterior_descuenta_lo_parcial(): void
    {
        $this->tomado(500);
        Carbon::setTestNow('2026-09-15 09:00:00');
        $this->actingAs($this->operario)->get('/picking/500');
        Carbon::setTestNow('2026-09-15 09:10:00');
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 3]);

        $this->post('/picking/500/liberar')->assertRedirect(route('picking.index'));

        $operacion = SidesPedidoOperacion::query()->find(500);
        $this->assertSame(['', 0], [(string) $operacion->despachador, (int) $operacion->despasignado]);
        $parcial = SidesLogpicking::query()->where('descripcion', PickingService::LOG_PARCIAL)->sole();
        $this->assertSame([1, 3, 600], [(int) $parcial->numren, (int) $parcial->numund, (int) $parcial->tiempo_picking]);

        // Otro operario lo retoma y lo termina: su registro descuenta lo ya hecho.
        $beto = $this->otroOperario();
        $this->actingAs($beto)->post('/picking/500/tomar')->assertRedirect(route('picking.show', 500));
        $this->get('/picking/500');
        $this->postJson('/picking/500/cantidad', ['item' => 2, 'cantidad' => 2]);
        $this->post('/picking/500/terminar');

        $final = SidesLogpicking::query()->where('descripcion', PickingService::LOG_PARCIAL_FINAL)->sole();
        $this->assertSame(['beto@example.com', 1, 2], [$final->usuario, (int) $final->numren, (int) $final->numund]);
        // Retomar no reinicia el inicio del picking que ve el monitor.
        $this->assertSame('2026-09-15 10:00:00', (string) Pedido::query()->find(500)->fecpicking);
    }
}
