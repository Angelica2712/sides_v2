<?php

namespace App\Services\Rutas;

use App\Models\Sides\SidesRuta;
use App\Models\Sides\SidesRutaren;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Rutas de despacho: qué clientes visita cada ruta y en qué orden (legacy AdminrutasController,
 * idéntico en las tres droguerías, y el cron vAccionSincRutas).
 *
 * Un cliente está en una sola ruta por sucursal (índice único codisb+codcli). El legacy lo
 * ignoraba en silencio o fallaba toda la operación; acá los clientes que ya tienen ruta se
 * omiten y se informa cuántos fueron. Los clientes salen de la tabla `cliente` de SEPED
 * (solo lectura), sin la conexión cruzada del legacy.
 */
class RutasService
{
    /** Distancia entre secuencias consecutivas, como en el legacy: deja lugar para intercalar. */
    public const PASO = 20;

    public const POR_PAGINA = 100;

    /** Orden de SEPED (cliente.orden); los que no lo tienen (0) van al final. */
    private const ORDEN_SEPED = 'CASE WHEN orden > 0 THEN 0 ELSE 1 END, orden';

    /** Columnas del Excel de rutas (importar y descargar). */
    public const COLUMNAS_EXCEL = ['CODIGO', 'CLIENTE', 'RIF', 'ZONA', 'ORDEN'];

    public function listar(string $codisb, string $buscar): LengthAwarePaginator
    {
        return SidesRuta::query()
            ->where('codisb', $codisb)
            ->when($buscar !== '', fn ($q) => $q->where('nombre', 'like', "%{$buscar}%"))
            ->withCount('clientes')
            ->orderBy('nombre')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    public function buscar(string $codisb, int $id): SidesRuta
    {
        return SidesRuta::query()->where('codisb', $codisb)->find($id)
            ?? throw new RutasException("La ruta #{$id} no existe en tu sucursal.");
    }

    public function clientesDeRuta(SidesRuta $ruta, string $buscar): LengthAwarePaginator
    {
        return SidesRutaren::query()
            ->where('id', $ruta->id)
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('codcli', 'like', "%{$buscar}%")
                ->orWhere('nomcli', 'like', "%{$buscar}%")
                ->orWhere('zona', 'like', "%{$buscar}%")
                ->orWhere('rif', 'like', "%{$buscar}%")))
            ->orderByRaw('sec + 0')
            ->orderBy('item')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    public function crear(string $codisb, string $nombre): SidesRuta
    {
        $nombre = $this->nombre($nombre);
        $this->exigirNombreLibre($codisb, $nombre);

        return SidesRuta::query()->create(['nombre' => $nombre, 'codisb' => $codisb, 'fecha' => Carbon::now()]);
    }

