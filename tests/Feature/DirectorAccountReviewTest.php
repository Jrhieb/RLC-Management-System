<?php

namespace Tests\Feature;

use App\Actions\ReviewCaseworkerAccount;
use App\Mail\AccountDecisionMail;
use App\Models\Beneficiary;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DirectorAccountReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_verified_director_lists_and_filters_requests_with_confirmations_for_pending_accounts_only(): void
    {
        $director = $this->director();
        $pending = User::factory()->pendingApproval()->create(['name' => 'Pending Applicant']);
        $approved = User::factory()->create(['name' => 'Approved Staff']);
        $rejected = User::factory()->create(['name' => 'Rejected Applicant', 'approval_status' => 'rejected', 'rejection_reason' => 'Review explanation']);
        User::factory()->pendingApproval()->create(['role' => 'admin', 'name' => 'Non Caseworker']);

        $page = $this->get(route('account-requests.index'))->assertOk()->assertSee($pending->name)->assertSee($pending->email)
            ->assertSee('Request date')->assertSee('Pending')->assertSee('Confirm approval')->assertSee('Confirm rejection')
            ->assertDontSee($approved->name)->assertDontSee($rejected->name)->assertDontSee('Non Caseworker');
        $xpath = $this->dom($page->getContent());
        $this->assertSame(2, $xpath->query('//form[@action="'.route('account-requests.review', ['user' => $pending, 'status' => 'pending', 'page' => 1]).'"]')->length);
        $this->assertSame(2, $xpath->query('//input[@name="confirmed" and @value="1"]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@name="rejection_reason" and @required and @maxlength="2000"]')->length);
        $this->assertSame(2, $xpath->query('//dialog[contains(@class, "min-w-0")]')->length);
        $this->assertSame(2, $xpath->query('//ui-modal[contains(@x-init, "aria-labelledby")]')->length);
        $this->get(route('account-requests.index', ['status' => 'all']))->assertOk()->assertSee($pending->name)
            ->assertSee($approved->name)->assertSee($rejected->name)->assertSee('Review explanation')
            ->assertViewHas('accounts', fn ($accounts) => ! $accounts->contains('id', $director->id));
        $this->get(route('account-requests.index', ['status' => 'approved']))->assertOk()->assertSee($approved->name)
            ->assertDontSee($pending->name)->assertDontSee('Confirm approval')->assertDontSee('Confirm rejection');
        $this->get(route('account-requests.index', ['status' => ['invalid']]))->assertOk()->assertViewHas('status', 'pending');
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    #[DataProvider('unauthorizedActors')]
    public function test_only_verified_directors_can_list_or_review_accounts(?string $role, string $approval, bool $verified, int $expected): void
    {
        $pending = User::factory()->pendingApproval()->create();
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'approval_status' => $approval, 'email_verified_at' => $verified ? now() : null]));
        }
        $before = DB::table('users')->where('id', $pending->id)->first();
        $this->getJson(route('account-requests.index'))->assertStatus($expected);
        $this->postJson(route('account-requests.review', $pending), $this->decision('approve'))->assertStatus($expected);
        $this->assertEquals($before, DB::table('users')->where('id', $pending->id)->first());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_approval_saves_reviewer_and_timestamp_transactionally_without_assigning_beneficiaries(): void
    {
        $director = $this->director();
        $pending = User::factory()->pendingApproval()->create()->fresh();
        $other = User::factory()->create();
        Beneficiary::create(['beneficiary_id' => 'REVIEW-UNASSIGNED', 'full_name' => 'Unassigned Fixture', 'registered_at' => '2026-10-09', 'status' => 'active']);
        Beneficiary::create(['beneficiary_id' => 'REVIEW-ASSIGNED', 'full_name' => 'Assigned Fixture', 'registered_at' => '2026-10-09', 'status' => 'active', 'caseworker_id' => $other->id]);
        $before = $pending->getRawOriginal();
        $assignments = DB::table('beneficiaries')->orderBy('id')->get()->toArray();
        $others = DB::table('users')->where('id', '!=', $pending->id)->orderBy('id')->get()->toArray();
        $baseLevel = DB::transactionLevel();
        $updateLevel = null;
        DB::listen(function ($query) use (&$updateLevel) {
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'approval_reviewed_by')) {
                $updateLevel = DB::transactionLevel();
            }
        });
        $this->post(route('account-requests.review', $pending), $this->decision('approve') + [
            'approval_reviewed_by' => $other->id, 'approval_status' => 'rejected', 'approval_reviewed_at' => '2000-01-01',
            'role' => 'project_director', 'email_verified_at' => null, 'email' => 'injected@example.test', 'rejection_reason' => 'Ignored approval reason',
        ])->assertRedirect(route('account-requests.index'))->assertSessionHasNoErrors()->assertSessionHas('status', 'Caseworker account approved.');
        $pending->refresh();
        $this->assertSame('approved', $pending->approval_status);
        $this->assertSame($director->id, $pending->approval_reviewed_by);
        $this->assertTrue($pending->approval_reviewed_at->equalTo(now()));
        $this->assertNull($pending->rejection_reason);
        $this->assertSame($baseLevel + 1, $updateLevel);
        $this->assertSame($baseLevel, DB::transactionLevel());
        $ignored = ['approval_status', 'approval_reviewed_by', 'approval_reviewed_at', 'rejection_reason', 'updated_at'];
        $this->assertSame(Arr::except($before, $ignored), Arr::except($pending->getRawOriginal(), $ignored));
        $this->assertEquals($assignments, DB::table('beneficiaries')->orderBy('id')->get()->toArray());
        $this->assertEquals($others, DB::table('users')->where('id', '!=', $pending->id)->orderBy('id')->get()->toArray());
        $this->assertAuthenticatedAs($director);
        Mail::assertQueued(AccountDecisionMail::class, fn ($mail) => $mail->hasTo($pending->email) && $mail->decision === 'approved');
        Mail::assertQueuedCount(1);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_rejection_saves_trimmed_reason_and_the_acting_director(): void
    {
        $director = $this->director();
        $pending = User::factory()->pendingApproval()->create();
        $this->postJson(route('account-requests.review', $pending), $this->decision('reject', '  Missing account details.  '))
            ->assertOk()->assertJsonPath('message', 'Caseworker account rejected.');
        $pending->refresh();
        $this->assertSame('rejected', $pending->approval_status);
        $this->assertSame('Missing account details.', $pending->rejection_reason);
        $this->assertSame($director->id, $pending->approval_reviewed_by);
        $this->assertTrue($pending->approval_reviewed_at->equalTo(now()));
    }

    #[DataProvider('completedDecisions')]
    public function test_repeated_or_changed_decisions_cannot_overwrite_a_completed_review(string $first, string $second): void
    {
        $this->director();
        $pending = User::factory()->pendingApproval()->create();
        $this->postJson(route('account-requests.review', $pending), $this->decision($first, 'Initial decision reason.'))->assertOk();
        $before = $pending->fresh()->getRawOriginal();
        $this->travel(3)->minutes();
        $this->director();
        $this->postJson(route('account-requests.review', $pending), $this->decision($second, 'Replacement attempt.'))->assertStatus(409);
        $this->assertSame($before, $pending->fresh()->getRawOriginal());
    }

    public function test_interleaved_director_decisions_have_one_winner_and_preserve_the_first_review(): void
    {
        $first = $this->director();
        $second = User::factory()->create(['role' => 'project_director']);
        $pending = User::factory()->pendingApproval()->create();
        $action = app(ReviewCaseworkerAccount::class);
        $contenderResult = null;
        $interleaved = false;
        // Interleave the second decision after the first conditional write, before commit.
        DB::listen(function ($query) use ($action, $second, $pending, &$contenderResult, &$interleaved) {
            if (! $interleaved && str_starts_with($query->sql, 'update') && str_contains($query->sql, 'approval_reviewed_by')) {
                $interleaved = true;
                $contenderResult = $action->execute($second, $pending, $this->decision('reject', 'Competing rejection.'));
            }
        });
        $this->assertTrue($action->execute($first, $pending, $this->decision('approve')));
        $this->assertFalse($contenderResult);
        $pending->refresh();
        $this->assertSame('approved', $pending->approval_status);
        $this->assertSame($first->id, $pending->approval_reviewed_by);
        $this->assertTrue($pending->approval_reviewed_at->equalTo(now()));
        $this->assertNull($pending->rejection_reason);
    }

    #[DataProvider('invalidTargets')]
    public function test_only_pending_caseworker_accounts_can_be_reviewed(string $role, string $approval): void
    {
        $this->director();
        $target = User::factory()->create(['role' => $role, 'approval_status' => $approval])->fresh();
        $before = $target->getRawOriginal();
        $this->postJson(route('account-requests.review', $target), $this->decision('reject', 'Attempted review.'))->assertStatus(409);
        $this->assertSame($before, $target->fresh()->getRawOriginal());
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_decisions_or_missing_confirmation_and_reason_leave_the_request_pending(array $fields, string $error): void
    {
        $this->director();
        $pending = User::factory()->pendingApproval()->create()->fresh();
        $before = $pending->getRawOriginal();
        $this->postJson(route('account-requests.review', $pending), array_replace($this->decision('reject', 'Review reason'), $fields))
            ->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame($before, $pending->fresh()->getRawOriginal());
    }

    public function test_invalid_web_rejection_returns_accessible_feedback_and_reopens_the_correct_confirmation(): void
    {
        $this->director();
        $pending = User::factory()->pendingApproval()->create();
        $response = $this->post(route('account-requests.review', $pending), $this->decision('reject', ''))
            ->assertRedirect(route('account-requests.index'))->assertSessionHasErrors('rejection_reason')
            ->assertSessionHas('review_account_id', $pending->id)->assertSessionHas('_old_input.decision', 'reject');
        $cookie = config('session.cookie');
        $this->withCookie($cookie, $response->getCookie($cookie)->getValue());
        $page = $this->get(route('account-requests.index'))->assertOk()->assertSee('role="alert"', false);
        $this->assertSame(1, $this->dom($page->getContent())->query('//div[@x-init]')->length);
    }

    public function test_review_feedback_keeps_the_filter_and_page_for_the_request_being_reviewed(): void
    {
        $this->director();
        $pending = User::factory()->pendingApproval()->create(['created_at' => now()->subDay()]);
        User::factory()->pendingApproval()->count(15)->create();
        $parameters = ['status' => 'all', 'page' => 2];
        $returnUrl = route('account-requests.index', $parameters);
        $reviewUrl = route('account-requests.review', ['user' => $pending] + $parameters);
        $this->get($returnUrl)->assertOk()->assertSee($pending->name);
        $response = $this->post($reviewUrl, $this->decision('reject', ''))
            ->assertRedirect($returnUrl)->assertSessionHasErrors('rejection_reason');
        $cookie = config('session.cookie');
        $this->withCookie($cookie, $response->getCookie($cookie)->getValue());
        $page = $this->get($returnUrl)->assertOk()->assertSee($pending->name);
        $this->assertSame(1, $this->dom($page->getContent())->query('//div[@x-init]')->length);
        $this->post($reviewUrl, $this->decision('reject', 'Review explanation.'))
            ->assertRedirect($returnUrl)->assertSessionHasNoErrors();
        $this->assertSame('rejected', $pending->fresh()->approval_status);
    }

    public function test_director_navigation_is_visible_only_to_verified_directors(): void
    {
        $this->director();
        $this->get(route('beneficiaries.index'))->assertOk()->assertSee('Account Requests')->assertSee(route('account-requests.index'));
        $this->actingAs(User::factory()->create());
        $this->get(route('beneficiaries.index'))->assertOk()->assertDontSee('Account Requests')->assertDontSee(route('account-requests.index'));
    }

    public function test_review_post_requires_csrf_and_unknown_accounts_are_not_reviewable(): void
    {
        $this->director();
        $pending = User::factory()->pendingApproval()->create();
        $this->postJson(route('account-requests.review', 999999), $this->decision('approve'))->assertNotFound();
        $this->app['env'] = 'production';
        try {
            $this->post(route('account-requests.review', $pending), $this->decision('approve'))->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame('pending', $pending->fresh()->approval_status);
    }

    public static function unauthorizedActors(): array
    {
        return [
            'guest' => [null, 'pending', false, 401],
            'approved caseworker' => ['caseworker', 'approved', true, 403],
            'pending caseworker' => ['caseworker', 'pending', true, 403],
            'rejected caseworker' => ['caseworker', 'rejected', true, 403],
            'unverified director' => ['project_director', 'approved', false, 403],
            'other role' => ['admin', 'approved', true, 403],
        ];
    }

    public static function completedDecisions(): array
    {
        return [['approve', 'approve'], ['approve', 'reject'], ['reject', 'approve'], ['reject', 'reject']];
    }

    public static function invalidTargets(): array
    {
        return [['project_director', 'pending'], ['admin', 'pending'], ['caseworker', 'approved'], ['caseworker', 'rejected']];
    }

    public static function invalidDecisions(): array
    {
        return [
            'unknown decision' => [['decision' => 'approved'], 'decision'],
            'array decision' => [['decision' => ['approve']], 'decision'],
            'missing confirmation' => [['confirmed' => null], 'confirmed'],
            'negative confirmation' => [['confirmed' => false], 'confirmed'],
            'empty reason' => [['rejection_reason' => ''], 'rejection_reason'],
            'whitespace reason' => [['rejection_reason' => '  '], 'rejection_reason'],
            'array reason' => [['rejection_reason' => ['invalid']], 'rejection_reason'],
            'long reason' => [['rejection_reason' => str_repeat('r', 2001)], 'rejection_reason'],
        ];
    }

    private function director(): User
    {
        $user = User::factory()->create(['role' => 'project_director', 'approval_status' => 'pending']);
        $this->actingAs($user);

        return $user;
    }

    private function decision(string $decision, ?string $reason = null): array
    {
        return ['decision' => $decision, 'confirmed' => '1', 'rejection_reason' => $reason];
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
