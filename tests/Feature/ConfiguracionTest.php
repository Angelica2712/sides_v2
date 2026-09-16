<?php

namespace Tests\Feature;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class ConfiguracionTest extends TestCase
{
    use TablasSides;

    private SidesUsers $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg(['activarValPicking' => 1, 'claveValPicking' => 'viejaclave', 'mostrarObsMonitor' => 1], ['batch']);
        $this->crearCfg(['codisb' => 'OTRA', 'nombre' => 'OTRA SUCURSAL'], []);
        $this->usuario = $this->crearUsuario(['activarConfig' => 1]);
    }

    private function formulario(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'DROGUERIA ACTIVA 2',
            'nomcorto' => 'DA2',
            'rif' => 'J-123',
            'direccion' => 'Av. Principal',
            'localidad' => 'Caracas',
            'contacto' => 'Luis',
            'telefono' => '0212-5550000',
            'TamLetraMonitor' => '24',
            'ordenPedSides' => 'UBICACION',
            'MostrarTituloMonitor' => '1',
            'mostrarTranMonitor' => '1',
            'activarValPacking' => '1',
            'claveValPicking' => 'nueva1',
        ], $cambios);
    }

    public function test_muestra_la_configuracion_de_la_sucursal_y_sus_modulos(): void
    {
        $this->actingAs($this->usuario)->get('/configuracion')->assertOk()
            ->assertSee('Parámetros de la sucursal 505094939')
            ->assertSee('value="DROGUERIA ACTIVA,C.A"', false)
            ->assertSee('value="viejaclave"', false)
            ->assertSeeInOrder(['Módulos', 'Packing', 'activo', 'Batch Picking', 'activo', 'Etiquetas', 'apagado']);
    }

    public function test_guardar_actualiza_solo_la_sucursal_del_usuario(): void
    {
        $this->actingAs($this->usuario)->put('/configuracion', $this->formulario())
            ->assertRedirect(route('configuracion.index'))
            ->assertSessionHas('mensaje', 'Configuración guardada.');

        $cfg = SidesCfg::query()->find('505094939');
        $this->assertSame(['DROGUERIA ACTIVA 2', 'J-123', 'Caracas', 24, 'UBICACION', 'nueva1'], [$cfg->nombre, $cfg->rif, $cfg->localidad, (int) $cfg->TamLetraMonitor, $cfg->ordenPedSides, $cfg->claveValPicking]);
        // Casillas ausentes = apagadas.
        $this->assertSame([1, 1, 0, 0, 1, 0], array_map('intval', [
            $cfg->MostrarTituloMonitor, $cfg->mostrarTranMonitor, $cfg->mostrarObsMonitor,
            $cfg->activarValPicking, $cfg->activarValPacking, $cfg->activar_separador_automatico,
        ]));
        $this->assertSame('OTRA SUCURSAL', SidesCfg::query()->find('OTRA')->nombre);
    }

    public function test_no_toca_packing_ni_modulos_del_administrador(): void
    {
        $this->actingAs($this->usuario)->put('/configuracion', $this->formulario([
            'activarPacking' => '0', 'procAlcabalaPicking' => '1', 'activarEtiPacking' => '1', 'codisb' => 'OTRA',
        ]))->assertRedirect();

        $cfg = SidesCfg::query()->find('505094939');
        $this->assertSame([1, 0], [(int) $cfg->activarPacking, (int) $cfg->procAlcabalaPicking]);
        $this->assertTrue($cfg->tieneModulo('batch'));
    }

    public function test_valida_clave_de_supervisor_y_listas(): void
    {
        $this->actingAs($this->usuario)->from('/configuracion')
            ->put('/configuracion', $this->formulario(['claveValPicking' => '', 'TamLetraMonitor' => '13', 'ordenPedSides' => 'PRECIO', 'nombre' => '']))
            ->assertRedirect('/configuracion')
            ->assertSessionHasErrors(['claveValPicking', 'TamLetraMonitor', 'ordenPedSides', 'nombre']);

        $this->assertSame('DROGUERIA ACTIVA,C.A', SidesCfg::query()->find('505094939')->nombre);

        // Si ni Picking ni Packing piden clave, puede quedar vacía y se conserva la anterior.
        $this->put('/configuracion', $this->formulario(['activarValPacking' => null, 'claveValPicking' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('viejaclave', SidesCfg::query()->find('505094939')->claveValPicking);
    }

    public function test_requiere_permiso_de_configuracion(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@example.com', 'activarPicking' => 1]);

        $this->actingAs($operario)->get('/configuracion')->assertForbidden();
        $this->put('/configuracion', $this->formulario())->assertForbidden();
    }
}