    /**
     * Clientes de SEPED de la sucursal que todavía no están en ninguna ruta.
     *
     * @return Collection<int, object{codcli: string, nombre: ?string, rif: ?string, ruta: string}>
     */
    public function clientesDisponibles(string $codisb, string $buscar, int $limite = 50): Collection
    {
        return $this->clientesSeped($codisb)
            ->whereNotExists(fn ($q) => $q->from('sides_rutasren as rr')
                ->whereColumn('rr.codcli', 'cliente.codcli')
                ->where('rr.codisb', $codisb))
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('cliente.codcli', 'like', "%{$buscar}%")
                ->orWhere('cliente.nombre', 'like', "%{$buscar}%")
                ->orWhere('cliente.rif', 'like', "%{$buscar}%")
                ->orWhere('cliente.ruta', 'like', "%{$buscar}%")))
            ->orderBy('nombre')
            ->limit($limite)
            ->get();
    }

    /**
     * Rutas que SEPED tiene asignadas a los clientes de la sucursal.
     *
     * @return Collection<int, object{ruta: string, clientes: int}>
     */
    public function rutasSeped(string $codisb): Collection
    {
        return DB::query()
            ->fromSub($this->clientesSeped($codisb)->where('cliente.ruta', '<>', ''), 'c')
            ->selectRaw('ruta, COUNT(*) as clientes')
            ->groupBy('ruta')
            ->orderBy('ruta')
            ->get();
    }

    /** @param list<string> $codigos */
    public function agregarClientes(SidesRuta $ruta, array $codigos): Resultado
    {
        $clientes = $this->clientesSeped($ruta->codisb)
            ->whereIn('cliente.codcli', array_values(array_unique($codigos)))
            ->orderBy('nombre')
            ->get()
            ->map(fn ($c) => ['codcli' => $c->codcli, 'nomcli' => $c->nombre, 'rif' => $c->rif, 'zona' => $c->ruta, 'sec' => null]);

        return new Resultado($ruta, $this->insertar($ruta, $clientes), count(array_unique($codigos)));
    }

    public function crearDesdeSeped(string $codisb, string $rutaSeped, string $nombre): Resultado
    {
        $rutaSeped = mb_strtoupper(trim($rutaSeped));
        $clientes = $this->clientesSeped($codisb)
            ->whereRaw('UPPER(TRIM(cliente.ruta)) = ?', [$rutaSeped])
            ->orderByRaw(self::ORDEN_SEPED)
            ->orderBy('nombre')
            ->get();

        if ($clientes->isEmpty()) {
            throw new RutasException("SEPED no tiene clientes en la ruta {$rutaSeped}.");
        }

        return DB::transaction(function () use ($codisb, $nombre, $clientes) {
            $ruta = $this->crear($codisb, $nombre);
            $filas = $clientes->map(fn ($c) => [
                'codcli' => $c->codcli, 'nomcli' => $c->nombre, 'rif' => $c->rif, 'zona' => $c->ruta,
                'sec' => $c->orden > 0 ? (int) $c->orden : null,
            ]);

            return $this->exigirAgregados(new Resultado($ruta, $this->insertar($ruta, $filas), $clientes->count()));
        });
    }

    /** Excel con encabezado y columnas: código, cliente, RIF, zona, orden (1, 2, 3…). */
    public function importar(string $codisb, string $nombre, string $archivo): Resultado
    {
        $filas = $this->leerExcel($archivo);

        if ($filas->isEmpty()) {
            throw new RutasException('El archivo no tiene clientes: revisa que los códigos estén en la primera columna, debajo del encabezado.');
        }

        // Se completan nombre y RIF vacíos con los datos de SEPED.
        $seped = $this->clientesSeped($codisb)->whereIn('cliente.codcli', $filas->pluck('codcli')->all())->get()->keyBy('codcli');
        $filas = $filas->map(fn (array $fila) => [
            ...$fila,
            'nomcli' => $fila['nomcli'] !== '' ? $fila['nomcli'] : ($seped[$fila['codcli']]->nombre ?? ''),
            'rif' => $fila['rif'] !== '' ? $fila['rif'] : ($seped[$fila['codcli']]->rif ?? ''),
            'zona' => $fila['zona'] !== '' ? $fila['zona'] : ($seped[$fila['codcli']]->ruta ?? ''),
        ]);

        return DB::transaction(function () use ($codisb, $nombre, $filas) {
            $ruta = $this->crear($codisb, $nombre);

            return $this->exigirAgregados(new Resultado($ruta, $this->insertar($ruta, $filas), $filas->count()));
        });
    }

    /** Genera el Excel de la ruta en un archivo temporal y devuelve su ruta en disco. */
    public function exportar(SidesRuta $ruta): string
    {
        $base = tempnam(sys_get_temp_dir(), 'ruta');
        @unlink($base);
        $archivo = $base.'.xlsx';
        $writer = new Writer();
        $writer->openToFile($archivo);
        $writer->addRow(Row::fromValuesWithStyle(self::COLUMNAS_EXCEL, new Style(fontBold: true)));

        // ORDEN es la posición: al volver a importar el archivo se reconstruye la misma secuencia.
        $posicion = 0;
        foreach (SidesRutaren::query()->where('id', $ruta->id)->orderByRaw('sec + 0')->orderBy('item')->cursor() as $cliente) {
            $writer->addRow(Row::fromValues([$cliente->codcli, $cliente->nomcli, $cliente->rif, $cliente->zona, ++$posicion]));
        }
        $writer->close();

        return $archivo;
    }

    /** Renombra la ruta y las guías que la usan (la guía guarda el nombre, como en el legacy). */
    public function renombrar(SidesRuta $ruta, string $nombre): void
    {
        $nombre = $this->nombre($nombre);
        if ($nombre === $ruta->nombre) {
            return;
        }
        $this->exigirNombreLibre($ruta->codisb, $nombre, $ruta->id);

        DB::transaction(function () use ($ruta, $nombre) {
            DB::table('sides_guia')->where('codisb', $ruta->codisb)->where('ruta', $ruta->nombre)->update(['ruta' => $nombre]);
            $ruta->update(['nombre' => $nombre]);
        });
    }

    public function renombrarZona(SidesRuta $ruta, string $zona): int
    {
        return SidesRutaren::query()->where('id', $ruta->id)->update(['zona' => $this->zona($zona)]);
    }

    public function actualizarCliente(SidesRuta $ruta, int $item, string $zona, int $secuencia, bool $retiraLocal): void
    {
        $cliente = $this->clienteDeRuta($ruta, $item);

        $ocupada = SidesRutaren::query()
            ->where('id', $ruta->id)
            ->where('item', '<>', $item)
            ->whereRaw('sec + 0 = ?', [$secuencia])
            ->first();
        if ($ocupada) {
            throw new RutasException("La secuencia {$secuencia} ya la tiene {$ocupada->nomcli} ({$ocupada->codcli}).");
        }

        $cliente->update(['zona' => $this->zona($zona), 'sec' => (string) $secuencia, 'retiraLocal' => $retiraLocal ? 1 : 0]);
    }

    public function quitarCliente(SidesRuta $ruta, int $item): SidesRutaren
    {
        $cliente = $this->clienteDeRuta($ruta, $item);
        $cliente->delete();

        return $cliente;
    }

    public function eliminar(SidesRuta $ruta): void
    {
        DB::transaction(function () use ($ruta) {
            SidesRutaren::query()->where('id', $ruta->id)->delete();
            $ruta->delete();
        });
    }

    /**
     * Sincronización automática con SEPED (legacy vAccionSincRutas, cada hora con
     * sides_cfg.activarSincronizacionRutas): crea las rutas que SEPED asigna a los clientes, agrega
     * los clientes que aún no tienen ruta y actualiza nombre, RIF y orden de los que ya están.
     * Como en el legacy, no cambia de ruta a un cliente que ya tiene una.
     *
     * @return array{rutas: int, agregados: int, actualizados: int}
     */
    public function sincronizar(string $codisb): array
    {
        $clientes = $this->clientesSeped($codisb)->where('cliente.ruta', '<>', '')->orderByRaw(self::ORDEN_SEPED)->orderBy('nombre')->get();
        $asignados = SidesRutaren::query()->where('codisb', $codisb)->get()->keyBy('codcli');
        $resultado = ['rutas' => 0, 'agregados' => 0, 'actualizados' => 0];

        DB::transaction(function () use ($codisb, $clientes, $asignados, &$resultado) {
            foreach ($clientes->groupBy('ruta') as $nombre => $grupo) {
                $ruta = SidesRuta::query()->where('codisb', $codisb)->where('nombre', $nombre)->first();
                if (! $ruta) {
                    $ruta = SidesRuta::query()->create(['nombre' => $nombre, 'codisb' => $codisb, 'fecha' => Carbon::now()]);
                    $resultado['rutas']++;
                }

                $nuevos = $grupo->reject(fn ($c) => $asignados->has($c->codcli));
                $resultado['agregados'] += $this->insertar($ruta, $nuevos->map(fn ($c) => [
                    'codcli' => $c->codcli, 'nomcli' => $c->nombre, 'rif' => $c->rif, 'zona' => $c->ruta,
                    'sec' => $c->orden > 0 ? (int) $c->orden : null,
                ]));
            }

            foreach ($clientes as $c) {
                $actual = $asignados->get($c->codcli);
                if (! $actual) {
                    continue;
                }
                $cambios = array_filter([
                    'nomcli' => $this->texto($c->nombre) !== $actual->nomcli ? $this->texto($c->nombre) : null,
                    'rif' => (string) $c->rif !== $actual->rif ? (string) $c->rif : null,
                    'sec' => $c->orden > 0 && (int) $actual->sec !== (int) $c->orden ? (string) $c->orden : null,
                ], fn ($valor) => $valor !== null);
                if ($cambios) {
                    $actual->update($cambios);
                    $resultado['actualizados']++;
                }
            }
        });

        return $resultado;
    }

    /** Un registro por cliente de la sucursal (en `cliente` se repite por codac3). */
    private function clientesSeped(string $codisb): Builder
    {
        return DB::table('cliente')
            ->where('cliente.codisb', $codisb)
            ->groupBy('cliente.codcli')
            ->selectRaw('cliente.codcli, MAX(cliente.nombre) as nombre, MAX(cliente.rif) as rif, MAX(UPPER(TRIM(cliente.ruta))) as ruta, MAX(cliente.orden) as orden');
    }

    /**
     * Inserta clientes al final de la ruta, sin los que ya están en alguna ruta de la sucursal.
     * Una fila con `sec` conserva esa secuencia; sin ella se numera de PASO en PASO.
     *
     * @param  Collection<int, array{codcli: string, nomcli: ?string, rif: ?string, zona: ?string, sec: ?int}>  $filas
     * @return int clientes agregados
     */
    private function insertar(SidesRuta $ruta, Collection $filas): int
    {
        $filas = $filas->unique('codcli');
        if ($filas->isEmpty()) {
            return 0;
        }

        $asignados = SidesRutaren::query()->where('codisb', $ruta->codisb)
            ->whereIn('codcli', $filas->pluck('codcli')->all())->pluck('codcli')->all();
        $ultima = (int) SidesRutaren::query()->where('id', $ruta->id)->max(DB::raw('sec + 0'));

        $registros = $filas
            ->reject(fn (array $fila) => in_array($fila['codcli'], $asignados, true))
            ->map(function (array $fila) use ($ruta, &$ultima) {
                $sec = $fila['sec'] ?? ($ultima + self::PASO);
                $ultima = max($ultima, $sec);

                return [
                    'id' => $ruta->id,
                    'codisb' => $ruta->codisb,
                    'codcli' => mb_substr($fila['codcli'], 0, 100),
                    'nomcli' => $this->texto($fila['nomcli']),
                    'rif' => mb_substr((string) $fila['rif'], 0, 100),
                    'zona' => $this->zona((string) $fila['zona']),
                    'sec' => (string) $sec,
                    'retiraLocal' => 0,
                ];
            })
            ->values();

        return $registros->chunk(500)->sum(fn (Collection $lote) => DB::table('sides_rutasren')->insertOrIgnore($lote->values()->all()));
    }

    /** @return Collection<int, array{codcli: string, nomcli: string, rif: string, zona: string, sec: ?int}> */
    private function leerExcel(string $archivo): Collection
    {
        $filas = collect();
        $reader = new Reader();

        try {
            $reader->open($archivo);
            foreach ($reader->getSheetIterator() as $hoja) {
                $encabezado = true;
                foreach ($hoja->getRowIterator() as $fila) {
                    $valores = array_map(fn ($valor) => trim((string) ($valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor)), $fila->toArray());
                    if ($encabezado || ($valores[0] ?? '') === '') {
                        $encabezado = false;
                        continue;
                    }
                    $orden = (int) ($valores[4] ?? 0);
                    $filas->push([
                        'codcli' => $valores[0],
                        'nomcli' => $valores[1] ?? '',
                        'rif' => $valores[2] ?? '',
                        'zona' => $valores[3] ?? '',
                        'sec' => $orden > 0 ? $orden * self::PASO : null,
                    ]);
                }
                break; // Solo la primera hoja, como el legacy.
            }
        } catch (Throwable) {
            throw new RutasException('No se pudo leer el archivo. Guárdalo como Excel (.xlsx) e inténtalo de nuevo.');
        } finally {
            $reader->close();
        }

        return $filas;
    }

    /** Una ruta nueva sin ningún cliente no se crea (la excepción deshace la transacción). */
    private function exigirAgregados(Resultado $resultado): Resultado
    {
        if ($resultado->agregados === 0) {
            throw new RutasException('No se creó la ruta: todos sus clientes ya están en otras rutas.');
        }

        return $resultado;
    }

    private function clienteDeRuta(SidesRuta $ruta, int $item): SidesRutaren
    {
        return SidesRutaren::query()->where('id', $ruta->id)->find($item)
            ?? throw new RutasException('Ese cliente ya no está en la ruta.');
    }

    private function exigirNombreLibre(string $codisb, string $nombre, ?int $excepto = null): void
    {
        $existe = SidesRuta::query()->where('codisb', $codisb)->where('nombre', $nombre)
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))->exists();

        if ($existe) {
            throw new RutasException("Ya existe una ruta llamada {$nombre}.");
        }
    }

    private function nombre(string $nombre): string
    {
        $nombre = mb_strtoupper(trim($nombre));
        if ($nombre === '') {
            throw new RutasException('Escribe el nombre de la ruta.');
        }

        return mb_substr($nombre, 0, 100);
    }

    private function zona(string $zona): string
    {
        return mb_substr(mb_strtoupper(trim($zona)), 0, 100);
    }

    private function texto(?string $valor): string
    {
        return trim(str_replace('"', '', (string) $valor));
    }
}
