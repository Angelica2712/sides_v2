<?php

namespace App\Services\Inicio;

use App\Support\FechaSeped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del almacén para el Inicio: cuánto tarda en promedio cada paso del pedido, cómo
 * está la cola ahora mismo y cuánto despachó la droguería en el período.
 *
 * Los tiempos salen de las fechas que SIDES ya escribe en `pedido`:
 * - aceptar: fecrecibido (llega a SIDES) → fecpicking (un despachador lo toma);
 * - picking: fecpicking → fecpacking (termina el picking);
 * - packing: fecpacking → feccompletado (queda empacado), con la espera en la cola de packing;
 * - total: fecrecibido → feccompletado.
 * Un tramo de más de 24 horas no entra al promedio: es un pedido que quedó abierto de un día
 * para otro (o con fechas viejas del legacy) y por sí solo movería el promedio varias horas.
 * Se calcula en PHP y no con TIMESTAMPDIFF para que corra igual en MySQL y en SQLite.
 */
class DesempenoAlmacen
{
    public const PERIODOS = ['hoy' => 'Hoy', '7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días'];

    public const MAXIMO_SEGUNDOS = 86400;

    private const TRAMOS = [
        'aceptar' => ['fecrecibido', 'fecpicking'],
        'picking' => ['fecpicking', 'fecpacking'],
        'packing' => ['fecpacking', 'feccompletado'],
        'total' => ['fecrecibido', 'feccompletado'],
    ];

    public static function periodo(?string $periodo): string
    {
        return array_key_exists((string) $periodo, self::PERIODOS) ? (string) $periodo : 'hoy';
    }

    /** @return array{0: Carbon, 1: Carbon} desde y hasta del período, y del anterior con el mismo largo. */
    public static function rango(string $periodo, int $atras = 0): array
    {
        $dias = $periodo === 'hoy' ? 1 : (int) $periodo;
        $desde = Carbon::today()->subDays($dias - 1 + $dias * $atras);

        return [$desde, $desde->copy()->addDays($dias)];
    }

    public function de(string $codisb, string $periodo, bool $conPacking = true): array
    {
        [$desde, $hasta] = self::rango($periodo);
        [$antesDesde, $antesHasta] = self::rango($periodo, 1);

        $actual = $this->tiempos($codisb, $desde, $hasta);
        $anterior = $this->tiempos($codisb, $antesDesde, $antesHasta);
        $tiempos = [];
        foreach ($actual as $tramo => $valor) {
            if ($tramo === 'packing' && ! $conPacking) {
                continue;
            }
            $tiempos[$tramo] = [
                ...$valor,
                'anterior' => $anterior[$tramo]['segundos'],
                'referencia' => $valor['pedidos'] === 0 ? $this->ultimoDia($codisb, $tramo, $desde) : null,
            ];
        }

        return [
            'periodo' => $periodo,
            'etiqueta' => self::PERIODOS[$periodo],
            'tiempos' => $tiempos,
            'cola' => $this->cola($codisb),
            'desempeno' => $this->desempeno($codisb, $desde, $hasta),
            'despachadores' => $this->ranking($codisb, 'sides_logpicking', 'fecha_del_picking', $desde, $hasta),
            'embaladores' => $conPacking ? $this->ranking($codisb, 'sides_logpacking', 'fecha_del_packing', $desde, $hasta) : [],
            'hora' => now()->format('H:i:s'),
        ];
    }

    /**
     * Cada tramo cuenta en el período en que terminó (el picking de hoy, aunque el pedido haya
     * llegado ayer).
     *
     * @return array<string, array{segundos: ?int, pedidos: int}>
     */
    private function tiempos(string $codisb, Carbon $desde, Carbon $hasta): array
    {
        $resultado = [];
        foreach (self::TRAMOS as $tramo => [$inicio, $fin]) {
            $duraciones = DB::table('pedido')
                ->where('codisb', $codisb)
                ->where('estado', '<>', 'ANULADO')
                ->where($inicio, '>', FechaSeped::VACIA)
                ->where($fin, '>=', $desde)
                ->where($fin, '<', $hasta)
                ->get([$inicio, $fin])
                ->map(fn ($fila) => Carbon::parse($fila->{$fin})->getTimestamp() - Carbon::parse($fila->{$inicio})->getTimestamp())
                ->filter(fn (int $segundos) => $segundos >= 0 && $segundos <= self::MAXIMO_SEGUNDOS);

            $resultado[$tramo] = [
                'segundos' => $duraciones->isEmpty() ? null : (int) round($duraciones->avg()),
                'pedidos' => $duraciones->count(),
            ];
        }

        return $resultado;
    }

    /**
     * Si en el período no terminó ningún pedido (un domingo, o a primera hora), el promedio del
     * último día en que sí hubo, para que la tarjeta no quede en blanco.
     *
     * @return array{fecha: string, segundos: int, pedidos: int}|null
     */
    private function ultimoDia(string $codisb, string $tramo, Carbon $antesDe): ?array
    {
        [$inicio, $fin] = self::TRAMOS[$tramo];
        // El último pedido con un tiempo que sí entra al promedio (los de más de 24 horas no).
        $ultimo = DB::table('pedido')
            ->where('codisb', $codisb)
            ->where('estado', '<>', 'ANULADO')
            ->where($inicio, '>', FechaSeped::VACIA)
            ->where($fin, '>', FechaSeped::VACIA)
            ->where($fin, '<', $antesDe)
            ->orderByDesc($fin)
            ->limit(500)
            ->get([$inicio, $fin])
            ->first(fn ($fila) => ($s = Carbon::parse($fila->{$fin})->getTimestamp() - Carbon::parse($fila->{$inicio})->getTimestamp()) >= 0 && $s <= self::MAXIMO_SEGUNDOS);
        if (! $ultimo) {
            return null;
        }
        $dia = Carbon::parse($ultimo->{$fin})->startOfDay();
        $valor = $this->tiempos($codisb, $dia, $dia->copy()->addDay())[$tramo];

        return $valor['segundos'] === null ? null : ['fecha' => $dia->format('d/m/Y'), ...$valor];
    }

