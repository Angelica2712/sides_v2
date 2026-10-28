<?php

namespace Tests\Unit;

use App\Services\Almacen\PlanoAlmacen;
use App\Support\ConfigAlmacen;
use App\Support\UbicacionAlmacen;
use PHPUnit\Framework\TestCase;

class UbicacionAlmacenTest extends TestCase
{
    public function test_interpreta_la_ubicacion_completa(): void
    {
        $this->assertSame([
            'codigo' => 'PK-03-12-B-04',
            'zona' => 'PK',
            'pasillo' => 3,
            'anaquel' => 12,
            'estante' => 'B',
            'posicion' => 4,
        ], UbicacionAlmacen::interpretar(' pk-03-12-b-04 '));
    }

    public function test_acepta_ubicaciones_cortadas_por_la_derecha(): void
    {
        $zona = UbicacionAlmacen::interpretar('FR');
        $this->assertSame('FR', $zona['zona']);
        $this->assertNull($zona['pasillo']);
        $this->assertSame(2, UbicacionAlmacen::interpretar('MY-02')['pasillo']);
        $this->assertNull(UbicacionAlmacen::interpretar('PK-03-12')['estante']);
    }

    public function test_rechaza_lo_que_no_sigue_el_formato(): void
    {
        foreach (['0707E05', 'P5L102', 'A01PICKPAE24D', 'PK-3-12', 'PK-03-12-B-4', 'PK-03-12-BB', 'Area Frio', ''] as $texto) {
            $this->assertNull(UbicacionAlmacen::interpretar($texto), $texto);
        }
    }

    public function test_varias_ubicaciones_separadas_con_punto_y_coma(): void
    {
        $codigos = array_column(UbicacionAlmacen::todas('PK-03-12-B-04;viejo;MY-01-05-A-01'), 'codigo');

        $this->assertSame(['PK-03-12-B-04', 'MY-01-05-A-01'], $codigos);
    }

    public function test_el_plano_arma_zonas_pasillos_y_anaqueles(): void
    {
        $plano = (new PlanoAlmacen)->armar([
            'PK-01-01-A-02',
            'PK-01-01-F-05',
            'PK-01-02',
            'PK-02-03-B-01;MY-01-01-A-01',
            'FR',
            '0707E05',
        ]);

        $this->assertSame(6, $plano['ubicaciones']);
        $this->assertSame(5, $plano['reconocidas']);
        $this->assertSame(['FR', 'MY', 'PK'], array_column($plano['zonas'], 'zona'));
        $this->assertSame([], $plano['zonas'][0]['pasillos']);
        $this->assertSame(ConfigAlmacen::POR_DEFECTO, $plano['config']);

        [$pasillo1, $pasillo2] = $plano['zonas'][2]['pasillos'];
        $this->assertSame([
            // Una ubicación más alta que el anaquel de 4 x 4 lo agranda.
            ['anaquel' => 1, 'estantes' => 6, 'posiciones' => 5],
            ['anaquel' => 2, 'estantes' => 4, 'posiciones' => 4],
        ], $pasillo1['anaqueles']);
        $this->assertSame(2, $pasillo2['pasillo']);
        $this->assertSame([['anaquel' => 3, 'estantes' => 4, 'posiciones' => 4]], $pasillo2['anaqueles']);
    }

    public function test_el_plano_usa_el_tamano_de_anaquel_configurado(): void
    {
        $config = ConfigAlmacen::normalizar(['estantes' => 6, 'posiciones' => 8]);

        $plano = (new PlanoAlmacen)->armar(['PK-01-01-B-02'], $config);

        $this->assertSame([['anaquel' => 1, 'estantes' => 6, 'posiciones' => 8]], $plano['zonas'][0]['pasillos'][0]['anaqueles']);
    }

    public function test_la_configuracion_descarta_valores_invalidos(): void
    {
        $config = ConfigAlmacen::normalizar([
            'numeracion' => 'zigzag',
            'estanteA' => 'arriba',
            'posicion1' => 'derecha',
            'estantes' => 99,
            'posiciones' => 0,
            'zonas' => ['pk' => 'Despacho rápido', 'MY' => 'Mayor', 'XYZ' => 'mal', 'FR' => ''],
        ]);

        $this->assertSame([
            'numeracion' => 'alternada',
            'estanteA' => 'arriba',
            'posicion1' => 'derecha',
            'estantes' => 12,
            'posiciones' => 1,
            // Solo se guardan los nombres que cambian el de SIDES.
            'zonas' => ['PK' => 'Despacho rápido'],
            'pasillos' => [],
            'quitados' => [],
            'extras' => [],
            'disposicion' => [],
            'entradas' => [],
        ], $config);
    }

    public function test_los_pasillos_guardan_solo_los_validos(): void
    {
        $config = ConfigAlmacen::normalizar(['pasillos' => [
            'PK-03' => ['izquierda' => 6, 'derecha' => 0, 'estantes' => 99, 'posiciones' => '5'],
            // Formato anterior: N anaqueles de 1 a N.
            'MY-01' => '5',
            'PK-00' => 3,
            'pk-02' => 5,
            'FR-01' => 0,
            'FR-02' => ['izquierda' => 50, 'derecha' => 1],
            'FR-03' => ['izquierda' => 0, 'derecha' => 0],
        ]]);

        $this->assertSame([
            'MY-01' => ['izquierda' => 3, 'derecha' => 2, 'estantes' => null, 'posiciones' => null],
            'PK-03' => ['izquierda' => 6, 'derecha' => 0, 'estantes' => 12, 'posiciones' => 5],
        ], $config['pasillos']);
        $this->assertSame([1, 3, 5, 2, 4], ConfigAlmacen::anaquelesDe($config['pasillos']['MY-01']));
        $this->assertSame([1, 3, 5, 7, 9, 11], ConfigAlmacen::anaquelesDe($config['pasillos']['PK-03']));
    }

    public function test_la_disposicion_guarda_solo_anaqueles_validos(): void
    {
        $config = ConfigAlmacen::normalizar(['disposicion' => [
            'PK-01-03' => ['x' => 2.456, 'z' => -1, 'giro' => 450],
            'PK-01-04' => ['x' => '5', 'z' => 3, 'giro' => -90],
            'PK-01-05' => ['x' => 99999, 'z' => 0],
            'pk-1-3' => ['x' => 1, 'z' => 1],
            'PK-01-06' => ['x' => 'mucho', 'z' => 1],
            'PK-01-07' => 'nada',
        ]]);

        $this->assertSame([
            'PK-01-03' => ['x' => 2.46, 'z' => -1.0, 'giro' => 90],
            'PK-01-04' => ['x' => 5.0, 'z' => 3.0, 'giro' => 270],
            'PK-01-05' => ['x' => 1000.0, 'z' => 0.0, 'giro' => 0],
        ], $config['disposicion']);
    }
}
