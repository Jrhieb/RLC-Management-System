<x-layouts::app :title="'Reports'">
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <div>
            <h1 class="text-3xl font-bold">Reports</h1>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                Attendance and assistance releases within the selected dates.
                Dates and times use Philippine time (PHT).
            </p>
        </div>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">Please check the following:</p>
                <ul class="mt-2 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="GET" action="{{ route('reports.index') }}"
              class="flex flex-wrap items-end gap-4 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <label for="date_from" class="mb-2 block text-sm font-medium">From</label>
                                {{-- rlc-us-date-date_from --}}
                <div x-data="{ iso: @js(old('date_from', $dateFrom)) }"
                     class="relative">
                    <div aria-hidden="true"
                         style="height:48px;box-sizing:border-box;" class="flex items-center justify-between gap-6 rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                        <span x-text="iso ? iso.split('-')[1] + '/' + iso.split('-')[2] + '/' + iso.split('-')[0] : 'MM/DD/YYYY'">
                            {{ $dateFrom ? \Illuminate\Support\Carbon::parse($dateFrom)->format('m/d/Y') : 'MM/DD/YYYY' }}
                        </span>
                        <svg width="20" height="20" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="5" width="18" height="16" rx="2"/>
                            <path d="M16 3v4M8 3v4M3 11h18"/>
                        </svg>
                    </div>

                    <input id="date_from" name="date_from" type="date" required
                           value="{{ old('date_from', $dateFrom) }}"
                           x-model="iso"
                           
                           @click="if ($el.showPicker) { try { $el.showPicker() } catch (error) {} }"
                           style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;"
                           class="peer">
                    <div class="pointer-events-none absolute inset-0 rounded-xl peer-focus-visible:ring-2 peer-focus-visible:ring-violet-500"></div>
                </div>
            </div>

            <div>
                <label for="date_to" class="mb-2 block text-sm font-medium">To</label>
                                {{-- rlc-us-date-date_to --}}
                <div x-data="{ iso: @js(old('date_to', $dateTo)) }"
                     class="relative">
                    <div aria-hidden="true"
                         style="height:48px;box-sizing:border-box;" class="flex items-center justify-between gap-6 rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800">
                        <span x-text="iso ? iso.split('-')[1] + '/' + iso.split('-')[2] + '/' + iso.split('-')[0] : 'MM/DD/YYYY'">
                            {{ $dateTo ? \Illuminate\Support\Carbon::parse($dateTo)->format('m/d/Y') : 'MM/DD/YYYY' }}
                        </span>
                        <svg width="20" height="20" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="3" y="5" width="18" height="16" rx="2"/>
                            <path d="M16 3v4M8 3v4M3 11h18"/>
                        </svg>
                    </div>

                    <input id="date_to" name="date_to" type="date" required
                           value="{{ old('date_to', $dateTo) }}"
                           x-model="iso"
                           
                           @click="if ($el.showPicker) { try { $el.showPicker() } catch (error) {} }"
                           style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;"
                           class="peer">
                    <div class="pointer-events-none absolute inset-0 rounded-xl peer-focus-visible:ring-2 peer-focus-visible:ring-violet-500"></div>
                </div>
            </div>

            <button type="submit"
                    style="height:48px;width:140px;box-sizing:border-box;" class="inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 text-sm font-semibold text-white hover:bg-violet-700">
                Apply Filters
            </button>

            <a href="{{ route('reports.index') }}"
               style="height:48px;box-sizing:border-box;" class="inline-flex items-center justify-center rounded-xl border border-zinc-300 px-5 text-sm font-semibold dark:border-zinc-600">
                Reset
            </a>
        </form>

        {{-- rlc-reports-download-menu --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Downloads use the applied date range.
            </p>

            <flux:dropdown position="bottom" align="end">
                <flux:button icon="arrow-down-tray"
                             aria-label="Download reports"
                             title="Download reports" />

                <flux:menu>
                    <flux:menu.item
                        :href="route('reports.export', ['type' => 'attendance', 'date_from' => $dateFrom, 'date_to' => $dateTo])">
                        Attendance — CSV
                    </flux:menu.item>

                    <flux:menu.item
                        :href="route('reports.export', ['type' => 'assistance', 'date_from' => $dateFrom, 'date_to' => $dateTo])">
                        Assistance — CSV
                    </flux:menu.item>
                                    <flux:menu.separator />

                    <flux:menu.item
                        :href="route('reports.pdf', ['type' => 'attendance', 'date_from' => $dateFrom, 'date_to' => $dateTo])">
                        Attendance - PDF
                    </flux:menu.item>

                    <flux:menu.item
                        :href="route('reports.pdf', ['type' => 'assistance', 'date_from' => $dateFrom, 'date_to' => $dateTo])">
                        Assistance - PDF
                    </flux:menu.item>

</flux:menu>
            </flux:dropdown>
        </div>

        @php
            $summaries = [
                ['Attendance check-ins', $attendanceCount],
                ['Unique participants', $participatingBeneficiariesCount],
                ['Assistance releases', $assistanceReleasesCount],
                ['Unique assistance recipients', $assistedBeneficiariesCount],
            ];
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($summaries as [$label, $value])
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                    <p class="mt-3 text-3xl font-bold">{{ number_format($value) }}</p>
                </div>
            @endforeach
        </div>

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 p-6 dark:border-zinc-700">
                <h2 class="text-lg font-semibold">Attendance Records</h2>
                <p class="mt-1 text-sm text-zinc-500">
                    Filtered by actual check-in date.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th scope="col" class="px-6 py-4">Beneficiary</th>
                            <th scope="col" class="px-6 py-4">Activity</th>
                            <th scope="col" class="px-6 py-4">Check-in Time (PHT)</th>
                            <th scope="col" class="px-6 py-4">Source</th>
                            <th scope="col" class="px-6 py-4">Recorded By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($attendances as $attendance)
                            <tr>
                                <td class="px-6 py-4">
                                    @if ($attendance->beneficiary)
                                        @if ($attendance->beneficiary->trashed())
                                            <span class="font-semibold">{{ $attendance->beneficiary->full_name }}</span>
                                            <span class="ml-2 rounded-lg bg-zinc-100 px-2 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                Archived
                                            </span>
                                        @else
                                            <a href="{{ route('beneficiaries.show', $attendance->beneficiary) }}"
                                               class="font-semibold text-violet-600 dark:text-violet-400">
                                                {{ $attendance->beneficiary->full_name }}
                                            </a>
                                        @endif
                                        <p class="mt-1 text-xs text-zinc-500">
                                            ID: {{ $attendance->beneficiary->beneficiary_id }}
                                        </p>
                                    @else
                                        <span class="text-zinc-500">Archived beneficiary</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if ($attendance->activity)
                                        <a href="{{ route('activities.show', $attendance->activity) }}"
                                           class="text-violet-600 dark:text-violet-400">
                                            {{ $attendance->activity->title }}
                                        </a>
                                    @else
                                        Activity unavailable
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    {{ $attendance->checked_in_at->copy()->timezone('Asia/Manila')->format('M d, Y - g:i A') }}
                                </td>
                                <td class="px-6 py-4">
                                    {{ match ($attendance->source) { 'nfc' => 'NFC', 'nfc_test' => 'Test UID', default => ucfirst($attendance->source) } }}
                                </td>
                                <td class="px-6 py-4">
                                    {{ $attendance->recorder?->name ?? 'Former staff member' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-zinc-500">
                                    No attendance records within these dates.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-6 dark:border-zinc-700">
                {{ $attendances->links() }}
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 p-6 dark:border-zinc-700">
                <h2 class="text-lg font-semibold">Assistance Releases</h2>
                <p class="mt-1 text-sm text-zinc-500">
                    Filtered by release date.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th scope="col" class="px-6 py-4">Beneficiary</th>
                            <th scope="col" class="px-6 py-4">Assistance</th>
                            <th scope="col" class="px-6 py-4">Quantity</th>
                            <th scope="col" class="px-6 py-4">Released (PHT)</th>
                            <th scope="col" class="px-6 py-4">Recorded By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($assistanceTransactions as $transaction)
                            <tr>
                                <td class="px-6 py-4">
                                    @if ($transaction->beneficiary)
                                        @if ($transaction->beneficiary->trashed())
                                            <span class="font-semibold">{{ $transaction->beneficiary->full_name }}</span>
                                            <span class="ml-2 rounded-lg bg-zinc-100 px-2 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                                Archived
                                            </span>
                                        @else
                                            <a href="{{ route('beneficiaries.show', $transaction->beneficiary) }}"
                                               class="font-semibold text-violet-600 dark:text-violet-400">
                                                {{ $transaction->beneficiary->full_name }}
                                            </a>
                                        @endif
                                        <p class="mt-1 text-xs text-zinc-500">
                                            ID: {{ $transaction->beneficiary->beneficiary_id }}
                                        </p>
                                    @else
                                        <span class="text-zinc-500">Archived beneficiary</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-medium">{{ $transaction->item_name }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ ucfirst($transaction->category) }}
                                    </p>
                                </td>
                                <td class="px-6 py-4">{{ $transaction->quantity }}</td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    {{ $transaction->released_at->copy()->timezone('Asia/Manila')->format('M d, Y - g:i A') }}
                                </td>
                                <td class="px-6 py-4">
                                    {{ $transaction->recorder?->name ?? 'Former staff member' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-zinc-500">
                                    No assistance releases within these dates.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-6 dark:border-zinc-700">
                {{ $assistanceTransactions->links() }}
            </div>
        </section>
    </div>
</x-layouts::app>