<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesAlcabalaLote;
use App\Models\Sides\SidesAlcabalaLotePedido;
use App\Models\Sides\SidesAlcabalaPedidoLiberado;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class BatchPickingTest extends TestCase
{
    use TablasSides;

    private SidesUsers $encargado;

    private SidesUsers $operario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->crearCfg(['codisb' => '404310975', 'nombre' => 'DROGUERIA EL MASTRANTO', 'procAlcabalaPicking' => 1, 'ordenPedSides' => 'UBICACION']);
        $this->encargado = $this->crearUsuario(['name' => 'Enca', 'email' => 'enca@example.com', 'codisb' => '404310975', 'activarPicking' => 1, 'activarLiberarAlcabala' => 1]);
        $this->operario = $this->crearUsuario(['name' => 'Oscar', 'email' => 'oscar@example.com', 'codisb' => '404310975', 'activarPicking' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param list<array{0: int, 1: string, 2: int, 3?: string}> $renglones [item, codprod, cantidad, lote] */
    private function enEspera(int $id, string $fecha, array $renglones): void
    {
        $this->crearPedido(['id' => $id, 'estado' => 'ALCABALA', 'codisb' => '404310975', 'fecha' => $fecha, 'nomcli' => "CLIENTE {$id}"]);
        foreach ($renglones as $renglon) {
            $this->crearRenglon($id, $renglon[0], [
                'codprod' => $renglon[1], 'barra' => "B{$renglon[1]}", 'cantidad' => $renglon[2],
                'lote' => $renglon[3] ?? 'L1', 'ubicacion' => "U-{$renglon[1]}", 'codisb' => '404310975',
            ]);
        }
    }

    /** Dos pedidos que comparten el producto P1: el 11 (más antiguo) pide 3 y el 12 pide 4. */
    private function loteIniciado(): int
    {
        $this->enEspera(11, '2026-09-15 07:00:00', [[1, 'P1', 3], [2, 'P2', 1]]);
        $this->enEspera(12, '2026-09-15 08:00:00', [[3, 'P1', 4]]);
        $this->actingAs($this->encargado)->post('/batch-picking/agrupar', ['pedidos' => [11, 12]]);
        $lote = SidesAlcabalaLote::query()->sole()->id;
        $this->actingAs($this->operario)->post("/batch-picking/lotes/{$lote}/iniciar", ['recipiente' => 'CAJA-7']);

        return $lote;
    }

    public function test_lista_pedidos_en_espera_y_recibidos_sin_tomar_y_lotes_en_curso(): void
    {
        $this->enEspera(11, '2026-09-15 07:00:00', [[1, 'P1', 3]]);
        $this->enEspera(12, '2026-09-15 08:00:00', [[2, 'P2', 1]]);
        $this->crearPedido(['id' => 13, 'estado' => 'RECIBIDO', 'codisb' => '404310975', 'nomcli' => 'CLIENTE RECIBIDO']);
        $this->crearPedido(['id' => 14, 'estado' => 'RECIBIDO', 'codisb' => '404310975', 'nomcli' => 'CLIENTE TOMADO'], ['despachador' => 'Otro', 'despasignado' => 1]);
        $this->crearPedido(['id' => 15, 'estado' => 'PICKING', 'codisb' => '404310975', 'nomcli' => 'CLIENTE EN PICKING']);
        $this->actingAs($this->encargado)->post('/batch-picking/agrupar', ['pedidos' => [12]]);

        $this->get('/batch-picking')
            ->assertOk()
            ->assertViewHas('pedidos', fn ($pedidos) => $pedidos->pluck('id')->all() === [11, 13])
            ->assertSee('Lote #')
            ->assertSee('CLIENTE RECIBIDO')
            ->assertDontSee('CLIENTE TOMADO')
            ->assertDontSee('CLIENTE EN PICKING')
            ->assertSee('Liberar al picking normal');
    }

    public function test_liberar_requiere_permiso_y_pasa_los_pedidos_a_recibido(): void
    {
        $this->enEspera(11, '2026-09-15 07:00:00', [[1, 'P1', 3]]);

        $this->actingAs($this->operario)->post('/batch-picking/liberar', ['pedidos' => [11]])
            ->assertSessionHas('error', 'No tienes permiso para liberar pedidos.');
        $this->assertSame('ALCABALA', Pedido::query()->find(11)->estado);

        $this->crearPedido(['id' => 13, 'estado' => 'RECIBIDO', 'codisb' => '404310975']);
        $this->actingAs($this->encargado)->post('/batch-picking/liberar', ['pedidos' => [13]])
            ->assertSessionHas('error', 'Los pedidos seleccionados no están en espera: los recibidos ya están disponibles en el picking normal.');

        $this->post('/batch-picking/liberar', ['pedidos' => [11, 13]])
            ->assertSessionHas('mensaje', '1 pedido liberado al picking normal.');
        $this->assertSame('RECIBIDO', Pedido::query()->find(11)->estado);
        $this->assertSame('Enca', SidesAlcabalaPedidoLiberado::query()->where('numped', 11)->value('liberado_por'));
    }

    public function test_agrupar_crea_el_lote_y_rechaza_pedidos_ya_agrupados_o_fuera_de_espera(): void
    {
        $this->enEspera(11, '2026-09-15 07:00:00', [[1, 'P1', 3]]);
        $this->enEspera(12, '2026-09-15 08:00:00', [[2, 'P2', 1]]);
        $this->crearPedido(['id' => 13, 'estado' => 'PICKING', 'codisb' => '404310975']);

        $this->actingAs($this->encargado)->post('/batch-picking/agrupar', ['pedidos' => [11, 12]])
            ->assertSessionHas('mensaje', fn ($mensaje) => str_contains($mensaje, 'creado con 2 pedidos'));

        $lote = SidesAlcabalaLote::query()->sole();
        $this->assertSame(['ABIERTO', 'MANUAL', 'Enca'], [$lote->estado, $lote->origen_creacion, $lote->creado_por]);
        $this->assertSame([11, 12], SidesAlcabalaLotePedido::query()->orderBy('numped')->pluck('numped')->map(fn ($id) => (int) $id)->all());

        $this->post('/batch-picking/agrupar', ['pedidos' => [11]])
            ->assertSessionHas('error', 'Los pedidos #11 ya pertenecen a otro lote.');
        $this->post('/batch-picking/agrupar', ['pedidos' => [13]])
            ->assertSessionHas('error', 'Los pedidos #13 ya no están disponibles para agrupar (los tomó un operario o cambiaron de estado).');
    }

    public function test_anular_lote_abierto_devuelve_los_pedidos_a_espera(): void
    {
        $this->enEspera(11, '2026-09-15 07:00:00', [[1, 'P1', 3]]);
        $this->actingAs($this->encargado)->post('/batch-picking/agrupar', ['pedidos' => [11]]);
        $lote = SidesAlcabalaLote::query()->sole();

        $this->post("/batch-picking/lotes/{$lote->id}/anular")->assertRedirect(route('batch.index'));

        $this->assertSame('ANULADO', $lote->fresh()->estado);
        $this->assertSame(0, SidesAlcabalaLotePedido::query()->count());
        $this->get('/batch-picking')->assertViewHas('pedidos', fn ($pedidos) => $pedidos->pluck('id')->all() === [11]);
    }

    public function test_iniciar_pone_todos_los_pedidos_en_picking_con_el_mismo_recipiente(): void
    {
        $lote = $this->loteIniciado();

        $this->assertSame('CONFIRMADO', SidesAlcabalaLote::query()->find($lote)->estado);
        foreach ([11, 12] as $pedidoId) {
            $this->assertSame('PICKING', Pedido::query()->find($pedidoId)->estado);
            $operacion = SidesPedidoOperacion::query()->find($pedidoId);
            $this->assertSame(['Oscar', 'CAJA-7', 1], [$operacion->despachador, $operacion->recipiente, (int) $operacion->despasignado]);
        }
        $this->assertSame(3, SidesPedrenOperacion::query()->where('cantdesp', -1)->count());

        // No aparecen como pedidos sueltos ni se pueden tomar uno por uno.
        $this->actingAs($this->operario)->get('/picking')
            ->assertViewHas('pedidos', fn ($pedidos) => $pedidos->isEmpty())
            ->assertSee("Lote #{$lote}");
        $this->post('/picking/11/tomar', ['recipiente' => 'X'])
            ->assertSessionHas('error', "El pedido #11 pertenece al lote #{$lote} de Batch Picking. Trabájalo desde Batch Picking.");
    }

    public function test_iniciar_exige_recipiente_y_que_el_operario_no_tenga_otro_trabajo(): void
    {
        $this->enEspera(11, '2026-09-15 07:00:00', [[1, 'P1', 3]]);
        $this->actingAs($this->encargado)->post('/batch-picking/agrupar', ['pedidos' => [11]]);
        $lote = SidesAlcabalaLote::query()->sole()->id;

        $this->actingAs($this->operario)->post("/batch-picking/lotes/{$lote}/iniciar", ['recipiente' => ''])
            ->assertSessionHas('error', 'Escribe el código del recipiente del lote.');

        $this->crearPedido(['id' => 20, 'estado' => 'RECIBIDO', 'codisb' => '404310975']);
        $this->crearRenglon(20, 9, ['codisb' => '404310975']);
        $this->post('/picking/20/tomar', ['recipiente' => 'C1']);

        $this->post("/batch-picking/lotes/{$lote}/iniciar", ['recipiente' => 'CAJA-1'])
            ->assertSessionHas('error', 'Ya tienes el pedido #20 en picking. Termínalo o libéralo antes de iniciar un lote.');
        $this->assertSame('ALCABALA', Pedido::query()->find(11)->estado);
    }

    public function test_productos_se_suman_por_codigo_y_lote_y_la_cantidad_se_reparte_fifo(): void
    {
        $lote = $this->loteIniciado();

        $this->actingAs($this->operario)->get("/batch-picking/lotes/{$lote}/picking")
            ->assertOk()
            ->assertViewHas('productos', fn (array $productos) => array_map(fn ($p) => [$p['clave'], $p['requerido'], $p['tocado']], $productos) === [
                ['P1|L1', 7, false],
                ['P2|L1', 1, false],
            ]);

        // Faltan 2 unidades de P1: se completa el pedido 11 (más antiguo) y al 12 le tocan 2 de 4.
        $this->postJson("/batch-picking/lotes/{$lote}/cantidad", ['producto' => 'P1|L1', 'cantidad' => 5])
            ->assertOk()
            ->assertJson(['requerido' => 7, 'pickeado' => 5, 'tocado' => true, 'reparto' => [
                ['numped' => 11, 'cantidad' => 3, 'asignado' => 3],
                ['numped' => 12, 'cantidad' => 4, 'asignado' => 2],
            ]]);

        $this->postJson("/batch-picking/lotes/{$lote}/cantidad", ['producto' => 'P1|L1', 'cantidad' => 8])
            ->assertStatus(422)
            ->assertJson(['mensaje' => 'La cantidad debe estar entre 0 y 7.']);

        $this->actingAs($this->encargado)->postJson("/batch-picking/lotes/{$lote}/cantidad", ['producto' => 'P2|L1', 'cantidad' => 1])
            ->assertStatus(422)
            ->assertJson(['mensaje' => "No tienes asignado el lote #{$lote}. Lo está trabajando Oscar."]);
    }

    public function test_terminar_exige_todo_marcado_y_cierra_cada_pedido(): void
    {
        $lote = $this->loteIniciado();
        $this->actingAs($this->operario)->postJson("/batch-picking/lotes/{$lote}/cantidad", ['producto' => 'P1|L1', 'cantidad' => 3]);

        $this->post("/batch-picking/lotes/{$lote}/terminar")
            ->assertSessionHas('error', 'Falta 1 producto por marcar antes de terminar.');

        // P2 solo lo pide el 11; el 12 se queda sin unidades de P1 (las 3 fueron al 11) y se anula.
        $this->postJson("/batch-picking/lotes/{$lote}/cantidad", ['producto' => 'P2|L1', 'cantidad' => 1]);

        $this->post("/batch-picking/lotes/{$lote}/terminar")
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('mensaje', "Lote #{$lote} terminado: 1 a packing, 1 anulados por no tener unidades.");

        $this->assertSame(['PACKING', 'ANULADO'], [Pedido::query()->find(11)->estado, Pedido::query()->find(12)->estado]);
        $lote = SidesAlcabalaLote::query()->find($lote);
        $this->assertSame(['TERMINADO', '2026-09-15 10:00:00'], [$lote->estado, (string) $lote->fecha_terminacion]);
    }

    public function test_sucursal_sin_pedidos_en_espera_agrupa_y_pickea_pedidos_recibidos(): void
    {
        DB::table('sides_cfg')->update(['procAlcabalaPicking' => 0]);
        $this->crearPedido(['id' => 31, 'estado' => 'RECIBIDO', 'codisb' => '404310975', 'fecha' => '2026-09-15 07:00:00']);
        $this->crearPedido(['id' => 32, 'estado' => 'RECIBIDO', 'codisb' => '404310975', 'fecha' => '2026-09-15 08:00:00']);
        $this->crearRenglon(31, 1, ['codprod' => 'P1', 'cantidad' => 2, 'lote' => 'L1', 'codisb' => '404310975']);
        $this->crearRenglon(32, 2, ['codprod' => 'P1', 'cantidad' => 2, 'lote' => 'L1', 'codisb' => '404310975']);

        $this->actingAs($this->encargado)->post('/batch-picking/agrupar', ['pedidos' => [31, 32]]);
        $lote = SidesAlcabalaLote::query()->sole()->id;

        // Agrupados ya no aparecen en el picking normal.
        $this->get('/picking')->assertViewHas('pedidos', fn ($pedidos) => $pedidos->isEmpty());

        $this->actingAs($this->operario)->post("/batch-picking/lotes/{$lote}/iniciar", ['recipiente' => 'CAJA-3'])
            ->assertRedirect(route('batch.picking', $lote));
        $this->postJson("/batch-picking/lotes/{$lote}/cantidad", ['producto' => 'P1|L1', 'cantidad' => 4])->assertOk();
        $this->post("/batch-picking/lotes/{$lote}/terminar")->assertRedirect(route('picking.index'));

        $this->assertSame(['PACKING', 'PACKING'], [Pedido::query()->find(31)->estado, Pedido::query()->find(32)->estado]);
    }
}
