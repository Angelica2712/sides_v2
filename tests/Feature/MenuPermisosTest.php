<?php

namespace Tests\Feature;

use App\Support\MenuSides;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class MenuPermisosTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
    }

    public function test_administrador_ve_todos_los_modulos_de_droactiva(): void
    {
        $this->crearCfg();
        $usuario = $this->crearUsuario(array_merge($this->todosLosPermisos(), [
            'activarGuiaCarga' => 0,
            'activarGuiaDescarga' => 0,
        ]));

        $this->actingAs($usuario)->get('/home')
            ->assertOk()
            ->assertSee('Operador Prueba')
            // Menú agrupado por secciones (MenuSides::SECCIONES).
            ->assertSeeInOrder([
                'Operación', 'Monitor', 'Picking', 'Batch Picking', 'Packing', 'Etiquetas',
                'Despacho', 'Guías', 'Rutas',
                'Consultas', 'Pedidos', 'Resumen', 'Informes',
                'Ajustes', 'Filtro monitor', 'Configuración', 'Usuarios',
            ]);
    }

    public function test_cada_modulo_esta_en_una_sola_seccion_del_menu(): void
    {
        $agrupados = array_merge(...array_values(MenuSides::SECCIONES));

        $this->assertEqualsCanonicalizing(array_keys(MenuSides::MODULOS), $agrupados);
        $this->assertSame(count($agrupados), count(array_unique($agrupados)));
    }

    public function test_etiquetas_solo_la_ve_el_usuario_con_ese_permiso(): void
    {
        $this->crearCfg([], ['etiquetas']);
        $sinPermiso = $this->crearUsuario(array_merge($this->todosLosPermisos(), ['activarEtiquetas' => 0]));

        $this->actingAs($sinPermiso)->get('/home')->assertSee('Monitor')->assertDontSee('Etiquetas');
        $this->get('/etiquetas')->assertForbidden();

        $conPermiso = $this->crearUsuario(['email' => 'etiquetas@example.com', 'activarEtiquetas' => 1]);
        $this->actingAs($conPermiso)->get('/home')->assertSee('Etiquetas');
        $this->get('/etiquetas')->assertOk();
    }

    public function test_usuario_solo_picking_ve_solo_picking(): void
    {
        $this->crearCfg();
        $usuario = $this->crearUsuario(['activarPicking' => 1]);

        $this->actingAs($usuario)->get('/home')
            ->assertOk()
            ->assertSee('Picking')
            ->assertDontSee('Monitor')
            ->assertDontSee('Etiquetas')
            ->assertDontSee('Configuración');

        $this->get('/picking')->assertOk()->assertSee('Pedidos para picking');
        $this->get('/monitor')->assertForbidden();
        $this->get('/configuracion')->assertForbidden();
    }

    public function test_packing_depende_de_la_configuracion_de_la_sucursal(): void
    {
        $this->crearCfg(['activarPacking' => 0]);
        $usuario = $this->crearUsuario(['activarPacking' => 1, 'activarMonitor' => 1]);

        $this->actingAs($usuario)->get('/home')->assertDontSee('Packing');
        $this->get('/packing')->assertForbidden();
    }

    public function test_modulos_opcionales_solo_aparecen_si_la_drogueria_los_tiene_activos(): void
    {
        $this->crearCfg([], ['batch']);
        // Sin «Administración»: ese permiso propio sí saca el módulo en el menú (AdministracionDrogueriaTest).
        $usuario = $this->crearUsuario(array_merge($this->todosLosPermisos(), ['activarGuiaCarga' => 0, 'activarGuiaDescarga' => 0, 'activarAdmin' => 0]));

        $this->actingAs($usuario)->get('/home')
            ->assertSee('Batch Picking')
            ->assertDontSee('Etiquetas')
            ->assertDontSee('Guías')
            ->assertDontSee('Rutas')
            ->assertDontSee('Administración');
        $this->get('/batch-picking')->assertOk();
        $this->get('/etiquetas')->assertForbidden();

        $sinPicking = $this->crearUsuario(['email' => 'monitor@example.com', 'activarMonitor' => 1]);
        $this->actingAs($sinPicking)->get('/batch-picking')->assertForbidden();
    }
}
