<?php

namespace App\Services\Informes;

use App\Services\Inicio\DesempenoAlmacen;
use App\Support\FechaSeped;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Reportes de operaciones, para el jefe de operaciones o del almacén:
 * - tiempos por día y por hora (dónde están las horas pico y los cuellos de botella);
 * - pedidos por cliente y por ruta (cuánto se le despacha a cada uno y qué tan completo);
 * - pedidos demorados (los que pasaron más de X minutos en una etapa, también los que siguen ahí);
 * - resumen del mes contra el mes anterior (OperacionesController::mensual).
 *
 * Los reportes de tiempos, clientes y demorados toman los pedidos por el día en que llegaron a
 * SIDES (fecrecibido), así un pedido sale una sola vez aunque su picking termine al día siguiente.
 * Los tiempos usan las mismas fechas y el mismo tope de 24 horas que el Inicio (DesempenoAlmacen).
 */
class OperacionesService
{
    /** Etapas del pedido: [fecha de inicio, fecha de fin, estados en que el pedido está en ella]. */
    public const ETAPAS = [
        'aceptar' => ['fecrecibido', 'fecpicking', ['RECIBIDO', 'ALCABALA']],
        'picking' => ['fecpicking', 'fecpacking', ['PICKING']],
        'packing' => ['fecpacking', 'feccompletado', ['PACKING']],
    ];

    public const NOMBRES_ETAPA = ['aceptar' => 'Aceptar', 'picking' => 'Picking', 'packing' => 'Packing', 'total' => 'Total'];

    public const AGRUPAR = ['cliente' => 'Por cliente', 'ruta' => 'Por ruta'];

    public const MINUTOS_DEMORA = 30;

    public const POR_PAGINA = 50;

    /** Lo despachado vive en sides_pedren_operacion mientras SIDES trabaja el pedido (-1 = sin revisar). */
    private const DESPACHADO = 'CASE WHEN op.cantdesp >= 0 THEN op.cantdesp WHEN pr.cantdesp >= 0 THEN pr.cantdesp ELSE 0 END';

    /** Tramos que se miden según la droguería use packing o no. */
    public static function tramos(bool $conPacking): array
    {
        return array_values(array_filter(['aceptar', 'picking', 'packing', 'total'], fn ($t) => $conPacking || $t !== 'packing'));
    }

    // ---------------------------------------------------------------- Tiempos por día y por hora

    /**
     * Promedio de cada tramo por día y por hora del día en que llegó el pedido.
     *
     * @return array{dias: list<array>, horas: list<array>, totales: array}
     */
    public function tiempos(string $codisb, Carbon $desde, Carbon $hasta, bool $conPacking = true): array
    {
        $tramos = self::tramos($conPacking);
        $vacio = fn (string $etiqueta) => ['etiqueta' => $etiqueta, 'pedidos' => 0, 'suma' => array_fill_keys($tramos, 0), 'cuenta' => array_fill_keys($tramos, 0)];

        $dias = [];
        for ($dia = $desde->copy()->startOfDay(); $dia->lte($hasta); $dia->addDay()) {
            $dias[$dia->format('Y-m-d')] = $vacio($dia->format('Y-m-d'));
        }
        $horas = [];
        foreach (range(0, 23) as $hora) {
            $horas[$hora] = $vacio(sprintf('%02d:00', $hora));
        }
        $totales = $vacio('Total');

        $sumar = function (array &$grupo, array $duraciones) use ($tramos): void {
            $grupo['pedidos']++;
            foreach ($tramos as $tramo) {
                if ($duraciones[$tramo] !== null) {
                    $grupo['suma'][$tramo] += $duraciones[$tramo];
                    $grupo['cuenta'][$tramo]++;
                }
            }
        };

        foreach ($this->pedidos($codisb, $desde, $hasta) as $pedido) {
            $recibido = Carbon::parse($pedido->fecrecibido);
            $duraciones = $this->duraciones($pedido);
            $dia = $recibido->format('Y-m-d');
            if (isset($dias[$dia])) {
                $sumar($dias[$dia], $duraciones);
            }
            $sumar($horas[(int) $recibido->format('G')], $duraciones);
            $sumar($totales, $duraciones);
        }

        $cerrar = fn (array $grupo) => [
            'etiqueta' => $grupo['etiqueta'],
            'pedidos' => $grupo['pedidos'],
            'tiempos' => array_map(
                fn (string $tramo) => $grupo['cuenta'][$tramo] > 0 ? (int) round($grupo['suma'][$tramo] / $grupo['cuenta'][$tramo]) : null,
                array_combine($tramos, $tramos),
            ),
        ];

        return [
            'dias' => array_values(array_map($cerrar, $dias)),
            // Solo las horas en que llegó algo: de madrugada no se trabaja.
            'horas' => array_values(array_map($cerrar, array_filter($horas, fn ($h) => $h['pedidos'] > 0))),
            'totales' => $cerrar($totales),
        ];
    }

