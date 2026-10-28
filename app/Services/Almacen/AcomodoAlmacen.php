<?php

namespace App\Services\Almacen;

use App\Models\Sides\SidesAlmacenCfg;
use App\Support\ConfigAlmacen;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Acomodo del almacén de una droguería (ConfigAlmacen): lo edita FULLTECH360 desde
 * Administración y la propia droguería desde Ubicaciones, con la misma pantalla
 * (almacen/acomodo.blade.php).
 */
class AcomodoAlmacen
{
    public function __construct(private readonly PlanoAlmacen $plano)
    {
    }

    /**
     * Datos de la pantalla: el plano y las zonas que se pueden renombrar. El editor del acomodo
     * pide el plano con los anaqueles borrados ($conQuitados) para poder volver a ponerlos.
     */
    public function pantalla(string $codisb, bool $conQuitados = false): array
    {
        $plano = $this->plano->de($codisb, $conQuitados);

        return [
            'plano' => $plano,
            // Se pueden renombrar las zonas de SIDES y las que ya aparecen en el catálogo.
            'zonas' => collect(ConfigAlmacen::ZONAS)
                ->merge(collect($plano['zonas'])->pluck('zona')->mapWithKeys(fn ($zona) => [$zona => ConfigAlmacen::ZONAS[$zona] ?? '']))
                ->sortKeys()
                ->all(),
        ];
    }

    public function guardar(Request $request, string $codisb): void
    {
        $datos = $request->validate([
            'numeracion' => ['required', Rule::in(array_keys(ConfigAlmacen::NUMERACIONES))],
            'estanteA' => ['required', Rule::in(array_keys(ConfigAlmacen::ESTANTE_A))],
            'posicion1' => ['required', Rule::in(array_keys(ConfigAlmacen::POSICION_1))],
            'estantes' => ['required', 'integer', 'min:1', 'max:12'],
            'posiciones' => ['required', 'integer', 'min:1', 'max:30'],
            'zonas' => ['array'],
            'zonas.*' => ['nullable', 'string', 'max:40'],
            // Todo lo arma el editor (lista de pasillos, plano y 3D) y ConfigAlmacen::normalizar
            // descarta lo que no sea válido.
            // Pasillos registrados: {"PK-03": {"izquierda": 6, "derecha": 6, "estantes": 4, "posiciones": 4}, ...}
            'pasillos' => ['nullable', 'json', 'max:100000'],
            // Anaqueles movidos: {"PK-03-12": {"x": 1.5, "z": 4, "giro": 90}, ...}
            'disposicion' => ['nullable', 'json', 'max:500000'],
            // Anaqueles borrados en el plano y anaqueles sueltos numerados a mano: ["PK-03-05", ...]
            'quitados' => ['nullable', 'json', 'max:100000'],
            'extras' => ['nullable', 'json', 'max:100000'],
            // Puertas del almacén: [{"x": 0, "z": 5, "giro": 0, "nombre": "Carga"}, ...]
            'entradas' => ['nullable', 'json', 'max:10000'],
        ], [], [
            'numeracion' => 'numeración de anaqueles',
            'estanteA' => 'estante A',
            'posicion1' => 'posición 1',
            'estantes' => 'estantes por anaquel',
            'posiciones' => 'posiciones por estante',
            'pasillos' => 'pasillos',
            'disposicion' => 'ubicación de los anaqueles',
        ]);
        foreach (['pasillos' => '{}', 'disposicion' => '{}', 'quitados' => '[]', 'extras' => '[]', 'entradas' => '[]'] as $campo => $vacio) {
            $datos[$campo] = json_decode($datos[$campo] ?? $vacio, true) ?: [];
        }

        $this->escribir($codisb, $datos, $request->user()->email);
    }

    public function restablecer(string $codisb): void
    {
        SidesAlmacenCfg::query()->whereKey($codisb)->delete();
    }

