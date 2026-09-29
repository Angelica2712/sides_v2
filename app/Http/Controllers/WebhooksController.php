<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesWebhook;
use App\Models\Sides\SidesWebhookEntrega;
use App\Services\Webhooks\EventosWebhook;
use App\Services\Webhooks\WebhooksService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administración de los webhooks de una droguería: aplicaciones externas que reciben avisos de SIDES. */
class WebhooksController extends Controller
{
    public function __construct(private readonly WebhooksService $webhooks)
    {
    }

    public function index(string $codisb): View
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);
        $webhooks = SidesWebhook::query()->where('codisb', $codisb)->orderBy('nombre')->get();

        return view('admin.webhooks', [
            'drogueria' => $drogueria,
            'webhooks' => $webhooks,
            'entregas' => SidesWebhookEntrega::query()->with('webhook')
                ->whereIn('webhook_id', $webhooks->pluck('id'))
                ->latest('id')->limit(30)->get(),
        ]);
    }

    public function store(Request $request, string $codisb): RedirectResponse
    {
        SidesCfg::query()->findOrFail($codisb);
        $datos = $request->validate($this->reglas(), $this->mensajes());
        $secreto = WebhooksService::nuevoSecreto();

        $webhook = SidesWebhook::query()->create([
            'codisb' => $codisb,
            'nombre' => $datos['nombre'],
            'url' => $datos['url'],
            'token' => $datos['token'] ?? null,
            'eventos' => $datos['eventos'] ?? [],
            'secreto' => $secreto,
            'activo' => true,
            'creado_por' => $request->user()->email,
        ]);

        return redirect()->route('admin.webhooks.index', $codisb)
            ->with('mensaje', "Webhook {$webhook->nombre} creado.")
            ->with('secreto', ['webhook' => $webhook->id, 'valor' => $secreto]);
    }

    public function update(Request $request, SidesWebhook $webhook): RedirectResponse
    {
        $datos = $request->validate($this->reglas(), $this->mensajes());

        $webhook->fill([
            'nombre' => $datos['nombre'],
            'url' => $datos['url'],
            'eventos' => $datos['eventos'] ?? [],
            'activo' => $request->boolean('activo'),
        ]);
        // El token no se vuelve a mostrar: vacío lo deja como estaba, salvo que pidan quitarlo.
        if ($request->boolean('quitar_token')) {
            $webhook->token = null;
        } elseif (! empty($datos['token'])) {
            $webhook->token = $datos['token'];
        }
        $webhook->save();

        return redirect()->route('admin.webhooks.index', $webhook->codisb)->with('mensaje', "Webhook {$webhook->nombre} actualizado.");
    }

    public function destroy(SidesWebhook $webhook): RedirectResponse
    {
        $webhook->entregas()->delete();
        $webhook->delete();

        return redirect()->route('admin.webhooks.index', $webhook->codisb)->with('mensaje', "Webhook {$webhook->nombre} eliminado.");
    }

    public function secreto(SidesWebhook $webhook): RedirectResponse
    {
        $secreto = WebhooksService::nuevoSecreto();
        $webhook->update(['secreto' => $secreto]);

        return redirect()->route('admin.webhooks.index', $webhook->codisb)
            ->with('mensaje', "Secreto de {$webhook->nombre} cambiado. El anterior ya no sirve.")
            ->with('secreto', ['webhook' => $webhook->id, 'valor' => $secreto]);
    }

    public function probar(SidesWebhook $webhook): RedirectResponse
    {
        $entrega = $this->webhooks->probar($webhook);
        $ruta = redirect()->route('admin.webhooks.index', $webhook->codisb);

        return $entrega->estado === SidesWebhookEntrega::ENTREGADO
            ? $ruta->with('mensaje', "{$webhook->nombre} respondió {$entrega->ultimo_codigo}: la conexión funciona.")
            : $ruta->with('error', "{$webhook->nombre} no recibió la prueba: {$entrega->ultimo_error}");
    }

    public function reintentar(SidesWebhookEntrega $entrega): RedirectResponse
    {
        $entrega->loadMissing('webhook');
        abort_unless($entrega->webhook, 404);

        $this->webhooks->reintentar($entrega);
        $entrega->refresh();

        return redirect()->route('admin.webhooks.index', $entrega->webhook->codisb)->with(
            ...($entrega->estado === SidesWebhookEntrega::ENTREGADO
                ? ['mensaje', "Aviso {$entrega->evento} entregado."]
                : ['error', "El aviso {$entrega->evento} sigue sin entregarse: {$entrega->ultimo_error}"])
        );
    }

    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:500'],
            'token' => ['nullable', 'string', 'max:500'],
            'eventos' => ['array'],
            'eventos.*' => ['string', Rule::in(array_keys(EventosWebhook::CATALOGO))],
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'Escribe un nombre para reconocer la aplicación.',
            'url.required' => 'Escribe la URL que recibe los avisos.',
            'url.url' => 'La URL debe empezar por http:// o https://.',
            'eventos.*.in' => 'Uno de los eventos elegidos no existe.',
        ];
    }
}
