<?php

namespace App\Services\Informes;

use App\Support\Duracion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Informes de picking y packing por operario. Porta InformepickingController e
 * InformepackingController de los tres SIDES legacy (droactiva y mastranto idénticos; dromarko
 * con la misma lógica reorganizada en servicios y con Excel de resumen + detalle, que es el que
 * se toma acá).
 *
 * Lee los registros que ya escriben PickingService y PackingService:
 * - productividad: sides_logpicking / sides_logpacking, un registro por pedido trabajado, con
 *   el tiempo desde que el operario lo abrió hasta que lo terminó (o lo liberó: tiempo parcial);
 * - inactividad: sides_log_inac_picking / sides_log_inac_packing, el tiempo muerto entre el
 *   pedido anterior del operario y el siguiente, dentro del mismo día.
 *
 * Diferencias intencionales con el legacy:
 * - Solo se cuentan pedidos de la sucursal del usuario (join a `pedido`): el legacy no filtraba
 *   porque cada droguería tenía su base; con la base compartida sería mostrar datos ajenos.
 * - Quitar un registro de inactividad borra ese registro, no todos los del pedido, y solo si el
 *   pedido es de la sucursal (el legacy borraba por id_pedido, sin más controles).
 * - Promedios con NULLIF: sin unidades o renglones no hay división por cero.
 */
class InformesService
{
    public const TIPOS = ['picking', 'packing'];

    public const VISTAS = ['productividad', 'inactividad'];

    /** Días que abarca el informe si no se eligen fechas (dromarko; droactiva usaba 3). */
    public const DIAS_POR_DEFECTO = 30;

    /** Tope del rango para que un informe no recorra años de registros. */
    public const DIAS_MAXIMOS = 366;

    public const POR_PAGINA = 50;

    /** Textos del legacy en logpicking.descripcion (con su errata) y cómo se muestran. */
    public const DESCRIPCIONES = [
        'tiempo completo' => 'Completo',
        'tiempo parcial' => 'Parcial (liberado)',
        'tiempo pacial-final' => 'Final tras liberar',
    ];

    /** @return array{titulo: string, detalle: string, rol: string, icono: string} */
    public static function textos(string $tipo, string $vista): array
    {
        $rol = $tipo === 'picking' ? 'despachador' : 'empacador';
        $etapa = $tipo === 'picking' ? 'Picking' : 'Packing';

        return $vista === 'productividad'
            ? [
                'titulo' => "Productividad en {$etapa}",
                'detalle' => $tipo === 'picking'
                    ? 'Cuánto tarda cada despachador en recoger sus pedidos, desde que abre el pedido hasta que lo termina.'
                    : 'Cuánto tarda cada empacador en verificar y embalar sus pedidos, desde que abre el pedido hasta que lo termina.',
                'rol' => $rol,
                'icono' => $tipo,
            ]
            : [
                'titulo' => "Inactividad en {$etapa}",
                'detalle' => "Tiempo muerto de cada {$rol} entre terminar un pedido y empezar el siguiente, dentro del mismo día.",
                'rol' => $rol,
                'icono' => 'clock',
            ];
    }

    /** @return array{tabla: string, tiempo: string, fecha: string} */
    public static function fuente(string $tipo, string $vista): array
    {
        $inactividad = $vista === 'inactividad';

        return [
            'tabla' => $inactividad ? "sides_log_inac_{$tipo}" : "sides_log{$tipo}",
            'tiempo' => $inactividad ? "tiempo_{$tipo}_inac" : "tiempo_{$tipo}",
            'fecha' => "fecha_del_{$tipo}",
        ];
    }

