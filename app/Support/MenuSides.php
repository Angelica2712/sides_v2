<?php

namespace App\Support;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;

/**
 * Menú de SIDES v2 y permiso de acceso a cada módulo.
 *
 * SIDES v2 tiene los módulos de los tres SIDES legacy. Cada módulo se ve según:
 * 1. los flags activar* del usuario (reglas del menú legacy de sides_droactiva, rama ModoCesta = 0);
 * 2. si es opcional (OPCIONALES), que el administrador lo haya activado para la droguería
 *    (sides_modulo_sucursal).
 *
 * La misma regla protege las rutas (middleware `permiso:{clave}`), así el menú y el acceso
 * directo por URL no se contradicen.
 */
class MenuSides
{
    /** clave => [etiqueta, uri, ícono de IconosSvg, descripción] */
    public const MODULOS = [
        'monitor' => ['Monitor', 'monitor', 'monitor', 'Estado en vivo de los pedidos recibidos de SEPED.'],
        'picking' => ['Picking', 'picking', 'picking', 'Recolección de productos por pedido.'],
        'batch' => ['Batch Picking', 'batch-picking', 'layers', 'Picking de varios pedidos agrupados en un lote.'],
        'packing' => ['Packing', 'packing', 'packing', 'Verificación y embalaje de pedidos.'],
        'etiquetas' => ['Etiquetas', 'etiquetas', 'tag', 'Impresión de etiquetas de bultos.'],
        'pedidos' => ['Pedidos', 'pedidos', 'cart', 'Consulta de pedidos.'],
        'filtromonitor' => ['Filtro monitor', 'filtro-monitor', 'filter', 'Criterios que usa el monitor.'],
        'resumen' => ['Resumen', 'resumen', 'chart', 'Resumen de la operación del día.'],
        'usuarios' => ['Usuarios', 'usuarios', 'users', 'Usuarios de SIDES y sus permisos.'],
        'informes' => ['Informes', 'informes', 'invoice', 'Informes de picking y packing.'],
        'configuracion' => ['Configuración', 'configuracion', 'settings', 'Parámetros de la sucursal.'],
        'guias' => ['Guías', 'guias', 'clipboard', 'Guías de despacho y choferes.'],
        'rutas' => ['Rutas', 'rutas', 'truck', 'Rutas y clientes por ruta.'],
        'admin' => ['Administración', 'admin', 'shield', 'Droguerías y módulos que usa cada una.'],
    ];

    /** Módulos que el administrador activa por droguería. Una droguería nueva los tiene apagados. */
    public const OPCIONALES = ['batch', 'etiquetas', 'guias', 'rutas'];

    public static function puede(SidesUsers $usuario, ?SidesCfg $cfg, string $clave): bool
    {
        if (in_array($clave, self::OPCIONALES, true) && ! $cfg?->tieneModulo($clave)) {
            return false;
        }

        // Legacy: un usuario con solo Picking no ve Etiquetas.
        $soloPicking = ! $usuario->activarMonitor && $usuario->activarPicking && ! $usuario->activarPacking
            && ! $usuario->activarPedido && ! $usuario->activarUsuario && ! $usuario->activarConfig;

        return match ($clave) {
            'monitor' => (bool) $usuario->activarMonitor,
            'picking', 'batch' => (bool) $usuario->activarPicking,
            'packing' => $usuario->activarPacking && $cfg?->activarPacking,
            // El interruptor de la droguería (legacy activarEtiPacking) es ahora el módulo opcional.
            'etiquetas' => ! $usuario->activarGuiaCarga && ! $usuario->activarGuiaDescarga && ! $soloPicking,
            'pedidos' => (bool) $usuario->activarPedido,
            'filtromonitor', 'configuracion', 'guias', 'rutas' => (bool) $usuario->activarConfig,
            'resumen' => (bool) $usuario->activarResumen,
            'usuarios', 'informes' => (bool) $usuario->activarUsuario,
            'admin' => (bool) $usuario->esAdmin,
            default => false,
        };
    }

    /** @return array{clave: string, etiqueta: string, ruta: string, icono: string, descripcion: string} */
    public static function modulo(string $clave): array
    {
        [$etiqueta, , $icono, $descripcion] = self::MODULOS[$clave];

        return [
            'clave' => $clave,
            'etiqueta' => $etiqueta,
            'ruta' => "{$clave}.index",
            'icono' => $icono,
            'descripcion' => $descripcion,
        ];
    }

    /** @return list<array{clave: string, etiqueta: string, ruta: string, icono: string, descripcion: string}> */
    public static function visibles(SidesUsers $usuario, ?SidesCfg $cfg): array
    {
        return collect(array_keys(self::MODULOS))
            ->filter(fn (string $clave) => self::puede($usuario, $cfg, $clave))
            ->map(fn (string $clave) => self::modulo($clave))
            ->values()
            ->all();
    }
}
