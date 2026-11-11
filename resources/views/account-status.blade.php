<x-layouts::auth :title="__('Account status')">
    <div class="flex flex-col gap-6">
        <div class="flex justify-end"><x-rlcp-notifications :activity-feed="false" /></div>
        <x-auth-header :title="__('Account status')" :description="$account->approval_status === 'rejected' ? __('Your account request was rejected.') : __('Your account is awaiting Director approval.')" />

        <div class="text-center text-sm text-zinc-600 dark:text-zinc-400">
            <p class="break-words font-medium text-zinc-900 dark:text-zinc-100">{{ $account->name }}</p>
            <p class="break-all">{{ $account->email }}</p>
        </div>

        <div role="status" class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Approval status') }}: {{ __(ucfirst($account->approval_status)) }}</p>
            @if ($account->approval_status === 'rejected')
                <p class="mt-3 font-medium text-zinc-900 dark:text-zinc-100">{{ __('Rejection reason') }}</p>
                <p class="mt-1 whitespace-pre-wrap break-words text-zinc-600 dark:text-zinc-400">{{ $account->rejection_reason ?? __('No rejection reason was recorded.') }}</p>
            @else
                <p class="mt-3 text-zinc-600 dark:text-zinc-400">{{ __('A Director must approve your request before you can access staff pages.') }}</p>
            @endif
        </div>

        <flux:button :href="route('account.status')">{{ __('Refresh status') }}</flux:button>
        @if ($account->hasVerifiedEmail())
            <flux:link :href="route('security.edit')" class="text-center" wire:navigate>{{ __('Account security') }}</flux:link>
        @else
            <flux:link :href="route('verification.notice')" class="text-center" wire:navigate>{{ __('Verify your email') }}</flux:link>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="primary" class="w-full">{{ __('Log out') }}</flux:button>
        </form>
    </div>
</x-layouts::auth>
