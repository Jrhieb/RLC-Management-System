<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginSessionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('staffRoles')]
    public function test_database_session_login_recovers_from_invalid_credentials_and_supports_logout_and_login(string $role, string $view): void
    {
        $this->useIsolatedDatabaseSessionsWithCsrf();
        $password = Str::random(24);
        $user = User::factory()->create(['role' => $role, 'password' => Hash::make($password)]);
        $form = $this->get(route('login'))->assertOk()->assertSee('x-data="loginForm"', false);
        $token = $this->formToken($form);
        $this->nextRequest($form);
        $invalid = $this->post(route('login.store'), ['_token' => $token, 'email' => $user->email, 'password' => $password.'x'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->nextRequest($invalid);

        $form = $this->get(route('login'))->assertOk();
        $token = $this->formToken($form);
        $this->nextRequest($form);
        $login = $this->post(route('login.store'), ['_token' => $token, 'email' => $user->email, 'password' => $password])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->nextRequest($login);
        $dashboard = $this->get(route('dashboard'))->assertOk()->assertViewIs($view);
        $logoutToken = $this->app['session']->token();
        $this->nextRequest($dashboard);
        $logout = $this->post(route('logout'), ['_token' => $logoutToken])->assertRedirect(route('home'));
        $this->assertGuest();
        $this->nextRequest($logout);

        $form = $this->get(route('login'))->assertOk();
        $token = $this->formToken($form);
        $this->nextRequest($form);
        $this->post(route('login.store'), ['_token' => $token, 'email' => $user->email, 'password' => $password])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertSame($role, $user->fresh()->role);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_replaying_the_pre_login_cookie_and_form_is_still_rejected_by_csrf(): void
    {
        $this->useIsolatedDatabaseSessionsWithCsrf();
        $password = Str::random(24);
        $user = User::factory()->create(['password' => Hash::make($password)]);
        $form = $this->get(route('login'))->assertOk();
        $cookieName = config('session.cookie');
        $originalCookie = $form->getCookie($cookieName)->getValue();
        $token = $this->formToken($form);
        $this->nextRequest($form);
        $login = $this->post(route('login.store'), ['_token' => $token, 'email' => $user->email, 'password' => $password])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(hash_equals($originalCookie, $login->getCookie($cookieName)->getValue()));
        $this->nextRequest($login);
        $this->withCookie($cookieName, $originalCookie);
        $this->post(route('login.store'), ['_token' => $token, 'email' => $user->email, 'password' => $password])->assertStatus(419);
        $this->assertGuest();
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, User::query()->count());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public static function staffRoles(): array
    {
        return [
            'Director' => ['project_director', 'dashboard'],
            'Caseworker' => ['caseworker', 'dashboard.caseworker'],
        ];
    }

    private function useIsolatedDatabaseSessionsWithCsrf(): void
    {
        // TestCase already guarded SQLite :memory:, fake mail and temporary paths before boot.
        config(['session.driver' => 'database', 'session.connection' => 'sqlite']);
        $this->app['env'] = 'local';
        $this->resetRequestServices();
    }

    private function nextRequest(TestResponse $response): void
    {
        $name = config('session.cookie');
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie);
        $this->withCookie($name, $cookie->getValue());
        $this->resetRequestServices();
    }

    private function resetRequestServices(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    private function formToken(TestResponse $response): string
    {
        $matched = preg_match('/name="_token"\s+value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertTrue($matched === 1);

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}
