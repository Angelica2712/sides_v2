<?php

namespace Tests\Feature;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesRuta;
use App\Models\Sides\SidesRutaren;
use App\Models\Sides\SidesUsers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class RutasTest extends TestCase
{
    use TablasSides;

    private SidesUsers $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearTablasRutas();

        $this->crearCfg();
        $this->usuario = $this->crearUsuario(['activarConfig' => 1]);
    }

    private function ruta(string $nombre, array $clientes = [], string $codisb = '505094939'): SidesRuta
    {
        $ruta = SidesRuta::query()->create(['nombre' => $nombre, 'codisb' => $codisb, 'fecha' => '2026-09-01 08:00:00']);
        foreach ($clientes as $sec => $codcli) {
            SidesRutaren::query()->create([
                'id' => $ruta->id, 'codisb' => $codisb, 'codcli' => $codcli, 'nomcli' => "CLIENTE {$codcli}",
                'rif' => "J-{$codcli}", 'sec' => (string) $sec, 'zona' => 'CENTRO',
            ]);
        }

        return $ruta;
    }

    /** @return list<string> codcli en orden de secuencia */
    private function ordenDe(SidesRuta $ruta): array
    {
        return SidesRutaren::query()->where('id', $ruta->id)->orderByRaw('sec + 0')->pluck('codcli')->all();
    }

    private function excel(array $filas): UploadedFile
    {
        $archivo = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($archivo);
        foreach ($filas as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }
        $writer->close();

        return new UploadedFile($archivo, 'ruta.xlsx', null, null, true);
    }

    public function test_lista_las_rutas_de_la_sucursal_con_sus_clientes(): void
    {
        $this->ruta('RUTA NORTE', [20 => 'C1', 40 => 'C2']);
        $this->ruta('RUTA SUR');
        $this->ruta('RUTA AJENA', [], 'OTRA');

        $this->actingAs($this->usuario)->get('/rutas')->assertOk()
            ->assertSeeInOrder(['RUTA NORTE', '2', 'RUTA SUR', '0'])
            ->assertDontSee('RUTA AJENA')
            ->assertSee('Copiar una ruta de SEPED');

        $this->get('/rutas?buscar=sur')->assertSee('RUTA SUR')->assertDontSee('RUTA NORTE');
        $this->get('/rutas/'.SidesRuta::query()->where('codisb', 'OTRA')->value('id'))->assertNotFound();
    }

    public function test_crear_ruta_y_agregar_clientes_libres_al_final(): void
    {
        $this->crearCliente('C1', 'BOTICA ALFA', 'zona a');
        $this->crearCliente('C1', 'BOTICA ALFA', 'zona a', ['codac3' => 'OTRO']);
        $this->crearCliente('C2', 'BOTICA BETA', 'ZONA B');
        $this->crearCliente('C3', 'BOTICA GAMA', 'ZONA B');
        $this->crearCliente('C9', 'DE OTRA SUCURSAL', 'X', ['codisb' => 'OTRA']);
        $this->ruta('RUTA VIEJA', [20 => 'C3']);

        $this->actingAs($this->usuario)->post('/rutas', ['nombre' => ' ruta centro '])
            ->assertRedirect()->assertSessionHas('mensaje', 'Ruta RUTA CENTRO creada. Ahora agrega sus clientes.');
        $ruta = SidesRuta::query()->where('nombre', 'RUTA CENTRO')->firstOrFail();

        // Solo clientes de la sucursal sin ruta, una vez cada uno.
        $this->get("/rutas/{$ruta->id}/clientes/agregar")->assertOk()
            ->assertSeeInOrder(['BOTICA ALFA', 'BOTICA BETA'])
            ->assertDontSee('BOTICA GAMA')
            ->assertDontSee('DE OTRA SUCURSAL');
        $this->assertSame(1, substr_count($this->get("/rutas/{$ruta->id}/clientes/agregar")->getContent(), 'value="C1"'));

        $this->post("/rutas/{$ruta->id}/clientes", ['clientes' => ['C2', 'C1', 'C3', 'NO-EXISTE']])
            ->assertRedirect(route('rutas.show', $ruta->id))
            ->assertSessionHas('mensaje', 'Listo: 2 clientes agregados. Omitidos: 2 (ya tenían ruta, estaban repetidos o no existen en SEPED).');

        $this->assertSame(['C1', 'C2'], $this->ordenDe($ruta));
        $this->assertSame(['20', '40'], SidesRutaren::query()->where('id', $ruta->id)->orderByRaw('sec + 0')->pluck('sec')->all());
        $this->assertSame('ZONA A', SidesRutaren::query()->where('codcli', 'C1')->value('zona'));

        $this->post('/rutas', ['nombre' => 'Ruta Centro'])->assertSessionHas('error', 'Ya existe una ruta llamada RUTA CENTRO.');
    }

    public function test_copiar_ruta_de_seped_respeta_su_orden_y_omite_clientes_con_ruta(): void
    {
        $this->crearCliente('C1', 'ALFA', 'RUTA 1', ['orden' => 5]);
        $this->crearCliente('C2', 'BETA', 'ruta 1 ');
        $this->crearCliente('C3', 'GAMA', 'RUTA 1', ['orden' => 2]);
        $this->crearCliente('C4', 'DELTA', 'RUTA 2');
        $this->ruta('OTRA', [20 => 'C3']);

        $this->actingAs($this->usuario)->get('/rutas')->assertSee('RUTA 1 (3 clientes)')->assertSee('RUTA 2 (1 cliente)');

        $this->post('/rutas/seped', ['ruta_seped' => 'RUTA 1', 'nombre' => ''])
            ->assertSessionHas('mensaje', 'Ruta RUTA 1 creada desde SEPED: 2 clientes agregados. Omitidos: 1 (ya tenían ruta, estaban repetidos o no existen en SEPED).');

        $ruta = SidesRuta::query()->where('nombre', 'RUTA 1')->firstOrFail();
        // C2 no tiene orden en SEPED: va después del último (5 + 20).
        $this->assertSame(['C1' => '5', 'C2' => '25'], SidesRutaren::query()->where('id', $ruta->id)->orderByRaw('sec + 0')->pluck('sec', 'codcli')->all());

        $this->post('/rutas/seped', ['ruta_seped' => 'NO EXISTE'])->assertSessionHas('error', 'SEPED no tiene clientes en la ruta NO EXISTE.');
        // Todos sus clientes ya tienen ruta: no queda una ruta vacía.
        $this->post('/rutas/seped', ['ruta_seped' => 'RUTA 1', 'nombre' => 'COPIA'])
            ->assertSessionHas('error', 'No se creó la ruta: todos sus clientes ya están en otras rutas.');
        $this->assertSame(2, SidesRuta::query()->count());
    }

    public function test_importar_excel_y_descargar_con_el_mismo_formato(): void
    {
        $this->crearCliente('C2', 'NOMBRE DESDE SEPED', 'ESTE');
        $this->ruta('OCUPADA', [20 => 'C3']);

        $this->actingAs($this->usuario)->post('/rutas/excel', [
            'nombre' => 'ruta excel',
            'archivo' => $this->excel([
                ['CODIGO', 'CLIENTE', 'RIF', 'ZONA', 'ORDEN'],
                ['C1', 'FARMACIA "UNO"', 'J-1', 'oeste', 2],
                ['C2', '', '', '', 1],
                ['', 'SIN CODIGO', '', '', 3],
                ['C3', 'YA EN OTRA RUTA', '', '', 4],
                ['C1', 'REPETIDO', '', '', 5],
            ]),
        ])->assertRedirect()
            ->assertSessionHas('mensaje', 'Ruta RUTA EXCEL creada desde Excel: 2 clientes agregados. Omitidos: 2 (ya tenían ruta, estaban repetidos o no existen en SEPED).');

        $ruta = SidesRuta::query()->where('nombre', 'RUTA EXCEL')->firstOrFail();
        $filas = SidesRutaren::query()->where('id', $ruta->id)->orderByRaw('sec + 0')->get(['codcli', 'nomcli', 'rif', 'zona', 'sec'])->toArray();
        $this->assertSame([
            ['codcli' => 'C2', 'nomcli' => 'NOMBRE DESDE SEPED', 'rif' => 'J-C2', 'zona' => 'ESTE', 'sec' => '20'],
            ['codcli' => 'C1', 'nomcli' => 'FARMACIA UNO', 'rif' => 'J-1', 'zona' => 'OESTE', 'sec' => '40'],
        ], $filas);

        $respuesta = $this->get("/rutas/{$ruta->id}/excel")->assertOk()->assertDownload('ruta_ruta_excel.xlsx');
        $reader = new Reader();
        $reader->open($respuesta->baseResponse->getFile()->getPathname());
        $leidas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $leidas[] = $fila->toArray();
            }
        }
        $reader->close();
        $this->assertSame([
            ['CODIGO', 'CLIENTE', 'RIF', 'ZONA', 'ORDEN'],
            ['C2', 'NOMBRE DESDE SEPED', 'J-C2', 'ESTE', 1],
            ['C1', 'FARMACIA UNO', 'J-1', 'OESTE', 2],
        ], $leidas);
    }

    public function test_importar_valida_el_archivo(): void
    {
        $this->actingAs($this->usuario)->from('/rutas')->post('/rutas/excel', [
            'nombre' => 'MALA',
            'archivo' => UploadedFile::fake()->create('ruta.xls', 10),
        ])->assertSessionHasErrors('archivo');

        $this->post('/rutas/excel', ['nombre' => 'VACIA', 'archivo' => $this->excel([['CODIGO']])])
            ->assertSessionHas('error');

        $this->post('/rutas/excel', ['nombre' => 'DAÑADA', 'archivo' => UploadedFile::fake()->createWithContent('ruta.xlsx', 'no es excel')])
            ->assertSessionHas('error', 'No se pudo leer el archivo. Guárdalo como Excel (.xlsx) e inténtalo de nuevo.');

        $this->assertSame(0, SidesRuta::query()->count());
    }

    public function test_modificar_secuencia_zona_y_retiro_de_un_cliente(): void
    {
        $ruta = $this->ruta('RUTA', [20 => 'C1', 40 => 'C2', 60 => 'C3']);
        $c3 = SidesRutaren::query()->where('codcli', 'C3')->first();

        $this->actingAs($this->usuario)->get("/rutas/{$ruta->id}")->assertOk()->assertSeeInOrder(['C1', 'C2', 'C3']);

        $this->from("/rutas/{$ruta->id}")->put("/rutas/{$ruta->id}/clientes/{$c3->item}", ['zona' => 'x', 'sec' => '40'])
            ->assertSessionHas('error', 'La secuencia 40 ya la tiene CLIENTE C2 (C2).');

        $this->put("/rutas/{$ruta->id}/clientes/{$c3->item}", ['zona' => ' playa ', 'sec' => '30', 'retiraLocal' => '1'])
            ->assertSessionHas('mensaje', 'Cliente actualizado.');
        $this->assertSame(['C1', 'C3', 'C2'], $this->ordenDe($ruta));
        $this->assertSame(['PLAYA', 1], [$c3->fresh()->zona, (int) $c3->fresh()->retiraLocal]);

        $this->put("/rutas/{$ruta->id}/clientes/{$c3->item}", ['sec' => '0'])->assertSessionHasErrors('sec');

        $otra = $this->ruta('OTRA', [80 => 'C8']);
        $c8 = SidesRutaren::query()->where('codcli', 'C8')->first();
        $this->put("/rutas/{$ruta->id}/clientes/{$c8->item}", ['sec' => '90'])->assertSessionHas('error', 'Ese cliente ya no está en la ruta.');
        $this->assertSame('80', $c8->fresh()->sec);
        $this->assertNotNull($otra);
    }

    public function test_renombrar_ruta_actualiza_las_guias_de_la_sucursal(): void
    {
        $ruta = $this->ruta('VIEJA', [20 => 'C1', 40 => 'C2']);
        $this->ruta('OCUPADA');
        DB::table('sides_guia')->insert([
            ['codisb' => '505094939', 'ruta' => 'VIEJA'],
            ['codisb' => 'OTRA', 'ruta' => 'VIEJA'],
        ]);

        $this->actingAs($this->usuario)->put("/rutas/{$ruta->id}", ['nombre' => 'ocupada'])->assertSessionHas('error', 'Ya existe una ruta llamada OCUPADA.');

        $this->put("/rutas/{$ruta->id}", ['nombre' => 'nueva'])->assertSessionHas('mensaje');
        $this->assertSame('NUEVA', $ruta->fresh()->nombre);
        $this->assertSame(['505094939' => 'NUEVA', 'OTRA' => 'VIEJA'], DB::table('sides_guia')->pluck('ruta', 'codisb')->all());

        $this->put("/rutas/{$ruta->id}/zona", ['zona' => 'oriente'])->assertSessionHas('mensaje', 'Zona cambiada en 2 clientes.');
        $this->assertSame(['ORIENTE'], SidesRutaren::query()->distinct()->pluck('zona')->all());
    }

    public function test_quitar_cliente_y_eliminar_ruta(): void
    {
        $ruta = $this->ruta('RUTA', [20 => 'C1', 40 => 'C2']);
        $c1 = SidesRutaren::query()->where('codcli', 'C1')->first();

        $this->actingAs($this->usuario)->delete("/rutas/{$ruta->id}/clientes/{$c1->item}")
            ->assertSessionHas('mensaje', 'CLIENTE C1 salió de la ruta.');
        $this->assertSame(['C2'], $this->ordenDe($ruta));

        $this->delete("/rutas/{$ruta->id}")->assertRedirect(route('rutas.index'))->assertSessionHas('mensaje', 'Ruta RUTA eliminada.');
        $this->assertSame([0, 0], [SidesRuta::query()->count(), SidesRutaren::query()->count()]);
    }

    public function test_sincronizacion_con_seped(): void
    {
        SidesCfg::query()->where('codisb', '505094939')->update(['activarSincronizacionRutas' => 1]);
        $this->crearCliente('C1', 'ALFA NUEVO', 'NORTE', ['orden' => 7]);
        $this->crearCliente('C2', 'BETA', 'norte');
        $this->crearCliente('C3', 'GAMA', 'SUR');
        $this->crearCliente('C4', 'SIN RUTA');
        $manual = $this->ruta('MANUAL', [20 => 'C1']);
        $this->crearCfg(['codisb' => 'APAGADA'], ['rutas']);
        $this->crearCliente('X1', 'NO SE SINCRONIZA', 'NORTE', ['codisb' => 'APAGADA']);

        $this->actingAs($this->usuario)->get('/rutas')->assertSee('Sincronizar ahora')->assertDontSee('Copiar una ruta de SEPED');
        $this->post('/rutas', ['nombre' => 'A MANO'])->assertSessionHas('error');

        $this->artisan('sides:sincronizar-rutas')->assertSuccessful();

        $this->assertSame(['MANUAL', 'NORTE', 'SUR'], SidesRuta::query()->where('codisb', '505094939')->orderBy('nombre')->pluck('nombre')->all());
        $this->assertSame(0, SidesRuta::query()->where('codisb', 'APAGADA')->count());
        // C1 ya tenía ruta: se queda en MANUAL, con el nombre y el orden de SEPED.
        $c1 = SidesRutaren::query()->where('codcli', 'C1')->first();
        $this->assertSame([$manual->id, 'ALFA NUEVO', '7'], [$c1->id, $c1->nomcli, $c1->sec]);
        $norte = SidesRuta::query()->where('nombre', 'NORTE')->first();
        $this->assertSame(['C2'], $this->ordenDe($norte));
        $this->assertNull(SidesRutaren::query()->where('codcli', 'C4')->first());

        // Una segunda pasada no duplica nada.
        $this->post('/rutas/sincronizar')->assertSessionHas('mensaje', 'Rutas sincronizadas con SEPED: 0 rutas nuevas, 0 clientes agregados y 0 actualizados.');
        $this->assertSame(3, SidesRutaren::query()->count());
    }

    public function test_sincronizar_a_mano_requiere_la_opcion_activa(): void
    {
        $this->actingAs($this->usuario)->from('/rutas')->post('/rutas/sincronizar')
            ->assertSessionHas('error', 'La sincronización automática de rutas está apagada en Configuración.');
    }

    public function test_configuracion_activa_la_sincronizacion_solo_con_el_modulo_rutas(): void
    {
        $formulario = ['nombre' => 'DROGUERIA', 'TamLetraMonitor' => '12', 'ordenPedSides' => 'ORIGINAL', 'activarSincronizacionRutas' => '1'];
        $this->actingAs($this->usuario)->get('/configuracion')->assertSee('Sincronizar las rutas con SEPED');
        $this->put('/configuracion', $formulario)->assertSessionHasNoErrors();
        $this->assertSame(1, (int) SidesCfg::query()->find('505094939')->activarSincronizacionRutas);

        DB::table('sides_modulo_sucursal')->where('modulo', 'rutas')->update(['activo' => 0]);
        $this->get('/configuracion')->assertDontSee('Sincronizar las rutas con SEPED');
        $this->put('/configuracion', array_merge($formulario, ['activarSincronizacionRutas' => null]))->assertSessionHasNoErrors();
        $this->assertSame(1, (int) SidesCfg::query()->find('505094939')->activarSincronizacionRutas);
    }

    public function test_requiere_permiso_y_modulo_activo(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@example.com', 'activarPicking' => 1]);
        $this->actingAs($operario)->get('/rutas')->assertForbidden();

        DB::table('sides_modulo_sucursal')->where('modulo', 'rutas')->update(['activo' => 0]);
        $this->actingAs($this->usuario)->get('/rutas')->assertForbidden();
    }
}
