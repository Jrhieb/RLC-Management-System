<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PhoneNumber
{
    public static function normalize(string $value): string
    {
        $value = preg_replace('/[\s().-]+/', '', trim($value));
        if (preg_match('/^09[0-9]{9}$/D', $value)) {
            $value = '+63'.substr($value, 1);
        } elseif (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $value)
            || (str_starts_with($value, '+63') && ! preg_match('/^\+639[0-9]{9}$/D', $value))) {
            throw ValidationException::withMessages(['phone_number' => 'Enter a mobile number in international format, for example +639171234567.']);
        }

        return $value;
    }
}
