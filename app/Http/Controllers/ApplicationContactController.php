<?php

namespace App\Http\Controllers;

use App\Models\BeneficiaryApplication;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApplicationContactController extends Controller
{
    public function store(Request $request, BeneficiaryApplication $application): RedirectResponse
    {
        Gate::authorize('view', $application);
        $data = $request->validate([
            'contact_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'method' => ['required', 'in:phone,in_person,manual_email'],
            'outcome' => ['required', 'in:reached,no_answer,unreachable,follow_up_needed'],
            'summary' => ['required', 'string', 'max:1000'],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'private_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($application, $request, $data): void {
            $record = BeneficiaryApplication::query()->lockForUpdate()->findOrFail($application->id);
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            Gate::forUser($actor)->authorize('view', $record);
            DB::table('application_contacts')->insert([
                'beneficiary_application_id' => $record->id,
                'actor_id' => $actor->id,
                'contact_at' => Carbon::createFromFormat('Y-m-d\TH:i', $data['contact_at'], 'Asia/Manila')->utc(),
                'method' => $data['method'],
                'outcome' => $data['outcome'],
                'summary' => $data['summary'],
                'follow_up_on' => $data['follow_up_on'] ?? null,
                'private_notes' => $data['private_notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('beneficiary_application_audits')->insert([
                'beneficiary_application_id' => $record->id,
                'actor_id' => $actor->id,
                'event' => 'contact_recorded',
                'status' => $record->status,
                'created_at' => now(),
            ]);
        }, attempts: 3);

        return back()->with('status', 'Contact recorded.');
    }
}
