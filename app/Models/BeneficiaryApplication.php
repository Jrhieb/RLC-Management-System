<?php

namespace App\Models;

use App\Services\ApplicationEmailService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BeneficiaryApplication extends Model
{
    protected $guarded = ['id'];

    public static function submit(array $data): self
    {
        return DB::transaction(function () use ($data): self {
            $application = new self;
            $application->fill(collect($data)->only(['guardian_name', 'guardian_relationship', 'contact_number', 'preferred_contact', 'email', 'barangay', 'municipality', 'reason', 'submission_key'])->all());
            $application->reference = (string) Str::uuid();
            $application->status = 'pending_review';
            $application->save();
            DB::table('beneficiary_application_audits')->insert(['beneficiary_application_id' => $application->id, 'actor_id' => null, 'event' => 'submitted', 'status' => 'pending_review', 'created_at' => now()]);
            app(ApplicationEmailService::class)->record($application, 'received', 'received', 'Application received', 'Your application has been received and is pending review. Submission does not guarantee acceptance.');

            return $application;
        });
    }
}
