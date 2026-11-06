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
            'activarEtiquetas' => ['Etiquetas', 'Imprime las etiquetas de los bultos.'],
            'activarAlmacen' => ['Almacén 3D', 'Ve el almacén en 3D y dónde está cada producto, también como mapa en el picking.'],
            'activarUbicaciones' => ['Registrar ubicaciones', 'Registra desde el teléfono dónde está cada producto y numera los pasillos y anaqueles del almacén. Necesita también Almacén 3D.'],
        ],
        'Pedidos' => [
            'activarPedido' => ['Pedidos', 'Consulta y modifica pedidos.'],
            'activarResetear' => ['Resetear pedidos', 'Devuelve un pedido a RECIBIDO y borra su avance en SIDES.'],
            'eliminarPedido' => ['Anular pedidos', 'Anula pedidos desde la consulta de pedidos.'],
        ],
        'Despacho' => [
            'activarGuiaCarga' => ['Guía de carga', 'Carga pedidos en las guías de despacho.'],
            'activarGuiaDescarga' => ['Guía de descarga', 'Registra la entrega de las guías.'],
        ],
        'Gestión' => [
            'activarIndicadores' => ['Indicadores del almacén', 'Para el jefe de operaciones o del almacén: ve en el Inicio los tiempos promedio de aceptar, picking y packing, el almacén en vivo y el desempeño de toda la droguería.'],
            'activarResumen' => ['Resumen', 'Ve el resumen de la operación del día.'],
            'activarInformes' => ['Informes', 'Ve los informes de picking y packing.'],
            'activarUsuario' => ['Usuarios', 'Crea usuarios y cambia sus permisos y contraseñas.'],
            'activarConfig' => ['Configuración', 'Cambia la configuración de la sucursal, rutas y guías.'],
            'activarAdmin' => ['Administración', 'Para quien administra la droguería: entra a Administración, cambia contraseñas de los usuarios y maneja el almacén y los webhooks. Los módulos, los datos y el logo de la droguería los cambia solo FULLTECH360. Solo lo puede dar quien ya lo tiene.'],
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
