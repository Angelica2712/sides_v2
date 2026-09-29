<?php

namespace Tests\Feature;

use App\Models\Sides\SidesMonitor;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class FiltroMonitorTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearCfg();
    }

    public function test_lista_crea_modifica_y_elimina_un_filtro_de_la_sucursal(): void
    {
        $usuario = $this->crearUsuario(['activarConfig' => 1]);
        SidesMonitor::query()->create(['descrip' => 'De otra sucursal', 'criterio' => 'NORTE', 'codisb' => 'OTRA']);

        $this->actingAs($usuario)->get('/filtro-monitor')->assertOk()
            ->assertSee('Todavía no hay filtros')
            ->assertDontSee('De otra sucursal');

        $this->post('/filtro-monitor', ['descrip' => 'Zona norte', 'criterio' => 'NORTE 1, NORTE 2', 'caracterLogo' => 'N'])
            ->assertRedirect(route('filtromonitor.index'))
            ->assertSessionHas('mensaje', 'Filtro "Zona norte" creado.');

        $filtro = SidesMonitor::query()->where('descrip', 'Zona norte')->firstOrFail();
        $this->assertSame(['505094939', 'NORTE 1, NORTE 2', 'N'], [$filtro->codisb, $filtro->criterio, $filtro->caracterLogo]);

        $this->get('/filtro-monitor')->assertOk()->assertSee('Zona norte')->assertSee('NORTE 1, NORTE 2');

        $this->put("/filtro-monitor/{$filtro->id}", ['descrip' => 'Zona norte', 'criterio' => 'NORTE', 'caracterLogo' => ''])
            ->assertRedirect(route('filtromonitor.index'))
            ->assertSessionHas('mensaje', 'Filtro "Zona norte" actualizado.');
        $this->assertSame('NORTE', $filtro->fresh()->criterio);

        $this->delete("/filtro-monitor/{$filtro->id}")
            ->assertRedirect(route('filtromonitor.index'))
            ->assertSessionHas('mensaje', 'Filtro "Zona norte" eliminado.');
        $this->assertSame(0, SidesMonitor::query()->where('codisb', '505094939')->count());
    }

    public function test_valida_los_campos_y_no_deja_tocar_filtros_de_otra_sucursal(): void
    {
        $usuario = $this->crearUsuario(['activarConfig' => 1]);
        $ajeno = SidesMonitor::query()->create(['descrip' => 'Ajeno', 'criterio' => 'SUR', 'codisb' => 'OTRA']);

        $this->actingAs($usuario);
        $this->post('/filtro-monitor', ['descrip' => '', 'criterio' => ''])->assertSessionHasErrors(['descrip', 'criterio']);
        $this->get("/filtro-monitor/{$ajeno->id}")->assertNotFound();
        $this->put("/filtro-monitor/{$ajeno->id}", ['descrip' => 'x', 'criterio' => 'x'])->assertNotFound();
        $this->delete("/filtro-monitor/{$ajeno->id}")->assertNotFound();
    }

    public function test_requiere_el_permiso_de_configuracion(): void
    {
        $usuario = $this->crearUsuario(['activarConfig' => 0]);

        $this->actingAs($usuario)->get('/filtro-monitor')->assertForbidden();
    }
}
