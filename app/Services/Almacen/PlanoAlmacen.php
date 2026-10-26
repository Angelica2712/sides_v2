<?php

namespace App\Services\Almacen;

use App\Support\ConfigAlmacen;
use App\Support\UbicacionAlmacen;
use Illuminate\Support\Facades\DB;

/**
 * Plano del almacén de una droguería, armado con las ubicaciones del catálogo (producto.ubicacion,
 * que manda el ERP). No hace falta dibujarlo a mano: cada ubicación en el formato de
 * UbicacionAlmacen dice qué zona, pasillo, anaquel y estante existen.
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
     *     config: array,
     * }
     */
    public function de(string $codisb): array
    {
        $textos = DB::table('producto')
            ->where('codisb', $codisb)
            ->where('ubicacion', '<>', '')
            ->distinct()
            ->pluck('ubicacion');

        return $this->armar($textos, ConfigAlmacen::de($codisb));
    }

    /**
     * @param  iterable<string>  $textos  valores de producto.ubicacion
     */
    public function armar(iterable $textos, array $config = ConfigAlmacen::POR_DEFECTO): array
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

        ksort($zonas);

        return [
            'zonas' => array_map(fn (string $zona) => [
                'zona' => $zona,
                'pasillos' => $this->pasillos($zonas[$zona], $config),
            ], array_keys($zonas)),
            'ubicaciones' => $ubicaciones,
            'reconocidas' => $reconocidas,
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
                    'estantes' => max($anaqueles[$anaquel]['estantes'], $config['estantes']),
                    'posiciones' => max($anaqueles[$anaquel]['posiciones'], $config['posiciones']),
                ], array_keys($anaqueles)),
            ];
        }, array_keys($pasillos));
    }
}
