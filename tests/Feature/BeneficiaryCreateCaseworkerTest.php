<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\BeneficiaryPolicy;
use App\Support\NfcCardUid;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryCreateCaseworkerTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Beneficiary creation assignment tests require guarded SQLite :memory:.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_director_form_defaults_to_unassigned_and_lists_only_eligible_accounts_alphabetically(): void
    {
        $this->director();
        $zoe = $this->caseworker(['name' => 'Zoe Santos']);
        $alex = $this->caseworker(['name' => 'Alex Cruz']);
        $unverified = $this->caseworker(['name' => 'Unverified', 'email_verified_at' => null]);
        $admin = User::factory()->create(['name' => 'Admin Account', 'role' => 'admin']);
        $response = $this->get(route('beneficiaries.create'))->assertOk()
            ->assertViewHas('selectedCaseworkerId', null)->assertSee('Caseworker assignment')->assertSee('Register NFC Card');
        $xpath = $this->xpath($response->getContent());
        $values = array_map(fn ($option) => $option->getAttribute('value'),
            iterator_to_array($xpath->query('//select[@name="caseworker_id"]/option')));
        $this->assertSame(['', (string) $alex->id, (string) $zoe->id], $values);
        $this->assertSame('', $this->selectedValue($response->getContent()));
        $this->assertCount(0, $xpath->query('//select[@name="caseworker_id"]/option[@value="'.$unverified->id.'" or @value="'.$admin->id.'"]'));
        $this->assertCount(1, $xpath->query('//form[@data-beneficiary-create-form]//select[@name="caseworker_id"]'));
    }

    public function test_director_without_eligible_caseworkers_can_still_register_unassigned(): void
    {
        $this->director();
        $response = $this->get(route('beneficiaries.create'))->assertOk()->assertSee('No eligible caseworkers are available yet.');
        $this->assertSame('', $this->selectedValue($response->getContent()));
        $this->post(route('beneficiaries.store'), $this->payload(['caseworker_id' => '']))->assertRedirect();
        $this->assertNull(Beneficiary::sole()->caseworker_id);
    }

    public function test_specific_eligible_caseload_link_prefills_the_form_and_selection_can_be_changed(): void
    {
        $this->director();
        $from = $this->caseworker(['name' => 'Current Caseworker']);
        $other = $this->caseworker(['name' => 'Other Caseworker']);
        $response = $this->get(route('beneficiaries.index', ['caseworker' => $from->id]))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $link = $xpath->query('//div[@class="rlcr-header-actions"]/a[normalize-space(.)="Add Beneficiary"]')->item(0)->getAttribute('href');
        $this->assertSame(route('beneficiaries.create', ['caseworker_id' => $from->id]), $link);
        $form = $this->get($link)->assertOk()->assertViewHas('selectedCaseworkerId', $from->id);
        $this->assertSame((string) $from->id, $this->selectedValue($form->getContent()));
        $this->post(route('beneficiaries.store'), $this->payload(['caseworker_id' => $other->id]))->assertRedirect();
        $this->assertSame($other->id, Beneficiary::sole()->caseworker_id);
    }

    public function test_director_links_preserve_optional_assignment_and_caseworkers_receive_no_creation_link(): void
    {
        $this->director();
        $unverified = $this->caseworker(['email_verified_at' => null]);
        $eligible = $this->caseworker();
        foreach (['unassigned', $unverified->id] as $filter) {
            $response = $this->get(route('beneficiaries.index', ['caseworker' => $filter]))->assertOk();
            $this->assertSame(route('beneficiaries.create'), $this->xpath($response->getContent())
                ->query('//div[@class="rlcr-header-actions"]/a[normalize-space(.)="Add Beneficiary"]')->item(0)->getAttribute('href'));
        }
        $this->actingAs($eligible);
        $response = $this->get(route('beneficiaries.index', ['caseworker' => $eligible->id]))->assertOk()
            ->assertDontSee('Add Beneficiary')->assertDontSee(route('beneficiaries.create'));
        $this->assertCount(0, $this->xpath($response->getContent())->query('//*[@data-registry-create]'));
    }

    #[DataProvider('invalidPreselections')]
    public function test_invalid_or_ineligible_preselection_defaults_to_unassigned_without_exposing_an_account(string $kind, mixed $value): void
    {
        $director = $this->director();
        $eligible = $this->caseworker();
        if ($kind === 'unverified') {
            $value = $this->caseworker(['email_verified_at' => null])->id;
        } elseif ($kind === 'admin') {
            $value = User::factory()->create(['role' => 'admin'])->id;
        } elseif ($kind === 'director') {
            $value = $director->id;
        }
        $response = $this->get(route('beneficiaries.create', ['caseworker_id' => $value]))->assertOk()
            ->assertViewHas('selectedCaseworkerId', null)->assertViewHas('preselectionUnavailable', true)
            ->assertSee('The requested caseworker is unavailable.');
        $this->assertSame('', $this->selectedValue($response->getContent()));
        $values = array_map(fn ($option) => $option->getAttribute('value'),
            iterator_to_array($this->xpath($response->getContent())->query('//select[@name="caseworker_id"]/option')));
        $this->assertSame(['', (string) $eligible->id], $values);
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public static function invalidPreselections(): array
    {
        return [['missing', 999999], ['unverified', null], ['admin', null], ['director', null],
            ['malformed', 'invalid'], ['malformed', -1], ['malformed', ['1']]];
    }

    public function test_director_saves_assignment_card_and_private_photo_in_one_transaction(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->director();
        $caseworker = $this->caseworker();
        $usersBefore = $this->snapshot('users');
        $beforeLevel = DB::transactionLevel();
        $saveLevels = [];
        Beneficiary::creating(function (Beneficiary $record) use ($caseworker, $beforeLevel, &$saveLevels) {
            $saveLevels[] = DB::transactionLevel();
            $this->assertSame($beforeLevel + 1, DB::transactionLevel());
            $this->assertSame($caseworker->id, $record->caseworker_id);
            $this->assertSame('0027947780', $record->nfc_uid);
        });
        $this->post(route('beneficiaries.store'), $this->payload([
            'beneficiary_id' => '000007', 'caseworker_id' => (string) $caseworker->id, 'nfc_uid' => '0027947780',
            'photo' => UploadedFile::fake()->image('private.jpg'),
        ]))->assertRedirect(route('beneficiaries.show', 1))->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Beneficiary registered successfully.');
        $record = Beneficiary::sole();
        $this->assertSame($caseworker->id, $record->caseworker_id);
        $this->assertTrue($record->caseworker->is($caseworker));
        $this->assertSame('0027947780', $record->nfc_uid);
        $this->assertSame('000007', $record->beneficiary_id);
        $this->assertSame([$beforeLevel + 1], $saveLevels);
        $this->assertSame($beforeLevel, DB::transactionLevel());
        Storage::disk('local')->assertExists($record->photo_path);
        Storage::disk('public')->assertMissing($record->photo_path);
        $this->get(route('beneficiaries.photo', $record))->assertOk();
        $this->assertSame($usersBefore, $this->snapshot('users'));
        $this->assertSame([$record->id], $caseworker->assignedBeneficiaries()->pluck('id')->all());
    }

    #[DataProvider('unassignedPayloads')]
    public function test_director_registration_remains_optional_without_an_assignment_or_card(array $fields): void
    {
        $this->director();
        $this->post(route('beneficiaries.store'), $this->payload($fields))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Beneficiary::sole()->caseworker_id);
        $this->assertNull(Beneficiary::sole()->nfc_uid);
    }

    public static function unassignedPayloads(): array
    {
        return [[[]], [['caseworker_id' => null]], [['caseworker_id' => '']]];
    }

    #[DataProvider('invalidDestinations')]
    public function test_invalid_or_ineligible_assignments_are_rejected_before_creation(string $kind, mixed $value): void
    {
        $director = $this->director();
        if ($kind === 'unverified') {
            $value = $this->caseworker(['email_verified_at' => null])->id;
        } elseif ($kind === 'admin') {
            $value = User::factory()->create(['role' => 'admin'])->id;
        } elseif ($kind === 'director') {
            $value = $director->id;
        } elseif ($kind === 'deleted') {
            $account = $this->caseworker();
            $value = $account->id;
            $account->delete();
        }
        $usersBefore = $this->snapshot('users');
        $this->postJson(route('beneficiaries.store'), $this->payload(['caseworker_id' => $value, 'nfc_uid' => '0027947780']))
            ->assertUnprocessable()->assertJsonValidationErrors('caseworker_id');
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertSame($usersBefore, $this->snapshot('users'));
    }

    public static function invalidDestinations(): array
    {
        return [['missing', 999999], ['unverified', null], ['admin', null], ['director', null], ['deleted', null],
            ['malformed', 'invalid'], ['malformed', -1], ['malformed', 0], ['malformed', 1.2], ['malformed', [1]], ['malformed', 'unassign']];
    }

    #[DataProvider('unauthorizedFields')]
    public function test_caseworkers_cannot_submit_any_assignment_field_even_when_empty(mixed $value): void
    {
        Storage::fake('local');
        $actor = $this->caseworker();
        $this->actingAs($actor);
        $this->postJson(route('beneficiaries.store'), $this->payload([
            'caseworker_id' => $value === 'self' ? $actor->id : $value,
            'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('unauthorized.jpg'),
        ]))->assertForbidden();
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    public static function unauthorizedFields(): array
    {
        return [['self'], [null], [''], [999999], [[1]]];
    }

    public function test_caseworkers_cannot_create_but_keep_assigned_access_and_receive_no_registration_links(): void
    {
        Storage::fake('local');
        $actor = $this->caseworker();
        $other = $this->caseworker();
        $existing = $this->record(['caseworker_id' => $other->id]);
        $own = $this->record(['beneficiary_id' => 'OWN-RECORD', 'caseworker_id' => $actor->id]);
        $before = $this->snapshot('beneficiaries');
        $this->actingAs($actor);
        $this->get(route('beneficiaries.create'))->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('error', 'Only Directors can add beneficiaries.');
        $this->get(route('beneficiaries.create', ['caseworker_id' => $actor->id]))->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('error', 'Only Directors can add beneficiaries.');
        $this->post(route('beneficiaries.store'), $this->payload([
            'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('denied.jpg'),
        ]))->assertForbidden();
        $this->assertSame($before, $this->snapshot('beneficiaries'));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->get(route('beneficiaries.show', $own))->assertOk();
        $this->get(route('beneficiaries.edit', $own))->assertOk();
        $this->get(route('beneficiaries.show', $existing))->assertForbidden();
        foreach ([route('beneficiaries.index'), route('beneficiaries.index', ['tab' => 'caseloads']),
            route('my-caseload.index'), route('dashboard'),
        ] as $url) {
            $this->get($url)->assertOk()->assertDontSee('Add Beneficiary')->assertDontSee('Register Beneficiary')
                ->assertDontSee(route('beneficiaries.create'));
        }
        $this->get(route('beneficiaries.index'))->assertOk()
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 1);
        $response = $this->get(route('beneficiaries.index'), ['X-Registry-Fragment' => 'results'])->assertOk();
        $metadata = json_decode($this->xpath($response->getContent())->query('//*[@data-registry-results]')->item(0)
            ->getAttribute('data-registry-page'), true);
        $this->assertNull($metadata['createUrl']);
        $this->postJson('/caseworkers', ['name' => 'Unauthorized Account', 'email' => 'unauthorized@example.test',
            'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();
    }

    #[DataProvider('creationDenials')]
    public function test_denied_creation_and_nfc_checks_do_not_write_records_files_or_notifications(
        ?string $role, string $approval, bool $verified, int $status
    ): void {
        Storage::fake('local');
        Storage::fake('public');
        $this->record();
        $actor = $role !== null ? User::factory()->create([
            'role' => $role, 'approval_status' => $approval, 'email_verified_at' => $verified ? now() : null,
        ]) : null;
        if ($actor !== null) {
            $this->actingAs($actor);
            $this->assertFalse((new BeneficiaryPolicy)->create($actor));
        }
        $before = $this->snapshot('beneficiaries');
        $usersBefore = $this->snapshot('users');
        $response = $this->getJson(route('beneficiaries.create'));
        if ($actor?->isEligibleCaseworker()) {
            $response->assertRedirect(route('beneficiaries.index'))
                ->assertSessionHas('error', 'Only Directors can add beneficiaries.');
        } else {
            $response->assertStatus($status);
        }
        $this->postJson(route('beneficiaries.store'), $this->payload([
            'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('denied.jpg'),
        ]))->assertStatus($status);
        $this->postJson(route('beneficiaries.store'), $this->payload([
            'caseworker_id' => $actor?->id ?? 999999, 'photo' => UploadedFile::fake()->image('forged.jpg'),
        ]))->assertStatus($status);
        $this->postJson(route('beneficiaries.store'), ['full_name' => ''])->assertStatus($status);
        $this->postJson(route('beneficiaries.nfc.check'), ['nfc_uid' => '0027947780'])->assertStatus($status);
        $this->assertSame($before, $this->snapshot('beneficiaries'));
        $this->assertSame($usersBefore, $this->snapshot('users'));
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public static function creationDenials(): array
    {
        return ['approved caseworker' => ['caseworker', 'approved', true, 403],
            'pending caseworker' => ['caseworker', 'pending', true, 403],
            'rejected caseworker' => ['caseworker', 'rejected', true, 403],
            'unverified caseworker' => ['caseworker', 'approved', false, 403],
            'unverified director' => ['project_director', 'approved', false, 403],
            'applicant' => ['applicant', 'approved', true, 403],
            'guest' => [null, 'approved', false, 401]];
    }

    #[DataProvider('caseworkerCreateQueries')]
    public function test_caseworker_create_get_shows_the_notice_in_the_registry_without_creation_links(array $query): void
    {
        Storage::fake('local');
        $actor = $this->caseworker();
        $this->actingAs($actor)->get(route('beneficiaries.create', $query))
            ->assertRedirect(route('beneficiaries.index'))
            ->assertSessionHas('error', 'Only Directors can add beneficiaries.');

        $response = $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('beneficiaries.index'))->assertOk()
            ->assertSee('Only Directors can add beneficiaries.')
            ->assertSee('No beneficiaries found')->assertDontSee('Add Beneficiary')
            ->assertDontSee(route('beneficiaries.create'))->assertDontSee('data-beneficiary-create-form')
            ->assertViewHas('beneficiaries', fn ($records) => $records->total() === 0);
        $this->assertCount(1, $this->xpath($response->getContent())->query('//*[@role="alert" and normalize-space(.)="Only Directors can add beneficiaries."]'));
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public static function caseworkerCreateQueries(): array
    {
        return ['plain URL' => [[]], 'forged assignment' => [['caseworker_id' => 999999]],
            'malformed assignment' => [['caseworker_id' => ['invalid']]]];
    }

    public function test_guest_web_creation_requires_login_and_does_not_save_an_uploaded_photo(): void
    {
        Storage::fake('local');
        $this->get(route('beneficiaries.create'))->assertRedirect(route('login'));
        $this->post(route('beneficiaries.store'), $this->payload([
            'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('guest.jpg'),
        ]))->assertRedirect(route('login'));
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    #[DataProvider('retainedSelections')]
    public function test_old_selection_overrides_caseload_preselection_and_pending_card_survives_validation(mixed $selection): void
    {
        $this->director();
        $original = $this->caseworker();
        $chosen = $this->caseworker();
        $selection = $selection === 'chosen' ? (string) $chosen->id : $selection;
        $url = route('beneficiaries.create', ['caseworker_id' => $original->id]);
        $this->from($url)->post(route('beneficiaries.store'), $this->payload([
            'full_name' => '', 'caseworker_id' => $selection, 'nfc_uid' => '0027947780',
        ]))->assertRedirect($url)->assertSessionHasErrors('full_name')
            ->assertSessionHasInput('nfc_uid', '0027947780')->assertSessionMissing('success');
        $this->assertArrayHasKey('caseworker_id', session()->getOldInput());
        $this->assertSame($selection === '' ? null : $selection, session()->getOldInput('caseworker_id'));
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get($url)->assertOk()
            ->assertSee('data-nfc-pending-input value="0027947780"', false)->assertSee('The full name field is required.');
        $this->assertSame($selection === null || $selection === '' ? '' : $selection, $this->selectedValue($response->getContent()));
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->post(route('beneficiaries.store'), $this->payload(['caseworker_id' => $selection, 'nfc_uid' => '0027947780']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($selection === null || $selection === '' ? null : $chosen->id, Beneficiary::sole()->caseworker_id);
        $this->assertSame('0027947780', Beneficiary::sole()->nfc_uid);
    }

    public static function retainedSelections(): array
    {
        return [['chosen'], [null], ['']];
    }

    public function test_ineligible_selection_shows_inline_feedback_and_retains_other_fields_and_card(): void
    {
        $this->director();
        $unverified = $this->caseworker(['email_verified_at' => null]);
        $url = route('beneficiaries.create');
        $this->from($url)->post(route('beneficiaries.store'), $this->payload(['caseworker_id' => $unverified->id, 'nfc_uid' => '0027947780']))
            ->assertRedirect($url)->assertSessionHasErrors('caseworker_id')->assertSessionMissing('success');
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get($url)->assertOk()
            ->assertSee('Choose an approved caseworker with a verified email')->assertSee('value="New Assigned Beneficiary"', false)
            ->assertSee('data-nfc-pending-input value="0027947780"', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//select[@name="caseworker_id" and @aria-invalid="true" and contains(@aria-describedby,"caseworker-error")]'));
        $this->assertCount(1, $xpath->query('//*[@id="caseworker-error"]'));
        $this->assertCount(0, $xpath->query('//select[@name="caseworker_id"]/option[@value="'.$unverified->id.'"]'));
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    #[DataProvider('eligibilityChanges')]
    public function test_eligibility_is_rechecked_inside_the_transaction_and_failed_private_photo_is_removed(string $change): void
    {
        Storage::fake('local');
        $this->director();
        $caseworker = $this->caseworker();
        $initialLevel = DB::transactionLevel();
        DB::connection()->beforeStartingTransaction(function ($connection) use ($caseworker, $initialLevel, $change) {
            if ($connection->transactionLevel() === $initialLevel) {
                $account = DB::table('users')->where('id', $caseworker->id);
                if ($change === 'deleted') {
                    $account->delete();
                } else {
                    $account->update($change === 'role' ? ['role' => 'admin'] : ['email_verified_at' => null]);
                }
            }
        });
        $this->postJson(route('beneficiaries.store'), $this->payload([
            'caseworker_id' => $caseworker->id, 'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('stale.jpg'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('caseworker_id');
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
        $this->assertSame($initialLevel, DB::transactionLevel());
    }

    public static function eligibilityChanges(): array
    {
        return [['verification'], ['role'], ['deleted']];
    }

    public function test_assignment_policy_authorizes_the_new_record_before_persisting_and_cleans_up_photo_on_rejection(): void
    {
        Storage::fake('local');
        $this->director();
        $caseworker = $this->caseworker();
        $policy = new class extends BeneficiaryPolicy
        {
            public function assignCaseworker(User $user, ?Beneficiary $beneficiary = null): bool
            {
                return parent::assignCaseworker($user, $beneficiary) && $beneficiary === null;
            }
        };
        Gate::policy(Beneficiary::class, get_class($policy));
        $this->postJson(route('beneficiaries.store'), $this->payload([
            'caseworker_id' => $caseworker->id, 'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('blocked.jpg'),
        ]))->assertForbidden();
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    public function test_nfc_database_conflict_rolls_back_assignment_and_registration_and_retains_selection(): void
    {
        Storage::fake('local');
        $this->director();
        $caseworker = $this->caseworker();
        $owner = $this->record();
        $before = $owner->getRawOriginal();
        $usersBefore = $this->snapshot('users');
        Beneficiary::creating(function (Beneficiary $record) use ($owner, $caseworker) {
            $this->assertSame($caseworker->id, $record->caseworker_id);
            DB::table('beneficiaries')->where('id', $owner->id)->update(['nfc_uid' => $record->nfc_uid]);
        });
        $this->from(route('beneficiaries.create'))->post(route('beneficiaries.store'), $this->payload([
            'caseworker_id' => (string) $caseworker->id, 'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('conflict.jpg'),
        ]))->assertRedirect(route('beneficiaries.create'))->assertSessionHasErrors('nfc_uid')
            ->assertSessionHasInput('caseworker_id', (string) $caseworker->id)->assertSessionHasInput('nfc_uid', '0027947780')->assertSessionMissing('success');
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertSame($before, $owner->refresh()->getRawOriginal());
        $this->assertSame($usersBefore, $this->snapshot('users'));
        $this->assertSame(0, $caseworker->assignedBeneficiaries()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    #[DataProvider('archivedOwners')]
    public function test_nfc_claim_between_validation_and_saving_rejects_the_entire_new_registration(bool $archived): void
    {
        Storage::fake('local');
        $this->director();
        $caseworker = $this->caseworker();
        $owner = $this->record();
        if ($archived) {
            $owner->delete();
        }
        $initialLevel = DB::transactionLevel();
        DB::connection()->beforeStartingTransaction(function ($connection) use ($owner, $initialLevel) {
            if ($connection->transactionLevel() === $initialLevel) {
                DB::table('beneficiaries')->where('id', $owner->id)->update(['nfc_uid' => '0027947780']);
            }
        });
        $this->postJson(route('beneficiaries.store'), $this->payload([
            'caseworker_id' => $caseworker->id, 'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('claim.jpg'),
        ]))->assertUnprocessable()->assertJsonPath('errors.nfc_uid.0', NfcCardUid::OWNERSHIP_ERROR);
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertSame('0027947780', $owner->refresh()->nfc_uid);
        $this->assertSame(0, $caseworker->assignedBeneficiaries()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    public static function archivedOwners(): array
    {
        return [[false], [true]];
    }

    public function test_failure_after_insert_rolls_back_beneficiary_assignment_card_and_private_photo(): void
    {
        Storage::fake('local');
        $this->director();
        $caseworker = $this->caseworker();
        Beneficiary::created(function () {
            throw new RuntimeException('Simulated failure after registration insertion.');
        });
        $this->postJson(route('beneficiaries.store'), $this->payload([
            'caseworker_id' => $caseworker->id, 'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('rollback.jpg'),
        ]))->assertServerError();
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertSame(0, $caseworker->assignedBeneficiaries()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    private function director(): User
    {
        $director = User::factory()->create(['role' => 'project_director']);
        $this->actingAs($director);

        return $director;
    }

    private function caseworker(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'caseworker']);
    }

    private function payload(array $attributes = []): array
    {
        return $attributes + ['beneficiary_id' => '000007', 'full_name' => 'New Assigned Beneficiary', 'registered_at' => '2026-10-07'];
    }

    private function record(array $attributes = []): Beneficiary
    {
        $record = new Beneficiary;
        $record->forceFill($attributes + ['beneficiary_id' => 'EXISTING-OWNER', 'full_name' => 'Existing Beneficiary',
            'registered_at' => '2026-10-07', 'status' => 'active'])->save();

        return $record->refresh();
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function selectedValue(string $html): string
    {
        return $this->xpath($html)->query('//select[@name="caseworker_id"]/option[@selected]')->item(0)->getAttribute('value');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
