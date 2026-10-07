<?php

namespace App\Services\Auditoria;

use App\Models\Sides\SidesAuditoria;
use App\Models\Sides\SidesUsers;
use App\Support\MenuSides;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Auditoría de SIDES v2: qué hizo cada usuario, cuándo, desde dónde y con qué resultado.
 *
 * Se registra en un solo punto (el middleware RegistrarAuditoria) toda petición que cambia algo
 * (POST/PUT/PATCH/DELETE), así un módulo nuevo queda auditado sin tocarlo; los inicios y cierres
 * de sesión llegan por los eventos de Auth (AppServiceProvider). Las lecturas no se registran.
 *
 * Reglas:
 * - Nunca se guardan contraseñas, claves de supervisor, tokens ni secretos: quedan como "***".
 * - Las lecturas del escáner una por una (packing.escanear, picking/batch.cantidad) no se
 *   registran: serían miles por día y el resultado queda al tomar, terminar o ajustar.
 * - Un formulario rechazado por validación no se registra (no cambió nada); una acción que el
 *   sistema no dejó hacer queda como FALLIDO con su motivo, y un acceso sin permiso como DENEGADO.
 * - Registrar nunca rompe la acción del usuario: si falla, se anota en el log y sigue.
 */
class Auditoria
{
    /** Acciones que no se registran (ver arriba). login/logout llegan por eventos. */
    public const IGNORADAS = ['packing.escanear', 'picking.cantidad', 'batch.cantidad', 'logout'];

    private const SENSIBLES = ['password', 'clave', 'token', 'secreto', 'secret'];

