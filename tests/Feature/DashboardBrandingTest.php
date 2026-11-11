<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestIsolation;
use Tests\TestCase;

class DashboardBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Presentation only: even the isolated memory database must not be queried.
        DB::connection()->beforeExecuting(function (): void {
            throw new RuntimeException('Dashboard branding fixtures must never query records.');
        });
        Queue::fake();
        Vite::useHotFile($this->app->storagePath('framework/testing/unused-dashboard.hot'));
        URL::forceRootUrl('http://rlcp-staff.test');
        $this->travelTo(Carbon::parse('2031-04-21 10:00:00', 'Asia/Manila'));
    }

    #[DataProvider('roles')]
    public function test_existing_dashboards_render_with_synthetic_data_and_original_controls(string $role, string $view, int $cards): void
    {
        $user = new User;
        $user->setRawAttributes([
            'id' => 9001, 'name' => 'Synthetic '.($role === 'project_director' ? 'Director' : 'Caseworker'),
            'email' => 'staff@example.test', 'role' => $role, 'approval_status' => 'approved',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);
        $people = collect(['active', 'inactive'])->map(function ($status, $index) use ($user) {
            $person = new Beneficiary;
            $person->setRawAttributes([
                'id' => 9100 + $index, 'full_name' => 'Synthetic Person '.($index + 1),
                'beneficiary_id' => 'QA-00'.($index + 1), 'status' => $status,
                'caseworker_id' => $user->id, 'photo_path' => null, 'deleted_at' => null,
            ]);

            return $person;
        });
        $activity = new ProgramActivity;
        $activity->setRawAttributes([
            'id' => 9200, 'title' => 'Synthetic activity for layout review', 'activity_date' => '2031-04-23',
            'start_time' => '09:00', 'location' => 'Test location', 'development_area' => 'spiritual',
        ]);
        $data = [
            'activeBeneficiariesCount' => 7, 'activitiesCount' => 4, 'attendanceCount' => 11,
            'assistanceReleasesCount' => 3, 'todaysActivities' => collect(), 'recentAssistance' => collect(),
            'upcomingActivities' => collect([$activity]), 'caseloadCounts' => ['total' => 2, 'active' => 1, 'inactive' => 1],
            'caseloadPreview' => $people,
            'attendanceTrend' => collect([1, 2, 0, 4, 2, 1, 1])->map(fn ($count, $index) => [
                'label' => now('Asia/Manila')->subDays(6 - $index)->format('D'),
                'date' => now('Asia/Manila')->subDays(6 - $index)->format('M d'), 'count' => $count,
            ]),
        ];
        // Substitute only this test application's route action. The real route,
        // authentication middleware, views and policies are retained; no controller runs.
        Route::getRoutes()->getByName('dashboard')->uses(fn () => view($view, $data));
        $response = $this->get(route('dashboard'))->assertOk()
            ->assertSee($user->name)->assertSee('dashboard-beneficiary-search')
            ->assertSee('rlc-notification-panel')->assertSee('sidebar-menu-button')
            ->assertSee('Upcoming Activities')->assertSee('Synthetic activity for layout review');
        $html = $response->getContent();
        $this->assertSame($cards, substr_count($html, 'rlc-panel rlc-stat '));
        $this->assertSame($role === 'caseworker', str_contains($html, 'data-caseload-dashboard'));
        $this->assertSame($role === 'project_director', str_contains($html, 'Account Requests'));
        $this->assertSame($role === 'caseworker', str_contains($html, '>My Caseload<'));
        if ($role === 'project_director') {
            $response->assertSeeInOrder(['Attendance Overview', "Today's Activities", 'Recent Assistance Releases', 'Upcoming Activities', 'Quick Actions']);
            $this->assertSame(7, substr_count($html, 'class="rlc-bar-slot"'));
        } else {
            $response->assertSee('Refresh caseload')->assertSee('data-caseload-preview-version')
                ->assertSee('Synthetic Person 1')->assertSee('Synthetic Person 2');
        }
        $this->exportFixture($role, $html);
        $this->exportFixture('caseload-preview', view('dashboard.partials.caseload-preview', $data)->render());
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('sharedPages')]
    public function test_shared_layout_preserves_appearance_controls_and_unrelated_page_markup(string $name, string $template): void
    {
        $user = new User;
        $user->setRawAttributes([
            'id' => 9001, 'name' => 'Synthetic Director', 'email' => 'staff@example.test',
            'role' => 'project_director', 'approval_status' => 'approved', 'email_verified_at' => now(),
        ]);
        $this->actingAs($user);
        Route::getRoutes()->getByName('dashboard')->uses(fn () => Blade::render($template));
        $response = $this->get(route('dashboard'))->assertOk()->assertSee('rlc-notification-panel');
        $this->exportFixture($name, $response->getContent());
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public static function sharedPages(): array
    {
        return [
            ['appearance', '<x-layouts::app title="Appearance">@include("livewire.settings.appearance")</x-layouts::app>'],
            ['module-probe', '<x-layouts::app title="Scope probe"><div class="rlc-dashboard rlc-registry rlc-registry-index" data-style-probe><section class="rlc-panel"><h2>Unrelated module style probe</h2><a class="rlc-button rlc-button-primary" href="/dashboard">Existing action</a></section></div></x-layouts::app>'],
        ];
    }

    public static function roles(): array
    {
        return [['project_director', 'dashboard', 4], ['caseworker', 'dashboard.caseworker', 3]];
    }

    private function exportFixture(string $name, string $html): void
    {
        $directory = getenv('STAFF_UI_FIXTURES');
        if (! is_string($directory) || $directory === '') {
            return;
        }
        TestIsolation::assertTemporaryPaths([$directory], sys_get_temp_dir());
        $stage = getenv('STAFF_UI_STAGE') === 'baseline' ? 'baseline' : 'current';
        file_put_contents($directory.'/'.$stage.'-'.$name.'.html', $html);
    }
}
