<?php

namespace Tests\Feature;

use App\Events\MonitorActualizado;
use App\Models\Sides\SidesGuia;
use App\Models\Sides\SidesUsers;
use App\Services\Guias\GuiasService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** Los camiones del andén en el Almacén 3D en vivo (PickingVivoController::camiones). */
class AndenVivoTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearTablasRutas();
        $this->crearTablasGuias();
        $this->admin = $this->crearUsuario(['email' => 'carla@example.com', 'activarMonitor' => 1, 'activarConfig' => 1]);
    }

    private function guia(int $id, string $estado, array $bultos): void
    {
        SidesGuia::query()->create(['id' => $id, 'codisb' => '505094939', 'estado' => $estado, 'ruta' => 'ESTE', 'nomchofer' => 'PEDRO PEREZ', 'unidad' => "AB{$id}CD - Camión blanco"]);
        foreach ($bultos as $i => [$pedido, $cliente, $cargado]) {
            DB::table('sides_etiqueta_pedido')->insert([
                'numepedi' => (string) $pedido, 'etiqueta' => "{$pedido}-{$i}", 'nomcli' => $cliente, 'ruta' => 'ESTE', 'codcli' => "C{$pedido}",
                'guia' => $id, 'estado' => $cargado ? 'CARGADO' : 'EN GUIA', 'feccargado' => $cargado ? now() : null,
            ]);
        }
    }

    public function test_cada_camion_con_sus_pedidos_y_los_bultos_montados(): void
    {
        $this->crearCfg([], ['guias']);
        $this->guia(7, 'CARGANDO', [[100, 'FARMACIA UNO', true], [100, 'FARMACIA UNO', true], [101, 'FARMACIA DOS', true], [101, 'FARMACIA DOS', false]]);
        $this->guia(8, 'NUEVO', [[102, 'FARMACIA TRES', false]]);
        // Ya salió a ruta: no está en el andén.
        $this->guia(9, 'TRANSITO', [[103, 'FARMACIA CUATRO', true]]);

        $this->actingAs($this->admin)->getJson('/monitor/picking/datos')
            ->assertOk()
            ->assertJsonCount(2, 'camiones')
            ->assertJsonPath('camiones.0', [
                'guia' => 7,
                'placa' => 'AB7CD',
                'unidad' => 'AB7CD - Camión blanco',
                'chofer' => 'PEDRO PEREZ',
                'ruta' => 'ESTE',
                'estado' => 'CARGANDO',
                'bultos' => 4,
                'cargados' => 3,
                'pedidos' => [
                    ['pedido' => 100, 'cliente' => 'FARMACIA UNO', 'bultos' => 2, 'cargados' => 2],
                    ['pedido' => 101, 'cliente' => 'FARMACIA DOS', 'bultos' => 2, 'cargados' => 1],
                ],
            ])
            ->assertJsonPath('camiones.1.guia', 8)
            ->assertJsonPath('camiones.1.cargados', 0);
    }

    public function test_sin_el_modulo_guias_no_hay_anden(): void
    {
        $this->crearCfg([], []);
        $this->guia(7, 'CARGANDO', [[100, 'FARMACIA UNO', true]]);

        $this->actingAs($this->admin)->getJson('/monitor/picking/datos')->assertOk()->assertJsonPath('camiones', null);
    }

    public function test_montar_un_bulto_avisa_a_la_pantalla_en_vivo(): void
    {
        $this->crearCfg([], ['guias']);
        $this->guia(7, 'NUEVO', [[100, 'FARMACIA UNO', false]]);
        Event::fake([MonitorActualizado::class]);

        app(GuiasService::class)->cargar(SidesGuia::query()->find(7), '100-0');

        Event::assertDispatched(MonitorActualizado::class, fn ($evento) => $evento->motivo === 'anden' && $evento->codisb === '505094939');
        $this->assertSame('CARGADO', SidesGuia::query()->find(7)->estado);
    }

    public function test_al_salir_de_la_drogueria_el_camion_deja_el_anden(): void
    {
        $this->crearCfg([], ['guias']);
        $this->guia(7, 'CARGANDO', [[100, 'FARMACIA UNO', true], [101, 'FARMACIA DOS', false]]);
        $this->actingAs($this->admin);

        // Con bultos sin cargar no sale.
        $this->from('/guias/7')->post('/guias/7/salida')->assertRedirect('/guias/7')->assertSessionHasErrors('guia');
        $this->assertNull(SidesGuia::query()->find(7)->fecha_salida);

        app(GuiasService::class)->cargar(SidesGuia::query()->find(7), '101-1');
        $this->get('/guias/7')->assertSee('Salió de la droguería');
        $this->post('/guias/7/salida')->assertRedirect('/guias/7')->assertSessionHas('mensaje');

        $this->assertNotNull(SidesGuia::query()->find(7)->fecha_salida);
        $this->getJson('/monitor/picking/datos')->assertJsonPath('camiones', []);
        $this->from('/guias/7')->post('/guias/7/salida')->assertSessionHasErrors('guia');
    }

    public function test_el_cargador_marca_la_salida_desde_carga_y_descarga(): void
    {
        $this->crearCfg([], ['guias']);
        $this->guia(7, 'CARGADO', [[100, 'FARMACIA UNO', true]]);
        $cargador = $this->crearUsuario(['email' => 'carga@example.com', 'activarGuiaCarga' => 1]);

        $this->actingAs($cargador)->get('/despacho/7/carga')->assertOk()->assertSee('Salió de la droguería');
        $this->post('/despacho/7/salida')->assertRedirect(route('despacho.index'));
        $this->assertNotNull(SidesGuia::query()->find(7)->fecha_salida);

        $soloDescarga = $this->crearUsuario(['email' => 'chofer@example.com', 'activarGuiaDescarga' => 1]);
        $this->actingAs($soloDescarga)->post('/despacho/7/salida')->assertForbidden();
    }
}
