<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_placeholder_is_only_available_to_directors(): void
    {
        $this->get('/deployment-event')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/deployment-event')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'project_director']))
            ->get('/deployment-event')
            ->assertOk()
            ->assertSee('Planned — Placeholder')
            ->assertSee('Development / Testing Only')
            ->assertSee('Public Applications Disabled')
            ->assertSee('Guardian acknowledgment')
            ->assertSee('Final production authorization');
    }
}
