<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/**
 * Pedidos que SEPED separó (pedido.idori): cada parte se trabaja por separado;
 * sides_cfg.pickingSiguienteParte le da al operario la parte siguiente.
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

    public function test_siguiente_parte_se_pregunta_y_al_aceptar_se_toma_con_el_mismo_recipiente(): void
    {
        $this->activar('pickingSiguienteParte');
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->get('/picking/500');
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);

        $this->post('/picking/500/terminar')
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('mensaje', 'Pedido #500 enviado a packing.')
            ->assertSessionHas('siguienteParte', ['pedido' => 501, 'anterior' => 500, 'recipiente' => 'C5']);

        // Todavía no se tomó: solo se pregunta.
        $this->assertSame([500 => 'PACKING', 501 => 'RECIBIDO', 502 => 'RECIBIDO', 600 => 'RECIBIDO'], $this->estados());
        $this->get('/picking')->assertOk()->assertSee('¿Sigues con el pedido #501?')->assertSee('No, dejarlo libre');

        // Sí, seguir.
        $this->post('/picking/501/tomar', ['recipiente' => 'C5'])->assertRedirect(route('picking.show', 501));
        $siguiente = SidesPedidoOperacion::query()->find(501);
        $this->assertSame(['Ana Operaria', 1, 'C5'], [$siguiente->despachador, (int) $siguiente->despasignado, $siguiente->recipiente]);
    }

    public function test_siguiente_parte_si_dice_que_no_queda_libre(): void
    {
        $this->activar('pickingSiguienteParte');
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);
        $this->post('/picking/500/terminar');

        // "No": no se envía nada; la pregunta no vuelve y la parte la puede tomar otro.
        $this->get('/picking')->assertSee('¿Sigues con el pedido #501?');
        $this->get('/picking')->assertDontSee('¿Sigues con el pedido #501?');

        $beto = $this->crearUsuario(['name' => 'Beto', 'email' => 'beto@example.com', 'activarPicking' => 1]);
        $this->actingAs($beto)->post('/picking/501/tomar', ['recipiente' => 'C7'])->assertRedirect(route('picking.show', 501));
        $this->assertSame('Beto', SidesPedidoOperacion::query()->find(501)->despachador);
    }

    public function test_siguiente_parte_salta_las_que_ya_tiene_otro_y_al_acabar_no_pregunta(): void
    {
        $this->activar('pickingSiguienteParte');
        $this->pedidoPartido();
        SidesPedidoOperacion::query()->create(['id_pedido' => 501, 'codisb' => '505094939', 'despachador' => 'Beto', 'despasignado' => 1, 'recipiente' => 'C1']);
        Pedido::query()->whereKey(501)->update(['estado' => 'PICKING']);

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);
        $this->post('/picking/500/terminar')->assertSessionHas('siguienteParte.pedido', 502);

        $this->post('/picking/502/tomar', ['recipiente' => 'C5']);
        $this->postJson('/picking/502/cantidad', ['item' => 1, 'cantidad' => 2]);
        $this->post('/picking/502/terminar')
            ->assertRedirect(route('picking.index'))
            ->assertSessionHas('mensaje', 'Pedido #502 enviado a packing.')
            ->assertSessionMissing('siguienteParte');
    }

    public function test_sin_la_opcion_terminar_una_parte_vuelve_a_la_lista(): void
    {
        $this->pedidoPartido();
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'C5']);
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2]);

        $this->post('/picking/500/terminar')->assertRedirect(route('picking.index'));
        $this->assertSame('RECIBIDO', Pedido::query()->find(501)->estado);
    }

    public function test_la_marca_de_la_parte_lleva_al_pedido_original_si_ve_pedidos(): void
    {
        $this->pedidoPartido();
        $enlace = 'href="'.route('pedidos.show', 500).'"';

        // Sin permiso de Pedidos la marca no es enlace.
        $this->actingAs($this->operario)->get('/picking')->assertOk()->assertSee('2/3 · del #500')->assertDontSee($enlace, false);

        SidesUsers::query()->whereKey($this->operario->getKey())->update(['activarPedido' => 1]);
        $this->actingAs($this->operario->fresh())->get('/picking')->assertSee($enlace, false);
        $this->get('/pedidos/500')->assertOk()->assertSee('SEPED separó este pedido en 3 partes');
    }

    public function test_configuracion_guarda_la_opcion(): void
    {
        $encargado = $this->crearUsuario(['name' => 'Eva', 'email' => 'eva@example.com'] + $this->todosLosPermisos());

        $this->actingAs($encargado)->get('/configuracion')->assertOk()
            ->assertSee('Dar la siguiente parte al mismo operario')
            ->assertDontSee('Trabajar las partes como un solo pedido');

        $this->put('/configuracion', ['TamLetraMonitor' => 12, 'ordenPedSides' => 'UBICACION', 'pickingSiguienteParte' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) SidesCfg::query()->first()->pickingSiguienteParte);
    }
}