    public function excelTiempos(string $codisb, Carbon $desde, Carbon $hasta, bool $conPacking = true): string
    {
        $datos = $this->tiempos($codisb, $desde, $hasta, $conPacking);
        $tramos = self::tramos($conPacking);
        $encabezado = fn (string $primera) => [$primera, 'PEDIDOS', ...array_map(fn ($t) => strtoupper(self::NOMBRES_ETAPA[$t]).' (MIN)', $tramos)];
        $fila = fn (array $g, string $etiqueta) => [$etiqueta, $g['pedidos'], ...array_map(fn ($t) => self::minutos($g['tiempos'][$t]), $tramos)];

        return $this->excel([
            'Por día' => [$encabezado('DIA'), array_map(fn ($g) => $fila($g, Carbon::parse($g['etiqueta'])->format('d-m-Y')), $datos['dias'])],
            'Por hora' => [$encabezado('HORA'), array_map(fn ($g) => $fila($g, $g['etiqueta']), $datos['horas'])],
        ]);
    }

    // ---------------------------------------------------------------- Pedidos por cliente y por ruta

    /**
     * Pedidos con el despacho cerrado (FallasService::ESTADOS) llegados en el rango, agrupados por
     * cliente o por ruta, del que más pedidos tuvo al que menos.
     *
     * @return Collection<int, array>
     */
    public function clientes(string $codisb, Carbon $desde, Carbon $hasta, string $agrupar = 'cliente'): Collection
    {
        $pedidos = $this->consultaPedidos($codisb, $desde, $hasta)->whereIn('p.estado', FallasService::ESTADOS);

        $renglones = DB::table('pedren as pr')
            ->leftJoin('sides_pedren_operacion as op', function ($join) {
                $join->on('op.id_pedido', '=', 'pr.id')->on('op.item', '=', 'pr.item');
            })
            ->whereIn('pr.id', (clone $pedidos)->select('p.id'))
            ->groupBy('pr.id')
            ->selectRaw('pr.id, COUNT(*) as renglones, SUM(pr.cantidad) as pedidas, SUM('.self::DESPACHADO.') as despachadas,
                SUM(CASE WHEN ('.self::DESPACHADO.') < pr.cantidad THEN 1 ELSE 0 END) as fallas')
            ->get()
            ->keyBy('id');

        $grupos = [];
        foreach ($pedidos->cursor() as $pedido) {
            $clave = $agrupar === 'ruta' ? (trim((string) $pedido->ruta) ?: 'Sin ruta') : (string) $pedido->codcli;
            $grupo = $grupos[$clave] ??= [
                'clave' => $clave,
                'nombre' => $agrupar === 'ruta' ? $clave : (string) $pedido->nomcli,
                'ruta' => $agrupar === 'ruta' ? null : (string) $pedido->ruta,
                'clientes' => [],
                'pedidos' => 0, 'renglones' => 0, 'pedidas' => 0, 'despachadas' => 0, 'fallas' => 0,
                'suma' => 0, 'cuenta' => 0,
            ];
            $r = $renglones[$pedido->id] ?? null;
            $grupo['pedidos']++;
            $grupo['clientes'][$pedido->codcli] = true;
            $grupo['renglones'] += (int) ($r->renglones ?? 0);
            $grupo['pedidas'] += (int) ($r->pedidas ?? 0);
            $grupo['despachadas'] += (int) ($r->despachadas ?? 0);
            $grupo['fallas'] += (int) ($r->fallas ?? 0);
            if (($total = DesempenoAlmacen::duracion($pedido->fecrecibido, $pedido->feccompletado)) !== null) {
                $grupo['suma'] += $total;
                $grupo['cuenta']++;
            }
            $grupos[$clave] = $grupo;
        }

        return collect($grupos)
            ->map(fn (array $g) => [
                'clave' => $g['clave'],
                'nombre' => $g['nombre'],
                'ruta' => $g['ruta'],
                'clientes' => count($g['clientes']),
                'pedidos' => $g['pedidos'],
                'renglones' => $g['renglones'],
                'pedidas' => $g['pedidas'],
                'despachadas' => $g['despachadas'],
                'fallas' => $g['fallas'],
                'servicio' => $g['pedidas'] > 0 ? round(100 * $g['despachadas'] / $g['pedidas'], 1) : null,
                'tiempo' => $g['cuenta'] > 0 ? (int) round($g['suma'] / $g['cuenta']) : null,
            ])
            ->sortBy([['pedidos', 'desc'], ['renglones', 'desc'], ['nombre', 'asc']])
            ->values();
    }

    public function excelClientes(string $codisb, Carbon $desde, Carbon $hasta, string $agrupar): string
    {
        $porRuta = $agrupar === 'ruta';
        $filas = $this->clientes($codisb, $desde, $hasta, $agrupar)->map(fn (array $g) => [
            ...($porRuta ? [$g['nombre'], $g['clientes']] : [$g['clave'], $g['nombre'], (string) $g['ruta']]),
            $g['pedidos'], $g['renglones'], $g['pedidas'], $g['despachadas'], $g['fallas'],
            $g['servicio'] ?? '', self::minutos($g['tiempo']),
        ])->all();

        return $this->excel([
            $porRuta ? 'Por ruta' : 'Por cliente' => [[
                ...($porRuta ? ['RUTA', 'CLIENTES'] : ['CODIGO', 'CLIENTE', 'RUTA']),
                'PEDIDOS', 'RENGLONES', 'UNIDADES PEDIDAS', 'UNIDADES DESPACHADAS', 'RENGLONES CON FALLA', 'SERVICIO %', 'TIEMPO PROMEDIO (MIN)',
            ], $filas],
        ]);
    }

    // ---------------------------------------------------------------- Pedidos demorados

    /**
     * Pedidos llegados en el rango con una etapa de $minutos o más. Una etapa que sigue abierta
     * (el pedido está en ella ahora) cuenta hasta este momento y sale «en curso».
     *
     * @return Collection<int, array>
     */
    public function demorados(string $codisb, Carbon $desde, Carbon $hasta, ?string $etapa, int $minutos, bool $conPacking = true): Collection
    {
        $etapas = array_keys(array_filter(self::ETAPAS, fn ($e, $clave) => $conPacking || $clave !== 'packing', ARRAY_FILTER_USE_BOTH));
        $mirar = $etapa && in_array($etapa, $etapas, true) ? [$etapa] : $etapas;
        $limite = $minutos * 60;
        $ahora = now()->getTimestamp();
        $demorados = collect();

        foreach ($this->pedidos($codisb, $desde, $hasta) as $pedido) {
            $etapasPedido = [];
            foreach ($etapas as $clave) {
                [$inicio, $fin, $estados] = self::ETAPAS[$clave];
                $desdeEtapa = FechaSeped::valor($pedido->{$inicio});
                $hastaEtapa = FechaSeped::valor($pedido->{$fin});
                $enCurso = $desdeEtapa && ! $hastaEtapa && in_array($pedido->estado, $estados, true);
                $segundos = match (true) {
                    $desdeEtapa && $hastaEtapa => $hastaEtapa->getTimestamp() - $desdeEtapa->getTimestamp(),
                    $enCurso => $ahora - $desdeEtapa->getTimestamp(),
                    default => null,
                };
                $etapasPedido[$clave] = ['segundos' => $segundos !== null && $segundos >= 0 ? $segundos : null, 'enCurso' => $enCurso];
            }

            $peor = collect($mirar)
                ->filter(fn ($clave) => ($etapasPedido[$clave]['segundos'] ?? 0) >= $limite)
                ->sortByDesc(fn ($clave) => $etapasPedido[$clave]['segundos'])
                ->first();
            if ($peor === null) {
                continue;
            }

            $demorados->push([
                'id' => (int) $pedido->id,
                'codcli' => (string) $pedido->codcli,
                'nomcli' => (string) $pedido->nomcli,
                'ruta' => (string) $pedido->ruta,
                'estado' => (string) $pedido->estado,
                'recibido' => Carbon::parse($pedido->fecrecibido),
                'etapa' => $peor,
                'segundos' => $etapasPedido[$peor]['segundos'],
                'enCurso' => $etapasPedido[$peor]['enCurso'],
                'etapas' => $etapasPedido,
                'responsable' => match ($peor) {
                    'picking' => (string) $pedido->despachador,
                    'packing' => (string) $pedido->embalador,
                    default => '',
                },
            ]);
        }

        return $demorados->sortByDesc('segundos')->values();
    }

    public function paginar(Collection $filas, int $porPagina = self::POR_PAGINA): LengthAwarePaginator
    {
        $pagina = Paginator::resolveCurrentPage();

        return (new LengthAwarePaginator($filas->forPage($pagina, $porPagina)->values(), $filas->count(), $porPagina, $pagina, [
            'path' => Paginator::resolveCurrentPath(),
        ]))->withQueryString();
    }

    public function excelDemorados(string $codisb, Carbon $desde, Carbon $hasta, ?string $etapa, int $minutos, bool $conPacking = true): string
    {
        $etapas = $conPacking ? ['aceptar', 'picking', 'packing'] : ['aceptar', 'picking'];
        $filas = $this->demorados($codisb, $desde, $hasta, $etapa, $minutos, $conPacking)->map(fn (array $d) => [
            $d['id'], $d['recibido']->format('d-m-Y H:i'), $d['estado'], $d['codcli'], $d['nomcli'], $d['ruta'],
            self::NOMBRES_ETAPA[$d['etapa']].($d['enCurso'] ? ' (en curso)' : ''), self::minutos($d['segundos']), $d['responsable'],
            ...array_map(fn ($e) => self::minutos($d['etapas'][$e]['segundos']), $etapas),
        ])->all();

        return $this->excel([
            'Demorados' => [[
                'PEDIDO', 'LLEGO', 'ESTADO', 'CODIGO CLIENTE', 'CLIENTE', 'RUTA', 'ETAPA DEMORADA', 'DEMORA (MIN)', 'RESPONSABLE',
                ...array_map(fn ($e) => strtoupper(self::NOMBRES_ETAPA[$e]).' (MIN)', $etapas),
            ], $filas],
        ]);
    }

    // ---------------------------------------------------------------- Resumen del mes

    /** Los clientes con más pedidos del mes (para el resumen mensual). */
    public function mejoresClientes(string $codisb, Carbon $desde, Carbon $hasta, int $cuantos = 5): Collection
    {
        return $this->clientes($codisb, $desde, $hasta)->take($cuantos);
    }

    /** Cuántos pedidos tuvieron una etapa de MINUTOS_DEMORA o más. */
    public function totalDemorados(string $codisb, Carbon $desde, Carbon $hasta, bool $conPacking = true): int
    {
        return $this->demorados($codisb, $desde, $hasta, null, self::MINUTOS_DEMORA, $conPacking)->count();
    }

    // ---------------------------------------------------------------- Comunes

    /** "3 h 5 min", "8 min 28 s", "45 s" o "—". */
    public static function texto(?int $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }
        if ($segundos < 60) {
            return $segundos.' s';
        }
        if ($segundos < 3600) {
            $s = $segundos % 60;

            return intdiv($segundos, 60).' min'.($s && $segundos < 600 ? " {$s} s" : '');
        }
        if ($segundos < 86400) {
            $m = intdiv($segundos % 3600, 60);

            return intdiv($segundos, 3600).' h'.($m ? " {$m} min" : '');
        }
        $h = intdiv($segundos % 86400, 3600);

        return intdiv($segundos, 86400).' d'.($h ? " {$h} h" : '');
    }

