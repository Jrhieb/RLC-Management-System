<x-layouts::app :title="__('Account Requests')">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
        <div>
            <flux:heading size="xl" level="1">{{ __('Account Requests') }}</flux:heading>
            <flux:subheading>{{ __('Review Caseworker accounts. Only pending requests can be approved or rejected.') }}</flux:subheading>
        </div>

        @if (session('status'))
            <p role="status" class="text-sm font-medium text-green-700 dark:text-green-400">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-300 p-4 text-sm text-red-700 dark:border-red-800 dark:text-red-300">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="GET" action="{{ route('account-requests.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:select name="status" :label="__('Approval status')" class="sm:max-w-xs">
                @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All requests'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ __($label) }}</option>
                @endforeach
            </flux:select>
            <flux:button type="submit">{{ __('Show requests') }}</flux:button>
        </form>

        <div class="space-y-4" aria-label="{{ __('Caseworker account requests') }}">
            @forelse ($accounts as $account)
                <article class="rounded-xl border border-zinc-200 bg-white p-4 sm:p-6 dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="account-request-{{ $account->id }}">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <flux:heading level="2" id="account-request-{{ $account->id }}" class="break-words">{{ $account->name }}</flux:heading>
                            <p class="break-all text-sm text-zinc-600 dark:text-zinc-400">{{ $account->email }}</p>
                            @if (! $account->hasVerifiedEmail())
                                <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">{{ __('Email verification is still required for staff access.') }}</p>
                            @endif
                        </div>
                        <flux:badge :color="match ($account->approval_status) { 'approved' => 'green', 'rejected' => 'red', default => 'amber' }">
                            {{ __(ucfirst($account->approval_status)) }}
                        </flux:badge>
                    </div>

                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Request date (PHT)') }}</dt>
                            <dd class="mt-1 text-zinc-900 dark:text-zinc-100">
                                @if ($account->created_at)
                                    <time datetime="{{ $account->created_at->toIso8601String() }}">{{ $account->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</time>
                                @else
                                    {{ __('Date unavailable') }}
                                @endif
                            </dd>
                        </div>
                        @if ($account->approval_reviewed_at)
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Reviewed (PHT)') }}</dt>
                                <dd class="mt-1 text-zinc-900 dark:text-zinc-100">
                                    <time datetime="{{ $account->approval_reviewed_at->toIso8601String() }}">{{ $account->approval_reviewed_at->timezone('Asia/Manila')->format('M j, Y g:i A') }}</time>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @if ($account->approval_status === 'rejected')
                        <div class="mt-4 text-sm">
                            <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Rejection reason') }}</p>
                            <p class="mt-1 whitespace-pre-wrap break-words text-zinc-600 dark:text-zinc-400">{{ $account->rejection_reason ?? __('No rejection reason was recorded.') }}</p>
                        </div>
                    @endif

                    @if ($account->approval_status === 'pending')
                        <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                            <flux:modal.trigger :name="'approve-request-'.$account->id">
                                <flux:button type="button" variant="primary" :aria-label="__('Approve :name', ['name' => $account->name])">{{ __('Approve') }}</flux:button>
                            </flux:modal.trigger>
                            <flux:modal.trigger :name="'reject-request-'.$account->id">
                                <flux:button type="button" variant="danger" :aria-label="__('Reject :name', ['name' => $account->name])">{{ __('Reject') }}</flux:button>
                            </flux:modal.trigger>
                        </div>

                        <flux:modal :name="'approve-request-'.$account->id" class="w-[calc(100vw-2rem)] min-w-0 max-w-md"
                            x-data x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'approve-request-heading-{{ $account->id }}')">
                            <form method="POST" action="{{ route('account-requests.review', ['user' => $account, 'status' => $status, 'page' => $accounts->currentPage()]) }}" class="space-y-6">
                                @csrf
                                <input type="hidden" name="decision" value="approve">
                                <input type="hidden" name="confirmed" value="1">
                                <div>
                                    <flux:heading size="lg" level="2" id="approve-request-heading-{{ $account->id }}">{{ __('Approve Caseworker account?') }}</flux:heading>
                                    <p class="mt-2 break-words text-sm text-zinc-600 dark:text-zinc-400">{{ $account->name }} &mdash; {{ $account->email }}</p>
                                    <flux:text class="mt-3">{{ __('Approval grants staff access after email verification. Beneficiary assignments are handled separately.') }}</flux:text>
                                </div>
                                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <flux:modal.close><flux:button type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                    <flux:button type="submit" variant="primary">{{ __('Confirm approval') }}</flux:button>
                                </div>
                            </form>
                        </flux:modal>

                        <flux:modal :name="'reject-request-'.$account->id" class="w-[calc(100vw-2rem)] min-w-0 max-w-md"
                            x-data x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'reject-request-heading-{{ $account->id }}')">
                            <form method="POST" action="{{ route('account-requests.review', ['user' => $account, 'status' => $status, 'page' => $accounts->currentPage()]) }}" class="space-y-6">
                                @csrf
                                <input type="hidden" name="decision" value="reject">
                                <input type="hidden" name="confirmed" value="1">
                                <div>
                                    <flux:heading size="lg" level="2" id="reject-request-heading-{{ $account->id }}">{{ __('Reject Caseworker account?') }}</flux:heading>
                                    <p class="mt-2 break-words text-sm text-zinc-600 dark:text-zinc-400">{{ $account->name }} &mdash; {{ $account->email }}</p>
                                </div>
                                <flux:textarea
                                    name="rejection_reason"
                                    :label="__('Rejection reason')"
                                    :value="(int) session('review_account_id') === $account->id && is_string(old('rejection_reason')) ? old('rejection_reason') : ''"
                                    rows="4"
                                    maxlength="2000"
                                    required
                                    :description="__('The Caseworker will see this reason on their account-status screen.')"
                                />
                                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                                    <flux:modal.close><flux:button type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
                                    <flux:button type="submit" variant="danger">{{ __('Confirm rejection') }}</flux:button>
                                </div>
                            </form>
                        </flux:modal>

                        @if ($errors->any() && (int) session('review_account_id') === $account->id && old('decision') === 'reject')
                            <div x-data x-init="$nextTick(() => $flux.modal('reject-request-{{ $account->id }}').show())"></div>
                        @endif
                    @endif
                </article>
            @empty
                <p role="status" class="rounded-xl border border-zinc-200 p-6 text-center text-zinc-600 dark:border-zinc-700 dark:text-zinc-400">{{ __('No account requests match this status.') }}</p>
            @endforelse
        </div>

        {{ $accounts->links() }}
    </div>
</x-layouts::app>
