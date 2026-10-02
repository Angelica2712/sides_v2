<?php

namespace Tests\Feature;

use App\Models\Sides\SidesModuloSucursal;
use App\Models\Sides\SidesUsers;
use App\Support\MenuSides;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** El administrador (la empresa) enciende y apaga por droguería todos los módulos, no solo los opcionales. */
class ModulosDrogueriaTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg(['codisb' => 'SIDES', 'nombre' => 'EMPRESA'], []);
        $this->admin = $this->crearUsuario(['name' => 'Empresa', 'email' => 'admin@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1]);
    }

    public function test_una_drogueria_que_ya_existia_conserva_los_modulos_basicos_sin_tocar_nada(): void
    {
        // Solo filas de opcionales, como las droguerías creadas antes de este cambio.
        $cfg = $this->crearCfg([], ['guias']);
        $usuario = $this->crearUsuario($this->todosLosPermisos());

        $this->assertSame([...MenuSides::BASICOS, 'guias'], $cfg->modulosEncendidos());
        $this->actingAs($usuario)->get('/monitor')->assertOk();
        $this->get('/informes')->assertOk();
        $this->get('/batch-picking')->assertForbidden();
    }

    public function test_apagar_un_modulo_basico_lo_quita_a_todos_los_usuarios_de_la_drogueria(): void
    {
        $this->crearCfg([], []);
        $usuario = $this->crearUsuario($this->todosLosPermisos());

        $encendidos = array_values(array_diff(MenuSides::BASICOS, ['monitor', 'informes']));
        $this->actingAs($this->admin)->put('/admin/droguerias/505094939', [
            'nombre' => 'DROGUERIA ACTIVA,C.A',
            'modulos' => $encendidos,
            'activarPacking' => '1',
        ])->assertRedirect(route('admin.index'));

        $this->assertSame(0, (int) SidesModuloSucursal::query()->where('codisb', '505094939')->where('modulo', 'monitor')->value('activo'));

        $this->actingAs($usuario)->get('/monitor')->assertForbidden();
        $this->get('/informes')->assertForbidden();
        $this->get('/pedidos')->assertOk();
        $this->get('/home')->assertDontSee('Informes de picking y packing');

        // La auditoría deja constancia de quién lo cambió.
        $this->assertDatabaseHas('sides_auditoria', ['accion' => 'admin.update', 'usuario' => 'admin@example.com', 'resultado' => 'OK']);
    }

    public function test_drogueria_nueva_arranca_con_los_basicos_encendidos_y_los_opcionales_apagados(): void
    {
        $this->actingAs($this->admin)->get('/admin/droguerias/nueva')
            ->assertOk()
            ->assertSee('Módulos básicos')
            ->assertSee('Módulos opcionales');

        $this->post('/admin/droguerias', [
            'codisb' => 'NUEVA',
            'nombre' => 'Droguería Nueva',
            'modulos' => MenuSides::BASICOS,
            'activarPacking' => '1',
        ])->assertRedirect(route('admin.edit', 'NUEVA'));

        $filas = SidesModuloSucursal::query()->where('codisb', 'NUEVA')->pluck('activo', 'modulo')->map(fn ($a) => (int) $a)->all();
        $this->assertEqualsCanonicalizing(MenuSides::CONTROLABLES, array_keys($filas));
        $this->assertEqualsCanonicalizing(MenuSides::BASICOS, array_keys(array_filter($filas)));
    }

    public function test_administracion_no_se_puede_apagar(): void
    {
        $this->actingAs($this->admin)->put('/admin/droguerias/SIDES', ['nombre' => 'EMPRESA', 'modulos' => [], 'activarPacking' => '1']);

        $this->get('/admin')->assertOk();
        $this->assertNotContains('admin', MenuSides::CONTROLABLES);
    }
}
