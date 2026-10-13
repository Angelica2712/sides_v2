<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesLogInacPicking;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/**
 * Pedidos que SEPED separó (pedido.idori): sides_cfg.unirPedidosPartidos los trabaja como uno
 * solo en Picking y Packing; sides_cfg.pickingSiguienteParte le da al operario la parte siguiente.
 */
class PedidosPartidosTest extends TestCase
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
        $this->operario = $this->crearUsuario(['name' => 'Ana Operaria', 'email' => 'ana@example.com', 'activarPicking' => 1, 'activarPacking' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activar(string $opcion): void
    {
        SidesCfg::query()->update([$opcion => 1]);
    }

    /** Pedido 500 partido en 500 (original), 501 y 502, más el 600 de otro cliente sin partir. */
    private function pedidoPartido(string $estado = 'RECIBIDO'): void
    {
        foreach ([500 => null, 501 => '500', 502 => '500'] as $id => $idori) {
            $this->crearPedido(['id' => $id, 'idori' => $idori, 'estado' => $estado, 'nomcli' => 'FARMACIA PARTIDA', 'numren' => 1, 'numund' => 2]);
            $this->crearRenglon($id, 1, ['codprod' => "P{$id}", 'barra' => "77{$id}", 'ubicacion' => 'A-0'.(503 - $id), 'cantidad' => 2]);
        }
        $this->crearPedido(['id' => 600, 'estado' => $estado, 'codcli' => 'C002', 'nomcli' => 'OTRA FARMACIA']);
        $this->crearRenglon(600, 1);
    }

    private function estados(): array
    {
        return Pedido::query()->orderBy('id')->pluck('estado', 'id')->all();
    }

    public function test_sin_la_opcion_cada_parte_se_toma_por_separado(): void
    {
        $this->pedidoPartido();

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);

        $this->assertSame([500 => 'PICKING', 501 => 'RECIBIDO', 502 => 'RECIBIDO', 600 => 'RECIBIDO'], $this->estados());
        $this->assertCount(4, $this->get('/picking')->viewData('pedidos'));
    }

    public function test_unidos_la_lista_de_picking_muestra_una_sola_fila_con_los_totales(): void
    {
        $this->activar('unirPedidosPartidos');
        $this->pedidoPartido();

        $filas = $this->actingAs($this->operario)->get('/picking')
            ->assertOk()
            ->assertSee('3 partes unidas: #500, #501, #502')
            ->viewData('pedidos');

        $this->assertSame([500, 600], $filas->pluck('id')->all());
        $this->assertSame([[500, 501, 502], 3, 6], [$filas[0]->unidos, (int) $filas[0]->numren, (int) $filas[0]->numund]);
    }

    public function test_unidos_tomar_una_parte_toma_todas_y_el_picking_muestra_todos_los_productos(): void
    {
        $this->activar('unirPedidosPartidos');
        $this->pedidoPartido();

        $this->actingAs($this->operario)->post('/picking/501/tomar', ['recipiente' => 'C5'])
            ->assertRedirect(route('picking.show', 501))
            ->assertSessionHas('mensaje', 'Tomaste el pedido #500 y sus partes #501 y #502.');

        $this->assertSame([500 => 'PICKING', 501 => 'PICKING', 502 => 'PICKING', 600 => 'RECIBIDO'], $this->estados());
        $this->assertSame(['C5'], SidesPedidoOperacion::query()->whereIn('id_pedido', [500, 501, 502])->pluck('recipiente')->unique()->all());

        // Un solo recorrido: los productos de las tres partes, en el orden de ubicación.
        $renglones = $this->get('/picking/501')->assertOk()->assertSee('3 partes unidas')->viewData('renglones');
        $this->assertSame(['502-1', '501-1', '500-1'], array_column($renglones, 'clave'));

        // Cada producto se guarda en su propio pedido.
        $this->postJson('/picking/502/cantidad', ['item' => 1, 'cantidad' => 2])->assertOk();
        $this->assertSame(2, (int) SidesPedrenOperacion::query()->where('id_pedido', 502)->value('cantdesp'));

        // Sigue sin poder tomar otro pedido distinto.
        $this->post('/picking/600/tomar', ['recipiente' => 'C6'])->assertSessionHas('error');
    }

    public function test_unidos_terminar_exige_todas_las_partes_y_las_pasa_juntas_a_packing(): void
    {
        $this->activar('unirPedidosPartidos');
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->get('/picking/500');
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);
        $this->postJson('/picking/501/cantidad', ['item' => 1, 'cantidad' => 1]);

        // Falta la parte 502: no avanza ninguna.
        $this->post('/picking/500/terminar')->assertSessionHas('error');
        $this->assertSame('PICKING', Pedido::query()->find(500)->estado);

        $this->postJson('/picking/502/cantidad', ['item' => 1, 'cantidad' => 2]);
        Carbon::setTestNow('2026-09-15 10:05:00');

        $this->post('/picking/500/terminar')
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('mensaje', 'Pedido #500 y sus partes #501 y #502 enviados a packing.');

        $this->assertSame([500 => 'PACKING', 501 => 'PACKING', 502 => 'PACKING', 600 => 'RECIBIDO'], $this->estados());

        // El tiempo de la sesión se registra una vez; las otras partes solo suman renglones y unidades.
        $logs = SidesLogpicking::query()->orderBy('id_pedido')->get();
        $this->assertSame([[500, 300, 2], [501, 0, 1], [502, 0, 2]], $logs->map(fn ($l) => [(int) $l->id_pedido, (int) $l->tiempo_picking, (int) $l->numund])->all());
        $this->assertSame(0, SidesLogInacPicking::query()->count());
    }

    public function test_unidos_liberar_suelta_todas_las_partes(): void
    {
        $this->activar('unirPedidosPartidos');
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);

        $this->post('/picking/502/liberar')->assertSessionHas('mensaje', 'Liberaste el pedido #500 y sus partes #501 y #502. Otro operario puede tomarlo.');

        $this->assertSame([0], SidesPedidoOperacion::query()->whereIn('id_pedido', [500, 501, 502])->pluck('despasignado')->map(fn ($v) => (int) $v)->unique()->all());
    }

    public function test_unidos_no_arrastra_partes_de_otro_operario_ni_de_un_lote(): void
    {
        $this->activar('unirPedidosPartidos');
        $this->pedidoPartido();
        $beto = $this->crearUsuario(['name' => 'Beto', 'email' => 'beto@example.com', 'activarPicking' => 1]);
        SidesPedidoOperacion::query()->create(['id_pedido' => 501, 'codisb' => '505094939', 'despachador' => 'Beto', 'despasignado' => 1, 'recipiente' => 'C1']);
        Pedido::query()->whereKey(501)->update(['estado' => 'PICKING']);
        DB::table('sides_alcabala_lote_pedido')->insert(['id_lote' => 9, 'numped' => 502, 'fecha_agregado' => now()]);

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5'])
            ->assertSessionHas('mensaje', 'Tomaste el pedido #500.');

        $this->assertSame('Beto', SidesPedidoOperacion::query()->find(501)->despachador);
        $this->assertSame('RECIBIDO', Pedido::query()->find(502)->estado);
        $this->assertNotNull($beto);
    }

    public function test_unidos_al_abrir_se_suman_las_partes_libres_que_faltaban(): void
    {
        // El operario empezó la parte 500 antes de que la droguería activara la opción.
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->activar('unirPedidosPartidos');

        $this->assertCount(3, $this->get('/picking/500')->assertOk()->viewData('renglones'));
        $this->assertSame([500 => 'PICKING', 501 => 'PICKING', 502 => 'PICKING', 600 => 'RECIBIDO'], $this->estados());
        $this->assertSame(['C5'], SidesPedidoOperacion::query()->whereIn('id_pedido', [500, 501, 502])->pluck('recipiente')->unique()->all());

        // Abrir un pedido que no es suyo no toma nada.
        $beto = $this->crearUsuario(['name' => 'Beto', 'email' => 'beto@example.com', 'activarPicking' => 1]);
        $this->actingAs($beto)->get('/picking/600')->assertRedirect(route('picking.index'));
        $this->assertSame('RECIBIDO', Pedido::query()->find(600)->estado);
    }

    public function test_siguiente_parte_se_entrega_sola_con_el_mismo_recipiente(): void
    {
        $this->activar('pickingSiguienteParte');
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->get('/picking/500');
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);

        $this->post('/picking/500/terminar')
            ->assertRedirect(route('picking.show', 501))
            ->assertSessionHas('mensaje', 'Pedido #500 enviado a packing. Sigues con el pedido #501, otra parte del mismo pedido.');

        $this->assertSame([500 => 'PACKING', 501 => 'PICKING', 502 => 'RECIBIDO', 600 => 'RECIBIDO'], $this->estados());
        $siguiente = SidesPedidoOperacion::query()->find(501);
        $this->assertSame(['Ana Operaria', 1, 'C5'], [$siguiente->despachador, (int) $siguiente->despasignado, $siguiente->recipiente]);
    }

    public function test_siguiente_parte_salta_las_que_ya_tiene_otro_y_al_acabar_vuelve_a_la_lista(): void
    {
        $this->activar('pickingSiguienteParte');
        $this->pedidoPartido();
        SidesPedidoOperacion::query()->create(['id_pedido' => 501, 'codisb' => '505094939', 'despachador' => 'Beto', 'despasignado' => 1, 'recipiente' => 'C1']);
        Pedido::query()->whereKey(501)->update(['estado' => 'PICKING']);

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);
        $this->post('/picking/500/terminar')->assertRedirect(route('picking.show', 502));

        $this->postJson('/picking/502/cantidad', ['item' => 1, 'cantidad' => 2]);
        $this->post('/picking/502/terminar')
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('mensaje', 'Pedido #502 enviado a packing.');
    }

    public function test_sin_la_opcion_terminar_una_parte_vuelve_a_la_lista(): void
    {
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);

        $this->post('/picking/500/terminar')->assertRedirect(route('picking.index'));
        $this->assertSame('RECIBIDO', Pedido::query()->find(501)->estado);
    }

    /** Las tres partes en PACKING, cada una con su producto ya pickeado (2 unidades). */
    private function partidoEnPacking(): void
    {
        $this->pedidoPartido('PACKING');
        foreach ([500, 501, 502, 600] as $id) {
            SidesPedidoOperacion::query()->create(['id_pedido' => $id, 'codisb' => '505094939', 'despachador' => 'Pedro Picker', 'recipiente' => 'C9']);
            SidesPedrenOperacion::query()->insert(['id_pedido' => $id, 'item' => 1, 'codisb' => '505094939', 'cantdesp' => 2, 'chequeado' => 0]);
        }
    }

    public function test_unidos_packing_abre_verifica_y_termina_las_partes_juntas(): void
    {
        $this->activar('unirPedidosPartidos');
        $this->partidoEnPacking();

        $filas = $this->actingAs($this->operario)->get('/packing')->assertOk()->assertSee('3 partes unidas')->viewData('pedidos');
        $this->assertSame([500, 600], $filas->pluck('id')->all());

        $renglones = $this->get('/packing/500')->assertOk()->viewData('renglones');
        $this->assertSame(['502-1', '501-1', '500-1'], array_column($renglones, 'clave'));
        $this->assertSame(['Ana Operaria'], SidesPedidoOperacion::query()->whereIn('id_pedido', [500, 501, 502])->pluck('embalador')->unique()->all());
        $this->assertEmpty(SidesPedidoOperacion::query()->find(600)->embalador);

        $datos = ['cantBultos' => '4', 'cestas' => '12'];
        $this->postJson('/packing/500/escanear', ['item' => 1, 'unidades' => 2])->assertOk();
        $this->postJson('/packing/501/escanear', ['item' => 1, 'unidades' => 2])->assertOk();

        // Falta verificar la parte 502: no se factura ninguna.
        $this->post('/packing/500/terminar', $datos)->assertSessionHas('error');
        $this->assertSame('PACKING', Pedido::query()->find(500)->estado);

        $this->postJson('/packing/502/escanear', ['item' => 1, 'unidades' => 2])->assertOk();
        Carbon::setTestNow('2026-09-15 10:02:00');
        $this->post('/packing/500/terminar', $datos)->assertRedirect(route('packing.index'));

        $this->assertSame([500 => 'PEND-FACTURA', 501 => 'PEND-FACTURA', 502 => 'PEND-FACTURA', 600 => 'PACKING'], $this->estados());
        // Los bultos se anotan una vez; SEPED recibe el despacho de cada parte.
        $this->assertSame([500 => '4', 501 => '0', 502 => '0'], SidesPedidoOperacion::query()->whereIn('id_pedido', [500, 501, 502])->orderBy('id_pedido')->pluck('cantBultos', 'id_pedido')->all());
        $this->assertSame([2, 2, 2], DB::table('pedren')->whereIn('id', [500, 501, 502])->orderBy('id')->pluck('cantdesp')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([120, 0, 0], SidesLogpacking::query()->orderBy('id_pedido')->pluck('tiempo_packing')->map(fn ($v) => (int) $v)->all());
    }

    public function test_configuracion_guarda_las_dos_opciones(): void
    {
        $encargado = $this->crearUsuario(['name' => 'Eva', 'email' => 'eva@example.com'] + $this->todosLosPermisos());

        $this->actingAs($encargado)->get('/configuracion')->assertOk()
            ->assertSee('Trabajar las partes como un solo pedido')
            ->assertSee('Dar la siguiente parte al mismo operario');

        $this->put('/configuracion', ['TamLetraMonitor' => 12, 'ordenPedSides' => 'UBICACION', 'unirPedidosPartidos' => 1, 'pickingSiguienteParte' => 1])
            ->assertSessionHasNoErrors();

        $cfg = SidesCfg::query()->first();
        $this->assertSame([1, 1], [(int) $cfg->unirPedidosPartidos, (int) $cfg->pickingSiguienteParte]);
    }
}
