<?php

namespace App\Services;

use App\Models\PpiHouseholdApplication;

class PpiApplicationReferenceGenerator
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        do {
            $reference = sprintf(
                'PPI-%s-%s',
                now()->timezone('Asia/Manila')->format('Y'),
                $this->randomSuffix(8),
            );
        } while (PpiHouseholdApplication::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    private function randomSuffix(int $length): string
    {
        $suffix = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($index = 0; $index < $length; $index++) {
            $suffix .= self::ALPHABET[random_int(0, $max)];
        }

        return $suffix;
    }
}
