<?php

namespace App\Support;

use Illuminate\Support\Str;

final class PpiHouseholdKey
{
    /**
     * Build a deterministic key from the contact number and address.
     *
     * Child and guardian names are intentionally excluded so a second
     * application for another child in the same household is still detected.
     * The key is a heuristic, not a legal household identifier.
     */
    public static function make(
        string $contactNumber,
        string $barangay,
        string $cityMunicipality,
        string $completeAddress,
    ): string {
        $phone = preg_replace('/\D+/', '', $contactNumber) ?? '';

        if (str_starts_with($phone, '63') && strlen($phone) === 12) {
            $phone = '0'.substr($phone, 2);
        } elseif (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0'.$phone;
        }

        $normalize = static fn (string $value): string => Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();

        return hash('sha256', implode('|', [
            $phone,
            $normalize($barangay),
            $normalize($cityMunicipality),
            $normalize($completeAddress),
        ]));
    }
}