    /** Descripción por acción; :parametro se reemplaza por el de la ruta. */
    public const DESCRIPCIONES = [
        'picking.tomar' => 'Tomó el pedido #:pedido para picking',
        'picking.alerta' => 'Marcó o quitó una alerta en el pedido #:pedido',
        'picking.terminar' => 'Terminó el picking del pedido #:pedido',
        'picking.liberar' => 'Liberó el pedido #:pedido en picking',
        'batch.agrupar' => 'Agrupó pedidos en un lote de Batch Picking',
        'batch.liberar' => 'Liberó pedidos en espera al picking normal',
        'batch.iniciar' => 'Inició el lote #:lote',
        'batch.anular' => 'Anuló el lote #:lote',
        'batch.terminar' => 'Terminó el lote #:lote',
        'packing.ajustar' => 'Ajustó una cantidad en el packing del pedido #:pedido',
        'packing.clave' => 'Desbloqueó con clave de supervisor el packing del pedido #:pedido',
        'packing.lote' => 'Cambió el lote de un producto en el pedido #:pedido',
        'packing.terminar' => 'Terminó el packing del pedido #:pedido',
        'packing.liberar' => 'Liberó el pedido #:pedido en packing',
        'etiquetas.generar' => 'Generó las etiquetas del pedido #:pedido',
        'pedidos.update' => 'Modificó el pedido #:pedido',
        'pedidos.resetear' => 'Reseteó el pedido #:pedido',
        'pedidos.anular' => 'Anuló el pedido #:pedido',
        'pedidos.agrupar' => 'Agrupó pedidos para facturar',
        'pedidos.desagrupar' => 'Deshizo el grupo de facturación #:grupo',
        'monitor.letra' => 'Cambió el tamaño de la letra del monitor',
        'filtromonitor.store' => 'Creó un filtro del monitor',
        'filtromonitor.update' => 'Modificó el filtro del monitor #:filtro',
        'filtromonitor.destroy' => 'Eliminó el filtro del monitor #:filtro',
        'configuracion.update' => 'Cambió la configuración de la sucursal',
        'usuarios.store' => 'Creó un usuario',
        'usuarios.update' => 'Modificó el usuario #:usuario',
        'usuarios.clave' => 'Cambió la contraseña del usuario #:usuario',
        'usuarios.destroy' => 'Eliminó el usuario #:usuario',
        'rutas.store' => 'Creó una ruta',
        'rutas.importar' => 'Importó rutas desde Excel',
        'rutas.seped' => 'Trajo rutas desde SEPED',
        'rutas.sincronizar' => 'Sincronizó las rutas',
        'rutas.update' => 'Modificó la ruta #:ruta',
        'rutas.destroy' => 'Eliminó la ruta #:ruta',
        'rutas.zona' => 'Cambió la zona de la ruta #:ruta',
        'rutas.clientes.store' => 'Agregó clientes a la ruta #:ruta',
        'rutas.clientes.update' => 'Modificó un cliente de la ruta #:ruta',
        'rutas.clientes.destroy' => 'Quitó un cliente de la ruta #:ruta',
        'guias.store' => 'Creó una guía de despacho',
        'guias.update' => 'Modificó la guía #:guia',
        'guias.destroy' => 'Eliminó la guía #:guia',
        'guias.separar' => 'Separó la guía #:guia',
        'guias.pedidos.store' => 'Agregó pedidos a la guía #:guia',
        'guias.pedidos.destroy' => 'Quitó el pedido #:pedido de la guía #:guia',
        'guias.clientes.destroy' => 'Quitó un cliente de la guía #:guia',
        'guias.entregar' => 'Marcó como entregada la guía #:guia',
        'guias.reiniciar' => 'Reinició la guía #:guia',
        'despacho.cargar' => 'Cargó un bulto de la guía #:guia al camión',
        'despacho.entregar' => 'Entregó bultos de la guía #:guia',
        'informes.inactividad.destroy' => 'Quitó un registro de inactividad de :tipo',
        'admin.store' => 'Dio de alta una droguería',
        'admin.update' => 'Cambió los datos, el logo o los módulos de la droguería :codisb',
        'admin.encargado' => 'Creó el usuario encargado de la droguería :codisb',
        'admin.clave' => 'Generó una contraseña nueva para el usuario #:usuario de la droguería :codisb',
        'admin.webhooks.store' => 'Creó un webhook para la droguería :codisb',
        'admin.webhooks.update' => 'Modificó el webhook #:webhook',
        'admin.webhooks.destroy' => 'Eliminó el webhook #:webhook',
        'admin.webhooks.secreto' => 'Cambió el secreto del webhook #:webhook',
        'admin.webhooks.probar' => 'Probó el webhook #:webhook',
        'admin.webhooks.reintentar' => 'Reintentó una entrega de webhook',
        'sesion.entrar' => 'Inició sesión',
        'sesion.salir' => 'Cerró sesión',
        'sesion.fallida' => 'Intento fallido de inicio de sesión',
        'sso.desde_seped' => 'Entró desde SEPED con el botón (sin contraseña)',
        'sso.ir_a_seped' => 'Pasó a SEPED con el botón',
    ];

    /** Nombre del módulo al que pertenece una acción (su primer segmento). */
    public static function modulo(string $accion): string
    {
        $clave = Str::before($accion, '.');

        return match ($clave) {
            'sesion', 'sso' => 'Sesión',
            'siad' => 'SIAD',
            'admin' => str_starts_with($accion, 'admin.webhooks.') ? 'Webhooks' : 'Administración',
            default => MenuSides::MODULOS[$clave][0] ?? ucfirst($clave),
        };
    }

    /** Registra una petición que cambia algo, con el resultado que tuvo. */
    public function desdePeticion(Request $request, Response $respuesta): void
    {
        $ruta = $request->route();
        $accion = $ruta?->getName();
        $usuario = $request->user();

        if (! $accion || ! $usuario || in_array($accion, self::IGNORADAS, true)) {
            return;
        }

        [$resultado, $motivo] = $this->resultado($request, $respuesta);
        if ($resultado === null) {
            return;
        }

        $parametros = collect($ruta->parameters())->map(fn ($valor) => is_scalar($valor) ? (string) $valor : null)->filter();
        $plantilla = self::DESCRIPCIONES[$accion] ?? $accion;

        $this->registrar([
            'usuario' => $usuario,
            'accion' => $accion,
            'descripcion' => $parametros->reduce(fn (string $texto, $valor, $clave) => str_replace(":{$clave}", $valor, $texto), $plantilla),
            'referencia' => $parametros->first(),
            'resultado' => $resultado,
            'detalle_resultado' => $motivo,
            'datos' => self::limpiar($request->except(['_token', '_method'])),
            'metodo' => $request->method(),
            'ruta' => '/'.ltrim($request->path(), '/'),
        ], $request);
    }

