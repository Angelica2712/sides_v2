<?php

namespace Tests\Feature;

use App\Models\Sides\SidesMonitorTv;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/**
 * Televisores: la vinculación por código (contrato de la app de TV del compañero) y la API con el
 * token de la TV. Ver App\Http\Controllers\MonitorTvApiController y TelevisoresController.
 */
class MonitorTvTest extends TestCase
{
    use TablasSides;

    private const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private SidesUsers $admin;

    private SidesUsers $pantalla;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        // El almacén en vivo trae los camiones del andén (módulo Guías).
        $this->crearTablasRutas();
        $this->crearTablasGuias();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->crearCfg(['alertaLimiteEsperaMin' => 60]);
        $this->admin = $this->crearUsuario(['name' => 'Carla Administradora', 'email' => 'carla@example.com', 'activarMonitor' => 1, 'activarConfig' => 1]);
        $this->pantalla = $this->crearUsuario(['name' => 'TV Almacén', 'email' => 'tv@example.com', 'activarTv' => 1]);

        // Recibido a las 08:30: lleva 90 minutos sin pasar a picking.
        $this->crearPedido(['id' => 700, 'estado' => 'RECIBIDO', 'ruta' => 'CENTRO']);
        $this->crearPedido(['id' => 701, 'estado' => 'PICKING', 'fecpicking' => '2026-09-15 09:40:00'], ['despachador' => 'Ana Operaria', 'recipiente' => 'CESTA 5', 'despasignado' => 1]);
        $this->crearPedido(['id' => 702, 'estado' => 'POR-APROBAR']);
        $this->crearPedido(['id' => 703, 'estado' => 'FACTURADO', 'fecfacturado' => '2026-09-15 09:59:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_tv_se_vincula_con_el_codigo_y_recibe_su_token_una_sola_vez(): void
    {
        $codigo = $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => self::UUID, 'plataforma' => 'android-tv', 'app_version' => '1.2.0'])
            ->assertCreated()
            ->assertJsonPath('data.expiraEn', 600)
            ->assertJsonPath('metadata', null)
            ->json('data.codigo');
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{3}-[A-HJKMNP-Z2-9]{3}$/', $codigo);

        $this->postJson('/api/monitor_estado', ['app_uuid' => self::UUID])->assertOk()->assertJsonPath('data.estado', 'PENDIENTE');

        // Quien administra escribe el código (sin guion y en minúsculas también sirve).
        $this->actingAs($this->admin)->get('/televisores/vincular?codigo='.strtolower(str_replace('-', '', $codigo)))
            ->assertOk()
            ->assertSee('android-tv')
            ->assertSee('TV Almacén');
        $this->actingAs($this->admin)->post('/televisores', [
            'codigo' => $codigo, 'alias' => 'TV del almacén', 'usuario_id' => $this->pantalla->id, 'vistaMonitor' => '1', 'vistaPicking' => '1',
        ])->assertRedirect('/televisores')->assertSessionHas('mensaje');

        $token = $this->postJson('/api/monitor_estado', ['app_uuid' => self::UUID])
            ->assertOk()
            ->assertJsonPath('data.estado', 'ACTIVO')
            ->assertJsonPath('data.alias', 'TV del almacén')
            ->json('data.token');
        $this->assertSame(64, strlen($token));

        $this->postJson('/api/monitor_estado', ['app_uuid' => self::UUID])->assertOk()->assertJsonMissingPath('data.token');
        $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => self::UUID])->assertStatus(409)->assertJsonPath('msg', 'TV YA VINCULADA');

        $tv = SidesMonitorTv::query()->sole();
        $this->assertSame(hash('sha256', $token), $tv->token_hash);
        $this->assertNull($tv->getRawOriginal('token_entrega'));
        $this->assertSame('505094939', $tv->codisb);

        $this->withToken($token)->getJson('/api/monitor_config')
            ->assertOk()
            ->assertJsonPath('data.alias', 'TV del almacén')
            ->assertJsonPath('data.vistas', ['monitor', 'picking'])
            ->assertJsonPath('data.drogueria.codisb', '505094939')
            ->assertJsonPath('data.mapa', true)
            ->assertJsonPath('data.tiempoReal.canal', 'sides-monitor.505094939');
        $this->assertNotNull($tv->fresh()->last_seen_at);
    }

    public function test_codigos_invalidos_y_quien_no_administra_no_vinculan(): void
    {
        $this->actingAs($this->admin)->get('/televisores/vincular?codigo=ZZZ999')
            ->assertRedirect('/televisores')->assertSessionHas('error', 'Código inválido o vencido.');

        $supervisor = $this->crearUsuario(['name' => 'Sara', 'email' => 'sara@example.com', 'activarMonitor' => 1]);
        $this->actingAs($supervisor)->get('/televisores')->assertForbidden();

        // Vencido: a los 10 minutos ya no se acepta.
        $codigo = $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => self::UUID])->json('data.codigo');
        Carbon::setTestNow('2026-09-15 10:11:00');
        $this->postJson('/api/monitor_estado', ['app_uuid' => self::UUID])->assertJsonPath('data.estado', 'VENCIDO');
        $this->actingAs($this->admin)->post('/televisores', [
            'codigo' => $codigo, 'alias' => 'TV', 'usuario_id' => $this->pantalla->id, 'vistaMonitor' => '1',
        ])->assertRedirect('/televisores')->assertSessionHas('error');
        $this->assertSame('PENDIENTE', SidesMonitorTv::query()->sole()->estado);
    }

    public function test_el_usuario_tiene_que_tener_monitor_o_pantalla_de_tv_y_al_menos_una_vista(): void
    {
        $codigo = $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => self::UUID])->json('data.codigo');
        $operario = $this->crearUsuario(['name' => 'Ana', 'email' => 'ana@example.com', 'activarPicking' => 1]);

        $this->actingAs($this->admin)->post('/televisores', ['codigo' => $codigo, 'alias' => 'TV', 'usuario_id' => $operario->id, 'vistaMonitor' => '1'])
            ->assertSessionHas('error', fn ($error) => str_contains($error, 'Pantalla de TV'));
        $this->actingAs($this->admin)->post('/televisores', ['codigo' => $codigo, 'alias' => 'TV', 'usuario_id' => $this->pantalla->id])
            ->assertSessionHas('error', 'Marca al menos una vista para esta TV.');
        $this->assertSame('PENDIENTE', SidesMonitorTv::query()->sole()->estado);
    }

    public function test_el_monitor_trae_los_pedidos_en_proceso_y_los_conteos(): void
    {
        $token = $this->vincular(['vistaMonitor' => true]);

        $this->withToken($token)->getJson('/api/monitor')
            ->assertOk()
            ->assertJsonPath('data.resumen', ['alcabala' => 1, 'recibidos' => 1, 'picking' => 1, 'packing' => 0, 'facturado' => 1])
            ->assertJsonCount(2, 'data.registros')
            ->assertJsonPath('data.registros.0.pedido', '700')
            ->assertJsonPath('data.registros.0.esperaMin', 90)
            ->assertJsonPath('data.registros.0.despachador', '—')
            ->assertJsonPath('data.registros.1.despachador', 'Ana Operaria')
            ->assertJsonPath('data.registros.1.recipiente', 'CESTA 5')
            ->assertJsonPath('data.registros.1.esperaMin', 20);
    }

    public function test_cada_vista_se_habilita_por_tv(): void
    {
        $token = $this->vincular(['vistaAlertas' => true]);

        // Sin «monitor»: vacío, sin error.
        $this->withToken($token)->getJson('/api/monitor')
            ->assertOk()->assertJsonPath('message', 'VISTA NO HABILITADA')->assertJsonPath('data.registros', []);
        $this->withToken($token)->getJson('/api/monitor_picking')->assertForbidden()->assertJsonPath('msg', 'VISTA NO HABILITADA');
        $this->withToken($token)->getJson('/api/monitor_plano')->assertForbidden();
        $this->assertSame(2, DB::table('sides_monitor_tv_log')->where('status_code', 403)->count());

        $this->withToken($token)->getJson('/api/monitor_alertas')
            ->assertOk()
            ->assertJsonPath('data.config.limiteEsperaMin', 60)
            ->assertJsonPath('data.retrasos.resumen.enRetraso', 1)
            ->assertJsonPath('data.retrasos.pedidos.0.pedido', '700')
            ->assertJsonPath('data.retrasos.pedidos.0.motivos', ['LIMITE'])
            ->assertJsonPath('data.retrasos.pedidos.0.excesoMin', 30);
    }

    public function test_picking_en_vivo_y_el_plano_con_la_vista_picking(): void
    {
        $token = $this->vincular(['vistaPicking' => true]);

        $this->withToken($token)->getJson('/api/monitor_picking')
            ->assertOk()
            ->assertJsonPath('data.operarios.0.pedido', 701)
            ->assertJsonPath('data.operarios.0.despachador', 'Ana Operaria');
        $this->withToken($token)->getJson('/api/monitor_plano')->assertOk()->assertJsonStructure(['data' => ['zonas', 'config']]);
        $this->withToken($token)->getJson('/api/monitor_alertas')->assertForbidden();
    }

    public function test_sin_token_valido_desvinculada_o_con_el_usuario_inactivo_no_entra(): void
    {
        $this->getJson('/api/monitor')->assertUnauthorized()->assertJson(['status' => 401, 'msg' => 'NO AUTORIZADO']);
        $this->withToken('otro')->getJson('/api/monitor_config')->assertUnauthorized();

        $token = $this->vincular(['vistaMonitor' => true]);
        $this->pantalla->forceFill(['estado' => 'INACTIVO'])->save();
        $this->withToken($token)->getJson('/api/monitor')->assertUnauthorized();

        $this->pantalla->forceFill(['estado' => 'ACTIVO'])->save();
        $tv = SidesMonitorTv::query()->sole();
        $this->actingAs($this->admin)->delete("/televisores/{$tv->id}")->assertRedirect('/televisores');
        $this->withToken($token)->getJson('/api/monitor')->assertUnauthorized();
        $this->postJson('/api/monitor_estado', ['app_uuid' => self::UUID])->assertJsonPath('data.estado', 'DESCONOCIDO');
    }

    public function test_si_la_tv_no_recoge_su_token_a_tiempo_vuelve_a_pedir_codigo(): void
    {
        $codigo = $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => self::UUID])->json('data.codigo');
        $this->actingAs($this->admin)->post('/televisores', ['codigo' => $codigo, 'alias' => 'TV', 'usuario_id' => $this->pantalla->id, 'vistaMonitor' => '1']);
        $this->assertNotSame($this->tokenEnClaro(), SidesMonitorTv::query()->sole()->getRawOriginal('token_entrega'));

        Carbon::setTestNow('2026-09-15 10:11:00');
        $this->postJson('/api/monitor_estado', ['app_uuid' => self::UUID])->assertJsonPath('data.estado', 'DESCONOCIDO');
        $this->assertSame(0, SidesMonitorTv::query()->count());
        $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => self::UUID])->assertCreated();
    }

    public function test_cambiar_las_vistas_no_pide_volver_a_vincular(): void
    {
        $token = $this->vincular(['vistaMonitor' => true]);
        $tv = SidesMonitorTv::query()->sole();

        $this->actingAs($this->admin)->get("/televisores/{$tv->id}")->assertOk()->assertSee('TV del almacén');
        $this->actingAs($this->admin)->put("/televisores/{$tv->id}", [
            'alias' => 'TV de despacho', 'usuario_id' => $this->admin->id, 'vistaAlertas' => '1',
        ])->assertRedirect('/televisores');

        $this->withToken($token)->getJson('/api/monitor_config')
            ->assertJsonPath('data.alias', 'TV de despacho')
            ->assertJsonPath('data.vistas', ['alertas']);
        $this->actingAs($this->admin)->get('/televisores')->assertOk()->assertSee('TV de despacho')->assertSee('Conectada');
    }

    public function test_la_firma_del_canal_es_solo_para_su_drogueria(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'clave-de-prueba',
            'secret' => 'secreto-de-prueba',
            'app_id' => '1',
            'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ]]);
        require base_path('routes/channels.php');

        $token = $this->vincular(['vistaPicking' => true]);

        $this->withToken($token)->postJson('/api/monitor_canal', ['channel_name' => 'private-sides-monitor.505094939', 'socket_id' => '1234.5678'])
            ->assertOk()->assertJsonStructure(['auth']);
        $this->withToken($token)->postJson('/api/monitor_canal', ['channel_name' => 'private-sides-monitor.999999999', 'socket_id' => '1234.5678'])
            ->assertForbidden();

        $soloAlertas = $this->vincular(['vistaAlertas' => true], 'c56a4180-65aa-42ec-a945-5fd21dec0538', 'TV 2');
        $this->withToken($soloAlertas)->postJson('/api/monitor_canal', ['channel_name' => 'private-sides-monitor.505094939', 'socket_id' => '1234.5678'])
            ->assertForbidden();
    }

    public function test_pedir_codigos_tiene_limite_por_ip(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => sprintf('0f8fad5b-d9cb-469f-a165-7086772895%02d', $i)])->assertCreated();
        }
        $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => self::UUID])->assertStatus(429)->assertJsonPath('msg', 'DEMASIADAS SOLICITUDES');
    }

    public function test_configuracion_guarda_los_parametros_de_las_alertas(): void
    {
        $base = ['TamLetraMonitor' => 14, 'ordenPedSides' => 'ORIGINAL'];

        $this->actingAs($this->admin)->put('/configuracion', [...$base, 'alertaUmbralAviso' => 30, 'alertaUmbralAlerta' => 20])
            ->assertSessionHasErrors('alertaUmbralAlerta');
        $this->actingAs($this->admin)->put('/configuracion', [...$base, 'alertaUmbralAviso' => 15, 'alertaUmbralAlerta' => 40, 'alertaLimitePickingMin' => 25])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sides_cfg', ['codisb' => '505094939', 'alertaUmbralAviso' => 15, 'alertaUmbralAlerta' => 40, 'alertaLimitePickingMin' => 25]);
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Alertas en los televisores')->assertSee('/televisores');
    }

    /** Vincula una TV por el flujo real y devuelve su token. */
    private function vincular(array $vistas, string $uuid = self::UUID, string $alias = 'TV del almacén'): string
    {
        $codigo = $this->postJson('/api/monitor_solicitar_codigo', ['app_uuid' => $uuid])->json('data.codigo');
        $this->actingAs($this->admin)->post('/televisores', [
            'codigo' => $codigo,
            'alias' => $alias,
            'usuario_id' => $this->pantalla->id,
            'vistaMonitor' => ! empty($vistas['vistaMonitor']) ? '1' : null,
            'vistaAlertas' => ! empty($vistas['vistaAlertas']) ? '1' : null,
            'vistaPicking' => ! empty($vistas['vistaPicking']) ? '1' : null,
        ])->assertSessionHas('mensaje');

        return $this->postJson('/api/monitor_estado', ['app_uuid' => $uuid])->json('data.token');
    }

    /** El token por entregar no se guarda en claro (cifrado con APP_KEY). */
    private function tokenEnClaro(): string
    {
        return SidesMonitorTv::query()->sole()->token_entrega;
    }
}
