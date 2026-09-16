<?php

namespace Tests\Feature;

use App\Models\Sides\SidesGuia;
use App\Models\Sides\SidesGuiaRen;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class DespachoTest extends TestCase
{
    use TablasSides;

    private SidesUsers $chofer;

    private SidesGuia $guia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearTablasRutas();
        $this->crearTablasGuias();
        Carbon::setTestNow('2026-09-16 10:00:00');

        $this->crearCfg();
        $this->chofer = $this->crearUsuario(['name' => 'Juan', 'email' => 'juan@example.com', 'activarGuiaCarga' => 1, 'activarGuiaDescarga' => 1]);
        // Vínculo del legacy: usuario de SEPED con el mismo correo y la cédula del chofer como código.
        DB::table('users')->insert(['email' => 'juan@example.com', 'codcli' => 'V1']);
        DB::table('choferes')->insert([
            ['chof_co' => 'CH1', 'chof_nom' => 'JUAN', 'chof_ced' => 'V1', 'codisb' => '505094939'],
            ['chof_co' => 'CH2', 'chof_nom' => 'PEDRO', 'chof_ced' => 'V2', 'codisb' => '505094939'],
        ]);

        $this->guia = $this->guia('CH1', ['C1' => ['101-01', '101-02'], 'C2' => ['102-01']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, list<string>> $clientes codcli => etiquetas, en orden de visita */
    private function guia(string $chofer, array $clientes, array $atributos = []): SidesGuia
    {
        $guia = SidesGuia::query()->create(array_merge([
            'codisb' => '505094939', 'ruta' => 'NORTE', 'estado' => 'NUEVO', 'chofer' => $chofer,
            'nomchofer' => $chofer, 'fecha' => '2026-09-16 08:00:00',
        ], $atributos));

        $orden = 0;
        foreach ($clientes as $codcli => $etiquetas) {
            SidesGuiaRen::query()->insert(['id' => $guia->id, 'codcli' => $codcli, 'nomcli' => "CLIENTE {$codcli}", 'orden' => $orden += 20]);
            foreach ($etiquetas as $etiqueta) {
                DB::table('sides_etiqueta_pedido')->insert([
                    'numepedi' => strstr($etiqueta, '-', true), 'etiqueta' => $etiqueta, 'nomcli' => "CLIENTE {$codcli}",
                    'ruta' => 'NORTE', 'estado' => 'EN GUIA', 'codcli' => $codcli, 'guia' => $guia->id,
                ]);
            }
        }

        return $guia;
    }

    private function bulto(string $etiqueta): object
    {
        return DB::table('sides_etiqueta_pedido')->where('etiqueta', $etiqueta)->first();
    }

    public function test_el_chofer_ve_solo_sus_guias_en_curso_y_el_almacen_ve_todas(): void
    {
        $comoAuxiliar = $this->guia('CH2', ['C3' => ['103-01']], ['chofer_aux_id' => 'CH1']);
        $ajena = $this->guia('CH2', ['C4' => ['104-01']]);
        $entregada = $this->guia('CH1', ['C5' => ['105-01']], ['estado' => 'ENTREGADO']);

        $this->actingAs($this->chofer)->get('/home')->assertSee('Carga y descarga')->assertDontSee('Etiquetas');
        $this->get('/despacho')->assertOk()
            ->assertSee("Guía #{$this->guia->id}")->assertSee("Guía #{$comoAuxiliar->id}")
            ->assertDontSee("Guía #{$ajena->id}")->assertDontSee("Guía #{$entregada->id}")
            ->assertSee('Tus guías en curso');
        $this->get("/despacho/{$ajena->id}/carga")->assertNotFound();

        $almacen = $this->crearUsuario(['email' => 'almacen@example.com', 'activarGuiaCarga' => 1]);
        $this->actingAs($almacen)->get('/despacho')->assertOk()
            ->assertSee("Guía #{$ajena->id}")->assertSee('Guías en curso de la sucursal')
            ->assertDontSee("Guía #{$entregada->id}");
    }

    public function test_carga_por_escaneo_en_orden_inverso_a_la_entrega(): void
    {
        $this->actingAs($this->chofer)->get("/despacho/{$this->guia->id}/carga")->assertOk()
            ->assertSeeInOrder(['CLIENTE C2', '102-01', 'CLIENTE C1', '101-01']);

        $this->post("/despacho/{$this->guia->id}/carga", ['etiqueta' => ' 101-01 '])
            ->assertRedirect(route('despacho.show', [$this->guia->id, 'carga']))
            ->assertSessionHas('mensaje', 'Bulto 101-01 cargado (CLIENTE C1).')
            ->assertSessionHas('resultado', 'exito');
        $this->assertSame(['CARGADO', '2026-09-16 10:00:00'], [$this->bulto('101-01')->estado, $this->bulto('101-01')->feccargado]);
        $this->assertSame('CARGANDO', $this->guia->fresh()->estado);

        $this->post("/despacho/{$this->guia->id}/carga", ['etiqueta' => '101-01'])
            ->assertSessionHas('error', 'El bulto 101-01 ya está cargado.')->assertSessionHas('resultado', 'error');
        $this->post("/despacho/{$this->guia->id}/carga", ['etiqueta' => '555-01'])
            ->assertSessionHas('error', "El bulto 555-01 no está en la guía #{$this->guia->id}.");

        // La entrega no empieza hasta cargar todo.
        $this->get("/despacho/{$this->guia->id}/descarga")->assertRedirect(route('despacho.index'))->assertSessionHas('error');
        $this->post("/despacho/{$this->guia->id}/descarga", ['etiqueta' => '101-01'])->assertSessionHas('error');
        $this->assertNull($this->bulto('101-01')->fecentregado);

        $this->post("/despacho/{$this->guia->id}/carga", ['etiqueta' => '101-02']);
        $this->post("/despacho/{$this->guia->id}/carga", ['etiqueta' => '102-01']);
        $this->assertSame('CARGADO', $this->guia->fresh()->estado);
        $this->get("/despacho/{$this->guia->id}/carga")->assertSee('Todos los bultos están cargados')->assertSee('Empezar la entrega');
    }

    public function test_descarga_por_escaneo_y_por_cliente(): void
    {
        DB::table('sides_etiqueta_pedido')->update(['estado' => 'CARGADO', 'feccargado' => '2026-09-16 09:00:00']);

        $this->actingAs($this->chofer)->get("/despacho/{$this->guia->id}/descarga")->assertOk()
            ->assertSeeInOrder(['CLIENTE C1', '101-01', 'CLIENTE C2', '102-01']);

        $this->post("/despacho/{$this->guia->id}/descarga", ['etiqueta' => '101-01'])->assertSessionHas('mensaje', 'Bulto entregado.');
        $this->assertSame(['ENTREGADO', '2026-09-16 10:00:00', '2026-09-16 09:00:00'], [
            $this->bulto('101-01')->estado, $this->bulto('101-01')->fecentregado, $this->bulto('101-01')->feccargado,
        ]);
        $this->assertSame('TRANSITO', $this->guia->fresh()->estado);

        $this->post("/despacho/{$this->guia->id}/descarga", ['codcli' => 'C1'])->assertSessionHas('mensaje', 'Bulto entregado.');
        $this->get("/despacho/{$this->guia->id}/descarga")->assertDontSee('101-02')->assertSee('1 cliente ya recibió todo');

        $this->post("/despacho/{$this->guia->id}/descarga", ['codcli' => 'C2'])
            ->assertRedirect(route('despacho.index'))
            ->assertSessionHas('mensaje', "Guía #{$this->guia->id} entregada completa.");
        $this->assertSame('ENTREGADO', $this->guia->fresh()->estado);
        $this->assertSame([[1, 1], [1, 1]], SidesGuiaRen::query()->orderBy('orden')->get()->map(fn ($r) => [(int) $r->cargado, (int) $r->terminado])->all());
    }

    public function test_cada_fase_exige_su_permiso(): void
    {
        $soloCarga = $this->crearUsuario(['email' => 'carga@example.com', 'activarGuiaCarga' => 1]);
        $this->actingAs($soloCarga)->get('/despacho')->assertOk()->assertSee('Cargar')->assertDontSee('>Entregar<', false);
        $this->get("/despacho/{$this->guia->id}/descarga")->assertForbidden();
        $this->post("/despacho/{$this->guia->id}/descarga", ['codcli' => 'C1'])->assertForbidden();

        $soloDescarga = $this->crearUsuario(['email' => 'descarga@example.com', 'activarGuiaDescarga' => 1]);
        $this->actingAs($soloDescarga)->post("/despacho/{$this->guia->id}/carga", ['etiqueta' => '101-01'])->assertForbidden();
        $this->assertSame('EN GUIA', $this->bulto('101-01')->estado);

        $sinPermiso = $this->crearUsuario(['email' => 'nadie@example.com', 'activarPicking' => 1]);
        $this->actingAs($sinPermiso)->get('/despacho')->assertForbidden();

        DB::table('sides_modulo_sucursal')->where('modulo', 'guias')->update(['activo' => 0]);
        $this->actingAs($this->chofer)->get('/despacho')->assertForbidden();
        $this->get('/home')->assertDontSee('Carga y descarga');
    }
}
