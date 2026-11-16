<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryApplication;
use App\Models\User;
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

        return response()->view('applications.show', compact('application', 'caseworkers'))->header('Cache-Control', 'private, no-store');
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
        $data = $request->validate(['findings' => ['required', 'string', 'max:10000']]);
        DB::transaction(function () use ($application, $request, $data): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($record->assigned_caseworker_id === $request->user()->id && $request->user()->isEligibleCaseworker(), 403);
            abort_unless($record->status === 'for_assessment', 409);
            $record->information_needed = $data['findings'];
            $record->status = 'needs_information';
            $record->save();
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'information_needed', 'status' => $record->status, 'created_at' => now()]);
        });

        return back()->with('status', 'Information needed was recorded.');
    }

    public function decide(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('viewAny', BeneficiaryApplication::class);
        $data = $request->validate(['decision' => ['required', 'in:approved,waitlisted,declined']]);
        DB::transaction(function () use ($application, $request, $data): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless($record->status === 'for_decision', 409);
            $record->status = $data['decision'];
            $record->save();
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'decision', 'status' => $record->status, 'created_at' => now()]);
        });

        return back()->with('status', 'Decision recorded. Approval does not enroll the applicant.');
    }
}
