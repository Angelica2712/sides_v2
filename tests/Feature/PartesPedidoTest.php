<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesUsers;
use App\Support\PartesPedido;
use Illuminate\Support\Carbon;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** Pedidos que SEPED partió por exceso de renglones: cada parte lleva el número del original en idori. */
class PartesPedidoTest extends TestCase
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
        $this->usuario = $this->crearUsuario([
            'name' => 'Ana', 'email' => 'ana@example.com',
            'activarPedido' => 1, 'activarMonitor' => 1, 'activarPicking' => 1,
        ]);

        // #500 original (30 renglones) partido en #501 y #502; #600 es un pedido normal del mismo cliente.
        $this->crearPedido(['id' => 500, 'estado' => 'PICKING', 'numren' => 30]);
        $this->crearPedido(['id' => 501, 'estado' => 'RECIBIDO', 'numren' => 30, 'idori' => '500']);
        $this->crearPedido(['id' => 502, 'estado' => 'RECIBIDO', 'numren' => 4, 'idori' => '500']);
        $this->crearPedido(['id' => 600, 'estado' => 'RECIBIDO']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ubica_cada_parte_dentro_de_su_pedido_original(): void
    {
        $partes = PartesPedido::deLista('505094939', Pedido::query()->orderBy('id')->get());

        $this->assertSame([
            500 => ['raiz' => 500, 'parte' => 1, 'total' => 3],
            501 => ['raiz' => 500, 'parte' => 2, 'total' => 3],
            502 => ['raiz' => 500, 'parte' => 3, 'total' => 3],
        ], $partes);
    }

    public function test_una_parte_cuyo_original_ya_no_existe_igual_muestra_de_donde_viene(): void
    {
        $this->crearPedido(['id' => 701, 'estado' => 'RECIBIDO', 'idori' => '700']);

        $partes = PartesPedido::deLista('505094939', [Pedido::query()->find(701)]);

        $this->assertSame([701 => ['raiz' => 700, 'parte' => 2, 'total' => 2]], $partes);
    }

    public function test_no_mezcla_familias_de_otra_sucursal(): void
    {
        $this->crearPedido(['id' => 900, 'estado' => 'RECIBIDO', 'codisb' => 'OTRA', 'idori' => '600']);

        $this->assertSame([], PartesPedido::deLista('505094939', [Pedido::query()->find(600)]));
    }

    public function test_las_listas_marcan_de_que_pedido_viene_cada_parte(): void
    {
        $this->actingAs($this->usuario);

        $this->get('/pedidos')->assertOk()->assertSee('2/3 · del #500')->assertSee('3/3 · del #500')->assertSee('1/3 · original');
        $this->get('/monitor')->assertOk()->assertSee('2/3 · del #500');
        $this->get('/picking')->assertOk()->assertSee('3/3 · del #500');
    }

    public function test_buscar_el_numero_original_trae_tambien_sus_partes(): void
    {
        $this->actingAs($this->usuario);

        $this->get('/pedidos?buscar=500')->assertOk()
            ->assertSee('#501')->assertSee('#502')->assertDontSee('#600');
    }

    public function test_el_detalle_muestra_el_original_y_todas_las_partes(): void
    {
        $this->actingAs($this->usuario);

        $this->get('/pedidos/502')->assertOk()
            ->assertSee('Pedido partido por exceso de renglones')
            ->assertSee('una parte del pedido original', false)
            ->assertSeeInOrder(['#500', 'original', '#501', 'parte', '#502', 'parte']);

        $this->get('/pedidos/500')->assertOk()->assertSee('separó este pedido en 3 partes');
        $this->get('/pedidos/600')->assertOk()->assertDontSee('Pedido partido');
    }
}