    /** Lo que hay en el almacén ahora mismo, y desde cuándo espera el pedido más viejo sin tomar. */
    private function cola(string $codisb): array
    {
        $porEstado = DB::table('pedido')
            ->where('codisb', $codisb)
            ->whereIn('estado', ['RECIBIDO', 'ALCABALA', 'PICKING', 'PACKING', 'PEND-FACTURA'])
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $masViejo = DB::table('pedido')
            ->where('codisb', $codisb)
            ->whereIn('estado', ['RECIBIDO', 'ALCABALA'])
            ->where('fecrecibido', '>', FechaSeped::VACIA)
            ->min('fecrecibido');

        return [
            'porTomar' => (int) ($porEstado['RECIBIDO'] ?? 0) + (int) ($porEstado['ALCABALA'] ?? 0),
            'picking' => (int) ($porEstado['PICKING'] ?? 0),
            'packing' => (int) ($porEstado['PACKING'] ?? 0),
            'facturar' => (int) ($porEstado['PEND-FACTURA'] ?? 0),
            'esperaMasLarga' => $masViejo ? max(0, now()->getTimestamp() - Carbon::parse($masViejo)->getTimestamp()) : null,
        ];
    }

    private function desempeno(string $codisb, Carbon $desde, Carbon $hasta): array
    {
        $completados = DB::table('pedido')
            ->where('codisb', $codisb)
            ->whereNotIn('estado', ['ANULADO'])
            ->where('feccompletado', '>=', $desde)
            ->where('feccompletado', '<', $hasta);

        // Nivel de servicio: unidades despachadas sobre las pedidas en los pedidos que se completaron.
        $unidades = DB::table('pedren')
            ->join('sides_pedren_operacion as op', function ($join) {
                $join->on('op.id_pedido', '=', 'pedren.id')->on('op.item', '=', 'pedren.item');
            })
            ->whereIn('pedren.id', (clone $completados)->select('id'))
            ->selectRaw('SUM(pedren.cantidad) as pedidas, SUM(CASE WHEN op.cantdesp > 0 THEN op.cantdesp ELSE 0 END) as despachadas')
            ->first();

        $picking = $this->totalesLog($codisb, 'sides_logpicking', 'fecha_del_picking', 'tiempo_picking', $desde, $hasta);
        $packing = $this->totalesLog($codisb, 'sides_logpacking', 'fecha_del_packing', 'tiempo_packing', $desde, $hasta);
        $pedidas = (int) ($unidades->pedidas ?? 0);

        return [
            'pedidos' => (clone $completados)->count(),
            'renglones' => $picking['renglones'],
            'unidades' => $picking['unidades'],
            'servicio' => $pedidas > 0 ? round(100 * (int) $unidades->despachadas / $pedidas, 1) : null,
            'renglonesHoraPicking' => $picking['porHora'],
            'renglonesHoraPacking' => $packing['porHora'],
        ];
    }

    private function totalesLog(string $codisb, string $tabla, string $fecha, string $tiempo, Carbon $desde, Carbon $hasta): array
    {
        $fila = $this->log($codisb, $tabla, $fecha, $desde, $hasta)
            ->selectRaw("SUM(log.numren) as renglones, SUM(log.numund) as unidades, SUM(log.{$tiempo}) as segundos")
            ->first();
        $segundos = (int) ($fila->segundos ?? 0);

        return [
            'renglones' => (int) ($fila->renglones ?? 0),
            'unidades' => (int) ($fila->unidades ?? 0),
            'porHora' => $segundos > 0 ? round((int) $fila->renglones / ($segundos / 3600), 1) : null,
        ];
    }

    /** Los 5 que más renglones trabajaron en el período, con su nombre de SIDES. */
    private function ranking(string $codisb, string $tabla, string $fecha, Carbon $desde, Carbon $hasta): Collection
    {
        return $this->log($codisb, $tabla, $fecha, $desde, $hasta)
            ->leftJoin('sides_users as u', function ($join) {
                $join->on('u.email', '=', 'log.usuario')->on('u.codisb', '=', 'pedido.codisb');
            })
            ->groupBy('log.usuario', 'u.name')
            ->selectRaw('log.usuario, u.name, COUNT(*) as pedidos, SUM(log.numren) as renglones, SUM(log.numund) as unidades')
            ->orderByDesc('renglones')
            ->limit(5)
            ->get()
            ->map(fn ($fila) => [
                'nombre' => (string) ($fila->name ?: $fila->usuario),
                'pedidos' => (int) $fila->pedidos,
                'renglones' => (int) $fila->renglones,
                'unidades' => (int) $fila->unidades,
            ]);
    }

    private function log(string $codisb, string $tabla, string $fecha, Carbon $desde, Carbon $hasta)
    {
        return DB::table("{$tabla} as log")
            ->join('pedido', 'pedido.id', '=', 'log.id_pedido')
            ->where('pedido.codisb', $codisb)
            ->where("log.{$fecha}", '>=', $desde)
            ->where("log.{$fecha}", '<', $hasta);
    }
}
