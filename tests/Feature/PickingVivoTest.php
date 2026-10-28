<?php

namespace Tests\Feature;

use App\Events\PickingAvanzo;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class PickingVivoTest extends TestCase
{
    use TablasSides;

    private SidesUsers $operario;

    private SidesUsers $supervisor;

    private SidesUsers $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->crearCfg(['ordenPedSides' => 'UBICACION']);
        $this->operario = $this->crearUsuario(['name' => 'Ana Operaria', 'email' => 'ana@example.com', 'activarPicking' => 1]);
        $this->supervisor = $this->crearUsuario(['name' => 'Sara Supervisora', 'email' => 'sara@example.com', 'activarMonitor' => 1]);
        $this->admin = $this->crearUsuario(['name' => 'Carla Administradora', 'email' => 'carla@example.com', 'activarMonitor' => 1, 'activarConfig' => 1]);

        $this->crearPedido(['id' => 500, 'estado' => 'RECIBIDO', 'nomcli' => 'FARMACIA UNO']);
        $this->crearRenglon(500, 1, ['desprod' => 'IBUPROFENO', 'ubicacion' => 'PK-02-01-A-01', 'cantidad' => 3]);
        $this->crearRenglon(500, 2, ['desprod' => 'ACETAMINOFEN', 'ubicacion' => 'PK-01-03-B-02', 'cantidad' => 2]);
        $this->crearRenglon(500, 3, ['desprod' => 'LORATADINA', 'ubicacion' => 'PK-01-05-A-04', 'cantidad' => 1]);
        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_solo_lo_ve_el_administrador_de_la_drogueria(): void
    {
        $this->actingAs($this->operario)->get('/monitor/picking')->assertForbidden();
        $this->actingAs($this->supervisor)->get('/monitor/picking')->assertForbidden();
        $this->actingAs($this->supervisor)->getJson('/monitor/picking/datos')->assertForbidden();

        $this->actingAs($this->admin)->get('/monitor/picking')
            ->assertOk()
            ->assertSee('Picking en vivo')
            ->assertSee('sides-monitor.505094939');
    }

    public function test_el_boton_del_monitor_solo_sale_para_el_administrador(): void
    {
        $this->actingAs($this->supervisor)->get('/monitor')->assertOk()->assertDontSee('/monitor/picking');
        $this->actingAs($this->admin)->get('/monitor')->assertOk()->assertSee('/monitor/picking');
    }

    public function test_cada_despachador_con_su_avance_lo_que_busca_y_lo_que_sigue(): void
    {
        $this->actingAs($this->operario)->postJson('/picking/500/cantidad', ['item' => 2, 'cantidad' => 2, 'manual' => true])->assertOk();

        $this->actingAs($this->admin)->getJson('/monitor/picking/datos')
            ->assertOk()
            ->assertJsonCount(1, 'operarios')
            ->assertJsonPath('operarios.0.despachador', 'Ana Operaria')
            ->assertJsonPath('operarios.0.pedido', 500)
            ->assertJsonPath('operarios.0.recipiente', 'CESTA 5')
            ->assertJsonPath('operarios.0.productos', 3)
            ->assertJsonPath('operarios.0.revisados', 1)
            ->assertJsonPath('operarios.0.despachadas', 2)
            ->assertJsonPath('operarios.0.actual.ubicacion', 'PK-01-05-A-04')
            ->assertJsonPath('operarios.0.siguiente.ubicacion', 'PK-02-01-A-01');
    }

    public function test_guardar_una_cantidad_avisa_el_movimiento_por_el_canal_del_monitor(): void
    {
        Event::fake([PickingAvanzo::class]);

        $this->actingAs($this->operario)->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 2, 'manual' => true])->assertOk();

        Event::assertDispatched(PickingAvanzo::class, function (PickingAvanzo $aviso) {
            return $aviso->broadcastOn()[0]->name === 'private-sides-monitor.505094939'
                && $aviso->broadcastAs() === 'picking'
                && [$aviso->pedido, $aviso->despachador, $aviso->producto, $aviso->ubicacion, $aviso->cantidad, $aviso->solicitado]
                    === [500, 'Ana Operaria', 'IBUPROFENO', 'PK-02-01-A-01', 2, 3];
        });
    }

    public function test_una_cantidad_rechazada_no_avisa(): void
    {
        Event::fake([PickingAvanzo::class]);

        $this->actingAs($this->operario)->postJson('/picking/500/cantidad', ['item' => 1, 'cantidad' => 9, 'manual' => true])->assertStatus(422);

        Event::assertNotDispatched(PickingAvanzo::class);
    }
}
