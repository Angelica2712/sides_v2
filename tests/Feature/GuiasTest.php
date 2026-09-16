<?php

namespace Tests\Feature;

use App\Models\Sides\SidesGuia;
use App\Models\Sides\SidesGuiaRen;
use App\Models\Sides\SidesRuta;
use App\Models\Sides\SidesRutaren;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class GuiasTest extends TestCase
{
    use TablasSides;

    private SidesUsers $usuario;

    private SidesRuta $ruta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearTablasRutas();
        $this->crearTablasGuias();
        Carbon::setTestNow('2026-09-16 10:00:00');

        $this->crearCfg(['nombre' => 'DROGUERIA PRUEBA']);
        $this->usuario = $this->crearUsuario(['activarConfig' => 1]);

        DB::table('choferes')->insert([
            ['chof_co' => 'CH1', 'chof_nom' => 'JUAN CHOFER', 'chof_ced' => 'V1', 'codisb' => '505094939'],
            ['chof_co' => 'CH2', 'chof_nom' => 'PEDRO AUXILIAR', 'chof_ced' => 'V2', 'codisb' => '505094939'],
            ['chof_co' => 'CH9', 'chof_nom' => 'DE OTRA SUCURSAL', 'chof_ced' => 'V9', 'codisb' => 'OTRA'],
        ]);

        $this->ruta = SidesRuta::query()->create(['nombre' => 'NORTE', 'codisb' => '505094939']);
        foreach ([['C1', 40, 0], ['C2', 20, 0], ['C3', 60, 1]] as [$codcli, $sec, $retira]) {
            SidesRutaren::query()->create([
                'id' => $this->ruta->id, 'codisb' => '505094939', 'codcli' => $codcli, 'nomcli' => "CLIENTE {$codcli}",
                'rif' => "J-{$codcli}", 'sec' => (string) $sec, 'zona' => 'NORTE', 'retiraLocal' => $retira,
            ]);
        }

        $this->pedidoConBultos(101, 'C1', 2);
        $this->pedidoConBultos(102, 'C2', 1);
        $this->pedidoConBultos(103, 'C3', 1);
        $this->pedidoConBultos(104, 'C1', 1, 'PACKING');
        $this->crearPedido(['id' => 105, 'codcli' => 'C2', 'estado' => 'FACTURADO']);
        $this->pedidoConBultos(106, 'C4', 1);
        $this->pedidoConBultos(107, 'C1', 1, 'FACTURADO', 'OTRA');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedidoConBultos(int $id, string $codcli, int $bultos, string $estado = 'FACTURADO', string $codisb = '505094939'): void
    {
        $this->crearPedido(['id' => $id, 'codcli' => $codcli, 'nomcli' => "CLIENTE {$codcli}", 'estado' => $estado, 'codisb' => $codisb, 'fecfacturado' => '2026-09-15 16:00:00']);
        foreach (range(1, $bultos) as $bulto) {
            DB::table('sides_etiqueta_pedido')->insert([
                'numepedi' => (string) $id, 'etiqueta' => $id.'-'.str_pad((string) $bulto, 2, '0', STR_PAD_LEFT),
                'nomcli' => "CLIENTE {$codcli}", 'ruta' => 'NORTE', 'estado' => 'NUEVO', 'codcli' => $codcli,
            ]);
        }
    }

    private function crearGuia(): SidesGuia
    {
        $this->actingAs($this->usuario)->post('/guias', ['fecha' => '2026-09-16T08:00', 'chofer' => 'CH1', 'ruta' => $this->ruta->id])->assertRedirect();

        return SidesGuia::query()->latest('id')->firstOrFail();
    }

    private function bulto(string $etiqueta): object
    {
        return DB::table('sides_etiqueta_pedido')->where('etiqueta', $etiqueta)->first();
    }

    /** @return array<string, array{int, int}> codcli => [cargado, terminado] */
    private function clientesDe(SidesGuia $guia): array
    {
        return SidesGuiaRen::query()->where('id', $guia->id)->orderBy('orden')->get()
            ->mapWithKeys(fn ($r) => [$r->codcli => [(int) $r->cargado, (int) $r->terminado]])->all();
    }

    public function test_crear_guia_toma_los_pedidos_facturados_de_la_ruta_en_su_orden(): void
    {
        $this->actingAs($this->usuario)->get('/guias/nueva')->assertOk()
            ->assertSee('JUAN CHOFER')->assertDontSee('DE OTRA SUCURSAL')
            ->assertSee('Hay 4 pedidos pendientes');

        $guia = $this->crearGuia();

        $this->assertSame(['NUEVO', 'CH1', 'JUAN CHOFER', 'NORTE', '2026-09-16 08:00'], [
            $guia->estado, $guia->chofer, $guia->nomchofer, $guia->ruta, Carbon::parse($guia->fecha)->format('Y-m-d H:i'),
        ]);
        // C2 (sec 20) antes que C1 (sec 40); C3 retira en local y C4 no está en la ruta.
        $this->assertSame(['C2' => [0, 0], 'C1' => [0, 0]], $this->clientesDe($guia));
        $this->assertSame(
            ['101-01' => $guia->id, '101-02' => $guia->id, '102-01' => $guia->id],
            DB::table('sides_etiqueta_pedido')->where('estado', 'EN GUIA')->orderBy('etiqueta')->pluck('guia', 'etiqueta')->map(fn ($g) => (int) $g)->all()
        );
        $this->assertNull($this->bulto('103-01')->guia);
        $this->assertNull($this->bulto('104-01')->guia);

        $this->get("/guias/{$guia->id}")->assertOk()
            ->assertSeeInOrder(['CLIENTE C2', 'Pedido #102', '102-01', 'CLIENTE C1', 'Pedido #101', '101-01', '101-02', 'Pedidos pendientes de la ruta', '#103']);

        // Ya no quedan pedidos para la ruta.
        $this->post('/guias', ['fecha' => '2026-09-16T09:00', 'chofer' => 'CH1', 'ruta' => $this->ruta->id])
            ->assertSessionHas('error', 'La ruta NORTE no tiene pedidos facturados con etiquetas pendientes de despacho.');
        $this->post('/guias', ['fecha' => '2026-09-16T09:00', 'chofer' => 'CH9', 'ruta' => $this->ruta->id])
            ->assertSessionHas('error', 'Elige un chofer de la lista.');
        $this->assertSame(1, SidesGuia::query()->count());
    }

    public function test_agregar_y_quitar_pedidos_y_clientes(): void
    {
        $guia = $this->crearGuia();

        $this->post("/guias/{$guia->id}/pedidos", ['pedidos' => [103, 106, 104, 107]])
            ->assertSessionHas('mensaje', '2 pedidos agregados a la guía.');
        // C3 toma su secuencia de la ruta; C4 no está en la ruta y va al final.
        $this->assertSame(['C2', 'C1', 'C3', 'C4'], array_keys($this->clientesDe($guia)));
        $this->assertSame(80, (int) SidesGuiaRen::query()->where('codcli', 'C4')->value('orden'));
        $this->assertNull($this->bulto('107-01')->guia);

        $this->delete("/guias/{$guia->id}/pedidos/106")->assertSessionHas('mensaje');
        $this->assertSame(['NUEVO', null], [$this->bulto('106-01')->estado, $this->bulto('106-01')->guia]);
        $this->assertArrayNotHasKey('C4', $this->clientesDe($guia));

        $this->delete("/guias/{$guia->id}/clientes", ['codcli' => 'C1'])->assertSessionHas('mensaje');
        $this->assertSame(['C2', 'C3'], array_keys($this->clientesDe($guia)));
        $this->assertSame('NUEVO', $this->bulto('101-02')->estado);

        $this->post("/guias/{$guia->id}/entregar", ['etiqueta' => '102-01']);
        $this->delete("/guias/{$guia->id}/pedidos/102")->assertSessionHas('error', 'El pedido #102 ya tiene bultos entregados: no se puede quitar de la guía.');
        $this->delete("/guias/{$guia->id}/clientes", ['codcli' => 'C2'])->assertSessionHas('error', 'El cliente ya tiene bultos entregados: no se puede quitar de la guía.');
    }

    public function test_entregas_y_estados_de_la_guia(): void
    {
        $guia = $this->crearGuia();

        $this->post("/guias/{$guia->id}/entregar", ['etiqueta' => '101-01'])->assertSessionHas('mensaje', 'Bulto marcado como entregado.');
        $bulto = $this->bulto('101-01');
        $this->assertSame(['ENTREGADO', '2026-09-16 10:00:00', '2026-09-16 10:00:00'], [$bulto->estado, $bulto->fecentregado, $bulto->feccargado]);
        $this->assertSame('TRANSITO', $guia->fresh()->estado);

        $this->post("/guias/{$guia->id}/entregar", ['etiqueta' => '101-01'])->assertSessionHas('error', 'El bulto 101-01 ya fue entregado.');
        $this->post("/guias/{$guia->id}/entregar", ['etiqueta' => '999-01'])->assertSessionHas('error', "El bulto 999-01 no está en la guía #{$guia->id}.");

        $this->post("/guias/{$guia->id}/entregar", ['codcli' => 'C1'])->assertSessionHas('mensaje', 'Bulto marcado como entregado.');
        $this->assertSame(['C2' => [0, 0], 'C1' => [1, 1]], $this->clientesDe($guia));

        $this->post("/guias/{$guia->id}/entregar", ['codcli' => 'C2']);
        $this->assertSame('ENTREGADO', $guia->fresh()->estado);

        $this->post("/guias/{$guia->id}/reiniciar", ['etiqueta' => '102-01'])->assertSessionHas('mensaje');
        $this->assertSame(['EN GUIA', null, null], [$this->bulto('102-01')->estado, $this->bulto('102-01')->feccargado, $this->bulto('102-01')->fecentregado]);
        $this->assertSame(['TRANSITO', [0, 0]], [$guia->fresh()->estado, $this->clientesDe($guia)['C2']]);
    }

    public function test_modificar_separar_y_eliminar(): void
    {
        $guia = $this->crearGuia();

        $this->put("/guias/{$guia->id}", ['fecha' => '2026-09-16T08:00', 'chofer' => 'CH1', 'auxiliar' => 'CH1'])
            ->assertSessionHas('error', 'El auxiliar no puede ser el mismo chofer.');
        $this->put("/guias/{$guia->id}", ['fecha' => '2026-09-16T08:00', 'chofer' => 'CH1', 'auxiliar' => 'CH2', 'fecha_salida' => '2026-09-16T11:30', 'unidad' => ' ABC-123 '])
            ->assertRedirect(route('guias.show', $guia->id));
        $guia->refresh();
        $this->assertSame(['CH2', 'PEDRO AUXILIAR', 'ABC-123', '2026-09-16 11:30:00'], [$guia->chofer_aux_id, $guia->chof_aux_nom, $guia->unidad, $guia->fecha_salida]);

        $this->post("/guias/{$guia->id}/separar", ['clientes' => ['C1', 'C2'], 'chofer' => 'CH2'])
            ->assertSessionHas('error', 'Deja al menos un cliente en esta guía. Para mover todos, cambia el chofer de la guía.');
        $this->post("/guias/{$guia->id}/separar", ['clientes' => ['C1'], 'chofer' => 'CH2'])->assertRedirect();
        $nueva = SidesGuia::query()->latest('id')->first();
        $this->assertSame(['CH2', 'NORTE', ['C1' => [0, 0]]], [$nueva->chofer, $nueva->ruta, $this->clientesDe($nueva)]);
        $this->assertSame(['C2' => [0, 0]], $this->clientesDe($guia));
        $this->assertSame($nueva->id, (int) $this->bulto('101-02')->guia);

        $this->post("/guias/{$nueva->id}/entregar", ['etiqueta' => '101-01']);
        $this->delete("/guias/{$nueva->id}")->assertSessionHas('error', 'No se puede eliminar una guía con bultos entregados.');

        $this->delete("/guias/{$guia->id}")->assertRedirect(route('guias.index'));
        $this->assertNull(SidesGuia::query()->find($guia->id));
        $this->assertSame(['NUEVO', null], [$this->bulto('102-01')->estado, $this->bulto('102-01')->guia]);
        $this->assertSame(0, SidesGuiaRen::query()->where('id', $guia->id)->count());
    }

    public function test_imprimir_y_excel_con_facturas_notas_y_devoluciones(): void
    {
        DB::table('cliente')->insert(['codcli' => 'C1', 'codisb' => '505094939', 'nombre' => 'FARMACIA UNO', 'rif' => 'J-111', 'entrega' => 'CALLE 1, LOCAL 2']);
        DB::table('fact')->insert([
            ['factnum' => 'F-500', 'codisb' => '505094939', 'codcli' => 'C1', 'descrip' => 'PEDIDO 101 SEPED', 'nroctrol' => '00-77'],
            ['factnum' => 'F-501', 'codisb' => '505094939', 'codcli' => 'C1', 'descrip' => 'PEDIDO 1010', 'nroctrol' => ''],
            ['factnum' => 'F-502', 'codisb' => 'OTRA', 'codcli' => 'C1', 'descrip' => 'PEDIDO 101', 'nroctrol' => ''],
        ]);
        DB::table('cxc')->insert(['id' => 'F-500', 'codisb' => '505094939', 'codcli' => 'C1']);
        $reclamo = DB::table('reclamo')->insertGetId(['codisb' => '505094939', 'codcli' => 'C1', 'factnum' => 'F-500']);
        DB::table('recren')->insert([['id' => $reclamo, 'motivo' => 'VENCIMIENTO '], ['id' => $reclamo, 'motivo' => 'PRECIO']]);
        $this->crearRenglon(101, 1, ['refrigerado' => 1]);
        $this->crearRenglon(101, 2);
        $guia = $this->crearGuia();

        $this->get("/guias/{$guia->id}/imprimir")->assertOk()
            ->assertSee('Guía de despacho N° '.$guia->id)
            ->assertSeeInOrder(['CLIENTE C2', 'J-111 - FARMACIA UNO', 'CALLE 1, LOCAL 2', 'F-500 / 00-77'])
            ->assertDontSee('F-501')->assertDontSee('F-502')
            ->assertSee('Piezas: 3')
            ->assertSee('<svg', false);

        $respuesta = $this->get("/guias/{$guia->id}/excel")->assertOk()->assertDownload("guia_{$guia->id}.xlsx");
        $reader = new Reader();
        $reader->open($respuesta->baseResponse->getFile()->getPathname());
        $filas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $filas[] = $fila->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['CODIGO', 'DIRECCION', 'NOMBRE', 'SEC', 'BULTOS', 'NEV', 'FACT', 'NOTAS', 'DEV'], $filas[3]);
        $this->assertSame(['C2', '', 'CLIENTE C2', 1, 1, 0, 0, 0, 0], $filas[4]);
        $this->assertSame(['C1', 'CALLE 1, LOCAL 2', 'FARMACIA UNO', 2, 2, 1, 1, 1, 1], $filas[5]);
        $this->assertContains('PIEZAS: 3', end($filas));
    }

    public function test_lista_con_filtros_y_solo_de_la_sucursal(): void
    {
        $guia = $this->crearGuia();
        $ajena = SidesGuia::query()->create(['codisb' => 'OTRA', 'ruta' => 'AJENA', 'estado' => 'NUEVO', 'chofer' => 'CH9', 'nomchofer' => 'X', 'fecha' => '2026-09-16 08:00:00']);

        $this->get('/guias')->assertOk()->assertSee("#{$guia->id}")->assertSee('JUAN CHOFER')->assertDontSee('AJENA')->assertSeeInOrder(['NORTE', 'JUAN CHOFER', '2', '3']);
        $this->get('/guias?estado=ENTREGADO')->assertDontSee("guias/{$guia->id}\"", false);
        $this->get('/guias?chofer=CH1&fecha=2026-09-16')->assertSee("#{$guia->id}");
        $this->get('/guias?fecha=2026-09-15')->assertSee('Ninguna guía coincide con los filtros');
        $this->get("/guias/{$ajena->id}")->assertNotFound();
    }

    public function test_requiere_permiso_y_modulo_guias(): void
    {
        $chofer = $this->crearUsuario(['email' => 'chofer@example.com', 'activarGuiaCarga' => 1]);
        $this->actingAs($chofer)->get('/guias')->assertForbidden();

        DB::table('sides_modulo_sucursal')->where('modulo', 'guias')->update(['activo' => 0]);
        $this->actingAs($this->usuario)->get('/guias')->assertForbidden();
    }
}
