<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class CaseworkerAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_director_creation_endpoint_and_permission_remain_disabled(): void
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director);
        $this->assertFalse(Route::has('caseworkers.store'));
        $this->assertFalse(Gate::allows('createCaseworker', User::class));
        $this->postJson('/caseworkers', ['name' => 'Disallowed Creation', 'email' => 'disallowed@example.test',
            'password' => 'Disallowed-creation-123!', 'password_confirmation' => 'Disallowed-creation-123!'])
            ->assertNotFound();
        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($director);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_director_registry_and_review_page_expose_review_without_a_creation_dialog(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
        $this->get(route('beneficiaries.index'))->assertOk()->assertDontSee('Add Caseworker')->assertDontSee('data-caseworker-dialog', false);
        $this->get(route('account-requests.index'))->assertOk()->assertDontSee('Add Caseworker')->assertDontSee('name="password"', false);
    }

    public function test_standard_fortify_registration_is_disabled_and_the_email_first_entry_remains_available(): void
    {
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->assertFalse(Route::has('register.store'));
        $this->get(route('register'))->assertOk()->assertSee(route('registration.request'))
            ->assertDontSee('name="password"', false);
        $this->assertDatabaseCount('users', 0);
    }
}
