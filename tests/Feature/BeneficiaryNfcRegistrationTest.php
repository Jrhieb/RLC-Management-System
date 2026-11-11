<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryNfcRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private int $beneficiaryNumber = 0;

    protected function beforeRefreshingDatabase(): void
    {
        // Run before any migrations or records are written, including with cached configuration.
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('NFC registration tests require the isolated SQLite :memory: test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_check_only_does_not_save_and_registration_preserves_leading_zeros(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary();
        $before = $beneficiary->getRawOriginal();
        $url = route('beneficiaries.nfc.update', $beneficiary);

        $this->putJson($url, ['nfc_uid' => '0027947780', 'check_only' => true])
            ->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('nfc_uid', '0027947780');
        $this->assertSame($before, $beneficiary->refresh()->getRawOriginal());

        $this->putJson($url, ['nfc_uid' => '0027947780'])
            ->assertOk()->assertExactJson([
                'status' => 'registered', 'message' => 'NFC card registered successfully', 'nfc_uid' => '0027947780',
            ]);
        $this->assertSame('0027947780', $beneficiary->refresh()->nfc_uid);
    }

    #[DataProvider('supportedIdentifiers')]
    public function test_supported_identifiers_are_saved_as_normalized_strings(string $input, string $expected): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary();

        $this->putJson(route('beneficiaries.nfc.update', $beneficiary), ['nfc_uid' => $input])
            ->assertOk()->assertJsonPath('status', 'registered')->assertJsonPath('nfc_uid', $expected);
        $this->assertSame($expected, $beneficiary->refresh()->nfc_uid);
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_invalid_identifiers_are_rejected_without_changes(mixed $input): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary();
        $before = $beneficiary->getRawOriginal();

        $this->putJson(route('beneficiaries.nfc.update', $beneficiary), ['nfc_uid' => $input])
            ->assertUnprocessable()->assertJsonValidationErrors('nfc_uid');
        $this->assertSame($before, $beneficiary->refresh()->getRawOriginal());
    }

    public function test_same_card_is_already_registered_without_a_write(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary('0027947780');
        $before = $beneficiary->getRawOriginal();
        Beneficiary::saving(function () {
            throw new RuntimeException('An already registered card must not be saved again.');
        });

        foreach ([true, false] as $checkOnly) {
            $this->putJson(route('beneficiaries.nfc.update', $beneficiary), [
                'nfc_uid' => '0027947780', 'check_only' => $checkOnly,
            ])->assertOk()->assertJsonPath('status', 'already_registered')->assertJsonPath('message', 'Already registered');
        }
        $this->assertSame($before, $beneficiary->refresh()->getRawOriginal());
    }

    #[DataProvider('archivedOwners')]
    public function test_cards_owned_by_other_beneficiaries_cannot_be_transferred(bool $archived): void
    {
        $this->authorize();
        $owner = $this->beneficiary('0027947780');
        if ($archived) {
            $owner->delete();
        }
        $target = $this->beneficiary('04A1B2C3');
        $before = $this->snapshot();

        foreach ([true, false] as $checkOnly) {
            $this->putJson(route('beneficiaries.nfc.update', $target), [
                'nfc_uid' => '0027947780', 'check_only' => $checkOnly,
                'confirm_replace' => true, 'expected_nfc_uid' => $target->nfc_uid,
            ])->assertUnprocessable()->assertJsonValidationErrors('nfc_uid')
                ->assertJsonPath('errors.nfc_uid.0', 'This NFC card is already linked to another beneficiary.');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_replacement_requires_explicit_confirmation_of_the_current_card(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary('04A1B2C3');
        $url = route('beneficiaries.nfc.update', $beneficiary);
        $before = $beneficiary->getRawOriginal();

        foreach ([['check_only' => true], [], ['confirm_replace' => true], ['confirm_replace' => false]] as $options) {
            $this->putJson($url, ['nfc_uid' => '0027947780'] + $options)
                ->assertConflict()->assertJsonPath('status', 'replacement_required')->assertJsonPath('nfc_uid', '04A1B2C3');
            $this->assertSame($before, $beneficiary->refresh()->getRawOriginal());
        }

        $this->putJson($url, [
            'nfc_uid' => '0027947780', 'confirm_replace' => true, 'expected_nfc_uid' => '04A1B2C3', 'check_only' => true,
        ])->assertOk()->assertJsonPath('status', 'ready');
        $this->assertSame($before, $beneficiary->refresh()->getRawOriginal());

        $this->putJson($url, [
            'nfc_uid' => '0027947780', 'confirm_replace' => true, 'expected_nfc_uid' => '04A1B2C3',
        ])->assertOk()->assertJsonPath('status', 'registered');
        $this->assertSame('0027947780', $beneficiary->refresh()->nfc_uid);
    }

    public function test_stale_confirmation_cannot_replace_a_card_changed_by_another_request(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary('04A1B2C3');
        $url = route('beneficiaries.nfc.update', $beneficiary);
        $this->putJson($url, ['nfc_uid' => '0027947780', 'check_only' => true])->assertConflict();
        $beneficiary->forceFill(['nfc_uid' => '04A1B2C4'])->save();

        $this->putJson($url, [
            'nfc_uid' => '0027947780', 'confirm_replace' => true, 'expected_nfc_uid' => '04A1B2C3',
        ])->assertConflict()->assertJsonPath('status', 'replacement_required')->assertJsonPath('nfc_uid', '04A1B2C4');
        $this->assertSame('04A1B2C4', $beneficiary->refresh()->nfc_uid);
    }

    public function test_card_claimed_between_check_and_save_is_rejected(): void
    {
        $this->authorize();
        $target = $this->beneficiary();
        $owner = $this->beneficiary();
        $url = route('beneficiaries.nfc.update', $target);
        $this->putJson($url, ['nfc_uid' => '0027947780', 'check_only' => true])->assertOk();
        $owner->forceFill(['nfc_uid' => '0027947780'])->save();

        $this->putJson($url, ['nfc_uid' => '0027947780'])->assertUnprocessable()->assertJsonValidationErrors('nfc_uid');
        $this->assertNull($target->refresh()->nfc_uid);
        $this->assertSame('0027947780', $owner->refresh()->nfc_uid);
    }

    public function test_database_uniqueness_conflict_during_save_is_reported_without_partial_changes(): void
    {
        $this->authorize();
        $target = $this->beneficiary('04A1B2C3');
        $other = $this->beneficiary();
        $before = $this->snapshot();
        // Cause an actual SQLite unique-index failure after request validation.
        Beneficiary::saving(function (Beneficiary $record) use ($target, $other) {
            if ($record->id === $target->id) {
                DB::table('beneficiaries')->where('id', $other->id)->update(['nfc_uid' => $record->nfc_uid]);
            }
        });

        $this->putJson(route('beneficiaries.nfc.update', $target), [
            'nfc_uid' => '0027947780', 'confirm_replace' => true, 'expected_nfc_uid' => '04A1B2C3',
        ])->assertUnprocessable()->assertJsonValidationErrors('nfc_uid');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failed_save_does_not_report_success_or_change_the_card(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary();
        Beneficiary::saving(fn () => false);

        $this->putJson(route('beneficiaries.nfc.update', $beneficiary), ['nfc_uid' => '0027947780'])
            ->assertServerError()->assertJsonMissing(['status' => 'registered']);
        $this->assertNull($beneficiary->refresh()->nfc_uid);
    }

    #[DataProvider('accessCases')]
    public function test_existing_authentication_verification_and_permissions_are_required(?string $role, bool $verified, int $status): void
    {
        if ($role !== null) {
            $this->actingAs(User::factory()->create([
                'role' => $role, 'email_verified_at' => $verified ? now() : null,
            ]));
        }
        $beneficiary = $this->beneficiary();
        $before = $beneficiary->getRawOriginal();

        $this->putJson(route('beneficiaries.nfc.update', $beneficiary), ['nfc_uid' => '0027947780'])->assertStatus($status);
        if ($status !== 200) {
            $this->assertSame($before, $beneficiary->refresh()->getRawOriginal());
        }
    }

    public function test_archived_beneficiary_cannot_register_a_card(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary('04A1B2C3');
        $beneficiary->delete();
        $before = $this->snapshot();

        $this->putJson(route('beneficiaries.nfc.update', $beneficiary), ['nfc_uid' => '0027947780'])->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_csrf_is_required_outside_the_testing_bypass(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary();
        // The SQLite memory guard has already run. Disable only the CSRF testing bypass.
        $this->app['env'] = 'csrf-check';
        try {
            $this->putJson(route('beneficiaries.nfc.update', $beneficiary), ['nfc_uid' => '0027947780'])
                ->assertStatus(419);
            $this->assertNull($beneficiary->refresh()->nfc_uid);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_profile_popup_uses_existing_routes_and_permission(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary();
        $response = $this->get(route('beneficiaries.show', $beneficiary))->assertOk()
            ->assertSee('data-nfc-registration', false)->assertSee('Register NFC Card')
            ->assertSee('Waiting for card…')->assertSee('Enter card manually')
            ->assertSee(route('beneficiaries.nfc.update', $beneficiary))->assertSee(route('beneficiaries.nfc.destroy', $beneficiary));

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertCount(1, $xpath->query('//*[@data-nfc-scan-form]//input[@data-nfc-input and @type="text" and @class="sr-only" and not(@hidden)]'));
        $this->assertCount(1, $xpath->query('//*[@data-nfc-manual-fields and @hidden]//input[@data-nfc-manual-input]'));
        $this->assertCount(1, $xpath->query('//*[@data-nfc-manual-fields and @hidden]//button[@type="submit"]'));
    }

    public function test_existing_form_requests_still_redirect_after_saving(): void
    {
        $this->authorize();
        $beneficiary = $this->beneficiary();
        $url = route('beneficiaries.nfc.update', $beneficiary);
        $this->put($url, ['nfc_uid' => '0027947780'])->assertRedirect(route('beneficiaries.show', $beneficiary))
            ->assertSessionHas('success', 'NFC card registered successfully');
        $this->put($url, ['nfc_uid' => '04A1B2C3'])->assertSessionHasErrors('nfc_uid');
        $this->assertSame('0027947780', $beneficiary->refresh()->nfc_uid);
    }

    public static function supportedIdentifiers(): array
    {
        return [
            ['0027947780', '0027947780'], ['0000000000', '0000000000'],
            ['00a1b2c3', '00A1B2C3'], [' 04:a1:b2:c3 ', '04A1B2C3'],
            ['04-a1-b2-c3-d4-e5-f6', '04A1B2C3D4E5F6'],
            ['04 a1 b2 c3 d4 e5 f6 07 08 09', '04A1B2C3D4E5F6070809'],
        ];
    }

    public static function invalidIdentifiers(): array
    {
        return [[null], [''], [27947780], [true], [['0027947780']], ['123456789'],
            ['12345678901'], ['00A7947780'], ['GG123456'], ['04_A1_B2_C3'], [str_repeat('0', 65)]];
    }

    public static function archivedOwners(): array
    {
        return ['active owner' => [false], 'archived owner' => [true]];
    }

    public static function accessCases(): array
    {
        return ['guest' => [null, false, 401], 'unverified' => ['caseworker', false, 403],
            'other role' => ['admin', true, 403], 'caseworker' => ['caseworker', true, 200],
            'director' => ['project_director', true, 200]];
    }

    private function authorize(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'caseworker']));
    }

    private function beneficiary(?string $uid = null): Beneficiary
    {
        $number = ++$this->beneficiaryNumber;
        $beneficiary = Beneficiary::create([
            'beneficiary_id' => sprintf('NFC-TEST-%03d', $number), 'full_name' => 'NFC Test Beneficiary '.$number,
            'registered_at' => '2026-10-07', 'status' => 'active',
        ]);
        // NFC behavior fixtures belong to the authenticated synthetic Caseworker.
        if (auth()->user()?->isCaseworker()) {
            $beneficiary->forceFill(['caseworker_id' => auth()->id()])->save();
        }
        if ($uid !== null) {
            $beneficiary->forceFill(['nfc_uid' => $uid])->save();
        }

        return $beneficiary->refresh();
    }

    private function snapshot(): array
    {
        return Beneficiary::withTrashed()->orderBy('id')->get()
            ->map(fn (Beneficiary $record) => $record->getRawOriginal())->all();
    }
}
