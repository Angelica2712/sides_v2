<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** El login sale con el logo de la droguería por su enlace de entrada o porque el equipo la recuerda. */
class LoginDrogueriaTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();

        $this->crearCfg(['codisb' => 'SIDES', 'nombre' => 'SIDES'], []);
        $this->crearCfg(['codisb' => 'ANDI', 'nombre' => 'DROGUERIA ANDICAR', 'nomcorto' => 'ANDICAR'], []);
        SidesCfg::query()->whereKey('ANDI')->update(['logo' => 'logos/andi-abc12345.png']);
    }

    public function test_el_enlace_de_la_drogueria_muestra_su_logo_y_el_de_sides_pequeno(): void
    {
        $this->get('/login/ANDI')->assertOk()
            ->assertSee('storage/logos/andi-abc12345.png', false)
            ->assertSee('Entra con tu usuario de ANDICAR.')
            ->assertSee('img/logo-sides.png', false)
            ->assertSee('by FULLTECH360');
    }

    public function test_sin_saber_la_drogueria_sale_el_login_de_sides(): void
    {
        $this->get('/login')->assertOk()
            ->assertDontSee('andi-abc12345.png')
            ->assertSee('Entra con tu usuario de SIDES.');

        // Un código que no existe no rompe: sale el login genérico.
        $this->get('/login/NOEXISTE')->assertOk()->assertSee('Entra con tu usuario de SIDES.');
    }

    public function test_despues_de_entrar_el_equipo_recuerda_la_drogueria(): void
    {
        $usuario = $this->crearUsuario(['email' => 'ana@andicar.com', 'codisb' => 'ANDI']);

        $this->post('/login', ['email' => $usuario->email, 'password' => 'secreto123'])
            ->assertCookie(AuthenticatedSessionController::COOKIE_DROGUERIA, 'ANDI');

        $this->post('/logout');
        $this->withCookie(AuthenticatedSessionController::COOKIE_DROGUERIA, 'ANDI')
            ->get('/login')->assertSee('storage/logos/andi-abc12345.png', false);
    }

    public function test_fulltech360_no_cambia_la_drogueria_que_recuerda_el_equipo(): void
    {
        $admin = $this->crearUsuario(['email' => 'soporte@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'secreto123'])
            ->assertCookieMissing(AuthenticatedSessionController::COOKIE_DROGUERIA);
    }

    public function test_administracion_muestra_el_enlace_para_entrar(): void
    {
        $admin = $this->crearUsuario(['email' => 'soporte@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1]);

        $this->actingAs($admin)->get('/admin/droguerias/ANDI')->assertOk()
            ->assertSee('Enlace para entrar')
            ->assertSee(route('login.drogueria', 'ANDI'));
    }
}
