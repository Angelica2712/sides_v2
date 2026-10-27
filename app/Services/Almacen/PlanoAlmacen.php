<?php

namespace App\Services\Almacen;

use App\Support\ConfigAlmacen;
use App\Support\UbicacionAlmacen;
use Illuminate\Support\Facades\DB;

/**
 * Plano del almacén de una droguería, armado con las ubicaciones del catálogo: la registrada en
 * SIDES (UbicacionesRegistradas) o, si no hay, la que manda el ERP en producto.ubicacion. No hace
 * falta dibujarlo a mano: cada ubicación en el formato de UbicacionAlmacen dice qué zona, pasillo,
 * anaquel y estante existen. Los pasillos que la droguería registra en el acomodo
 * (config.pasillos) aparecen con todos sus anaqueles aunque todavía no tengan productos.
 *
 * Cada anaquel tiene los estantes y posiciones que diga la configuración del almacén (4 y 4 si no
 * se configuró), o más si alguna ubicación llega más alto. El plano lleva esa configuración para
 * que el visor sepa cómo está acomodado.
 */
class PlanoAlmacen
{
    /**
     * @return array{
     *     zonas: list<array{zona: string, pasillos: list<array{pasillo: int, anaqueles: list<array{anaquel: int, estantes: int, posiciones: int}>}>}>,
     *     ubicaciones: int,
     *     reconocidas: int,
     *     ocupados: list<string>,
     *     config: array,
     * }
     *
     * @param  bool  $conQuitados  también los anaqueles borrados en el plano (el editor los filtra
     *                             él mismo, para poder volver a ponerlos)
     */
    public function de(string $codisb, bool $conQuitados = false): array
    {
        $ubicacion = UbicacionesRegistradas::sql('producto.ubicacion');
        $textos = UbicacionesRegistradas::unir(DB::table('producto'), 'producto')
            ->where('producto.codisb', $codisb)
            ->whereRaw("{$ubicacion} <> ''")
            ->distinct()
            ->pluck(DB::raw("{$ubicacion} as ubicacion"));

        return $this->armar($textos, ConfigAlmacen::de($codisb), $conQuitados);
    }

    /**
     * @param  iterable<string>  $textos  valores de producto.ubicacion
     */
    public function armar(iterable $textos, array $config = ConfigAlmacen::POR_DEFECTO, bool $conQuitados = false): array
    {
        $zonas = [];
        $ubicaciones = 0;
        $reconocidas = 0;

        foreach ($textos as $texto) {
            $ubicaciones++;
            $todas = UbicacionAlmacen::todas($texto);
            if ($todas) {
                $reconocidas++;
            }

            foreach ($todas as $u) {
                $zonas[$u['zona']] ??= [];
                if ($u['pasillo'] === null) {
                    continue;
                }
                $zonas[$u['zona']][$u['pasillo']] ??= [];
                if ($u['anaquel'] === null) {
                    continue;
                }

                $anaquel = $zonas[$u['zona']][$u['pasillo']][$u['anaquel']] ?? ['estantes' => 0, 'posiciones' => 0];
                if ($u['estante'] !== null) {
                    $anaquel['estantes'] = max($anaquel['estantes'], ord($u['estante']) - ord('A') + 1);
                }
                if ($u['posicion'] !== null) {
                    $anaquel['posiciones'] = max($anaquel['posiciones'], $u['posicion']);
                }
                $zonas[$u['zona']][$u['pasillo']][$u['anaquel']] = $anaquel;
            }
        }

        // Anaqueles con productos ubicados: existen aunque se hayan borrado en el plano.
        $ocupados = [];
        foreach ($zonas as $zona => $pasillos) {
            foreach ($pasillos as $numero => $anaqueles) {
                foreach (array_keys($anaqueles) as $anaquel) {
                    $ocupados[sprintf('%s-%02d-%02d', $zona, $numero, $anaquel)] = true;
                }
            }
        }
        $quitados = $conQuitados ? [] : array_flip($config['quitados'] ?? []);

        foreach ($config['pasillos'] ?? [] as $clave => $pasillo) {
            [$zona, $numero] = explode('-', $clave);
            $numero = (int) $numero;
            // Lo registrado manda sobre el tamaño general del almacén.
            $base = [
                'estantes' => $pasillo['estantes'] ?? $config['estantes'],
                'posiciones' => $pasillo['posiciones'] ?? $config['posiciones'],
            ];
            foreach (ConfigAlmacen::anaquelesDe($pasillo) as $anaquel) {
                if (isset($quitados[sprintf('%s-%02d', $clave, $anaquel)])) {
                    continue;
                }
                $zonas[$zona][$numero][$anaquel] = [...($zonas[$zona][$numero][$anaquel] ?? ['estantes' => 0, 'posiciones' => 0]), 'base' => $base];
            }
        }

        ksort($zonas);

        return [
            'zonas' => array_map(fn (string $zona) => [
                'zona' => $zona,
                'pasillos' => $this->pasillos($zonas[$zona], $config),
            ], array_keys($zonas)),
            'ubicaciones' => $ubicaciones,
            'reconocidas' => $reconocidas,
            'ocupados' => array_keys($ocupados),
            'config' => $config,
        ];
    }

    private function pasillos(array $pasillos, array $config): array
    {
        ksort($pasillos);

        return array_map(function (int $pasillo) use ($pasillos, $config) {
            $anaqueles = $pasillos[$pasillo];
            ksort($anaqueles);

            return [
                'pasillo' => $pasillo,
                'anaqueles' => array_map(fn (int $anaquel) => [
                    'anaquel' => $anaquel,
                    'estantes' => max($anaqueles[$anaquel]['estantes'], $anaqueles[$anaquel]['base']['estantes'] ?? $config['estantes']),
                    'posiciones' => max($anaqueles[$anaquel]['posiciones'], $anaqueles[$anaquel]['base']['posiciones'] ?? $config['posiciones']),
                ], array_keys($anaqueles)),
            ];
        }, array_keys($pasillos));
    }
}
