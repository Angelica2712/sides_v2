<?php

namespace Tests\Feature;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesModuloSucursal;
use App\Models\Sides\SidesUsers;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg(['codisb' => 'SIDES', 'nombre' => 'SIDES', 'procAlcabalaPicking' => 1]);
        $this->admin = $this->crearUsuario(['name' => 'Admin', 'email' => 'admin@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1, 'activarPicking' => 1]);
    }

    private function modulosDe(string $codisb): array
    {
        return SidesModuloSucursal::query()->where('codisb', $codisb)->where('activo', 1)->orderBy('modulo')->pluck('modulo')->all();
    }

    public function test_solo_el_administrador_entra_y_ve_administracion_en_el_menu(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@example.com', 'codisb' => 'SIDES', 'activarPicking' => 1, 'activarConfig' => 1]);

        $this->actingAs($operario)->get('/admin')->assertForbidden();
        $this->actingAs($operario)->get('/home')->assertDontSee('Administración');

        $this->actingAs($this->admin)->get('/home')->assertSee('Administración');
        $this->get('/admin')->assertOk()->assertSee('Droguerías')->assertSee('Código SIDES');
        $this->get('/admin/droguerias/SIDES')->assertOk()->assertSee('Tamaño de la etiqueta')->assertSee('Imprimir las etiquetas al terminar Packing');
    }

    public function test_crear_drogueria_con_sus_modulos(): void
    {
        $this->actingAs($this->admin)->post('/admin/droguerias', [
            'codisb' => 'DROGA1',
            'nombre' => 'Droguería Uno',
            'modulos' => ['etiquetas', 'guias', 'rutas', 'inventado'],
            'activarPacking' => '1',
            'procAlcabalaPicking' => '1',
            'formatoPersEtiq' => 'rptetiqueta10x7',
            'activar_etiqueta_packing' => '1',
            'activarImpTicket' => '1',
        ])->assertRedirect(route('admin.index'))->assertSessionHas('mensaje', 'Droguería Droguería Uno creada.');

        $drogueria = SidesCfg::query()->find('DROGA1');
        $this->assertSame(['Droguería Uno', 1, 0], [$drogueria->nombre, (int) $drogueria->activarPacking, (int) $drogueria->procAlcabalaPicking]);
        $this->assertSame(['etiquetas', 'guias', 'rutas'], $this->modulosDe('DROGA1'));
        $this->assertSame(
            ['rptetiqueta10x7', 1, 1, 1, 0],
            [$drogueria->formatoPersEtiq, (int) $drogueria->activarEtiPacking, (int) $drogueria->activar_etiqueta_packing, (int) $drogueria->activarImpTicket, (int) $drogueria->mostrarEntrega]
        );

        $this->post('/admin/droguerias', ['codisb' => 'DROGA2', 'nombre' => 'Dos', 'formatoPersEtiq' => 'gigante'])
            ->assertSessionHasErrors(['formatoPersEtiq' => 'Elige un tamaño de etiqueta de la lista.']);

        $this->post('/admin/droguerias', ['codisb' => 'DROGA1', 'nombre' => 'Repetida'])
            ->assertSessionHasErrors(['codisb' => 'Ya existe una droguería con ese código.']);
    }

    public function test_apagar_batch_picking_lo_quita_del_menu_de_la_drogueria(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@example.com', 'codisb' => 'SIDES', 'activarPicking' => 1]);
        $this->actingAs($operario)->get('/batch-picking')->assertOk();

        $this->actingAs($this->admin)->put('/admin/droguerias/SIDES', [
            'nombre' => 'SIDES',
            'modulos' => ['etiquetas'],
            'activarPacking' => '1',
            'procAlcabalaPicking' => '1',
        ])->assertRedirect(route('admin.index'));

        $this->assertSame(['etiquetas'], $this->modulosDe('SIDES'));
        // Sin Batch Picking los pedidos no pueden llegar en espera.
        $this->assertSame(0, (int) SidesCfg::query()->find('SIDES')->procAlcabalaPicking);

        $operario = SidesUsers::query()->find($operario->id);
        $this->actingAs($operario)->get('/batch-picking')->assertForbidden();
        $this->get('/home')->assertDontSee('Batch Picking');
    }

    public function test_no_se_apaga_batch_picking_con_pedidos_en_espera(): void
    {
        $this->crearPedido(['id' => 40, 'estado' => 'ALCABALA', 'codisb' => 'SIDES']);

        $this->actingAs($this->admin)->from('/admin/droguerias/SIDES')->put('/admin/droguerias/SIDES', [
            'nombre' => 'SIDES',
            'modulos' => [],
            'activarPacking' => '1',
        ])->assertRedirect('/admin/droguerias/SIDES')
            ->assertSessionHasErrors(['modulos' => 'No se puede desactivar Batch Picking: la droguería tiene 1 pedidos en espera y 0 lotes en curso. Libéralos o termina los lotes primero.']);

        $this->assertContains('batch', $this->modulosDe('SIDES'));
    }
}
