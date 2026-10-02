<?php

namespace Tests\Feature;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class LogoDrogueriaTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    private SidesUsers $operario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg(['codisb' => 'SIDES', 'nombre' => 'SIDES'], []);
        $this->crearCfg(['activarImpTicket' => 1]);
        $this->admin = $this->crearUsuario(['name' => 'FULLTECH360', 'email' => 'soporte@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1]);
        $this->operario = $this->crearUsuario(['email' => 'op@example.com', 'activarMonitor' => 1, 'activarConfig' => 1]);
    }

    private function formulario(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'DROGUERIA ACTIVA,C.A',
            'nomcorto' => 'DROACTIVA',
            'rif' => 'J-12345678-9',
            'telefono' => '0212-5550000',
            'direccion' => 'Av. Principal, Caracas',
            'activarPacking' => '1',
            'activarImpTicket' => '1',
            'modulos' => ['monitor', 'picking', 'pedidos', 'configuracion', 'etiquetas'],
        ], $cambios);
    }

    private function logo(): ?string
    {
        return SidesCfg::query()->find('505094939')->logo;
    }

    public function test_el_administrador_sube_el_logo_y_los_datos_de_la_drogueria(): void
    {
        $this->actingAs($this->admin)->get('/admin/droguerias/505094939')->assertOk()
            ->assertSee('Sin logo: se usa el de SIDES')
            ->assertSee('enctype="multipart/form-data"', false);

        $this->put('/admin/droguerias/505094939', $this->formulario(['logo' => UploadedFile::fake()->image('logo.png', 300, 120)]))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('mensaje', 'Droguería DROGUERIA ACTIVA,C.A actualizada.');

        $cfg = SidesCfg::query()->find('505094939');
        $this->assertSame(['J-12345678-9', '0212-5550000', 'Av. Principal, Caracas'], [$cfg->rif, $cfg->telefono, $cfg->direccion]);
        $this->assertStringStartsWith('logos/505094939-', $cfg->logo);
        Storage::disk('public')->assertExists($cfg->logo);

        $this->get('/admin')->assertSee($cfg->urlLogo());
    }

    public function test_cambiar_el_logo_borra_el_anterior_y_quitarlo_vuelve_al_de_sides(): void
    {
        $this->actingAs($this->admin)->put('/admin/droguerias/505094939', $this->formulario(['logo' => UploadedFile::fake()->image('uno.png', 200, 80)]));
        $primero = $this->logo();

        $this->put('/admin/droguerias/505094939', $this->formulario(['logo' => UploadedFile::fake()->image('dos.jpg', 200, 80)]));
        $segundo = $this->logo();
        $this->assertNotSame($primero, $segundo);
        Storage::disk('public')->assertMissing($primero);
        Storage::disk('public')->assertExists($segundo);

        // Guardar sin archivo conserva el logo.
        $this->put('/admin/droguerias/505094939', $this->formulario());
        $this->assertSame($segundo, $this->logo());

        $this->put('/admin/droguerias/505094939', $this->formulario(['quitarLogo' => '1']));
        $this->assertNull($this->logo());
        Storage::disk('public')->assertMissing($segundo);
        $this->actingAs($this->operario)->get('/home')->assertSee('img/logo-sides.png');
    }

    public function test_rechaza_archivos_que_no_son_imagen(): void
    {
        $this->actingAs($this->admin)->from('/admin/droguerias/505094939')
            ->put('/admin/droguerias/505094939', $this->formulario(['logo' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf')]))
            ->assertSessionHasErrors(['logo']);

        $this->put('/admin/droguerias/505094939', $this->formulario(['logo' => UploadedFile::fake()->image('enorme.png', 300, 120)->size(3000)]))
            ->assertSessionHasErrors(['logo' => 'El logo no puede pesar más de 2 MB.']);

        $this->assertNull($this->logo());
    }

    public function test_el_logo_sale_en_el_encabezado_las_etiquetas_y_el_ticket(): void
    {
        $this->actingAs($this->admin)->put('/admin/droguerias/505094939', $this->formulario(['logo' => UploadedFile::fake()->image('logo.png', 300, 120)]));
        $url = SidesCfg::query()->find('505094939')->urlLogo();

        $this->crearPedido(['id' => 500, 'estado' => 'PEND-FACTURA', 'nomcli' => 'CLIENTE 500'], ['cantBultos' => '1', 'despachador' => 'Pedro', 'embalador' => 'Eva']);
        $this->actingAs($this->operario);

        $this->get('/home')->assertSee($url)->assertDontSee('img/logo-sides.png');
        $this->get('/etiquetas/500/imprimir')->assertOk()->assertSeeInOrder([$url, 'DROGUERIA ACTIVA,C.A', 'CÓDIGO CLIENTE']);
        $this->get('/etiquetas/500/ticket')->assertOk()->assertSeeInOrder([$url, 'DROGUERIA ACTIVA,C.A']);

        // El logo de una droguería no aparece en otra.
        $this->actingAs($this->admin)->get('/home')->assertDontSee($url);
    }

    public function test_la_drogueria_no_cambia_su_nombre_ni_su_logo_desde_configuracion(): void
    {
        $this->actingAs($this->operario)->put('/configuracion', [
            'nombre' => 'OTRO NOMBRE',
            'rif' => 'J-0',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 120),
            'TamLetraMonitor' => '24',
            'ordenPedSides' => 'UBICACION',
        ])->assertRedirect(route('configuracion.index'));

        $cfg = SidesCfg::query()->find('505094939');
        $this->assertSame(['DROGUERIA ACTIVA,C.A', null, null], [$cfg->nombre, $cfg->rif, $cfg->logo]);
        $this->assertEmpty(Storage::disk('public')->allFiles());

        $this->put('/admin/droguerias/505094939', $this->formulario())->assertForbidden();
    }
}
