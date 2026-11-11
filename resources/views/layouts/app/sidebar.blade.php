<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" >
    <head>
        @include('partials.head')
    </head>
    <body class="rlc-shell rlcp-staff-brand min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="rlc-sidebar border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <a class="rlc-brand" href="{{ route(auth()->user()->requiresAccountReview() ? 'account.status' : 'dashboard') }}" wire:navigate>
                    <span class="rlc-brand-mark"><img src="{{ asset('images/branding/rlc-logo.png') }}" alt="RLCP emblem" width="300" height="300"></span>
                    <span><strong>RLCP</strong><small>Risen Lord Church Phils., Inc.</small></span>
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    @if (auth()->user()->canAccessStaff())
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                        @can('viewMyCaseload', \App\Models\Beneficiary::class)
                            <flux:sidebar.item icon="user-group" :href="route('my-caseload.index')" :current="request()->routeIs('my-caseload.*')" wire:navigate>
                                {{ __('My Caseload') }}
                            </flux:sidebar.item>
                        @endcan
                        <flux:sidebar.item icon="users" :href="route('beneficiaries.index')" :current="request()->routeIs('beneficiaries.*')" wire:navigate>
                            {{ __('Registry') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('attendance.index')" :current="request()->routeIs('attendance.*', 'activities.*')" wire:navigate>
                            {{ __('Attendance') }}
                        </flux:sidebar.item>
                        @can('viewAny', \App\Models\Beneficiary::class)
                            @can('viewAny', \App\Models\ProgramActivity::class)
                                <flux:sidebar.item icon="chart-bar"
                                    :href="route('reports.index')"
                                    :current="request()->routeIs('reports.*')"
                                    wire:navigate>
                                    {{ __('Reports') }}
                                </flux:sidebar.item>
                            @endcan
                        @endcan

                        @can('viewAny', \App\Models\Beneficiary::class)
                            <flux:sidebar.item icon="magnifying-glass"
                                :href="route('nfc.index')"
                                :current="request()->routeIs('nfc.*')"
                                wire:navigate>
                                {{ __('NFC Lookup') }}
                            </flux:sidebar.item>
                        @endcan

                        @can('reviewAccountRequests', \App\Models\User::class)
                            <flux:sidebar.item icon="user-group" :href="route('account-requests.index')"
                                :current="request()->routeIs('account-requests.*')" wire:navigate>
                                {{ __('Account Requests') }}
                            </flux:sidebar.item>
                        @endcan
                    @else
                        <flux:sidebar.item icon="user-circle" :href="route('account.status')"
                            :current="request()->routeIs('account.status')" wire:navigate>
                            {{ __('Account status') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav class="rlc-sidebar-footer">
                <flux:sidebar.item icon="cog" :href="route(auth()->user()->requiresAccountReview() ? 'security.edit' : 'profile.edit')" :current="request()->routeIs('profile.*', 'security.*')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <flux:header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <x-rlcp-notifications />

            <flux:dropdown position="top" align="end" class="lg:hidden">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route(auth()->user()->requiresAccountReview() ? 'security.edit' : 'profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
