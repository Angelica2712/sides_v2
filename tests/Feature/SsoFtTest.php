<?php

namespace Tests\Feature;

use App\Models\Sides\SidesLogpicking;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** Paso del FT entre SEPED y SIDES con pases de un solo uso (sso_pases). */
class SsoFtTest extends TestCase
{
    use TablasSides;

    private const ERROR = 'El enlace para entrar desde SEPED venció o ya se usó. Vuelve a tocar el botón o entra con tu correo.';

    private SidesUsers $ft;

    private SidesUsers $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearCfg();
        Carbon::setTestNow('2026-10-02 10:00:00');
        config(['services.seped.url' => 'https://seped.andicar.test/']);

        // Como lo copia SEPED: seped_user_id, esAdmin y todos los permisos.
        $this->ft = $this->crearUsuario(['name' => 'FULLTECH360', 'email' => 'soporte@fulltech360.com', 'esAdmin' => 1, ...$this->todosLosPermisos()]);
        DB::table('sides_users')->where('id', $this->ft->id)->update(['seped_user_id' => 77]);
        $this->ft->refresh();

        $this->encargado = $this->crearUsuario(['name' => 'Ana Encargada', 'email' => 'ana@example.com', ...$this->todosLosPermisos()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pase(string $token, array $cambios = []): void
    {
        DB::table('sso_pases')->insert(array_merge([
            'token_hash' => hash('sha256', $token),
            'seped_user_id' => 77,
            'origen' => 'SEPED',
            'destino' => 'SIDES',
            'expira_at' => Carbon::now()->addSeconds(60),
            'created_at' => Carbon::now(),
        ], $cambios));
    }

    public function test_un_pase_valido_inicia_la_sesion_del_ft_una_sola_vez(): void
    {
        $this->pase('token-bueno');

        $this->get('/sso?token=token-bueno')->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($this->ft);
        $this->assertNotNull(DB::table('sso_pases')->value('usado_at'));
        $this->assertDatabaseHas('sides_auditoria', ['accion' => 'sso.desde_seped', 'usuario' => 'soporte@fulltech360.com']);
        $this->assertDatabaseMissing('sides_auditoria', ['datos' => 'token-bueno']);

        $this->post('/logout');
        $this->get('/sso?token=token-bueno')->assertRedirect(route('login'))->assertSessionHasErrors(['email' => self::ERROR]);
        $this->assertGuest();
    }

    public function test_un_pase_con_sesion_abierta_de_otro_usuario_la_reemplaza(): void
    {
        $this->pase('token-bueno');

        $this->actingAs($this->encargado)->get('/sso?token=token-bueno')->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($this->ft);
    }

    public function test_pases_que_no_sirven_vuelven_al_login_con_el_mismo_mensaje(): void
    {
        $this->pase('vencido', ['expira_at' => Carbon::now()->subSecond()]);
        $this->pase('para-seped', ['destino' => 'SEPED']);
        $this->pase('de-otro', ['seped_user_id' => 999]);

        foreach (['', 'no-existe', 'vencido', 'para-seped', 'de-otro'] as $token) {
            $this->get("/sso?token={$token}")->assertRedirect(route('login'))->assertSessionHasErrors(['email' => self::ERROR]);
            $this->assertGuest();
        }
    }

    public function test_un_ft_inactivo_o_sin_esadmin_no_entra(): void
    {
        DB::table('sides_users')->where('id', $this->ft->id)->update(['estado' => 'INACTIVO']);
        $this->pase('uno');
        $this->get('/sso?token=uno')->assertSessionHasErrors(['email' => self::ERROR]);

        DB::table('sides_users')->where('id', $this->ft->id)->update(['estado' => 'ACTIVO', 'esAdmin' => 0]);
        $this->pase('dos');
        $this->get('/sso?token=dos')->assertSessionHasErrors(['email' => self::ERROR]);

        $this->assertGuest();
    }

    public function test_ir_a_seped_guarda_un_pase_y_redirige_con_el_token(): void
    {
        $respuesta = $this->actingAs($this->ft)->get('/ir-a-seped');

        $destino = $respuesta->headers->get('Location');
        $this->assertStringStartsWith('https://seped.andicar.test/sso?token=', $destino);
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);

        $pase = DB::table('sso_pases')->first();
        $this->assertSame(hash('sha256', $query['token']), $pase->token_hash);
        $this->assertSame(['SIDES', 'SEPED', 77], [$pase->origen, $pase->destino, (int) $pase->seped_user_id]);
        $this->assertSame('2026-10-02 10:01:00', Carbon::parse($pase->expira_at)->toDateTimeString());
        $this->assertDatabaseHas('sides_auditoria', ['accion' => 'sso.ir_a_seped']);
    }

    public function test_el_boton_solo_lo_ve_el_ft_con_seped_configurado(): void
    {
        $this->actingAs($this->ft)->get('/home')->assertSee('Ir a SEPED');
        $this->actingAs($this->encargado)->get('/home')->assertDontSee('Ir a SEPED');
        $this->get('/ir-a-seped')->assertNotFound();

        config(['services.seped.url' => '']);
        $this->actingAs($this->ft)->get('/home')->assertDontSee('Ir a SEPED');
        $this->get('/ir-a-seped')->assertNotFound();
    }

    public function test_el_ft_no_aparece_en_usuarios_ni_se_abre_por_url(): void
    {
        $this->actingAs($this->encargado)->get('/usuarios')->assertOk()
            ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->pluck('email')->all() === ['ana@example.com'])
            ->assertDontSee('soporte@fulltech360.com');

        $id = $this->ft->id;
        $this->get("/usuarios/{$id}")->assertNotFound();
        $this->put("/usuarios/{$id}", ['name' => 'X'])->assertNotFound();
        $this->put("/usuarios/{$id}/clave", ['password' => 'nueva123', 'password_confirmation' => 'nueva123'])->assertNotFound();
        $this->delete("/usuarios/{$id}")->assertNotFound();

        // Tampoco para el propio FT: la droguería no lo tiene en su lista.
        $this->actingAs($this->ft)->get('/usuarios')
            ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->pluck('email')->all() === ['ana@example.com']);
        $this->assertSame('ACTIVO', $this->ft->fresh()->estado);
    }

    public function test_el_ft_no_cuenta_en_administracion_ni_en_informes(): void
    {
        $this->actingAs($this->ft)->get('/admin/droguerias/505094939')->assertOk()
            ->assertViewHas('usuarios', fn ($usuarios) => $usuarios->pluck('email')->all() === ['ana@example.com']);
        $this->get('/admin')->assertViewHas('usuarios', fn ($usuarios) => (int) $usuarios['505094939'] === 1);

        $this->crearPedido(['id' => 1, 'estado' => 'FACTURADO']);
        foreach ([$this->ft, $this->encargado] as $usuario) {
            SidesLogpicking::query()->create([
                'id_pedido' => 1, 'usuario' => $usuario->email, 'numren' => 3, 'numund' => 10,
                'tiempo_picking' => 300, 'fecha_del_picking' => '2026-10-01 09:00:00', 'descripcion' => 'tiempo completo',
            ]);
        }

        $this->get('/informes/picking/productividad')->assertOk()
            ->assertViewHas('ranking', fn ($ranking) => $ranking->pluck('usuario')->all() === ['ana@example.com']);
        $this->get("/informes/picking/productividad/operario/{$this->ft->id}")->assertNotFound();
    }
}
