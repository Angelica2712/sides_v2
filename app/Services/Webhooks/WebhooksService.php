<?php

namespace App\Services\Webhooks;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesAlcabalaLote;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesWebhook;
use App\Models\Sides\SidesWebhookEntrega;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avisos HTTP de SIDES a aplicaciones externas.
 *
 * Cada aviso se guarda primero como una entrega PENDIENTE (sides_webhook_entregas) y después
 * se envía: si la aplicación está caída, el aviso no se pierde y `sides:enviar-webhooks`
 * lo reintenta con espera creciente (REINTENTOS_MINUTOS).
 *
 * Contrato del envío (lo que tiene que validar quien recibe):
 * - POST JSON a la URL del webhook.
 * - X-Sides-Evento, X-Sides-Entrega (uuid, para descartar repetidos) y X-Sides-Timestamp.
 * - X-Sides-Firma: "sha256=" + HMAC-SHA256("{timestamp}.{cuerpo}", secreto).
 * - Authorization: Bearer {token}, solo si el webhook tiene token.
 * - Cualquier respuesta 2xx cuenta como entregado.
 */
class WebhooksService
{
    /** Espera antes de cada reintento; al agotarse la lista, la entrega queda FALLIDA. */
    public const REINTENTOS_MINUTOS = [1, 5, 15, 60, 360];

    private const CAMPOS_PEDIDO = [
        'id', 'codisb', 'codcli', 'nomcli', 'estado', 'documento', 'ruta', 'numren', 'numund', 'tipedido',
        'fecha', 'fecrecibido', 'fecpicking', 'fecpacking', 'fecfacturado', 'feccompletado',
    ];

    /**
     * Crea una entrega por cada webhook activo de la droguería que escucha el evento.
     *
     * @return Collection<int, SidesWebhookEntrega>
     */
    public function registrar(string $codisb, string $evento, int|string|null $id = null): Collection
    {
        if (! EventosWebhook::existe($evento)) {
            return collect();
        }

        $webhooks = SidesWebhook::query()->where('codisb', $codisb)->where('activo', 1)->get()
            ->filter(fn (SidesWebhook $webhook) => $webhook->escucha($evento));

        if ($webhooks->isEmpty()) {
            return collect();
        }

        $datos = EventosWebhook::esDeLote($evento)
            ? ['lote' => $this->lote($codisb, $id)]
            : ['pedido' => $this->pedido($codisb, $id)];

        return $webhooks->map(fn (SidesWebhook $webhook) => $this->crearEntrega($webhook, $evento, EventosWebhook::CATALOGO[$evento], $datos))->values();
    }

    /** Aviso de prueba que el administrador manda desde la pantalla de webhooks. */
    public function probar(SidesWebhook $webhook): SidesWebhookEntrega
    {
        $entrega = $this->crearEntrega($webhook, 'ping', 'Prueba de conexión desde la Administración de SIDES', []);
        $this->enviar($entrega);

        return $entrega->refresh();
    }

