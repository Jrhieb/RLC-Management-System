<?php

namespace App\Services;

use App\Models\PpiDirectorNotification;
use App\Models\PpiHouseholdApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class PpiDirectorNotificationService
{
    public function recordNewApplication(PpiHouseholdApplication $application): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('PPI director notifications must be recorded inside the submission transaction.');
        }

        User::query()
            ->where('role', 'project_director')
            ->whereNotNull('email_verified_at')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $recipientId) use ($application): void {
                PpiDirectorNotification::firstOrCreate([
                    'ppi_household_application_id' => $application->id,
                    'recipient_id' => $recipientId,
                    'kind' => PpiDirectorNotification::NEW_APPLICATION,
                ]);
            });
    }
}
