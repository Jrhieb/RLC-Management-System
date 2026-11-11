<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CaseworkerController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('createCaseworker', User::class);

        if (config('fortify.lowercase_usernames') && is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }

        $validated = $request->validateWithBag('createCaseworker', [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        $caseworker = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $caseworker->role = 'caseworker';

        try {
            if (! $caseworker->save()) {
                throw ValidationException::withMessages([
                    'account' => 'The account could not be saved. Please try again.',
                ])->errorBag('createCaseworker');
            }
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken.',
            ])->errorBag('createCaseworker');
        }

        return redirect()->route('beneficiaries.index')->with('success',
            'Caseworker account created successfully. They can sign in and request a verification email to finish setup.');
    }
}
