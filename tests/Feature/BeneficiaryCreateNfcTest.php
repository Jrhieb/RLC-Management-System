<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use App\Policies\BeneficiaryPolicy;
use App\Support\NfcCardUid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryCreateNfcTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Beneficiary creation NFC tests require the isolated SQLite :memory: test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[DataProvider('supportedIdentifiers')]
    public function test_check_only_returns_a_pending_identifier_and_creation_saves_it_atomically(string $input, string $expected): void
    {
        $this->authorize();
        $this->postJson(route('beneficiaries.nfc.check'), [
            'nfc_uid' => $input, 'confirm_replace' => true, 'check_only' => false,
        ])->assertOk()->assertExactJson(['status' => 'ready', 'message' => 'Card ready to link', 'nfc_uid' => $expected]);
        $this->assertDatabaseCount('beneficiaries', 0);

        $beforeLevel = DB::transactionLevel();
        Beneficiary::creating(function (Beneficiary $record) use ($beforeLevel, $expected) {
            $this->assertGreaterThan($beforeLevel, DB::transactionLevel());
            $this->assertSame($expected, $record->nfc_uid);
        });

        $this->post(route('beneficiaries.store'), $this->payload(['nfc_uid' => $input]))
            ->assertRedirect(route('beneficiaries.show', 1))->assertSessionHas('success', 'Beneficiary registered successfully.');
        $this->assertDatabaseCount('beneficiaries', 1);
        $beneficiary = Beneficiary::sole();
        $this->assertSame($expected, $beneficiary->nfc_uid);
        $this->assertSame('active', $beneficiary->status);
        $this->assertSame('NFC Create Test', $beneficiary->full_name);
    }

    #[DataProvider('noCardPayloads')]
    public function test_registration_still_works_without_a_card(array $card): void
    {
        $this->authorize();
        $this->post(route('beneficiaries.store'), $this->payload($card))
            ->assertRedirect(route('beneficiaries.show', 1))->assertSessionHas('success', 'Beneficiary registered successfully.');
        $this->assertNull(Beneficiary::sole()->nfc_uid);
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_invalid_reader_identifiers_cannot_be_checked_or_saved(mixed $input): void
    {
        $this->authorize();
        $this->postJson(route('beneficiaries.nfc.check'), ['nfc_uid' => $input])
            ->assertUnprocessable()->assertJsonValidationErrors('nfc_uid');
        $this->postJson(route('beneficiaries.store'), $this->payload(['nfc_uid' => $input]))
            ->assertUnprocessable()->assertJsonValidationErrors('nfc_uid');
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    #[DataProvider('archivedOwners')]
    public function test_owned_cards_are_rejected_during_check_and_save_including_archived_records(bool $archived): void
    {
        $this->authorize();
        $owner = $this->owner('0027947780', $archived);
        $before = $owner->getRawOriginal();

        $this->postJson(route('beneficiaries.nfc.check'), ['nfc_uid' => '0027947780'])
            ->assertUnprocessable()->assertJsonPath('errors.nfc_uid.0', NfcCardUid::OWNERSHIP_ERROR);
        $this->postJson(route('beneficiaries.store'), $this->payload(['nfc_uid' => '0027947780']))
            ->assertUnprocessable()->assertJsonPath('errors.nfc_uid.0', NfcCardUid::OWNERSHIP_ERROR);
        $this->assertSame($before, $owner->refresh()->getRawOriginal());
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public function test_card_claimed_after_scanning_is_rejected_at_submission_and_retained_for_correction(): void
    {
        $this->authorize();
        $this->postJson(route('beneficiaries.nfc.check'), ['nfc_uid' => '0027947780'])->assertOk();
        $owner = $this->owner('0027947780');
        $before = $owner->getRawOriginal();

        $this->from(route('beneficiaries.create'))->post(route('beneficiaries.store'), $this->payload(['nfc_uid' => '0027947780']))
            ->assertRedirect(route('beneficiaries.create'))->assertSessionHasErrors('nfc_uid')
            ->assertSessionHasInput('nfc_uid', '0027947780')->assertSessionMissing('success');
        // Keep the browser's session cookie across the redirect in this multi-request test.
        $response = $this->withCookie(config('session.cookie'), session()->getId())
            ->get(route('beneficiaries.create'))->assertOk();
        $this->assertSame(NfcCardUid::OWNERSHIP_ERROR, $response->viewData('errors')->first('nfc_uid'));
        $response->assertSee(NfcCardUid::OWNERSHIP_ERROR)->assertSee('data-nfc-pending-input value="0027947780"', false);
        $this->assertSame($before, $owner->refresh()->getRawOriginal());
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    public function test_pending_card_survives_other_field_validation_errors_and_can_be_saved_after_correction(): void
    {
        $this->authorize();
        $this->from(route('beneficiaries.create'))->post(route('beneficiaries.store'), $this->payload([
            'full_name' => '', 'nfc_uid' => '0027947780',
        ]))->assertRedirect(route('beneficiaries.create'))->assertSessionHasErrors('full_name')
            ->assertSessionHasInput('nfc_uid', '0027947780')->assertSessionMissing('success');
        $this->assertDatabaseCount('beneficiaries', 0);

        $this->withCookie(config('session.cookie'), session()->getId())->get(route('beneficiaries.create'))
            ->assertOk()->assertSee('Card ready to link')->assertSee('The full name field is required.')
            ->assertSee('data-nfc-pending-input value="0027947780"', false)->assertSee('Remove card');
        $this->post(route('beneficiaries.store'), $this->payload(['nfc_uid' => '0027947780']))
            ->assertRedirect(route('beneficiaries.show', 1));
        $this->assertSame('0027947780', Beneficiary::sole()->nfc_uid);
    }

    public function test_availability_is_rechecked_inside_the_save_transaction_and_failed_photo_is_cleaned_up(): void
    {
        Storage::fake('local');
        $this->authorize();
        $owner = $this->owner(null);
        $initialLevel = DB::transactionLevel();
        $hook = function ($connection) use ($owner, $initialLevel) {
            if ($connection->transactionLevel() === $initialLevel) {
                // Simulate a claim after form validation but before the save transaction.
                DB::table('beneficiaries')->where('id', $owner->id)->update(['nfc_uid' => '0027947780']);
            }
        };
        DB::connection()->beforeStartingTransaction($hook);

        $this->postJson(route('beneficiaries.store'), $this->payload([
            'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('nfc-create.jpg'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('nfc_uid');
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertSame('0027947780', $owner->refresh()->nfc_uid);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    public function test_actual_database_unique_constraint_conflict_rolls_back_creation_and_cleans_up_photo(): void
    {
        Storage::fake('local');
        $this->authorize();
        $owner = $this->owner(null);
        $before = $owner->getRawOriginal();
        Beneficiary::creating(function (Beneficiary $record) use ($owner) {
            DB::table('beneficiaries')->where('id', $owner->id)->update(['nfc_uid' => $record->nfc_uid]);
        });

        $this->from(route('beneficiaries.create'))->post(route('beneficiaries.store'), $this->payload([
            'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('nfc-conflict.jpg'),
        ]))->assertRedirect(route('beneficiaries.create'))->assertSessionHasErrors('nfc_uid')
            ->assertSessionHasInput('nfc_uid', '0027947780')->assertSessionMissing('success');
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertSame($before, $owner->refresh()->getRawOriginal());
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    public function test_failure_after_inserting_beneficiary_rolls_back_beneficiary_card_and_photo(): void
    {
        Storage::fake('local');
        $this->authorize();
        Beneficiary::created(function () {
            throw new RuntimeException('Simulated failure after insertion.');
        });

        $this->postJson(route('beneficiaries.store'), $this->payload([
            'nfc_uid' => '0027947780', 'photo' => UploadedFile::fake()->image('nfc-rollback.jpg'),
        ]))->assertServerError()->assertJsonMissing(['status' => 'registered']);
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
    }

    public function test_cancelled_model_save_cannot_report_registration_success(): void
    {
        $this->authorize();
        Beneficiary::creating(fn () => false);
        $this->postJson(route('beneficiaries.store'), $this->payload(['nfc_uid' => '0027947780']))->assertServerError();
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    #[DataProvider('accessCases')]
    public function test_check_and_creation_use_existing_authentication_verification_and_create_permission(?string $role, bool $verified, int $status): void
    {
        if ($role !== null) {
            $this->actingAs(User::factory()->create(['role' => $role, 'email_verified_at' => $verified ? now() : null]));
        }
        $this->postJson(route('beneficiaries.nfc.check'), ['nfc_uid' => '0027947780'])->assertStatus($status);
        $this->postJson(route('beneficiaries.store'), $this->payload(['nfc_uid' => '0027947780']))
            ->assertStatus($status === 200 ? 302 : $status);
        $this->assertDatabaseCount('beneficiaries', $status === 200 ? 1 : 0);
    }

    public function test_create_permission_is_used_instead_of_existing_profile_update_permission(): void
    {
        $this->authorize();
        $policy = new class extends BeneficiaryPolicy
        {
            public function create(User $user): bool
            {
                return false;
            }
        };
        $this->app->instance($policy::class, $policy);
        Gate::policy(Beneficiary::class, $policy::class);
        $this->postJson(route('beneficiaries.nfc.check'), ['nfc_uid' => '0027947780'])->assertForbidden();
        $this->postJson(route('beneficiaries.store'), $this->payload(['nfc_uid' => '0027947780']))->assertForbidden();
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_csrf_is_required_for_check_and_form_submission(): void
    {
        $this->authorize();
        $this->app['env'] = 'csrf-check';
        try {
            $this->postJson(route('beneficiaries.nfc.check'), ['nfc_uid' => '0027947780'])->assertStatus(419);
            $this->postJson(route('beneficiaries.store'), $this->payload(['nfc_uid' => '0027947780']))->assertStatus(419);
            $this->assertDatabaseCount('beneficiaries', 0);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_create_view_has_optional_pending_card_and_a_separate_scan_form(): void
    {
        $this->authorize();
        $response = $this->get(route('beneficiaries.create'))->assertOk()
            ->assertSee('Register NFC Card')->assertSee('data-nfc-remove', false)
            ->assertSee('data-beneficiary-create-form', false)->assertSee('data-nfc-pending-input', false)
            ->assertSee(route('beneficiaries.nfc.check'))->assertSee('The card will be linked when you save this beneficiary.')
            ->assertSee('Waiting for card…')->assertSee('Enter card manually');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertCount(1, $xpath->query('//*[@data-nfc-scan-form]//input[@data-nfc-input and @type="text" and @class="sr-only" and not(@hidden)]'));
        $this->assertCount(1, $xpath->query('//*[@data-nfc-manual-fields and @hidden]//input[@data-nfc-manual-input]'));
        $this->assertCount(1, $xpath->query('//*[@data-nfc-manual-fields and @hidden]//button[@type="submit"]'));
    }

    public static function supportedIdentifiers(): array
    {
        return BeneficiaryNfcRegistrationTest::supportedIdentifiers();
    }

    public static function invalidIdentifiers(): array
    {
        return [[27947780], [true], [['0027947780']], ['123456789'], ['12345678901'],
            ['00A7947780'], ['GG123456'], ['04_A1_B2_C3'], [str_repeat('0', 65)]];
    }

    public static function noCardPayloads(): array
    {
        return ['omitted' => [[]], 'blank' => [['nfc_uid' => '']], 'null' => [['nfc_uid' => null]]];
    }

    public static function archivedOwners(): array
    {
        return ['active' => [false], 'archived' => [true]];
    }

    public static function accessCases(): array
    {
        return [[null, false, 401], ['admin', true, 403], ['caseworker', false, 403],
            ['caseworker', true, 403], ['project_director', false, 403], ['project_director', true, 200]];
    }

    private function authorize(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'project_director']));
    }

    private function payload(array $attributes = []): array
    {
        return $attributes + [
            'beneficiary_id' => 'NFC-CREATE-TEST', 'full_name' => 'NFC Create Test', 'registered_at' => '2026-10-07',
        ];
    }

    private function owner(?string $uid, bool $archived = false): Beneficiary
    {
        $record = Beneficiary::create([
            'beneficiary_id' => 'NFC-OWNER-TEST', 'full_name' => 'Existing card owner',
            'registered_at' => '2026-10-07', 'status' => 'active',
        ]);
        $record->forceFill(['nfc_uid' => $uid])->save();
        if ($archived) {
            $record->delete();
        }

        return $record->refresh();
    }
}