    /** Envía una entrega pendiente. Devuelve true si la aplicación respondió 2xx. */
    public function enviar(SidesWebhookEntrega $entrega): bool
    {
        if (! $this->apartar($entrega)) {
            return false;
        }

        $webhook = $entrega->webhook;
        if (! $webhook) {
            $entrega->update(['estado' => SidesWebhookEntrega::FALLIDO, 'ultimo_error' => 'El webhook ya no existe.', 'proximo_intento_at' => null]);

            return false;
        }

        $cuerpo = json_encode($entrega->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();

        $codigo = null;
        $error = null;
        try {
            $respuesta = Http::connectTimeout(3)->timeout(5)
                ->withHeaders($this->cabeceras($webhook, $entrega, $timestamp, $cuerpo))
                ->withBody($cuerpo, 'application/json')
                ->post($webhook->url);

            $codigo = $respuesta->status();
            if (! $respuesta->successful()) {
                $error = "HTTP {$codigo}: ".Str::limit(trim($respuesta->body()), 200);
            }
        } catch (Throwable $e) {
            $error = Str::limit($e->getMessage(), 480);
        }

        $intentos = $entrega->intentos + 1;
        $entrega->intentos = $intentos;
        $entrega->ultimo_codigo = $codigo;
        $entrega->ultimo_error = $error;

        if ($error === null) {
            $entrega->estado = SidesWebhookEntrega::ENTREGADO;
            $entrega->entregado_at = now();
            $entrega->proximo_intento_at = null;
        } elseif ($entrega->evento === 'ping' || $intentos > count(self::REINTENTOS_MINUTOS)) {
            // La prueba no se reintenta: el administrador está mirando el resultado.
            $entrega->estado = SidesWebhookEntrega::FALLIDO;
            $entrega->proximo_intento_at = null;
        } else {
            $entrega->estado = SidesWebhookEntrega::PENDIENTE;
            $entrega->proximo_intento_at = now()->addMinutes(self::REINTENTOS_MINUTOS[$intentos - 1]);
        }
        $entrega->save();

        return $error === null;
    }

    /** Lo que corre `sides:enviar-webhooks`: las pendientes cuyo turno ya llegó. */
    public function enviarPendientes(int $limite = 100): int
    {
        $entregas = SidesWebhookEntrega::query()
            ->where('estado', SidesWebhookEntrega::PENDIENTE)
            ->where(fn ($q) => $q->whereNull('proximo_intento_at')->orWhere('proximo_intento_at', '<=', now()))
            ->orderBy('id')->limit($limite)->get();

        return $entregas->filter(fn (SidesWebhookEntrega $entrega) => $this->enviar($entrega))->count();
    }

    /** Vuelve a poner en cola una entrega fallida (botón "Reintentar"). */
    public function reintentar(SidesWebhookEntrega $entrega): void
    {
        $entrega->update(['estado' => SidesWebhookEntrega::PENDIENTE, 'intentos' => 0, 'proximo_intento_at' => null]);
        $this->enviar($entrega);
    }

    public static function nuevoSecreto(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** Firma que el receptor recalcula con su copia del secreto. */
    public static function firmar(string $timestamp, string $cuerpo, string $secreto): string
    {
        return 'sha256='.hash_hmac('sha256', "{$timestamp}.{$cuerpo}", $secreto);
    }

    /**
     * Reserva la entrega por dos minutos para que el comando y el envío inmediato de la misma
     * petición no la manden dos veces. Solo uno de los dos consigue actualizar la fila.
     */
    private function apartar(SidesWebhookEntrega $entrega): bool
    {
        $apartada = SidesWebhookEntrega::query()
            ->whereKey($entrega->getKey())
            ->where('estado', SidesWebhookEntrega::PENDIENTE)
            ->where(fn ($q) => $q->whereNull('proximo_intento_at')->orWhere('proximo_intento_at', '<=', now()))
            ->update(['proximo_intento_at' => now()->addMinutes(2)]);

        if ($apartada) {
            $entrega->refresh();
        }

        return $apartada === 1;
    }

    private function crearEntrega(SidesWebhook $webhook, string $evento, string $descripcion, array $datos): SidesWebhookEntrega
    {
        $uuid = (string) Str::uuid();

        return SidesWebhookEntrega::query()->create([
            'uuid' => $uuid,
            'webhook_id' => $webhook->id,
            'evento' => $evento,
            'estado' => SidesWebhookEntrega::PENDIENTE,
            'payload' => [
                'id' => $uuid,
                'evento' => $evento,
                'descripcion' => $descripcion,
                'ocurrido_en' => now()->toIso8601String(),
                'codisb' => $webhook->codisb,
                ...$datos,
            ],
        ]);
    }

    /** @return array<string, string> */
    private function cabeceras(SidesWebhook $webhook, SidesWebhookEntrega $entrega, string $timestamp, string $cuerpo): array
    {
        $cabeceras = [
            'User-Agent' => 'SIDES-Webhooks/1.0',
            'X-Sides-Evento' => $entrega->evento,
            'X-Sides-Entrega' => $entrega->uuid,
            'X-Sides-Timestamp' => $timestamp,
            'X-Sides-Firma' => self::firmar($timestamp, $cuerpo, $webhook->secreto),
        ];

        if ($webhook->token) {
            $cabeceras['Authorization'] = 'Bearer '.$webhook->token;
        }

        return $cabeceras;
    }

    /** Resumen del pedido: la aplicación externa no tiene acceso a la base compartida. */
    private function pedido(string $codisb, int|string|null $id): ?array
    {
        $pedido = $id === null ? null : Pedido::query()->where('codisb', $codisb)->find($id);
        if (! $pedido) {
            return $id === null ? null : ['id' => (int) $id];
        }

        $operacion = SidesPedidoOperacion::query()->find($pedido->id);

        return [
            ...collect(self::CAMPOS_PEDIDO)->mapWithKeys(fn ($campo) => [$campo => $pedido->getAttribute($campo)])->all(),
            'despachador' => $operacion?->despachador,
            'embalador' => $operacion?->embalador,
        ];
    }

    private function lote(string $codisb, int|string|null $id): ?array
    {
        $lote = $id === null ? null : SidesAlcabalaLote::query()->where('codisb', $codisb)->find($id);
        if (! $lote) {
            return $id === null ? null : ['id' => (int) $id];
        }

        return [
            'id' => $lote->id,
            'nombre' => $lote->nombre,
            'estado' => $lote->estado,
            'pedidos' => $lote->pedidos()->orderBy('id')->pluck('numped')->map(fn ($numped) => (int) $numped)->all(),
        ];
    }
}
