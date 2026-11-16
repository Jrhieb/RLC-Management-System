<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryApplication;
use App\Models\User;
use App\Services\ApplicationEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BeneficiaryApplicationController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', BeneficiaryApplication::class);
        $applications = BeneficiaryApplication::query()->latest()->paginate(15);

        return response()->view('applications.index', compact('applications'))->header('Cache-Control', 'private, no-store');
    }

    public function show(BeneficiaryApplication $application): Response
    {
        Gate::authorize('view', $application);

        $caseworkers = User::query()->eligibleCaseworkers()->orderBy('name')->get(['id', 'name']);
        $contacts = DB::table('application_contacts')->leftJoin('users', 'users.id', '=', 'application_contacts.actor_id')
            ->where('application_contacts.beneficiary_application_id', $application->id)
            ->orderByDesc('application_contacts.contact_at')
            ->select('application_contacts.*', 'users.name as actor_name')->get();
        $emails = DB::table('application_emails')->where('beneficiary_application_id', $application->id)->latest()->get();
        $appointments = DB::table('application_appointments')->where('beneficiary_application_id', $application->id)->latest()->get();

        return response()->view('applications.show', compact('application', 'caseworkers', 'contacts', 'emails', 'appointments'))->header('Cache-Control', 'private, no-store');
    }

    public function assigned(Request $request): Response
    {
        abort_unless($request->user()->isEligibleCaseworker(), 403);
        $applications = BeneficiaryApplication::query()->where('assigned_caseworker_id', $request->user()->id)->latest()->paginate(15);

        return response()->view('applications.index', compact('applications'))->header('Cache-Control', 'private, no-store');
    }

    public function assign(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('viewAny', BeneficiaryApplication::class);
        $data = $request->validate(['caseworker_id' => ['required', 'integer']]);
        DB::transaction(function () use ($application, $data, $request): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless(in_array($record->status, ['pending_review', 'under_review', 'for_assessment', 'needs_information'], true), 409);
            $worker = User::query()->eligibleCaseworkers()->lockForUpdate()->findOrFail($data['caseworker_id']);
            $record->assigned_caseworker_id = $worker->id;
            $record->status = 'for_assessment';
            $record->save();
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'assigned', 'status' => $record->status, 'created_at' => now()]);
        });

        return back()->with('status', 'Caseworker assigned.');
    }

    public function assess(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('view', $application);
        abort_unless($request->user()->isEligibleCaseworker(), 403);
        $data = $request->validate(['findings' => ['required', 'string', 'max:10000'], 'recommendation' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($application, $data, $request): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($record->assigned_caseworker_id === $request->user()->id && $request->user()->isEligibleCaseworker(), 403);
            abort_unless(in_array($record->status, ['for_assessment', 'needs_information'], true), 409);
            $record->assessment_findings = $data['findings'];
            $record->assessment_recommendation = $data['recommendation'];
            $record->status = 'for_decision';
            $record->save();
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'assessment_submitted', 'status' => $record->status, 'created_at' => now()]);
        });

        return back()->with('status', 'Assessment submitted for Director decision.');
    }

    public function review(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('viewAny', BeneficiaryApplication::class);
        DB::transaction(function () use ($application, $request): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($record->status === 'pending_review', 409);
            $record->status = 'under_review';
            $record->save();
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'review_started', 'status' => $record->status, 'created_at' => now()]);
        });

        return back()->with('status', 'Application is under review.');
    }

    public function requestInformation(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('view', $application);
        abort_unless($request->user()->isEligibleCaseworker(), 403);
        $data = $request->validate(['findings' => ['required', 'string', 'max:10000'], 'applicant_message' => [$application->preferred_contact === 'email' ? 'required' : 'nullable', 'string', 'max:3000']]);
        DB::transaction(function () use ($application, $request, $data): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($record->assigned_caseworker_id === $request->user()->id && $request->user()->isEligibleCaseworker(), 403);
            abort_unless($record->status === 'for_assessment', 409);
            $record->information_needed = $data['findings'];
            $record->status = 'needs_information';
            $record->save();
            $auditId = DB::table('beneficiary_application_audits')->insertGetId(['beneficiary_application_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'information_needed', 'status' => $record->status, 'created_at' => now()]);
            app(ApplicationEmailService::class)->record($record, 'information:'.$auditId, 'information_needed', 'More information needed', $data['applicant_message'] ?? '', $request->user()->id);
        });

        return back()->with('status', 'Information needed was recorded.');
    }

    public function decide(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('viewAny', BeneficiaryApplication::class);
        $data = $request->validate(['decision' => ['required', 'in:approved,waitlisted,declined'], 'applicant_message' => [$application->preferred_contact === 'email' && in_array($request->input('decision'), ['approved', 'declined'], true) ? 'required' : 'nullable', 'string', 'max:3000']]);
        DB::transaction(function () use ($application, $request, $data): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($record->status === 'for_decision', 409);
            $record->status = $data['decision'];
            $record->save();
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'decision', 'status' => $record->status, 'created_at' => now()]);
            $message = match ($record->status) {
                'approved' => "Your application has been approved for enrollment. Enrollment is not yet complete.\n\n".($data['applicant_message'] ?? ''),
                'waitlisted' => 'Your application has been waitlisted. Acceptance is not guaranteed.',
                'declined' => $data['applicant_message'] ?? '',
            };
            app(ApplicationEmailService::class)->record($record, 'decision:'.$record->status, $record->status, 'Application decision', $message, $request->user()->id);
        });

        return back()->with('status', 'Decision recorded. Approval does not enroll the applicant.');
    }
}
