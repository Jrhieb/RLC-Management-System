<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

class SkipRegistrationVerification implements ShouldQueueAfterCommit
{
    public int $tries = 1;

    // Exercise dispatch without retaining recipient data or performing delivery.
    public function handle(): void {}
}
