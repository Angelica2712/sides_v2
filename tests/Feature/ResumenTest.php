<?php

namespace Tests\Feature;

use App\Models\Sides\SidesUsers;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class ResumenTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
    }

    private function usuarioCompleto(): SidesUsers
    {
        return $this->crearUsuario(['email' => 'ana@example.com', ...$this->todosLosPermisos()]);
    }

    public function test_muestra_los_contadores_agrupados_por_estado_y_lo_pendiente_por_aprobar(): void
    {
        $this->crearCfg(['pedidoxAprobar' => 7]);
        $this->crearPedido(['id' => 1, 'estado' => 'RECIBIDO']);
        $this->crearPedido(['id' => 2, 'estado' => 'PICKING']);
        $this->crearPedido(['id' => 3, 'estado' => 'PACKING']);
        $this->crearPedido(['id' => 4, 'estado' => 'PEND-FACTURA']);
        $this->crearPedido(['id' => 5, 'estado' => 'FACTURANDO']);
        $this->crearPedido(['id' => 6, 'estado' => 'FACTURADO']);
        $this->crearPedido(['id' => 7, 'estado' => 'FACTURADO']);
        $this->crearPedido(['id' => 8, 'estado' => 'RECIBIDO', 'codisb' => 'OTRA']);

        $this->actingAs($this->usuarioCompleto())->get('/resumen')->assertOk()
            ->assertSee('7', false) // pedidos por aprobar
            ->assertSee('Pendiente por facturar')
            ->assertSee('Facturados');
    }

    public function test_cada_tarjeta_solo_aparece_si_el_usuario_tiene_el_permiso_del_modulo(): void
    {
        $this->crearCfg();
        $this->crearPedido(['id' => 1, 'estado' => 'RECIBIDO']);

        $usuario = $this->crearUsuario(['email' => 'limitado@example.com', 'activarResumen' => 1, 'activarPicking' => 1]);

        $this->actingAs($usuario)->get('/resumen')->assertOk()
            ->assertSee('Picking')
            ->assertDontSee('Recibidos')
            ->assertDontSee('Packing')
            ->assertDontSee('Pendiente por facturar')
            ->assertDontSee('Pedidos (todos)');
    }

    public function test_requiere_el_permiso_de_resumen(): void
    {
        $usuario = $this->crearUsuario(['email' => 'sinpermiso@example.com', 'activarResumen' => 0]);

        $this->actingAs($usuario)->get('/resumen')->assertForbidden();
    }
}
