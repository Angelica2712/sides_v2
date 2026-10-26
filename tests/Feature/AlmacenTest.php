<?php

namespace Tests\Feature;

use App\Models\Sides\SidesUsers;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class AlmacenTest extends TestCase
{
    use TablasSides;

    private SidesUsers $operario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearCfg();
        $this->operario = $this->crearUsuario(['name' => 'Ana', 'email' => 'ana@example.com', 'activarPicking' => 1]);

        DB::table('producto')->insert([
            ['codprod' => 'A1', 'codisb' => '505094939', 'desprod' => 'ACETAMINOFEN 500', 'barra' => '7701', 'ubicacion' => 'PK-01-02-C-04'],
            ['codprod' => 'L5', 'codisb' => '505094939', 'desprod' => 'LOSARTAN 50', 'barra' => '7702', 'ubicacion' => 'PK-02-01-A-01;MY-01-03'],
            ['codprod' => 'V1', 'codisb' => '505094939', 'desprod' => 'VIEJO', 'barra' => '7703', 'ubicacion' => '0707E05'],
            ['codprod' => 'X9', 'codisb' => '999', 'desprod' => 'ACETAMINOFEN OTRA', 'barra' => '7709', 'ubicacion' => 'PK-09-09-A-01'],
        ]);
    }

    public function test_el_plano_sale_del_catalogo_de_la_drogueria(): void
    {
        $plano = $this->actingAs($this->operario)->getJson('/almacen/plano')->assertOk()->json();

        $this->assertSame(['MY', 'PK'], array_column($plano['zonas'], 'zona'));
        $this->assertSame([1, 2], array_column($plano['zonas'][1]['pasillos'], 'pasillo'));
        $this->assertSame(3, $plano['ubicaciones']);
        $this->assertSame(2, $plano['reconocidas']);
    }

    public function test_la_pantalla_cuenta_las_ubicaciones_con_el_formato(): void
    {
        $this->actingAs($this->operario)->get('/almacen')
            ->assertOk()
            ->assertSee('Almacén 3D')
            ->assertSee('2 de 3 ubicaciones del catálogo con el formato');
    }

    public function test_sin_ubicaciones_en_el_formato_explica_cual_es(): void
    {
        DB::table('producto')->where('codisb', '505094939')->update(['ubicacion' => 'A01PICKPAE24D']);

        $this->actingAs($this->operario)->get('/almacen')
            ->assertOk()
            ->assertSee('ZONA-PASILLO-ANAQUEL-ESTANTE-POSICIÓN')
            ->assertSee('Ver un almacén de ejemplo');
    }

    public function test_busca_productos_de_la_drogueria_con_sus_ubicaciones(): void
    {
        $productos = $this->actingAs($this->operario)->getJson('/almacen/buscar?q=acetaminofen')->assertOk()->json('productos');

        $this->assertCount(1, $productos);
        $this->assertSame(['PK-01-02-C-04'], $productos[0]['ubicaciones']);

        $porBarra = $this->actingAs($this->operario)->getJson('/almacen/buscar?q=7702')->json('productos');
        $this->assertSame(['PK-02-01-A-01', 'MY-01-03'], $porBarra[0]['ubicaciones']);

        $viejo = $this->actingAs($this->operario)->getJson('/almacen/buscar?q=V1')->json('productos');
        $this->assertSame([], $viejo[0]['ubicaciones']);
    }

    public function test_sin_permiso_de_picking_ni_packing_no_entra(): void
    {
        $otro = $this->crearUsuario(['name' => 'Rosa', 'email' => 'rosa@example.com', 'activarPicking' => 0, 'activarPacking' => 0, 'activarPedido' => 1]);

        $this->actingAs($otro)->get('/almacen')->assertForbidden();
    }

    public function test_el_picking_ofrece_el_almacen_con_el_modulo_activo(): void
    {
        $this->crearPedido(['id' => 700, 'estado' => 'RECIBIDO']);
        $this->crearRenglon(700, 1, ['ubicacion' => 'PK-01-02-C-04']);
        $this->actingAs($this->operario)->post('/picking/700/tomar', ['recipiente' => 'C1']);

        $this->actingAs($this->operario)->get('/picking/700')
            ->assertOk()
            ->assertSee('Ver en el almacén')
            ->assertViewHas('planoAlmacen', route('almacen.plano'));
    }
}
