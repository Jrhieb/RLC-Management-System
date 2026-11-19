<x-layouts::app :title="__('Deployment Event')">
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <header class="space-y-2">
            <p class="text-sm font-semibold uppercase tracking-wide text-rose-800 dark:text-rose-300">RLCP Beneficiary Management System</p>
            <h1 class="text-3xl font-bold text-zinc-900 dark:text-zinc-100">Deployment Event</h1>
            <p class="text-zinc-600 dark:text-zinc-400">Fictional example for interface testing. All event details below are sample data.</p>
        </header>

        <section class="rounded-xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950">
            <h2 class="font-semibold text-amber-950 dark:text-amber-100">Planned — Placeholder</h2>
            <p class="mt-1 text-sm text-amber-900 dark:text-amber-200">Development / Testing Only · Public Applications Disabled</p>
        </section>

        <section aria-labelledby="deployment-details" class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 id="deployment-details" class="text-xl font-semibold">Deployment information</h2>
            <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                @foreach ([
                    'Deployment date' => 'January 15, 2030 (example)',
                    'Deployment time' => '10:00 AM, Asia/Manila (example)',
                    'Environment' => 'Development / Testing Only',
                    'URL' => 'https://example.invalid (example)',
                    'Responsible developer' => 'Sample Developer (fictional)',
                    'Authorized RLCP representative' => 'Sample Representative (fictional)',
                    'Approval status' => 'Pending RLCP review and approval',
                ] as $label => $value)
                    <div><dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd class="mt-1 font-medium">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section aria-labelledby="approval-checklist" class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 id="approval-checklist" class="text-xl font-semibold">Deployment approval checklist</h2>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">All items are awaiting review. This checklist is a placeholder.</p>
            <ul class="mt-5 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach (['Privacy notice', 'Retention policy', 'Guardian acknowledgment', 'Consent mechanisms', 'Public application scope', 'Security testing', 'Final production authorization'] as $item)
                    <li class="flex items-center justify-between gap-4 py-3"><span>{{ $item }}</span><span class="text-sm text-zinc-500 dark:text-zinc-400">Pending</span></li>
                @endforeach
            </ul>
        </section>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Approval document status: Draft — not yet authorized. Dates, personnel, and approvals will be recorded after confirmation by RLCP.</p>
    </div>
</x-layouts::app>
