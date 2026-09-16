<?php

namespace App\Support;

/**
 * Permisos de un usuario de SIDES (columnas activar* de sides_users), agrupados como se
 * muestran en el formulario de Usuarios. esAdmin no está acá: solo se asigna por base de datos.
 */
class PermisosUsuario
{
    /** grupo => [columna => [etiqueta, ayuda]] */
    public const GRUPOS = [
        'Operación' => [
            'activarMonitor' => ['Monitor', 'Ve el monitor de pedidos.'],
            'activarPicking' => ['Picking', 'Recolecta pedidos, también en Batch Picking.'],
            'activarLiberarAlcabala' => ['Liberar pedidos en espera', 'En Batch Picking, pasa pedidos en espera al picking normal.'],
            'activarPacking' => ['Packing', 'Verifica y embala pedidos.'],
        ],
        'Pedidos' => [
            'activarPedido' => ['Pedidos', 'Consulta y modifica pedidos.'],
            'activarResetear' => ['Resetear pedidos', 'Devuelve un pedido a RECIBIDO y borra su avance en SIDES.'],
            'eliminarPedido' => ['Anular pedidos', 'Anula pedidos desde la consulta de pedidos.'],
        ],
        'Despacho' => [
            'activarGuiaCarga' => ['Guía de carga', 'Carga pedidos en las guías de despacho. Sin acceso a Etiquetas.'],
            'activarGuiaDescarga' => ['Guía de descarga', 'Registra la entrega de las guías. Sin acceso a Etiquetas.'],
        ],
        'Gestión' => [
            'activarResumen' => ['Resumen', 'Ve el resumen de la operación del día.'],
            'activarInformes' => ['Informes', 'Ve los informes de picking y packing.'],
            'activarUsuario' => ['Usuarios', 'Crea usuarios y cambia sus permisos y contraseñas.'],
            'activarConfig' => ['Configuración', 'Cambia la configuración de la sucursal, rutas y guías.'],
        ],
    ];

    /** @return list<string> */
    public static function columnas(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::GRUPOS)));
    }

    /** @return array<string, string> columna => etiqueta */
    public static function etiquetas(): array
    {
        return array_map(fn (array $permiso) => $permiso[0], array_merge(...array_values(self::GRUPOS)));
    }
}
