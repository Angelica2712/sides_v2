<?php

namespace Tests\Feature;

use App\Models\Sides\SidesUsers;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class EncargadoDrogueriaTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg(['codisb' => 'SIDES', 'nombre' => 'SIDES'], []);
        $this->crearCfg(['codisb' => 'ANDI', 'nombre' => 'ANDICAR'], []);
        $this->admin = $this->crearUsuario(['name' => 'FULLTECH360', 'email' => 'soporte@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1]);
    }

    public function test_una_drogueria_sin_usuarios_lo_avisa(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertSee('sin usuarios: crea su encargado');
        $this->get('/admin/droguerias/ANDI')->assertOk()->assertSee('Esta droguería todavía no tiene usuarios')->assertSee('Crear encargado');
    }

    public function test_crear_el_encargado_muestra_su_contrasena_una_vez_y_puede_entrar(): void
    {
        $respuesta = $this->actingAs($this->admin)->post('/admin/droguerias/ANDI/encargado', ['name' => 'Ana Encargada', 'email' => ' Ana@Andicar.com ']);
        $respuesta->assertRedirect(route('admin.edit', 'ANDI'))->assertSessionHas('mensaje', 'Usuario encargado Ana Encargada creado.');

        $credenciales = session('credenciales');
        $this->assertSame('ana@andicar.com', $credenciales['correo']);
        $this->assertSame(10, strlen($credenciales['clave']));

        $encargado = SidesUsers::query()->where('email', 'ana@andicar.com')->first();
        $this->assertSame(['ANDI', 'ACTIVO', 0], [$encargado->codisb, $encargado->estado, (int) $encargado->esAdmin]);
        $this->assertTrue(Hash::check($credenciales['clave'], $encargado->password));
        $this->assertSame([1, 1, 1, 1, 0, 0], array_map('intval', [
            $encargado->activarUsuario, $encargado->activarConfig, $encargado->activarPicking, $encargado->activarMonitor,
            $encargado->activarGuiaCarga, $encargado->activarGuiaDescarga,
        ]));

        $this->get('/admin/droguerias/ANDI')->assertSee($credenciales['clave'])->assertSee('Ana Encargada');
        // La contraseña se muestra una sola vez.
        $this->get('/admin/droguerias/ANDI')->assertDontSee($credenciales['clave']);

        $this->post('/admin/droguerias/ANDI/encargado', ['name' => 'Otra', 'email' => 'ana@andicar.com'])
            ->assertSessionHasErrors(['email' => 'Ya hay un usuario con ese correo.']);

        auth()->logout();
        $this->post('/login', ['email' => 'ana@andicar.com', 'password' => $credenciales['clave']])->assertRedirect();
        $this->assertAuthenticatedAs($encargado);
        $this->get('/usuarios')->assertOk();
    }

    public function test_generar_contrasena_nueva_solo_para_usuarios_de_esa_drogueria(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@andicar.com', 'codisb' => 'ANDI', 'activarPicking' => 1]);
        $anterior = $operario->password;

        $this->actingAs($this->admin)->post("/admin/droguerias/ANDI/usuarios/{$operario->id}/clave")
            ->assertRedirect(route('admin.edit', 'ANDI'));
        $clave = session('credenciales')['clave'];
        $this->assertNotSame($anterior, $operario->fresh()->password);
        $this->assertTrue(Hash::check($clave, $operario->fresh()->password));

        // No sirve para usuarios de otra droguería ni para el administrador.
        $this->post("/admin/droguerias/SIDES/usuarios/{$operario->id}/clave")->assertNotFound();
        $this->post("/admin/droguerias/SIDES/usuarios/{$this->admin->id}/clave")->assertNotFound();
    }

    public function test_la_contrasena_se_puede_escribir_a_mano(): void
    {
        $this->actingAs($this->admin)->post('/admin/droguerias/ANDI/encargado', ['name' => 'Ana', 'email' => 'ana@andicar.com', 'password' => 'Andicar2026'])
            ->assertSessionHas('credenciales', ['correo' => 'ana@andicar.com', 'clave' => 'Andicar2026']);
        $encargado = SidesUsers::query()->where('email', 'ana@andicar.com')->first();
        $this->assertTrue(Hash::check('Andicar2026', $encargado->password));

        $this->post("/admin/droguerias/ANDI/usuarios/{$encargado->id}/clave", ['password' => 'OtraClave1'])
            ->assertRedirect(route('admin.edit', 'ANDI'))
            ->assertSessionHas('credenciales', ['correo' => 'ana@andicar.com', 'clave' => 'OtraClave1']);
        $this->assertTrue(Hash::check('OtraClave1', $encargado->fresh()->password));

        // Muy corta: no se cambia y el error queda junto a ese usuario.
        $this->post("/admin/droguerias/ANDI/usuarios/{$encargado->id}/clave", ['password' => '123'])
            ->assertSessionHasErrorsIn("clave{$encargado->id}", ['password' => 'La contraseña debe tener al menos 6 caracteres.']);
        $this->assertTrue(Hash::check('OtraClave1', $encargado->fresh()->password));

        $this->post('/admin/droguerias/ANDI/encargado', ['name' => 'Luis', 'email' => 'luis@andicar.com', 'password' => 'abc'])
            ->assertSessionHasErrors(['password' => 'La contraseña debe tener al menos 6 caracteres.']);
        $this->assertNull(SidesUsers::query()->where('email', 'luis@andicar.com')->first());
    }

    public function test_solo_el_administrador(): void
    {
        $encargado = $this->crearUsuario(['email' => 'jefe@andicar.com', 'codisb' => 'ANDI', 'activarUsuario' => 1, 'activarConfig' => 1]);

        $this->actingAs($encargado)->post('/admin/droguerias/ANDI/encargado', ['name' => 'X', 'email' => 'x@andicar.com'])->assertForbidden();
        $this->post("/admin/droguerias/ANDI/usuarios/{$encargado->id}/clave")->assertForbidden();
        $this->assertNull(SidesUsers::query()->where('email', 'x@andicar.com')->first());
    }
}
