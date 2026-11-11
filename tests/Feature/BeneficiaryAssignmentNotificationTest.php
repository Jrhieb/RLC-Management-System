<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\Beneficiary;
use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BeneficiaryAssignmentNotificationTest extends TestCase
{
    private User $director;

    private int $recordNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // TestCase rejects non-memory databases before this migration call.
        // Real commit boundaries also verify that assignment mail is never queued.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(fn () => DB::purge('sqlite'));
        $this->withoutVite();
        $this->travelTo(now()->startOfSecond());
        $this->director = User::factory()->create(['role' => 'project_director']);
    }

    #[DataProvider('selectionSizes')]
    public function test_row_and_bulk_assignment_notify_only_the_receiving_caseworker(int $size): void
    {
        $recipient = User::factory()->create();
        $unaffected = User::factory()->create();
        $records = array_map(fn ($number) => $this->record(null, ['status' => $number % 2 ? 'active' : 'inactive']), range(1, $size));
        $this->record($unaffected);
        $level = DB::transactionLevel();
        $insertLevels = [];
        AccountNotification::creating(function () use (&$insertLevels) {
            $insertLevels[] = DB::transactionLevel();
        });

        $this->assign($records, $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 1);
        $this->notice($recipient, $size, 0);
        $this->assertCount(1, $insertLevels);
        $this->assertGreaterThan($level, $insertLevels[0]);
        $this->assertSame($level, DB::transactionLevel());
        $this->actingAs($recipient)->getJson(route('account.notifications.index'))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('unread_count', 1)
            ->assertJsonPath('items.0.title', 'Caseload updated')
            ->assertJsonPath('items.0.message', 'Your caseload changed: '.$size.' added, 0 removed.')
            ->assertJsonPath('items.0.url', '/my-caseload');
        $this->actingAs($unaffected)->getJson(route('account.notifications.count'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->actingAs($this->director)->getJson(route('account.notifications.count'))->assertOk()->assertJsonPath('unread_count', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    #[DataProvider('selectionSizes')]
    public function test_row_and_bulk_reassignment_notify_previous_and_receiving_caseworkers(int $size): void
    {
        $previous = User::factory()->create();
        $recipient = User::factory()->create();
        $records = array_map(fn () => $this->record($previous), range(1, $size));
        $this->assign($records, $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 2);
        $this->notice($previous, 0, $size);
        $this->notice($recipient, $size, 0);
        $this->assertSame(1, AccountNotification::pluck('event_key')->unique()->count());
        foreach ($records as $record) {
            $this->assertSame($recipient->id, $record->fresh()->caseworker_id);
        }
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_mixed_bulk_changes_are_aggregated_per_recipient_and_exclude_unchanged_records(): void
    {
        $previous = User::factory()->create();
        $another = User::factory()->create();
        $recipient = User::factory()->create();
        $unchanged = $this->record($recipient);
        $unselected = $this->record($previous);
        $records = [$this->record($previous), $this->record($previous), $this->record($another), $this->record(), $unchanged];
        $before = $unchanged->getRawOriginal();
        $unselectedBefore = $unselected->getRawOriginal();
        $this->travel(1)->minutes();
        $this->assign($records, $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 3);
        $this->notice($previous, 0, 2);
        $this->notice($another, 0, 1);
        $this->notice($recipient, 4, 0);
        $this->assertSame($before, $unchanged->fresh()->getRawOriginal());
        $this->assertSame($unselectedBefore, $unselected->fresh()->getRawOriginal());
        $notifications = DB::table('account_notifications')->orderBy('id')->get()->toJson();
        $this->assign(array_reverse($records), $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($notifications, DB::table('account_notifications')->orderBy('id')->get()->toJson());
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('selectionSizes')]
    public function test_row_and_bulk_unassignment_notify_removals_only_and_repeated_requests_are_noops(int $size): void
    {
        $previous = User::factory()->create();
        $records = array_map(fn () => $this->record($previous), range(1, $size));
        $alreadyUnassigned = $this->record();
        $before = $alreadyUnassigned->getRawOriginal();
        $this->travel(1)->minutes();
        $this->assign([...$records, $alreadyUnassigned])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 1);
        $notification = $this->notice($previous, 0, $size);
        $this->assertSame($before, $alreadyUnassigned->fresh()->getRawOriginal());
        $this->assign($records)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 1);
        $this->assertSame($notification->getRawOriginal(), $notification->fresh()->getRawOriginal());
        foreach ($records as $record) {
            $this->assertNull($record->fresh()->caseworker_id);
        }
        Mail::assertNothingOutgoing();
    }

    public static function selectionSizes(): array
    {
        return ['row' => [1], 'bulk' => [3]];
    }

    public function test_unchanged_assignments_neither_save_records_nor_create_notifications(): void
    {
        $recipient = User::factory()->create();
        $assigned = $this->record($recipient);
        $unassigned = $this->record();
        $before = DB::table('beneficiaries')->orderBy('id')->get()->toJson();
        $saves = 0;
        Beneficiary::saving(function () use (&$saves) {
            $saves++;
        });
        $this->travel(1)->minutes();
        $this->assign([$assigned], $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assign([$unassigned])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, $saves);
        $this->assertSame($before, DB::table('beneficiaries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('account_notifications', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_later_actual_changes_create_new_operations_but_repeated_submissions_preserve_read_state(): void
    {
        $recipient = User::factory()->create();
        $record = $this->record();
        $this->assign([$record], $recipient)->assertRedirect();
        $notification = $this->notice($recipient, 1, 0);
        $this->actingAs($recipient)->postJson(route('account.notifications.read', $notification))->assertOk();
        $readAt = $notification->fresh()->read_at;
        $this->assign([$record], $recipient)->assertRedirect();
        $this->assertDatabaseCount('account_notifications', 1);
        $this->assertTrue($readAt->equalTo($notification->fresh()->read_at));
        $this->assign([$record])->assertRedirect();
        $this->assertDatabaseCount('account_notifications', 2);
        $this->assertSame(2, AccountNotification::pluck('event_key')->unique()->count());
        $this->actingAs($recipient)->getJson(route('account.notifications.count'))->assertOk()->assertJsonPath('unread_count', 1);
        Mail::assertNothingOutgoing();
    }

    public function test_creation_assignment_notifies_once_without_storing_beneficiary_details_and_duplicate_creation_does_not_notify(): void
    {
        Storage::fake('local');
        $recipient = User::factory()->create();
        $payload = ['beneficiary_id' => '000PRIVATE001', 'full_name' => 'Private Beneficiary Name',
            'registered_at' => '2026-10-01', 'caseworker_id' => $recipient->id, 'address' => 'Private beneficiary address',
            'guardian_name' => 'Private Guardian Name', 'guardian_contact' => 'private-contact', 'birthdate' => '2010-03-03',
            'recipient_id' => $this->director->id, 'added_count' => 99, 'removed_count' => 99];
        $level = DB::transactionLevel();
        AccountNotification::creating(function () use ($level) {
            $this->assertGreaterThan($level, DB::transactionLevel());
        });
        $this->actingAs($this->director)->post(route('beneficiaries.store'), $payload + ['photo' => UploadedFile::fake()->image('private.jpg')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $record = Beneficiary::sole();
        $notification = $this->notice($recipient, 1, 0);
        $this->assertSame($recipient->id, $record->caseworker_id);
        $this->assertSame('000PRIVATE001', $record->beneficiary_id);
        $this->assertEqualsCanonicalizing(['id', 'recipient_id', 'account_id', 'kind', 'event_key', 'added_count', 'removed_count',
            'read_at', 'created_at', 'updated_at'], array_keys($notification->fresh()->getAttributes()));
        $json = json_encode($this->actingAs($recipient)->getJson(route('account.notifications.index'))->assertOk()->json(), JSON_UNESCAPED_SLASHES);
        $stored = json_encode($notification->toArray(), JSON_UNESCAPED_SLASHES);
        foreach ([$record->full_name, $record->beneficiary_id, $record->photo_path, $record->address,
            $record->guardian_name, $record->guardian_contact, '2010-03-03'] as $detail) {
            $this->assertStringNotContainsString($detail, $stored);
            $this->assertStringNotContainsString($detail, $json);
        }
        $this->actingAs($this->director)->post(route('beneficiaries.store'), $payload)->assertSessionHasErrors('beneficiary_id');
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('account_notifications', 1);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_unassigned_director_creation_and_denied_caseworker_creation_do_not_notify(): void
    {
        $recipient = User::factory()->create();
        $this->actingAs($this->director)->post(route('beneficiaries.store'), $this->creationPayload() + ['caseworker_id' => null])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($recipient)->post(route('beneficiaries.store'), $this->creationPayload('000SECOND'))
            ->assertForbidden();
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->postJson(route('beneficiaries.store'), $this->creationPayload('000DENIED') + ['caseworker_id' => $recipient->id])->assertForbidden();
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('account_notifications', 0);
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('ineligibleRecipients')]
    public function test_ineligible_receiving_caseworkers_cannot_be_assigned_or_notified(string $status, bool $verified): void
    {
        $recipient = User::factory()->create(['approval_status' => $status, 'email_verified_at' => $verified ? now() : null]);
        $record = $this->record();
        $this->assign([$record], $recipient)->assertSessionHasErrors('caseworker_id', null, 'assignCaseworker');
        $this->actingAs($this->director)->post(route('beneficiaries.store'), $this->creationPayload() + ['caseworker_id' => $recipient->id])
            ->assertSessionHasErrors('caseworker_id');
        $this->assertNull($record->fresh()->caseworker_id);
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('account_notifications', 0);
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('ineligibleRecipients')]
    public function test_previous_restricted_caseworkers_receive_stored_removals_but_cannot_read_them_until_eligible(string $status, bool $verified): void
    {
        $previous = User::factory()->create(['approval_status' => $status, 'email_verified_at' => $verified ? now() : null]);
        $record = $this->record($previous);
        $this->assign([$record])->assertRedirect()->assertSessionHasNoErrors();
        $notification = $this->notice($previous, 0, 1);
        $this->actingAs($previous)->getJson(route('account.notifications.index'))->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('unread_count', 0);
        $this->postJson(route('account.notifications.read', $notification))->assertNotFound();
        $this->postJson(route('account.notifications.read-all'))->assertOk();
        $this->assertNull($notification->fresh()->read_at);
        $this->getJson('/my-caseload')->assertForbidden();
        $previous->forceFill(['approval_status' => 'approved', 'email_verified_at' => now()])->save();
        $this->actingAs($previous)->getJson(route('account.notifications.index'))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('unread_count', 1);
        $this->get('/my-caseload')->assertOk()->assertSee('No assigned beneficiaries yet.');
    }

    public static function ineligibleRecipients(): array
    {
        return ['pending' => ['pending', true], 'rejected' => ['rejected', true], 'unverified' => ['approved', false]];
    }

    public function test_notification_links_open_the_authenticated_recipients_caseload_even_after_removal_and_archival(): void
    {
        $previous = User::factory()->create();
        $recipient = User::factory()->create();
        $record = $this->record($previous, ['full_name' => 'Transferred Profile']);
        $retained = $this->record($previous, ['full_name' => 'Retained Profile']);
        $this->assign([$record], $recipient)->assertRedirect();
        foreach ([$previous, $recipient] as $viewer) {
            $list = $this->actingAs($viewer)->getJson(route('account.notifications.index'))->assertOk()->json('items');
            $this->assertSame('/my-caseload', $list[0]['url']);
            $this->postJson($list[0]['read_url'])->assertOk()->assertJsonPath('unread_count', 0);
            $this->get($list[0]['url'].'?caseworker_id='.$recipient->id.'&caseworker='.$recipient->id)
                ->assertOk()->assertViewHas('beneficiaries', fn ($records) => $records->pluck('id')->all() === [$viewer->is($previous) ? $retained->id : $record->id]);
        }
        $record->delete();
        $this->actingAs($recipient)->get('/my-caseload')->assertOk()->assertSee('No assigned beneficiaries yet.');
        $this->getJson(route('account.notifications.index'))->assertOk()->assertJsonPath('items.0.url', '/my-caseload');
        $this->actingAs($previous)->get(route('beneficiaries.show', $retained))->assertOk();
        $this->get(route('beneficiaries.index'))->assertOk();
    }

    public function test_assignment_notification_list_count_read_and_read_all_are_owned_and_legacy_account_notices_still_work(): void
    {
        $previous = User::factory()->create();
        $recipient = User::factory()->create();
        $this->assign([$this->record($previous)], $recipient)->assertRedirect();
        $own = $this->notice($recipient, 1, 0);
        $other = $this->notice($previous, 0, 1);
        $decision = AccountNotification::create(['recipient_id' => $recipient->id, 'account_id' => $recipient->id,
            'kind' => AccountNotification::APPROVED, 'event_key' => 'decision:'.$recipient->id]);
        $invalid = AccountNotification::create(['recipient_id' => $recipient->id, 'account_id' => $previous->id,
            'kind' => AccountNotification::ASSIGNMENT, 'event_key' => 'fixture:'.Str::uuid(), 'added_count' => 9]);
        $this->actingAs($recipient)->getJson(route('account.notifications.index', ['recipient_id' => $previous->id, 'account_id' => $previous->id]))
            ->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('unread_count', 2)->assertDontSee($other->id)->assertDontSee($invalid->id);
        $this->postJson(route('account.notifications.read', $other))->assertNotFound();
        $this->postJson(route('account.notifications.read', $invalid))->assertNotFound();
        $this->postJson(route('account.notifications.read', $own))->assertOk()->assertJsonPath('unread_count', 1);
        $readAt = $own->fresh()->read_at;
        $this->travel(1)->minutes();
        $this->postJson(route('account.notifications.read', $own))->assertOk()->assertJsonPath('unread_count', 1);
        $this->assertTrue($readAt->equalTo($own->fresh()->read_at));
        $this->postJson(route('account.notifications.read-all'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertNotNull($decision->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
        $this->assertNull($invalid->fresh()->read_at);
    }

    public function test_the_existing_hundred_record_limit_is_preserved_and_a_full_bulk_creates_one_notice(): void
    {
        $recipient = User::factory()->create();
        $records = array_map(fn () => $this->record(), range(1, 101));
        $this->assign($records, $recipient)->assertSessionHasErrors('beneficiary_ids', null, 'assignCaseworker');
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame(0, Beneficiary::whereNotNull('caseworker_id')->count());
        $this->assign(array_slice($records, 0, 100), $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 1);
        $this->notice($recipient, 100, 0);
        $this->assertSame(100, Beneficiary::where('caseworker_id', $recipient->id)->count());
        $this->assertNull(end($records)->fresh()->caseworker_id);
    }

    public function test_a_failed_beneficiary_save_rolls_back_all_assignments_and_leaves_no_notifications(): void
    {
        $recipient = User::factory()->create();
        $first = $this->record();
        $second = $this->record();
        $fail = true;
        Beneficiary::saving(function (Beneficiary $record) use ($second, &$fail) {
            return ! $fail || $record->id !== $second->id;
        });
        $this->assign([$first, $second], $recipient)->assertSessionHasErrors('beneficiary_ids', null, 'assignCaseworker');
        $this->assertNull($first->fresh()->caseworker_id);
        $this->assertNull($second->fresh()->caseworker_id);
        $this->assertDatabaseCount('account_notifications', 0);
        $fail = false;
        $this->assign([$first, $second], $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($recipient->id, $first->fresh()->caseworker_id);
        $this->assertSame($recipient->id, $second->fresh()->caseworker_id);
        $this->notice($recipient, 2, 0);
        $this->assign([$first, $second], $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 1);
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('notificationFailures')]
    public function test_failure_on_the_second_notification_rolls_back_the_first_notice_and_assignment(bool $cancel): void
    {
        $previous = User::factory()->create();
        $recipient = User::factory()->create();
        $record = $this->record($previous);
        $inserts = 0;
        AccountNotification::creating(function () use (&$inserts, $cancel) {
            $this->assertGreaterThan(0, DB::transactionLevel());
            if (++$inserts === 2) {
                if ($cancel) {
                    return false;
                }
                throw new RuntimeException('Synthetic notification storage failure.');
            }

            return true;
        });
        $this->withoutExceptionHandling();
        try {
            $this->assign([$record], $recipient);
            $this->fail('Notification storage failure must fail the assignment.');
        } catch (RuntimeException $error) {
            $this->assertSame($cancel ? 'Assignment notification could not be stored.' : 'Synthetic notification storage failure.', $error->getMessage());
        }
        $this->assertSame(2, $inserts);
        $this->assertSame($previous->id, $record->fresh()->caseworker_id);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame(0, DB::transactionLevel());
        $this->assign([$record], $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($recipient->id, $record->fresh()->caseworker_id);
        $this->notice($previous, 0, 1);
        $this->notice($recipient, 1, 0);
        $this->assign([$record], $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_notifications', 2);
        Mail::assertNothingOutgoing();
    }

    public static function notificationFailures(): array
    {
        return ['exception' => [false], 'cancelled insert' => [true]];
    }

    public function test_creation_notification_failure_rolls_back_the_beneficiary_and_cleans_up_its_uploaded_photo(): void
    {
        Storage::fake('local');
        $recipient = User::factory()->create();
        AccountNotification::creating(fn () => throw new RuntimeException('Synthetic creation notice failure.'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->director)->post(route('beneficiaries.store'), $this->creationPayload() + [
                'caseworker_id' => $recipient->id, 'photo' => UploadedFile::fake()->image('private.jpg'),
            ]);
            $this->fail('Notification failure must fail beneficiary creation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic creation notice failure.', $error->getMessage());
        }
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
        Mail::assertNothingOutgoing();
    }

    #[DataProvider('assignmentPaths')]
    public function test_outer_transaction_rollback_discards_assignments_and_notifications_on_both_paths(bool $creating): void
    {
        $previous = User::factory()->create();
        $recipient = User::factory()->create();
        $record = $this->record($previous);
        $before = DB::table('beneficiaries')->get()->toJson();
        try {
            DB::transaction(function () use ($creating, $record, $recipient) {
                if ($creating) {
                    $this->actingAs($this->director)->post(route('beneficiaries.store'), $this->creationPayload() + ['caseworker_id' => $recipient->id])
                        ->assertRedirect()->assertSessionHasNoErrors();
                } else {
                    $this->assign([$record], $recipient)->assertRedirect()->assertSessionHasNoErrors();
                }
                $this->assertDatabaseCount('account_notifications', $creating ? 1 : 2);
                throw new RuntimeException('Synthetic outer rollback.');
            });
            $this->fail('Outer transaction must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic outer rollback.', $error->getMessage());
        }
        $this->assertSame($before, DB::table('beneficiaries')->get()->toJson());
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame(0, DB::transactionLevel());
        Mail::assertNothingOutgoing();
    }

    public static function assignmentPaths(): array
    {
        return ['row/bulk endpoint' => [false], 'creation' => [true]];
    }

    public function test_operation_retries_reuse_the_unique_event_and_preserve_the_original_counts_and_read_state(): void
    {
        $recipient = User::factory()->create();
        $operationId = (string) Str::uuid();
        $changes = [$recipient->id => ['added' => 3, 'removed' => 2]];
        $service = app(AccountNotificationService::class);
        DB::transaction(fn () => $service->assignmentsChanged($changes, $operationId));
        $notification = $this->notice($recipient, 3, 2);
        $this->actingAs($recipient)->postJson(route('account.notifications.read', $notification))->assertOk();
        $before = $notification->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        DB::transaction(fn () => $service->assignmentsChanged($changes, $operationId));
        $this->assertDatabaseCount('account_notifications', 1);
        $this->assertSame($before, $notification->fresh()->getRawOriginal());
        Mail::assertNothingOutgoing();
    }

    public function test_the_notification_service_refuses_to_write_outside_an_assignment_transaction(): void
    {
        $recipient = User::factory()->create();
        $this->assertSame(0, DB::transactionLevel());
        try {
            app(AccountNotificationService::class)->assignmentsChanged([$recipient->id => ['added' => 1, 'removed' => 0]], (string) Str::uuid());
            $this->fail('Assignment notification writes require a transaction.');
        } catch (LogicException $error) {
            $this->assertSame('Assignment notifications must be stored inside the assignment transaction.', $error->getMessage());
        }
        $this->assertDatabaseCount('account_notifications', 0);
    }

    public function test_sql_notice_failure_rolls_back_mixed_bulk_changes_and_retry_commits_once_on_the_same_connection(): void
    {
        $previous = User::factory()->create();
        $another = User::factory()->create();
        $recipient = User::factory()->create();
        $first = $this->record($previous);
        $second = $this->record($previous);
        $third = $this->record($another);
        $unassigned = $this->record();
        $unchanged = $this->record($recipient);
        $unselected = $this->record($previous);
        $records = [$first, $second, $third, $unassigned, $unchanged];
        $before = DB::table('beneficiaries')->orderBy('id')->get()->toJson();
        $pdo = DB::connection()->getPdo();
        $insertedRecipients = [];
        Beneficiary::saving(function (Beneficiary $record) use ($pdo) {
            $this->assertSame($pdo, $record->getConnection()->getPdo());
            $this->assertGreaterThan(0, $record->getConnection()->transactionLevel());
        });
        AccountNotification::created(function (AccountNotification $notification) use ($pdo, &$insertedRecipients) {
            $this->assertSame($pdo, $notification->getConnection()->getPdo());
            $this->assertGreaterThan(0, $notification->getConnection()->transactionLevel());
            $insertedRecipients[] = $notification->recipient_id;
        });
        $this->failNoticeInsertFor($recipient);
        $this->withoutExceptionHandling();
        try {
            $this->assign($records, $recipient);
            $this->fail('The database must reject the final notification insert.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic assignment notice SQL failure', $exception->errorInfo[2]);
        } finally {
            DB::unprepared('DROP TRIGGER assignment_notice_failure');
        }
        $this->withExceptionHandling();
        // Both earlier notices actually persisted inside the transaction before
        // the SQL failure; neither may survive its rollback.
        $this->assertSame([$previous->id, $another->id], $insertedRecipients);
        $this->assertSame($before, DB::table('beneficiaries')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame(0, DB::transactionLevel());
        $this->actingAs($previous)->get(route('beneficiaries.show', $first))->assertOk();
        $this->actingAs($recipient)->get(route('beneficiaries.show', $first))->assertForbidden();
        $this->assign($records, $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->notice($previous, 0, 2);
        $this->notice($another, 0, 1);
        $this->notice($recipient, 4, 0);
        $this->assertDatabaseCount('account_notifications', 3);
        $this->assertSame(1, AccountNotification::pluck('event_key')->unique()->count());
        foreach ($records as $record) {
            $this->assertSame($recipient->id, $record->fresh()->caseworker_id);
        }
        $this->assertSame($previous->id, $unselected->fresh()->caseworker_id);
        $this->actingAs($previous)->get(route('beneficiaries.show', $first))->assertForbidden();
        $this->actingAs($recipient)->get(route('beneficiaries.show', $first))->assertOk();
        $notices = DB::table('account_notifications')->orderBy('id')->get()->toJson();
        $this->assign(array_reverse($records), $recipient)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($notices, DB::table('account_notifications')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_creation_sql_notice_failure_cleans_photo_and_card_and_retry_creates_one_assigned_beneficiary(): void
    {
        Storage::fake('local');
        $recipient = User::factory()->create();
        $pdo = DB::connection()->getPdo();
        $inserted = false;
        Beneficiary::created(function (Beneficiary $record) use ($pdo, &$inserted) {
            $inserted = true;
            $this->assertSame($pdo, $record->getConnection()->getPdo());
            $this->assertGreaterThan(0, $record->getConnection()->transactionLevel());
            $this->assertSame('A1B2C3D4', $record->nfc_uid);
        });
        AccountNotification::creating(function (AccountNotification $notification) use ($pdo) {
            $this->assertSame($pdo, $notification->getConnection()->getPdo());
            $this->assertGreaterThan(0, $notification->getConnection()->transactionLevel());
        });
        $payload = $this->creationPayload() + ['caseworker_id' => $recipient->id, 'nfc_uid' => 'A1B2C3D4'];
        $this->failNoticeInsertFor($recipient);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->director)->post(route('beneficiaries.store'), $payload + ['photo' => UploadedFile::fake()->image('rollback.jpg')]);
            $this->fail('A failed SQL notification insert must fail registration.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Synthetic assignment notice SQL failure', $exception->errorInfo[2]);
        } finally {
            DB::unprepared('DROP TRIGGER assignment_notice_failure');
        }
        $this->withExceptionHandling();
        $this->assertTrue($inserted);
        $this->assertDatabaseCount('beneficiaries', 0);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
        $this->assertSame(0, DB::transactionLevel());
        $this->actingAs($this->director)->post(route('beneficiaries.store'), $payload + ['photo' => UploadedFile::fake()->image('retry.jpg')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $record = Beneficiary::sole();
        $this->assertSame($recipient->id, $record->caseworker_id);
        $this->assertSame('A1B2C3D4', $record->nfc_uid);
        $this->assertCount(1, Storage::disk('local')->allFiles('beneficiary-photos'));
        Storage::disk('local')->assertExists($record->photo_path);
        $this->notice($recipient, 1, 0);
        $this->assertDatabaseCount('account_notifications', 1);
        $this->actingAs($recipient)->get(route('beneficiaries.show', $record))->assertOk();
        $this->assertDatabaseCount('jobs', 0);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    #[DataProvider('approvalRevocations')]
    public function test_destination_approval_is_rechecked_at_the_transaction_boundary_on_both_paths(bool $creating, string $status): void
    {
        Storage::fake('local');
        $previous = User::factory()->create();
        $recipient = User::factory()->create();
        $record = $this->record($previous);
        $before = $record->getRawOriginal();
        $changed = false;
        // Single-connection interleaving before the transaction begins, not a
        // simulation of simultaneous multi-process writes or database locking.
        DB::connection()->beforeStartingTransaction(function ($connection) use ($recipient, $status, &$changed) {
            if (! $changed && $connection->transactionLevel() === 0) {
                $changed = true;
                DB::table('users')->where('id', $recipient->id)->update(['approval_status' => $status]);
            }
        });
        if ($creating) {
            $this->actingAs($this->director)->postJson(route('beneficiaries.store'), $this->creationPayload() + [
                'caseworker_id' => $recipient->id, 'nfc_uid' => 'A1B2C3D4', 'photo' => UploadedFile::fake()->image('stale.jpg'),
            ])->assertUnprocessable()->assertJsonValidationErrors('caseworker_id');
        } else {
            $this->actingAs($this->director)->postJson(route('beneficiaries.assign-caseworker'), [
                'beneficiary_ids' => [$record->id], 'caseworker_id' => $recipient->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('caseworker_id');
        }
        $this->assertTrue($changed);
        $this->assertSame($status, $recipient->fresh()->approval_status);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('beneficiary-photos'));
        $this->assertSame(0, DB::transactionLevel());
        $this->actingAs($previous)->get(route('beneficiaries.show', $record))->assertOk();
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public static function approvalRevocations(): array
    {
        return ['bulk pending' => [false, 'pending'], 'bulk rejected' => [false, 'rejected'],
            'creation pending' => [true, 'pending'], 'creation rejected' => [true, 'rejected']];
    }

    public function test_intervening_owner_change_is_read_fresh_and_removal_counts_ignore_stale_client_fields(): void
    {
        $stale = User::factory()->create();
        $current = User::factory()->create();
        $recipient = User::factory()->create();
        $record = $this->record($stale);
        $changed = false;
        DB::connection()->beforeStartingTransaction(function ($connection) use ($record, $current, &$changed) {
            if (! $changed && $connection->transactionLevel() === 0) {
                $changed = true;
                DB::table('beneficiaries')->where('id', $record->id)->update(['caseworker_id' => $current->id]);
            }
        });
        $query = ['search' => 'Private', 'status' => 'active', 'caseworker' => (string) $stale->id, 'page' => '2'];
        $this->actingAs($this->director)->post(route('beneficiaries.assign-caseworker', $query), [
            'beneficiary_ids' => [$record->id], 'caseworker_id' => $recipient->id,
            'previous_caseworker_id' => $stale->id, 'recipient_id' => $stale->id, 'added_count' => 99, 'removed_count' => 99,
        ])->assertRedirect(route('beneficiaries.index', $query))->assertSessionHasNoErrors();
        $this->assertTrue($changed);
        $this->assertSame($recipient->id, $record->fresh()->caseworker_id);
        $this->assertDatabaseMissing('account_notifications', ['recipient_id' => $stale->id]);
        $this->notice($current, 0, 1);
        $this->notice($recipient, 1, 0);
        $this->assertDatabaseCount('account_notifications', 2);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_forged_actor_fields_cannot_authorize_a_caseworker_or_choose_notice_counts(): void
    {
        $actor = User::factory()->create();
        $recipient = User::factory()->create();
        $record = $this->record($actor);
        $payload = ['beneficiary_ids' => [$record->id], 'caseworker_id' => $recipient->id,
            'role' => 'project_director', 'actor_id' => $this->director->id, 'user_id' => $this->director->id,
            'recipient_id' => $this->director->id, 'added_count' => 99, 'removed_count' => 99];
        $this->actingAs($actor)->postJson(route('beneficiaries.assign-caseworker'), $payload)->assertForbidden();
        $this->assertSame($actor->id, $record->fresh()->caseworker_id);
        $this->assertDatabaseCount('account_notifications', 0);
        $payload['role'] = 'caseworker';
        $payload['actor_id'] = $payload['user_id'] = $actor->id;
        $this->actingAs($this->director)->post(route('beneficiaries.assign-caseworker'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->notice($actor, 0, 1);
        $this->notice($recipient, 1, 0);
        $this->assertDatabaseCount('account_notifications', 2);
        $this->assertDatabaseMissing('account_notifications', ['recipient_id' => $this->director->id]);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    private function failNoticeInsertFor(User $recipient): void
    {
        // Only the guarded in-memory schema is changed. No production hook or
        // migration is needed to exercise an actual database write failure.
        DB::unprepared('CREATE TEMP TRIGGER assignment_notice_failure BEFORE INSERT ON account_notifications '
            .'WHEN NEW.recipient_id = '.$recipient->id." BEGIN SELECT RAISE(ABORT, 'Synthetic assignment notice SQL failure'); END");
    }

    private function assign(array $records, ?User $recipient = null): TestResponse
    {
        return $this->actingAs($this->director)->post(route('beneficiaries.assign-caseworker'), [
            'beneficiary_ids' => array_map(fn ($record) => $record->id, $records), 'caseworker_id' => $recipient?->id ?? 'unassign',
            'recipient_id' => $this->director->id, 'added_count' => 99, 'removed_count' => 99,
        ]);
    }

    private function notice(User $recipient, int $added, int $removed): AccountNotification
    {
        $notification = AccountNotification::where('recipient_id', $recipient->id)->where('kind', AccountNotification::ASSIGNMENT)->sole();
        $this->assertSame($recipient->id, $notification->account_id);
        $this->assertSame($added, $notification->added_count);
        $this->assertSame($removed, $notification->removed_count);
        $this->assertNull($notification->read_at);
        $this->assertStringStartsWith('assignment:', $notification->event_key);

        return $notification;
    }

    private function record(?User $caseworker = null, array $attributes = []): Beneficiary
    {
        $number = ++$this->recordNumber;

        return Beneficiary::forceCreate($attributes + ['beneficiary_id' => '000PRIVATE'.$number, 'full_name' => 'Private Beneficiary '.$number,
            'registered_at' => '2026-10-01', 'status' => 'active', 'caseworker_id' => $caseworker?->id])->refresh();
    }

    private function creationPayload(string $beneficiaryId = '000CREATED'): array
    {
        return ['beneficiary_id' => $beneficiaryId, 'full_name' => 'Private Created Beneficiary', 'registered_at' => '2026-10-01'];
    }
}
