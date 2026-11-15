<x-layouts::auth :title="__('Optional phone verification')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Verify your mobile number')" :description="__('Optional. Your email verification and Director approval remain separate.')" />
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        <form method="POST" action="{{ route('account.phone.save') }}" class="flex flex-col gap-4">
            @csrf
            <flux:input name="phone_number" type="tel" :label="__('Mobile number (optional)')" autocomplete="tel" maxlength="40"
                :value="is_string(old('phone_number')) ? old('phone_number') : $account->phone_number" placeholder="+639171234567" />
            <flux:error name="phone_number" />
            <flux:text>{{ __('Use international format. Philippine 09 numbers are converted to +63. Clear the field and save to remove your number.') }}</flux:text>
            <flux:button type="submit">{{ __('Save number') }}</flux:button>
        </form>
        @if ($account->phone_verified_at)
            <p role="status">{{ __('This phone number is verified.') }}</p>
        @elseif ($account->phone_number)
            <form method="POST" action="{{ route('account.phone.send') }}">
                @csrf
                <flux:text>{{ __('Send an SMS only to your own phone. Codes expire after 5 minutes. Wait at least 60 seconds before resending; request limits apply.') }}</flux:text>
                <flux:button type="submit">{{ __('Send or resend code') }}</flux:button>
            </form>
            <form method="POST" action="{{ route('account.phone.verify') }}" class="flex flex-col gap-4">
                @csrf
                <flux:input name="phone_code" :label="__('6-digit verification code')" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required />
                <flux:error name="phone_code" />
                <flux:button type="submit" variant="primary">{{ __('Verify phone') }}</flux:button>
            </form>
        @endif
        <flux:link :href="$account->requiresAccountReview() ? route('account.status') : route('profile.edit')">{{ __('Back to account') }}</flux:link>
    </div>
</x-layouts::auth>
