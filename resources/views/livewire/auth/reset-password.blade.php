<x-layouts::auth :title="__('Reset password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />
        @if ($errors->has('email') || $errors->has('token'))
            <p role="alert">{{ __('This reset link could not be used. It may be invalid, expired, or already used. Request a new reset email to continue.') }}</p>
        @endif
        <flux:link :href="route('password.request')">{{ __('Request a new reset email') }}</flux:link>

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ request()->route('token') }}">

            <!-- Email Address -->
            <flux:input
                name="email"
                value="{{ request('email') }}"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('New Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('New Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm New Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm New Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    {{ __('Reset Password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