    public static function minutos(?int $segundos): float|string
    {
        return $segundos === null ? '' : round($segundos / 60, 1);
    }

    private function consultaPedidos(string $codisb, Carbon $desde, Carbon $hasta)
    {
        return DB::table('pedido as p')
            ->where('p.codisb', $codisb)
            ->where('p.estado', '<>', 'ANULADO')
            ->whereBetween('p.fecrecibido', [
                $desde->copy()->startOfDay()->toDateTimeString(),
                $hasta->copy()->endOfDay()->toDateTimeString(),
            ])
            ->where('p.fecrecibido', '>', FechaSeped::VACIA);
    }

    /** Pedidos llegados en el rango (fechas inclusivas), con quién hizo el picking y el packing. */
    private function pedidos(string $codisb, Carbon $desde, Carbon $hasta): LazyCollection
    {
        return $this->consultaPedidos($codisb, $desde, $hasta)
            ->leftJoin('sides_pedido_operacion as po', 'po.id_pedido', '=', 'p.id')
            ->orderBy('p.fecrecibido')
            ->select([
                'p.id', 'p.codcli', 'p.nomcli', 'p.ruta', 'p.estado',
                'p.fecrecibido', 'p.fecpicking', 'p.fecpacking', 'p.feccompletado',
                'po.despachador', 'po.embalador',
            ])
            ->cursor();
    }

    /** @return array<string, ?int> */
    private function duraciones(object $pedido): array
    {
        $resultado = [];
        foreach (DesempenoAlmacen::TRAMOS as $tramo => [$inicio, $fin]) {
            $resultado[$tramo] = DesempenoAlmacen::duracion($pedido->{$inicio}, $pedido->{$fin});
        }

        return $resultado;
    }

    /** @param array<string, array{0: list<string>, 1: list<list<mixed>>}> $hojas */
    private function excel(array $hojas): string
    {
        $negrita = new Style(fontBold: true);
        $base = tempnam(sys_get_temp_dir(), 'ops');
        @unlink($base);
        $archivo = $base.'.xlsx';
        $writer = new Writer();
        $writer->openToFile($archivo);

        $primera = true;
        foreach ($hojas as $nombre => [$encabezado, $filas]) {
            $hoja = $primera ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $hoja->setName($nombre);
            $primera = false;
            $writer->addRow(Row::fromValuesWithStyle($encabezado, $negrita));
            foreach ($filas as $fila) {
                $writer->addRow(Row::fromValues($fila));
            }
        }
        $writer->close();

        return $archivo;
    }
}
