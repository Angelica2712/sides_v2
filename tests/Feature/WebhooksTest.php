<?php

namespace Tests\Feature;

use App\Models\Sides\SidesUsers;
use App\Models\Sides\SidesWebhook;
use App\Models\Sides\SidesWebhookEntrega;
use App\Services\Webhooks\WebhooksService;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutDefer();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-22 10:00:00');

        $this->crearCfg();
        $this->admin = $this->crearUsuario(['name' => 'Admin', 'email' => 'admin@example.com', 'esAdmin' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function webhook(array $atributos = []): SidesWebhook
    {
        return SidesWebhook::query()->create(array_merge([
            'codisb' => '505094939',
            'nombre' => 'App despacho',
            'url' => 'https://app.test/webhooks/sides',
            'secreto' => 'whsec_prueba',
            'activo' => true,
        ], $atributos));
    }

    public function test_solo_el_administrador_gestiona_webhooks(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@example.com', 'activarPicking' => 1, 'activarConfig' => 1]);

        $this->actingAs($operario)->get('/admin/droguerias/505094939/webhooks')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/droguerias/505094939/webhooks')->assertOk()->assertSee('Nuevo webhook');
    }

    public function test_crear_webhook_muestra_el_secreto_una_vez_y_lo_guarda_cifrado(): void
    {
        $this->actingAs($this->admin)->post('/admin/droguerias/505094939/webhooks', [
            'nombre' => 'App despacho',
            'url' => 'https://app.test/webhooks/sides',
            'token' => 'token-del-companero',
            'eventos' => ['picking.terminar', 'packing.terminar'],
        ])->assertRedirect(route('admin.webhooks.index', '505094939'))->assertSessionHas('secreto');

        $webhook = SidesWebhook::query()->sole();
        $this->assertStringStartsWith('whsec_', $webhook->secreto);
        $this->assertSame('token-del-companero', $webhook->token);
        $this->assertSame(['picking.terminar', 'packing.terminar'], $webhook->eventos);

        $crudo = DB::table('sides_webhooks')->first();
        $this->assertStringNotContainsString($webhook->secreto, $crudo->secreto);
        $this->assertStringNotContainsString('token-del-companero', $crudo->token);

        $this->post('/admin/droguerias/505094939/webhooks', ['nombre' => 'X', 'url' => 'ftp://x', 'eventos' => ['inventado']])
            ->assertSessionHasErrors(['url' => 'La URL debe empezar por http:// o https://.', 'eventos.0']);
    }

    public function test_tomar_un_pedido_avisa_por_webhook_firmado(): void
    {
        Http::fake(['app.test/*' => Http::response(['ok' => true], 200)]);
        $this->webhook(['token' => 'token-del-companero']);
        $this->crearPedido(['id' => 500, 'estado' => 'RECIBIDO', 'nomcli' => 'CLIENTE 500']);
        $this->crearRenglon(500, 1, ['cantidad' => 3]);
        $operario = $this->crearUsuario(['name' => 'Ana', 'email' => 'ana@example.com', 'activarPicking' => 1]);

        $this->actingAs($operario)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5'])->assertRedirect();

        Http::assertSent(function (PeticionHttp $peticion) {
            $cuerpo = $peticion->body();
            $datos = json_decode($cuerpo, true);

            return $peticion->url() === 'https://app.test/webhooks/sides'
                && $peticion->header('X-Sides-Evento')[0] === 'picking.tomar'
                && $peticion->header('Authorization')[0] === 'Bearer token-del-companero'
                && $peticion->header('X-Sides-Firma')[0] === WebhooksService::firmar($peticion->header('X-Sides-Timestamp')[0], $cuerpo, 'whsec_prueba')
                && $datos['pedido']['id'] === 500
                && $datos['pedido']['estado'] === 'PICKING'
                && $datos['pedido']['despachador'] === 'Ana'
                && $datos['codisb'] === '505094939';
        });

        $entrega = SidesWebhookEntrega::query()->sole();
        $this->assertSame([SidesWebhookEntrega::ENTREGADO, 1, 200], [$entrega->estado, $entrega->intentos, $entrega->ultimo_codigo]);
    }

    public function test_solo_avisa_los_eventos_elegidos_y_los_webhooks_activos(): void
    {
        Http::fake();
        $this->webhook(['eventos' => ['packing.terminar']]);
        $this->webhook(['nombre' => 'Pausado', 'activo' => false]);
        $this->webhook(['codisb' => 'OTRA']);

        $entregas = app(WebhooksService::class)->registrar('505094939', 'picking.tomar', 1);
        $this->assertCount(0, $entregas);

        $this->assertCount(1, app(WebhooksService::class)->registrar('505094939', 'packing.terminar', 1));
        $this->assertCount(0, app(WebhooksService::class)->registrar('505094939', 'motivo.desconocido', 1));
    }

    public function test_si_la_aplicacion_falla_reintenta_con_espera_y_al_final_queda_fallido(): void
    {
        $respuesta = Http::response('caido', 503);
        Http::fake(['app.test/*' => function () use (&$respuesta) { return $respuesta; }]);
        $this->webhook();
        $servicio = app(WebhooksService::class);

        $entrega = $servicio->registrar('505094939', 'pedidos.anular', 77)->sole();
        $this->assertFalse($servicio->enviar($entrega));

        $entrega->refresh();
        $this->assertSame([SidesWebhookEntrega::PENDIENTE, 1, 503], [$entrega->estado, $entrega->intentos, $entrega->ultimo_codigo]);
        $this->assertSame('2026-09-22 10:01:00', $entrega->proximo_intento_at->format('Y-m-d H:i:s'));
        $this->assertSame(['id' => 77], $entrega->payload['pedido']);

        // Antes de su turno el comando no la toca.
        $this->artisan('sides:enviar-webhooks')->assertSuccessful();
        $this->assertSame(1, $entrega->refresh()->intentos);

        foreach (WebhooksService::REINTENTOS_MINUTOS as $minutos) {
            Carbon::setTestNow(now()->addMinutes($minutos));
            $this->artisan('sides:enviar-webhooks')->assertSuccessful();
        }

        $entrega->refresh();
        $this->assertSame([SidesWebhookEntrega::FALLIDO, 6], [$entrega->estado, $entrega->intentos]);
        $this->assertNull($entrega->proximo_intento_at);

        $respuesta = Http::response('', 204);
        $this->actingAs($this->admin)->post("/admin/webhooks/entregas/{$entrega->id}/reintentar")
            ->assertSessionHas('mensaje', 'Aviso pedidos.anular entregado.');
        $this->assertSame(SidesWebhookEntrega::ENTREGADO, $entrega->refresh()->estado);
    }

    public function test_probar_la_conexion_informa_el_resultado(): void
    {
        $webhook = $this->webhook();
        $respuesta = Http::response('', 200);
        Http::fake(['app.test/*' => function () use (&$respuesta) { return $respuesta; }]);

        $this->actingAs($this->admin)->post("/admin/webhooks/{$webhook->id}/probar")
            ->assertSessionHas('mensaje', 'App despacho respondió 200: la conexión funciona.');

        $respuesta = Http::response('no autorizado', 401);
        $this->post("/admin/webhooks/{$webhook->id}/probar")
            ->assertSessionHas('error', 'App despacho no recibió la prueba: HTTP 401: no autorizado');

        $this->assertSame(0, SidesWebhookEntrega::query()->where('estado', SidesWebhookEntrega::PENDIENTE)->count());
    }

    public function test_editar_conserva_el_token_si_no_se_escribe_otro_y_eliminar_borra_el_historial(): void
    {
        Http::fake();
        $webhook = $this->webhook(['token' => 'viejo']);
        app(WebhooksService::class)->registrar('505094939', 'picking.tomar', 1);

        $this->actingAs($this->admin)->put("/admin/webhooks/{$webhook->id}", [
            'nombre' => 'Renombrado', 'url' => 'https://otra.test/hook', 'token' => '',
        ])->assertRedirect();

        $webhook->refresh();
        $this->assertSame(['Renombrado', 'https://otra.test/hook', 'viejo', false], [$webhook->nombre, $webhook->url, $webhook->token, $webhook->activo]);

        $this->delete("/admin/webhooks/{$webhook->id}")->assertRedirect();
        $this->assertSame(0, SidesWebhook::query()->count() + SidesWebhookEntrega::query()->count());
    }
}
