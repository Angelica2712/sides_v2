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
            ->assertSee('DROGUERIA ACTIVA,C.A')
            ->assertSee('pídelo a FULLTECH360')
            ->assertDontSee('name="nombre"', false)
            ->assertSee('value="viejaclave"', false)
            ->assertSeeInOrder(['Módulos', 'Packing', 'activo', 'Batch Picking', 'activo', 'Etiquetas', 'apagado']);
    }

    public function test_guardar_actualiza_solo_la_sucursal_del_usuario(): void
    {
        $this->actingAs($this->usuario)->put('/configuracion', $this->formulario())
            ->assertRedirect(route('configuracion.index'))
            ->assertSessionHas('mensaje', 'Configuración guardada.');

        $cfg = SidesCfg::query()->find('505094939');
        $this->assertSame([24, 'UBICACION', 'nueva1'], [(int) $cfg->TamLetraMonitor, $cfg->ordenPedSides, $cfg->claveValPicking]);
        // Nombre, RIF y demás datos de la droguería solo los cambia FULLTECH360 en Administración.
        $this->assertSame(['DROGUERIA ACTIVA,C.A', null, null], [$cfg->nombre, $cfg->rif, $cfg->localidad]);
        // Casillas ausentes = apagadas.
        $this->assertSame([1, 1, 0, 0, 1, 0], array_map('intval', [
            $cfg->MostrarTituloMonitor, $cfg->mostrarTranMonitor, $cfg->mostrarObsMonitor,
            $cfg->activarValPicking, $cfg->activarValPacking, $cfg->activar_separador_automatico,
        ]));
        $this->assertSame('OTRA SUCURSAL', SidesCfg::query()->find('OTRA')->nombre);
        $this->assertSame(0, (int) $cfg->pickingOrdenLibre);
    }

    public function test_escanear_en_cualquier_orden_se_guarda_como_casilla(): void
    {
        $this->actingAs($this->usuario)->get('/configuracion')->assertSee('Escanear en cualquier orden');

        $this->put('/configuracion', [...$this->formulario(), 'pickingOrdenLibre' => '1']);
        $this->assertSame(1, (int) SidesCfg::query()->find('505094939')->pickingOrdenLibre);

        $this->put('/configuracion', $this->formulario());
        $this->assertSame(0, (int) SidesCfg::query()->find('505094939')->pickingOrdenLibre);
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

    public function test_la_drogueria_enciende_y_apaga_el_modulo_etiquetas(): void
    {
        $this->actingAs($this->usuario)->get('/configuracion')->assertSee('name="moduloEtiquetas"', false);
        $this->get('/etiquetas')->assertForbidden();

        $this->put('/configuracion', $this->formulario(['moduloEtiquetas' => '1']))->assertRedirect(route('configuracion.index'));
        $cfg = SidesCfg::query()->find('505094939');
        $this->assertTrue($cfg->tieneModulo('etiquetas'));
        $this->assertSame(1, (int) $cfg->activarEtiPacking);
        $this->assertTrue($cfg->tieneModulo('batch'), 'los demás módulos no cambian');
        $this->assertFalse(SidesCfg::query()->find('OTRA')->tieneModulo('etiquetas'));
        $this->actingAs($this->usuario->fresh())->get('/etiquetas')->assertOk();
        $this->get('/configuracion')->assertSeeInOrder(['Etiquetas', 'activo']);

        // Casilla sin marcar = módulo apagado, y con él la etiqueta automática del packing.
        SidesCfg::query()->whereKey('505094939')->update(['activar_etiqueta_packing' => 1]);
        $this->put('/configuracion', $this->formulario());
        $cfg = SidesCfg::query()->find('505094939');
        $this->assertFalse($cfg->tieneModulo('etiquetas'));
        $this->assertSame([0, 0], [(int) $cfg->activarEtiPacking, (int) $cfg->activar_etiqueta_packing]);
        $this->actingAs($this->usuario->fresh())->get('/etiquetas')->assertForbidden();
    }

    public function test_valida_clave_de_supervisor_y_listas(): void
    {
        $this->actingAs($this->usuario)->from('/configuracion')
            ->put('/configuracion', $this->formulario(['claveValPicking' => '', 'TamLetraMonitor' => '13', 'ordenPedSides' => 'PRECIO']))
            ->assertRedirect('/configuracion')
            ->assertSessionHasErrors(['claveValPicking', 'TamLetraMonitor', 'ordenPedSides']);

        $this->assertSame(12, (int) SidesCfg::query()->find('505094939')->TamLetraMonitor);

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
