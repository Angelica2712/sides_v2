<?php

namespace Tests\Feature;

use App\Models\Sides\SidesAlmacenCfg;
use App\Models\Sides\SidesUsers;
use App\Support\ConfigAlmacen;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class AdminAlmacenTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg(['codisb' => 'SIDES', 'nombre' => 'SIDES']);
        $this->crearCfg(['codisb' => 'DROMARKO', 'nombre' => 'Dromarko']);
        $this->admin = $this->crearUsuario(['name' => 'Admin', 'email' => 'admin@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1]);

        DB::table('producto')->insert(['codprod' => 'A1', 'codisb' => 'DROMARKO', 'desprod' => 'ACETAMINOFEN', 'ubicacion' => 'QX-01-02-B-03']);
    }

    private function guardar(array $datos = [])
    {
        return $this->actingAs($this->admin)->put('/admin/droguerias/DROMARKO/almacen', [
            'numeracion' => 'u',
            'estanteA' => 'arriba',
            'posicion1' => 'derecha',
            'estantes' => 6,
            'posiciones' => 5,
            'zonas' => ['PK' => 'Despacho', 'QX' => 'Quirúrgicos', 'MY' => ''],
            ...$datos,
        ]);
    }

    public function test_el_administrador_ve_el_acomodo_y_las_zonas_del_catalogo(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee(route('admin.almacen.edit', 'DROMARKO'));

        $this->get('/admin/droguerias/DROMARKO/almacen')
            ->assertOk()
            ->assertSee('Almacén de Dromarko')
            ->assertSee('En U')
            ->assertSee('zonas[QX]', false)
            ->assertSee('zonas[PK]', false);
    }

    public function test_guarda_el_acomodo_de_la_drogueria(): void
    {
        $this->guardar()->assertRedirect('/admin/droguerias/DROMARKO/almacen')->assertSessionHas('mensaje');

        $this->assertSame([
            'numeracion' => 'u',
            'estanteA' => 'arriba',
            'posicion1' => 'derecha',
            'estantes' => 6,
            'posiciones' => 5,
            'zonas' => ['PK' => 'Despacho', 'QX' => 'Quirúrgicos'],
            'pasillos' => [],
            'quitados' => [],
            'extras' => [],
            'disposicion' => [],
            'entradas' => [],
        ], SidesAlmacenCfg::query()->find('DROMARKO')->config);
        $this->assertSame('admin@example.com', SidesAlmacenCfg::query()->find('DROMARKO')->actualizado_por);
        $this->assertSame(ConfigAlmacen::POR_DEFECTO, ConfigAlmacen::de('SIDES'));
    }

    public function test_el_plano_de_la_drogueria_lleva_su_acomodo(): void
    {
        $this->guardar();
        $operario = $this->crearUsuario(['email' => 'op@example.com', 'codisb' => 'DROMARKO', 'activarPicking' => 1]);

        $plano = $this->actingAs($operario)->getJson('/almacen/plano')->assertOk()->json();

        $this->assertSame('u', $plano['config']['numeracion']);
        $this->assertSame([['anaquel' => 2, 'estantes' => 6, 'posiciones' => 5]], $plano['zonas'][0]['pasillos'][0]['anaqueles']);
    }

    public function test_guarda_los_anaqueles_movidos_en_el_plano(): void
    {
        $this->guardar(['disposicion' => json_encode(['QX-01-02' => ['x' => 4.5, 'z' => -2, 'giro' => 90]])])->assertSessionHasNoErrors();

        $this->assertSame(['QX-01-02' => ['x' => 4.5, 'z' => -2.0, 'giro' => 90]], ConfigAlmacen::de('DROMARKO')['disposicion']);

        // El plano que usan el Almacén 3D y el picking lleva la disposición.
        $operario = $this->crearUsuario(['email' => 'op2@example.com', 'codisb' => 'DROMARKO', 'activarPicking' => 1]);
        $plano = $this->actingAs($operario)->getJson('/almacen/plano')->json();
        $this->assertSame(['x' => 4.5, 'z' => -2, 'giro' => 90], $plano['config']['disposicion']['QX-01-02']);

        $this->guardar(['disposicion' => 'esto no es json'])->assertSessionHasErrors('disposicion');
    }

    public function test_rechaza_opciones_que_no_existen(): void
    {
        $this->guardar(['numeracion' => 'zigzag', 'estantes' => 0])->assertSessionHasErrors(['numeracion', 'estantes']);

        $this->assertNull(SidesAlmacenCfg::query()->find('DROMARKO'));
    }

    public function test_volver_al_acomodo_recomendado(): void
    {
        $this->guardar();

        $this->actingAs($this->admin)->delete('/admin/droguerias/DROMARKO/almacen')->assertRedirect('/admin/droguerias/DROMARKO/almacen');

        $this->assertNull(SidesAlmacenCfg::query()->find('DROMARKO'));
        $this->assertSame(ConfigAlmacen::POR_DEFECTO, ConfigAlmacen::de('DROMARKO'));
    }

    public function test_la_drogueria_no_entra_al_almacen_de_otra_desde_administracion(): void
    {
        $encargado = $this->crearUsuario(['email' => 'enc@example.com', 'codisb' => 'DROMARKO', 'activarConfig' => 1, 'activarUsuario' => 1, 'activarPicking' => 1]);

        $this->actingAs($encargado)->get('/admin/droguerias/DROMARKO/almacen')->assertForbidden();
        $this->actingAs($encargado)->put('/admin/droguerias/DROMARKO/almacen', ['numeracion' => 'u'])->assertForbidden();
        $this->assertNull(SidesAlmacenCfg::query()->find('DROMARKO'));
    }

    public function test_drogueria_que_no_existe(): void
    {
        $this->actingAs($this->admin)->get('/admin/droguerias/NOEXISTE/almacen')->assertNotFound();
    }
}
