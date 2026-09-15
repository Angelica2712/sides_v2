<?php

namespace Tests\Feature;

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
            ->assertSee('Hola, Operador Prueba')
            ->assertSeeInOrder(['Monitor', 'Picking', 'Batch Picking', 'Packing', 'Etiquetas', 'Pedidos', 'Filtro monitor', 'Resumen', 'Usuarios', 'Informes', 'Configuración', 'Guías', 'Rutas']);
    }

    public function test_usuario_de_guias_de_carga_no_ve_etiquetas(): void
    {
        $this->crearCfg();
        $usuario = $this->crearUsuario($this->todosLosPermisos());

        $this->actingAs($usuario)->get('/home')->assertSee('Monitor')->assertDontSee('Etiquetas');
        $this->get('/etiquetas')->assertForbidden();
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
        $usuario = $this->crearUsuario(array_merge($this->todosLosPermisos(), ['activarGuiaCarga' => 0, 'activarGuiaDescarga' => 0]));

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
