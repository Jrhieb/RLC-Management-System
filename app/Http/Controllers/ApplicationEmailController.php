<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryApplication;
use App\Models\User;
use App\Services\ApplicationEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApplicationEmailController extends Controller
{
    public function appointment(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('view', $application);
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'location_or_method' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:3000'],
            'confirmed' => ['accepted'],
        ]);
        DB::transaction(function () use ($application, $request, $data): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('view', $record);
            $existing = DB::table('application_appointments')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->beneficiary_application_id === $record->id, 409);

                return;
            }
            $scheduled = Carbon::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], 'Asia/Manila');
            $id = DB::table('application_appointments')->insertGetId([
                'beneficiary_application_id' => $record->id, 'actor_id' => $actor->id,
                'request_key' => $data['request_key'], 'scheduled_at' => $scheduled->copy()->utc(),
                'location_or_method' => $data['location_or_method'], 'instructions' => $data['instructions'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $actor->id, 'event' => 'appointment_confirmed', 'status' => $record->status, 'created_at' => now()]);
            $message = 'Your assessment appointment is confirmed for '.$scheduled->format('M d, Y g:i A').' (Philippine time).'."\n\n".$data['location_or_method']."\n\n".$data['instructions'];
            app(ApplicationEmailService::class)->record($record, 'appointment:'.$id, 'appointment', 'Assessment appointment', $message, $actor->id);
        }, attempts: 3);

        return back()->with('status', 'Appointment saved. Email delivery status is shown separately.');
    }

    public function retry(Request $request, BeneficiaryApplication $application, int $email): RedirectResponse
    {
        Gate::authorize('view', $application);
        $claimed = DB::transaction(function () use ($application, $request, $email): bool {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('view', $record);
            $changed = DB::table('application_emails')->where('beneficiary_application_id', $record->id)->where('id', $email)->where('status', 'failed')->update(['status' => 'pending', 'updated_at' => now()]);
            if ($changed) {
                DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $actor->id, 'event' => 'email_retry_requested', 'status' => $record->status, 'created_at' => now()]);
            }

            return (bool) $changed;
        });
        abort_unless($claimed, 409);
        app(ApplicationEmailService::class)->deliver($email);

        return back()->with('status', 'Email retry attempted. Check its status below.');
    }

    public function confirmReceipt(Request $request, BeneficiaryApplication $application, int $email): RedirectResponse
    {
        Gate::authorize('view', $application);
        $request->validate(['recipient_confirmed' => ['accepted']]);
        DB::transaction(function () use ($application, $request, $email): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('view', $record);
            $updated = DB::table('application_emails')->where('beneficiary_application_id', $record->id)->where('id', $email)->where('status', 'accepted')->whereNull('delivery_confirmed_at')->update(['delivery_confirmed_at' => now(), 'updated_at' => now()]);
            abort_unless($updated, 409);
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $actor->id, 'event' => 'email_receipt_confirmed', 'status' => $record->status, 'created_at' => now()]);
        });

        return back()->with('status', 'Recipient-reported receipt recorded.');
    }
}
