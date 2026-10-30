<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCesta;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class CestasTest extends TestCase
{
    use RefreshDatabase;
    use TablasSides;

    private SidesUsers $ana;

    private SidesUsers $luis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearCfg();
        $this->ana = $this->crearUsuario(['name' => 'Ana', 'email' => 'ana@example.com', 'activarPicking' => 1]);
        $this->luis = $this->crearUsuario(['name' => 'Luis', 'email' => 'luis@example.com', 'activarPicking' => 1]);

        foreach ([500, 501] as $id) {
            $this->crearPedido(['id' => $id, 'estado' => 'RECIBIDO']);
            $this->crearRenglon($id, 1);
        }
    }

    public function test_no_acepta_una_cesta_que_no_esta_en_la_lista(): void
    {
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'INVENTADA'])
            ->assertSessionHas('error', 'La cesta INVENTADA no está registrada en la lista de cestas.');

        $this->assertSame('RECIBIDO', Pedido::query()->find(500)->estado);
    }

    public function test_no_acepta_una_cesta_desactivada(): void
    {
        SidesCesta::query()->where('codigo', 'CESTA 5')->update(['activo' => 0]);

        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5'])
            ->assertSessionHas('error', 'La cesta CESTA 5 está desactivada.');
    }

    public function test_guarda_el_codigo_del_catalogo_aunque_lo_escriban_en_minusculas(): void
    {
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => ' cesta 5 ']);

        $this->assertSame('CESTA 5', SidesPedidoOperacion::query()->find(500)->recipiente);
    }

    public function test_otro_operario_no_puede_usar_una_cesta_ocupada(): void
    {
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);

        $this->actingAs($this->luis)->post('/picking/501/tomar', ['recipiente' => 'CESTA 5'])
            ->assertSessionHas('error', 'La cesta CESTA 5 está ocupada con el pedido #500 (Ana). Elige otra.');

        $this->assertSame('RECIBIDO', Pedido::query()->find(501)->estado);
    }

    public function test_se_libera_al_terminar_el_picking(): void
    {
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);
        $this->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 1]);
        $this->post('/picking/500/terminar');
        $this->assertSame('PACKING', Pedido::query()->find(500)->estado);

        $this->actingAs($this->luis)->post('/picking/501/tomar', ['recipiente' => 'CESTA 5'])
            ->assertRedirect(route('picking.show', 501));
    }

    public function test_al_liberar_el_pedido_la_cesta_sigue_con_el_y_la_hereda_quien_lo_retoma(): void
    {
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);
        $this->actingAs($this->ana)->post('/picking/500/liberar');

        $this->actingAs($this->luis)->post('/picking/501/tomar', ['recipiente' => 'CESTA 5'])
            ->assertSessionHas('error', 'La cesta CESTA 5 está ocupada con el pedido #500. Elige otra.');

        $this->actingAs($this->luis)->post('/picking/500/tomar', ['recipiente' => 'CESTA 6'])
            ->assertRedirect(route('picking.show', 500));
        $this->assertSame('CESTA 5', SidesPedidoOperacion::query()->find(500)->recipiente);
    }

    public function test_dos_partes_del_mismo_pedido_en_picking_no_comparten_la_cesta(): void
    {
        Pedido::query()->whereKey(501)->update(['idori' => '500']);
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);

        $this->actingAs($this->luis)->post('/picking/501/tomar', ['recipiente' => 'CESTA 5'])
            ->assertSessionHas('error', 'La cesta CESTA 5 está ocupada con el pedido #500 (Ana). Elige otra.');
    }

    public function test_la_lista_de_picking_solo_ofrece_las_libres(): void
    {
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);

        $this->actingAs($this->luis)->get('/picking')
            ->assertOk()
            ->assertViewHas('cestasLibres', fn (array $libres) => ! in_array('CESTA 5', $libres, true) && in_array('CESTA 6', $libres, true));
    }

    public function test_sin_cestas_no_se_puede_tomar_y_avisa(): void
    {
        SidesCesta::query()->delete();

        $this->actingAs($this->ana)->get('/picking')->assertSee('No hay cestas libres');
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5'])->assertSessionHas('error');
    }

    public function test_agrega_una_lista_y_un_rango_sin_repetir(): void
    {
        $admin = $this->crearUsuario(['email' => 'cfg@example.com', 'activarConfig' => 1]);

        $this->actingAs($admin)->post('/configuracion/cestas', ['lista' => "azul 1\nAZUL 1\n\nCESTA 5", 'prefijo' => 'CARRO', 'desde' => 1, 'hasta' => 3])
            ->assertRedirect(route('cestas.index'))
            ->assertSessionHas('mensaje', 'Se agregaron 4 cestas. 1 ya estaban en la lista.');

        foreach (['AZUL 1', 'CARRO 1', 'CARRO 2', 'CARRO 3'] as $codigo) {
            $this->assertTrue(SidesCesta::query()->where('codigo', $codigo)->exists(), $codigo);
        }
    }

    public function test_no_se_borra_una_cesta_en_uso_y_un_operario_no_entra_a_la_lista(): void
    {
        $admin = $this->crearUsuario(['email' => 'cfg@example.com', 'activarConfig' => 1]);
        $this->actingAs($this->ana)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);
        $cesta = SidesCesta::query()->where('codigo', 'CESTA 5')->first();

        $this->actingAs($admin)->delete("/configuracion/cestas/{$cesta->id}")
            ->assertSessionHas('error', 'La cesta CESTA 5 está en uso: no se puede borrar.');
        $this->assertNotNull($cesta->fresh());

        $this->actingAs($this->ana)->get('/configuracion/cestas')->assertForbidden();
    }
}