    /**
     * Rango del informe: el elegido, o los últimos DIAS_POR_DEFECTO días.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function rango(?string $desde, ?string $hasta): array
    {
        if (! $desde || ! $hasta) {
            $fin = Carbon::today();

            return [$fin->copy()->subDays(self::DIAS_POR_DEFECTO), $fin];
        }

        return [Carbon::parse($desde)->startOfDay(), Carbon::parse($hasta)->startOfDay()];
    }

    /**
     * Un renglón por operario, ordenado del más rápido al más lento: por tiempo por renglón en
     * productividad (legacy avg_numren) y por tiempo promedio en inactividad.
     *
     * @return Collection<int, object>
     */
    public function ranking(string $codisb, string $tipo, string $vista, Carbon $desde, Carbon $hasta): Collection
    {
        $tiempo = 'l.'.self::fuente($tipo, $vista)['tiempo'];

        $filas = $this->base($codisb, $tipo, $vista, $desde, $hasta)
            ->leftJoin('sides_users as u', 'u.email', '=', 'l.usuario')
            ->groupBy('l.usuario')
            ->selectRaw("l.usuario,
                MAX(u.id) as usuario_id,
                MAX(u.name) as nombre,
                COUNT(*) as pedidos,
                SUM(l.numund) as unidades,
                SUM(l.numren) as renglones,
                SUM({$tiempo}) as total,
                AVG({$tiempo}) as promedio,
                SUM({$tiempo}) / NULLIF(SUM(l.numund), 0) as por_unidad,
                SUM({$tiempo}) / NULLIF(SUM(l.numren), 0) as por_renglon")
            ->get();

        $clave = $vista === 'productividad' ? 'por_renglon' : 'promedio';

        // Los que no tienen renglones (sin promedio posible) van al final.
        return $filas
            ->sortBy(fn (object $fila) => [$fila->{$clave} === null ? 1 : 0, (float) $fila->{$clave}])
            ->values();
    }

    /** Registros de un operario, del más reciente al más antiguo. */
    public function detalle(string $codisb, string $tipo, string $vista, string $email, Carbon $desde, Carbon $hasta): LengthAwarePaginator
    {
        return $this->consultaDetalle($codisb, $tipo, $vista, $desde, $hasta)
            ->where('l.usuario', $email)
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /** Quita un registro de inactividad justificado (almuerzo, reunión...). */
    public function quitarInactividad(string $codisb, string $tipo, int $id): bool
    {
        $tabla = self::fuente($tipo, 'inactividad')['tabla'];

        return DB::table($tabla)
            ->where('id', $id)
            ->whereIn('id_pedido', DB::table('pedido')->select('id')->where('codisb', $codisb))
            ->delete() > 0;
    }

    /**
     * Excel del informe: hoja "Resumen" con el ranking y hoja "Detalle" con cada registro
     * (dromarko PickingResumenDetalleExport). Devuelve la ruta del archivo temporal.
     */
    public function excelResumen(string $codisb, string $tipo, string $vista, Carbon $desde, Carbon $hasta): string
    {
        $negrita = new Style(fontBold: true);
        [$archivo, $writer] = $this->nuevoExcel();
        $writer->getCurrentSheet()->setName('Resumen');

        $productividad = $vista === 'productividad';
        $writer->addRow(Row::fromValuesWithStyle(array_values(array_filter([
            'RANKING', 'OPERARIO', 'CORREO', 'PEDIDOS', 'UNIDADES', 'RENGLONES',
            $productividad ? 'PROMEDIO POR UNIDAD' : null,
            $productividad ? 'PROMEDIO POR RENGLON' : null,
            'TIEMPO PROMEDIO', 'TIEMPO ACUMULADO',
        ])), $negrita));

        foreach ($this->ranking($codisb, $tipo, $vista, $desde, $hasta)->values() as $posicion => $fila) {
            $writer->addRow(Row::fromValues(array_values(array_filter([
                $posicion + 1,
                $fila->nombre ?: $fila->usuario,
                $fila->usuario,
                (int) $fila->pedidos,
                (int) $fila->unidades,
                (int) $fila->renglones,
                $productividad ? Duracion::texto($fila->por_unidad) : null,
                $productividad ? Duracion::texto($fila->por_renglon) : null,
                Duracion::texto($fila->promedio),
                Duracion::texto($fila->total),
            ], fn ($valor) => $valor !== null))));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Detalle');
        $this->filasDetalle($writer, $this->consultaDetalle($codisb, $tipo, $vista, $desde, $hasta), $tipo, $vista, true);
        $writer->close();

        return $archivo;
    }

    /** Excel con los registros de un operario (dromarko obtenerinformacionexcel). */
    public function excelDetalle(string $codisb, string $tipo, string $vista, string $email, Carbon $desde, Carbon $hasta): string
    {
        [$archivo, $writer] = $this->nuevoExcel();
        $writer->getCurrentSheet()->setName('Detalle');
        $this->filasDetalle($writer, $this->consultaDetalle($codisb, $tipo, $vista, $desde, $hasta)->where('l.usuario', $email), $tipo, $vista, false);
        $writer->close();

        return $archivo;
    }

    /** Registros de la sucursal en el rango (fechas inclusivas, día completo). */
    private function base(string $codisb, string $tipo, string $vista, Carbon $desde, Carbon $hasta): Builder
    {
        ['tabla' => $tabla, 'fecha' => $fecha] = self::fuente($tipo, $vista);

        return DB::table("{$tabla} as l")
            ->join('pedido as p', 'p.id', '=', 'l.id_pedido')
            ->where('p.codisb', $codisb)
            // Lo que hizo el FT (FULLTECH360) no cuenta como trabajo de un operario de la droguería.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sides_users as ft')
                ->whereColumn('ft.email', 'l.usuario')->whereNotNull('ft.seped_user_id'))
            ->whereBetween("l.{$fecha}", [
                $desde->copy()->startOfDay()->toDateTimeString(),
                $hasta->copy()->endOfDay()->toDateTimeString(),
            ]);
    }

    private function consultaDetalle(string $codisb, string $tipo, string $vista, Carbon $desde, Carbon $hasta): Builder
    {
        ['tiempo' => $tiempo, 'fecha' => $fecha] = self::fuente($tipo, $vista);
        $columnas = ['l.id', 'l.id_pedido', 'l.usuario', 'l.numren', 'l.numund', "l.{$tiempo} as tiempo", "l.{$fecha} as fecha", 'p.nomcli', 'u.name as nombre'];

        if ($vista === 'inactividad') {
            $columnas[] = 'l.id_pedido_anterior';
        } elseif ($tipo === 'picking') {
            $columnas[] = 'l.descripcion';
        }

        return $this->base($codisb, $tipo, $vista, $desde, $hasta)
            ->leftJoin('sides_users as u', 'u.email', '=', 'l.usuario')
            ->select($columnas)
            ->orderByDesc("l.{$fecha}")
            ->orderByDesc('l.id');
    }

    private function filasDetalle(Writer $writer, Builder $consulta, string $tipo, string $vista, bool $conOperario): void
    {
        $inactividad = $vista === 'inactividad';
        $conDescripcion = ! $inactividad && $tipo === 'picking';

        $writer->addRow(Row::fromValuesWithStyle(array_values(array_filter([
            'FECHA',
            $conOperario ? 'OPERARIO' : null,
            'PEDIDO',
            'CLIENTE',
            $inactividad ? 'PEDIDO ANTERIOR' : null,
            'UNIDADES',
            'RENGLONES',
            $inactividad ? 'TIEMPO INACTIVO' : 'TIEMPO',
            $conDescripcion ? 'TIPO' : null,
        ])), new Style(fontBold: true)));

        foreach ($consulta->cursor() as $registro) {
            $writer->addRow(Row::fromValues(array_values(array_filter([
                Carbon::parse($registro->fecha)->format('d-m-Y H:i:s'),
                $conOperario ? ($registro->nombre ?: $registro->usuario) : null,
                (int) $registro->id_pedido,
                (string) $registro->nomcli,
                $inactividad ? (string) $registro->id_pedido_anterior : null,
                (int) $registro->numund,
                (int) $registro->numren,
                Duracion::texto($registro->tiempo),
                $conDescripcion ? (self::DESCRIPCIONES[$registro->descripcion] ?? (string) $registro->descripcion) : null,
            ], fn ($valor) => $valor !== null))));
        }
    }

    /** @return array{0: string, 1: Writer} */
    private function nuevoExcel(): array
    {
        $base = tempnam(sys_get_temp_dir(), 'inf');
        @unlink($base);
        $archivo = $base.'.xlsx';
        $writer = new Writer();
        $writer->openToFile($archivo);

        return [$archivo, $writer];
    }
}
