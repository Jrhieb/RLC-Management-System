<?php

namespace App\Support;

use App\Models\Beneficiary;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;

class NfcCardUid
{
    public const FORMAT_ERROR = 'Enter a 10-digit card identifier or a hexadecimal UID with 8, 14, or 20 characters.';

    public const OWNERSHIP_ERROR = 'This NFC card is already linked to another beneficiary.';

    public static function normalize(string $uid): string
    {
        return strtoupper(str_replace([' ', ':', '-'], '', trim($uid)));
    }

    public static function rules(?Beneficiary $beneficiary = null, bool $required = true): array
    {
        // A table-level rule retains ownership for archived beneficiaries as well.
        $unique = Rule::unique('beneficiaries', 'nfc_uid');
        if ($beneficiary !== null) {
            $unique->ignore($beneficiary->getKey());
        }

        return [...self::formatRules($required), $unique];
    }

    public static function formatRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable', 'string', 'max:64',
            'regex:/\A(?:[0-9]{10}|[0-9A-F]{8}(?:[0-9A-F]{6}|[0-9A-F]{12})?)\z/',
        ];
    }

    public static function messages(): array
    {
        return ['nfc_uid.regex' => self::FORMAT_ERROR, 'nfc_uid.unique' => self::OWNERSHIP_ERROR];
    }

    public static function isConflict(UniqueConstraintViolationException $exception): bool
    {
        return in_array('nfc_uid', $exception->columns, true)
            || $exception->index === 'beneficiaries_nfc_uid_unique';
    }
}
