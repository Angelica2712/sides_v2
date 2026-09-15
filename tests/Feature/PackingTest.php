<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesEtiquetaPedido;
use App\Models\Sides\SidesLogInacPacking;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class PackingTest extends TestCase
{
    use TablasSides;

    private SidesUsers $empacador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->crearCfg();
        $this->empacador = $this->crearUsuario(['name' => 'Eva Empaca', 'email' => 'eva@example.com', 'activarPacking' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Pedido que terminó picking: item 1 despacha 3 de 3, item 2 despacha 1 de 2. */
    private function pedidoEnPacking(int $id = 800, array $renglon1 = []): void
    {
        $this->crearPedido(['id' => $id, 'estado' => 'PACKING', 'nomcli' => "CLIENTE {$id}"], [
            'despachador' => 'Pedro Picker',
            'recipiente' => 'C9',
        ]);
        $this->crearRenglon($id, 1, array_merge(['cantidad' => 3, 'barra' => '0771'], $renglon1));
        $this->crearRenglon($id, 2, ['cantidad' => 2, 'barra' => '772']);
        SidesPedrenOperacion::query()->insert([
            ['id_pedido' => $id, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 3, 'chequeado' => 0],
            ['id_pedido' => $id, 'item' => 2, 'codisb' => '505094939', 'cantdesp' => 1, 'chequeado' => 0],
        ]);
    }

    private function otroEmpacador(): SidesUsers
    {
        return $this->crearUsuario(['name' => 'Beto', 'email' => 'beto@example.com', 'activarPacking' => 1]);
    }

    private function verificarTodo(int $id = 800): void
    {
        foreach ([1, 1, 1, 2] as $item) {
            $this->postJson("/packing/{$id}/escanear", ['item' => $item])->assertOk();
        }
    }

    private function datosTerminar(): array
    {
        return ['cantBultos' => '2', 'despachador' => 'Pedro Picker', 'embalador' => 'Eva Empaca', 'cestas' => '12, 14'];
    }

    public function test_lista_muestra_solo_pedidos_en_packing_de_la_sucursal(): void
    {
        $this->pedidoEnPacking(800);
        $this->crearPedido(['id' => 801, 'estado' => 'PICKING', 'nomcli' => 'CLIENTE EN PICKING']);
        $this->crearPedido(['id' => 802, 'estado' => 'PACKING', 'nomcli' => 'CLIENTE OTRA SUCURSAL', 'codisb' => '999']);

        $this->actingAs($this->empacador)->get('/packing')
            ->assertOk()
            ->assertSee('CLIENTE 800')
            ->assertSee('Empacar')
            ->assertDontSee('CLIENTE EN PICKING')
            ->assertDontSee('CLIENTE OTRA SUCURSAL');
    }

    public function test_abrir_deja_el_pedido_a_nombre_del_empacador_y_bloquea_a_otros(): void
    {
        $this->pedidoEnPacking();

        $this->actingAs($this->empacador)->get('/packing/800')
            ->assertOk()
            ->assertSee('Pedido #800')
            ->assertViewHas('renglones', fn (array $renglones) => count($renglones) === 2 && $renglones[0]['cantdesp'] === 3);

        $operacion = SidesPedidoOperacion::query()->find(800);
        $this->assertSame(['Eva Empaca', '2026-09-15 10:00:00'], [$operacion->embalador, (string) $operacion->fecpacking2]);

        $this->actingAs($this->otroEmpacador())->get('/packing/800')
            ->assertRedirect(route('packing.index'))
            ->assertSessionHas('error', 'El pedido #800 lo está empacando Eva Empaca.');
    }

    public function test_escanear_suma_de_a_una_unidad_sin_pasar_lo_despachado(): void
    {
        $this->pedidoEnPacking();
        $this->actingAs($this->empacador)->get('/packing/800');

        $this->postJson('/packing/800/escanear', ['item' => 2])->assertOk()->assertJson(['chequeado' => 1, 'cantdesp' => 1]);
        $this->postJson('/packing/800/escanear', ['item' => 2])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'Ese producto ya está verificado completo (1).']);

        $this->assertSame(1, (int) SidesPedrenOperacion::query()->where('id_pedido', 800)->where('item', 2)->value('chequeado'));

        $this->actingAs($this->otroEmpacador())->postJson('/packing/800/escanear', ['item' => 1])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'No tienes abierto el pedido #800. Lo está empacando Eva Empaca.']);
    }

    public function test_escanear_varias_unidades_de_una_vez_sin_pasar_lo_que_falta(): void
    {
        $this->pedidoEnPacking();
        $this->actingAs($this->empacador)->get('/packing/800');

        $this->postJson('/packing/800/escanear', ['item' => 1, 'unidades' => 2])->assertOk()->assertJson(['chequeado' => 2, 'cantdesp' => 3]);

        $this->postJson('/packing/800/escanear', ['item' => 1, 'unidades' => 5])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'Solo falta 1 unidad de ese producto.']);
        $this->assertSame(2, (int) SidesPedrenOperacion::query()->where('id_pedido', 800)->where('item', 1)->value('chequeado'));

        $this->postJson('/packing/800/escanear', ['item' => 1, 'unidades' => 0])->assertStatus(422);
        $this->postJson('/packing/800/escanear', ['item' => 1, 'unidades' => 1])->assertOk()->assertJson(['chequeado' => 3]);
    }

    public function test_ajustar_fija_cantidad_verificada_y_exige_clave_si_la_sucursal_lo_pide(): void
    {
        $this->pedidoEnPacking();
        $this->actingAs($this->empacador)->get('/packing/800');

        $this->postJson('/packing/800/ajustar', ['item' => 2, 'cantidad' => 3])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'La cantidad debe estar entre 0 y 2.']);

        $this->postJson('/packing/800/ajustar', ['item' => 2, 'cantidad' => 2])->assertOk()->assertJson(['cantdesp' => 2]);
        $renglon = SidesPedrenOperacion::query()->where('id_pedido', 800)->where('item', 2)->first();
        $this->assertSame([2, 2], [(int) $renglon->cantdesp, (int) $renglon->chequeado]);

        DB::table('sides_cfg')->update(['activarValPacking' => 1, 'claveValPicking' => '4321']);

        $this->postJson('/packing/800/ajustar', ['item' => 1, 'cantidad' => 1, 'clave' => 'mala'])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'La clave de autorización no es correcta.']);
        $this->postJson('/packing/800/ajustar', ['item' => 1, 'cantidad' => 1, 'clave' => '4321'])->assertOk();
    }

    public function test_clave_de_supervisor_para_desbloquear(): void
    {
        $this->pedidoEnPacking();
        DB::table('sides_cfg')->update(['claveValPicking' => '4321']);
        $this->actingAs($this->empacador)->get('/packing/800');

        $this->postJson('/packing/800/clave', ['clave' => '0000'])->assertStatus(422);
        $this->postJson('/packing/800/clave', ['clave' => '4321'])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_cambiar_lote_valida_la_opcion_y_la_existencia_del_lote(): void
    {
        $this->pedidoEnPacking(800, ['listalote' => 'L1_2028-01-01_2__A_P1;L2_2029-06-01 00:00:00_50_B_P1;']);
        $this->actingAs($this->empacador)->get('/packing/800');

        $this->postJson('/packing/800/lote', ['item' => 1, 'lote' => 'NO-EXISTE'])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'Ese lote no está disponible para el producto.']);

        $this->postJson('/packing/800/lote', ['item' => 1, 'lote' => 'L1_2028-01-01_2__A_P1'])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'La cantidad a despachar (3) supera la existencia del lote L1 (2).']);

        $this->postJson('/packing/800/lote', ['item' => 1, 'lote' => 'L2_2029-06-01 00:00:00_50_B_P1'])
            ->assertOk()
            ->assertJson(['lote' => 'L2', 'vence' => '2029-06-01', 'deposito' => 'B']);

        $renglon = SidesPedrenOperacion::query()->where('id_pedido', 800)->where('item', 1)->first();
        $this->assertSame(['L2', '2029-06-01', 'B'], [$renglon->lote, $renglon->feclote, $renglon->deposito]);
    }

    public function test_terminar_con_unidades_sin_verificar_no_avanza(): void
    {
        $this->pedidoEnPacking();
        $this->actingAs($this->empacador)->get('/packing/800');
        $this->postJson('/packing/800/escanear', ['item' => 1]);

        $this->post('/packing/800/terminar', $this->datosTerminar())
            ->assertSessionHas('error', 'Faltan 2 productos por verificar.');

        $this->assertSame('PACKING', Pedido::query()->find(800)->estado);
    }

    public function test_terminar_envia_a_facturar_escribe_despacho_en_seped_y_registra_tiempos(): void
    {
        $this->pedidoEnPacking();
        SidesLogpacking::query()->create([
            'id_pedido' => 700, 'usuario' => 'eva@example.com', 'numren' => 1, 'numund' => 1,
            'tiempo_packing' => 30, 'fecha_del_packing' => '2026-09-15 09:25:00',
        ]);

        Carbon::setTestNow('2026-09-15 09:30:00');
        $this->actingAs($this->empacador)->get('/packing/800');
        Carbon::setTestNow('2026-09-15 09:45:00');
        $this->verificarTodo();

        $this->post('/packing/800/terminar', $this->datosTerminar())
            ->assertRedirect(route('packing.index'))
            ->assertSessionHas('mensaje', 'Pedido #800 enviado a facturar.');

        $pedido = Pedido::query()->find(800);
        $this->assertSame(['PEND-FACTURA', '2026-09-15 09:45:00'], [$pedido->estado, (string) $pedido->feccompletado]);

        $this->assertSame(
            [[3, 'FACTURADO'], [1, 'PARCIAL']],
            DB::table('pedren')->where('id', 800)->orderBy('item')->get(['cantdesp', 'estado_desp'])
                ->map(fn ($r) => [(int) $r->cantdesp, $r->estado_desp])->all()
        );

        $operacion = SidesPedidoOperacion::query()->find(800);
        $this->assertSame(['2', '12, 14', 'Eva Empaca', 'Pedro Picker'], [$operacion->cantBultos, $operacion->num_cesta_ped, $operacion->embalador, $operacion->despachador]);
        $this->assertSame(2, SidesPedrenOperacion::query()->where('id_pedido', 800)->where('packing', 1)->count());

        $log = SidesLogpacking::query()->where('id_pedido', 800)->sole();
        $this->assertSame([2, 4, 900], [(int) $log->numren, (int) $log->numund, (int) $log->tiempo_packing]);

        $inactividad = SidesLogInacPacking::query()->where('id_pedido', 800)->sole();
        $this->assertSame([300, 700], [(int) $inactividad->tiempo_packing_inac, (int) $inactividad->id_pedido_anterior]);

        // Un segundo envío (doble clic) no vuelve a cerrar el pedido.
        $this->post('/packing/800/terminar', $this->datosTerminar())
            ->assertSessionHas('error', 'El pedido #800 ya fue enviado a facturar.');
        $this->assertSame(1, SidesLogpacking::query()->where('id_pedido', 800)->count());
    }

    public function test_terminar_abre_las_etiquetas_si_la_drogueria_lo_pide(): void
    {
        SidesCfg::query()->whereKey('505094939')->update(['activar_etiqueta_packing' => 1]);
        $this->pedidoEnPacking();
        $this->actingAs($this->empacador)->get('/packing/800');
        $this->verificarTodo();

        $this->post('/packing/800/terminar', $this->datosTerminar())
            ->assertRedirect(route('etiquetas.imprimir', ['pedido' => 800, 'imprimir' => 1, 'volver' => 'packing']))
            ->assertSessionHas('mensaje', 'Pedido #800 enviado a facturar.');

        $this->assertSame(['800-01', '800-02'], SidesEtiquetaPedido::query()->orderBy('etiqueta')->pluck('etiqueta')->all());
    }

    public function test_terminar_sin_unidades_anula_el_pedido(): void
    {
        $this->pedidoEnPacking();
        SidesPedrenOperacion::query()->where('id_pedido', 800)->update(['cantdesp' => 0]);
        $this->actingAs($this->empacador)->get('/packing/800');

        $this->post('/packing/800/terminar', $this->datosTerminar())
            ->assertSessionHas('mensaje', 'Pedido #800 anulado automáticamente: no tiene unidades para despachar.');

        $this->assertSame('ANULADO', Pedido::query()->find(800)->estado);
    }

    public function test_solo_quien_lo_abrio_puede_liberarlo(): void
    {
        $this->pedidoEnPacking();
        $this->actingAs($this->empacador)->get('/packing/800');

        $this->actingAs($this->otroEmpacador())->post('/packing/800/liberar')
            ->assertSessionHas('error', 'No tienes abierto el pedido #800. Lo está empacando Eva Empaca.');

        $this->actingAs($this->empacador)->post('/packing/800/liberar')->assertRedirect(route('packing.index'));
        $this->assertSame('', (string) SidesPedidoOperacion::query()->find(800)->embalador);
    }
}
