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
        'retenidos' => ['Alcabala de packing', 'retenidos', 'hand', 'Retiene pedidos empacados para sumarles otro del mismo cliente.'],
        'almacen' => ['Almacén 3D', 'almacen', 'warehouse', 'Los anaqueles del almacén y dónde está cada producto.'],
        'etiquetas' => ['Etiquetas', 'etiquetas', 'tag', 'Impresión de etiquetas de bultos.'],
        'pedidos' => ['Pedidos', 'pedidos', 'cart', 'Consulta de pedidos.'],
        'filtromonitor' => ['Filtro monitor', 'filtro-monitor', 'filter', 'Criterios que usa el monitor.'],
        'resumen' => ['Resumen', 'resumen', 'chart', 'Resumen de la operación del día.'],
        'usuarios' => ['Usuarios', 'usuarios', 'users', 'Usuarios de SIDES y sus permisos.'],
        'informes' => ['Informes', 'informes', 'invoice', 'Informes de picking y packing.'],
        'configuracion' => ['Configuración', 'configuracion', 'settings', 'Parámetros de la sucursal.'],
        'guias' => ['Guías', 'guias', 'clipboard', 'Guías de despacho y choferes.'],
        'despacho' => ['Carga y descarga', 'despacho', 'package', 'Carga de bultos al camión y entrega a los clientes.'],
        'rutas' => ['Rutas', 'rutas', 'truck', 'Rutas y clientes por ruta.'],
        'admin' => ['Administración', 'admin', 'shield', 'Droguerías y módulos que usa cada una.'],
        'auditoria' => ['Auditoría', 'admin/auditoria', 'eye', 'Qué hace cada usuario en todas las droguerías.'],
    ];

    /** Agrupación del menú lateral y del inicio, en el orden en que se muestran. */
    public const SECCIONES = [
        'Operación' => ['monitor', 'picking', 'batch', 'packing', 'retenidos', 'etiquetas', 'almacen'],
        'Despacho' => ['guias', 'despacho', 'rutas'],
        'Consultas' => ['pedidos', 'resumen', 'informes'],
        'Ajustes' => ['filtromonitor', 'configuracion', 'usuarios', 'admin', 'auditoria'],
    ];

    /** Módulos que el administrador activa por droguería. Una droguería nueva los tiene apagados. */
    public const OPCIONALES = ['batch', 'retenidos', 'etiquetas', 'guias', 'rutas'];

    /**
     * Módulos básicos: encendidos salvo que el administrador los apague para una droguería (fila
     * con activo = 0 en sides_modulo_sucursal). Sin fila siguen encendidos, así las droguerías
     * que ya existían no pierden nada.
     */
    public const BASICOS = ['monitor', 'picking', 'pedidos', 'filtromonitor', 'resumen', 'usuarios', 'informes', 'configuracion', 'almacen'];

    /**
     * Todos los módulos con interruptor por droguería en Administración. Packing tiene el suyo en
     * sides_cfg.activarPacking (cambia el flujo del pedido), Carga y descarga sigue a Guías y
     * Administración no se puede apagar.
     */
    public const CONTROLABLES = [...self::BASICOS, ...self::OPCIONALES];

    /**
     * Módulos que dependen de otro con otra clave. "ubicaciones" no sale en el menú: son las
     * pestañas del Almacén 3D para registrar ubicaciones y numerar los anaqueles.
     */
    private const DEPENDE_DE = ['despacho' => 'guias', 'ubicaciones' => 'almacen'];

    public static function puede(SidesUsers $usuario, ?SidesCfg $cfg, string $clave): bool
    {
        $modulo = self::DEPENDE_DE[$clave] ?? $clave;
        if (in_array($modulo, self::OPCIONALES, true) && ! $cfg?->tieneModulo($modulo)) {
            return false;
        }
        if (in_array($modulo, self::BASICOS, true) && $cfg && ! $cfg->tieneModulo($modulo)) {
            return false;
        }

        // Legacy: un usuario con solo Picking no ve Etiquetas.
        $soloPicking = ! $usuario->activarMonitor && $usuario->activarPicking && ! $usuario->activarPacking
            && ! $usuario->activarPedido && ! $usuario->activarUsuario && ! $usuario->activarConfig;

        return match ($clave) {
            'monitor' => (bool) $usuario->activarMonitor,
            'picking', 'batch' => (bool) $usuario->activarPicking,
            // Permiso propio desde 2026-10-28; la migración se lo dio a quienes ya lo veían
            // (Picking, Packing, Registrar ubicaciones o Configuración). También es el mapa del picking.
            'almacen' => (bool) $usuario->activarAlmacen,
            // Registran ubicaciones y numeran el almacén quienes tienen el permiso y quien administra la droguería.
            'ubicaciones' => $usuario->activarUbicaciones || $usuario->activarConfig,
            'packing', 'retenidos' => $usuario->activarPacking && $cfg?->activarPacking,
            // El interruptor de la droguería (legacy activarEtiPacking) es ahora el módulo opcional.
            'etiquetas' => ! $usuario->activarGuiaCarga && ! $usuario->activarGuiaDescarga && ! $soloPicking,
            'pedidos' => (bool) $usuario->activarPedido,
            'filtromonitor', 'configuracion', 'guias', 'rutas' => (bool) $usuario->activarConfig,
            'resumen' => (bool) $usuario->activarResumen,
            'usuarios' => (bool) $usuario->activarUsuario,
            // Permiso propio de dromarko; droactiva y mastranto usaban activarUsuario.
            'informes' => (bool) $usuario->activarInformes,
            'despacho' => $usuario->activarGuiaCarga || $usuario->activarGuiaDescarga,
            // La auditoría es de la empresa: solo el administrador de SIDES.
            'admin', 'auditoria' => (bool) $usuario->esAdmin,
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

    /**
     * Módulos visibles agrupados por sección; las secciones sin módulos visibles no aparecen.
     *
     * @return array<string, list<array{clave: string, etiqueta: string, ruta: string, icono: string, descripcion: string}>>
     */
    public static function porSeccion(SidesUsers $usuario, ?SidesCfg $cfg): array
    {
        $secciones = [];
        foreach (self::SECCIONES as $seccion => $claves) {
            $modulos = array_values(array_map(
                fn (string $clave) => self::modulo($clave),
                array_filter($claves, fn (string $clave) => self::puede($usuario, $cfg, $clave)),
            ));
            if ($modulos) {
                $secciones[$seccion] = $modulos;
            }
        }

        return $secciones;
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