    /** @param  array<string, mixed>  $registro */
    public function registrar(array $registro, ?Request $request = null): void
    {
        $request ??= request();
        $usuario = $registro['usuario'] ?? null;
        unset($registro['usuario']);

        try {
            SidesAuditoria::query()->create([
                'fecha' => Carbon::now(),
                'codisb' => $usuario instanceof SidesUsers ? $usuario->codisb : ($registro['codisb'] ?? null),
                'usuario_id' => $usuario instanceof SidesUsers ? $usuario->id : null,
                'usuario' => $usuario instanceof SidesUsers ? $usuario->email : ($registro['correo'] ?? null),
                'nombre' => $usuario instanceof SidesUsers ? $usuario->name : null,
                'modulo' => self::modulo($registro['accion']),
                'accion' => $registro['accion'],
                'descripcion' => Str::limit($registro['descripcion'] ?? self::DESCRIPCIONES[$registro['accion']] ?? $registro['accion'], 495),
                'referencia' => isset($registro['referencia']) ? Str::limit((string) $registro['referencia'], 55) : null,
                'resultado' => $registro['resultado'] ?? 'OK',
                'detalle_resultado' => isset($registro['detalle_resultado']) ? Str::limit((string) $registro['detalle_resultado'], 495) : null,
                'datos' => ($registro['datos'] ?? null) ?: null,
                'metodo' => $registro['metodo'] ?? null,
                'ruta' => isset($registro['ruta']) ? Str::limit($registro['ruta'], 250) : null,
                'ip' => $request->ip(),
                'agente' => Str::limit((string) $request->userAgent(), 250),
            ]);
        } catch (Throwable $e) {
            Log::warning("AUDITORIA -> NO SE PUDO REGISTRAR ({$registro['accion']}): ".$e->getMessage());
        }
    }

    /**
     * Copia de los datos enviados sin nada sensible y sin textos enormes.
     *
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    public static function limpiar(array $datos): array
    {
        $limpios = [];
        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && Str::contains(Str::lower($clave), self::SENSIBLES)) {
                $limpios[$clave] = filled($valor) ? '***' : null;
            } elseif (is_array($valor)) {
                $limpios[$clave] = self::limpiar($valor);
            } elseif (is_object($valor)) {
                // Archivos subidos (Excel de rutas): solo el nombre.
                $limpios[$clave] = method_exists($valor, 'getClientOriginalName') ? '[archivo] '.$valor->getClientOriginalName() : '[objeto]';
            } else {
                $limpios[$clave] = is_string($valor) ? Str::limit($valor, 300) : $valor;
            }
        }

        return $limpios;
    }

    /**
     * OK, FALLIDO (el sistema no dejó hacerlo) o DENEGADO (sin permiso). null = no registrar:
     * formulario con errores de validación, que no cambió nada.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resultado(Request $request, Response $respuesta): array
    {
        $estado = $respuesta->getStatusCode();

        if ($estado === 403) {
            return ['DENEGADO', 'Sin permiso para esta acción'];
        }
        if ($estado === 419 || ($estado === 422 && ! $request->expectsJson())) {
            return [null, null];
        }
        if ($estado >= 400) {
            $mensaje = json_decode((string) $respuesta->getContent(), true)['mensaje'] ?? null;

            return $estado === 422 && ! $mensaje ? [null, null] : ['FALLIDO', $mensaje ?? "Error {$estado}"];
        }

        $sesion = $request->hasSession() ? $request->session() : null;
        if ($sesion?->has('errors')) {
            return [null, null];
        }
        if ($sesion?->has('error')) {
            return ['FALLIDO', (string) $sesion->get('error')];
        }

        return ['OK', null];
    }
}