    // ---- Asistente «Armar almacén»: un pasillo a la vez ----

    /**
     * Guarda un pasillo como lo cuenta quien está parado en su entrada. El asistente arma el
     * almacén con la numeración de SIDES (impares a la izquierda, estante A abajo, posición 1 a la
     * izquierda), así que la deja puesta; la zona nueva se guarda con su nombre.
     *
     * @return array la configuración guardada
     */
    public function guardarPasillo(Request $request, string $codisb): array
    {
        $datos = $request->validate([
            'zona' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'nombreZona' => ['nullable', 'string', 'max:40'],
            'pasillo' => ['required', 'integer', 'min:1', 'max:99'],
            'izquierda' => ['required', 'integer', 'min:0', 'max:'.ConfigAlmacen::MAXIMO_POR_LADO],
            'derecha' => ['required', 'integer', 'min:0', 'max:'.ConfigAlmacen::MAXIMO_POR_LADO],
            'estantes' => ['required', 'integer', 'min:1', 'max:12'],
            'posiciones' => ['required', 'integer', 'min:1', 'max:30'],
            'antes' => ['nullable', 'string', 'regex:/^[A-Z]{2}-\d{2}$/'],
        ], [
            'zona.regex' => 'La zona debe tener 2 letras (por ejemplo PK).',
        ], [
            'izquierda' => 'anaqueles a la izquierda',
            'derecha' => 'anaqueles a la derecha',
            'estantes' => 'estantes',
            'posiciones' => 'huecos por estante',
        ]);
        if ($datos['izquierda'] + $datos['derecha'] < 1) {
            throw ValidationException::withMessages(['izquierda' => 'El pasillo debe tener al menos un anaquel.']);
        }

        $config = ConfigAlmacen::de($codisb);
        // Al editar un pasillo se le puede cambiar el número o la zona.
        if (! empty($datos['antes'])) {
            unset($config['pasillos'][$datos['antes']]);
        }
        $zona = strtoupper($datos['zona']);
        $clave = self::clave($zona, $datos['pasillo']);
        $config['pasillos'][$clave] = $datos;
        // Recién contado: queda exactamente como se contó, sin anaqueles borrados ni sueltos de antes.
        $deEstePasillo = fn (string $anaquel) => str_starts_with($anaquel, "{$clave}-") || str_starts_with($anaquel, ($datos['antes'] ?? '-').'-');
        foreach (['quitados', 'extras'] as $lista) {
            $config[$lista] = array_values(array_filter($config[$lista], fn (string $anaquel) => ! $deEstePasillo($anaquel)));
        }
        if (filled($datos['nombreZona'] ?? null)) {
            $config['zonas'][$zona] = $datos['nombreZona'];
        }

        return $this->escribir($codisb, [...$config, ...ConfigAlmacen::NUMERACION_SIDES], $request->user()->email);
    }

    /** @return array la configuración guardada */
    public function quitarPasillo(Request $request, string $codisb): array
    {
        $datos = $request->validate(['clave' => ['required', 'string', 'regex:/^[A-Z]{2}-\d{2}$/']]);
        $config = ConfigAlmacen::de($codisb);
        unset($config['pasillos'][$datos['clave']]);
        $config['extras'] = array_values(array_filter($config['extras'], fn (string $anaquel) => ! str_starts_with($anaquel, "{$datos['clave']}-")));

        return $this->escribir($codisb, $config, $request->user()->email);
    }

    private static function clave(string $zona, int|string $pasillo): string
    {
        return strtoupper($zona).'-'.sprintf('%02d', (int) $pasillo);
    }

    private function escribir(string $codisb, array $config, string $email): array
    {
        $config = ConfigAlmacen::normalizar($config);
        SidesAlmacenCfg::query()->updateOrCreate(['codisb' => $codisb], ['config' => $config, 'actualizado_por' => $email]);

        return $config;
    }
}
