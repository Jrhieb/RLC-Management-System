# RLCP dashboard live suggestions - run from C:\rlcsystem\app
$ErrorActionPreference = 'Stop'
$root = 'C:\rlcsystem\app'
Set-Location $root
$controllerPath = Join-Path $root 'app\Http\Controllers\BeneficiarySearchController.php'
$componentPath = Join-Path $root 'resources\views\components\rlcp-beneficiary-search.blade.php'
$routePath = Join-Path $root 'routes\web.php'
$dashboardPath = Join-Path $root 'resources\views\dashboard.blade.php'
$controllerCode = @'
<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BeneficiarySearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Beneficiary::class);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim($validated['search'] ?? '');
        $results = collect();

        if ($search !== '') {
            $results = Beneficiary::query()
                ->where(function ($query) use ($search) {
                    $query->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('beneficiary_id', 'like', '%'.$search.'%');
                })
                ->orderBy('full_name')
                ->orderBy('id')
                ->limit(8)
                ->get(['id', 'full_name', 'beneficiary_id', 'photo_path'])
                ->filter(fn (Beneficiary $beneficiary) => Gate::allows('view', $beneficiary))
                ->map(fn (Beneficiary $beneficiary) => [
                    'id' => $beneficiary->id,
                    'name' => $beneficiary->full_name,
                    'beneficiary_id' => $beneficiary->beneficiary_id,
                    'url' => route('beneficiaries.show', $beneficiary),
                    'photo_url' => $beneficiary->photo_path
                        ? route('beneficiaries.photo', $beneficiary) : null,
                ])->values();
        }

        return response()->json(['results' => $results])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
'@
$componentCode = @'
{{-- rlc-dashboard-live-search --}}
<div class="rlc-search-wrap"
     x-data="{
        term: '', results: [], open: false, loading: false, error: '', active: -1,
        timer: null, controller: null, version: 0,
        changed() {
            clearTimeout(this.timer);
            this.controller?.abort();
            const version = ++this.version;
            this.results = []; this.active = -1; this.error = '';
            if (!this.term.trim()) { this.open = false; this.loading = false; return; }
            this.open = true; this.loading = true;
            this.timer = setTimeout(() => this.lookup(version), 250);
        },
        async lookup(version) {
            this.controller = new AbortController();
            try {
                const url = new URL(this.$el.dataset.endpoint, window.location.origin);
                url.searchParams.set('search', this.term.trim());
                const response = await fetch(url, {
                    signal: this.controller.signal,
                    credentials: 'same-origin', headers: { Accept: 'application/json' }
                });
                if (!response.ok) throw new Error('Search failed');
                const data = await response.json();
                if (version !== this.version) return;
                if (!Array.isArray(data.results)) throw new Error('Invalid response');
                this.results = data.results;
            } catch (error) {
                if (version !== this.version || error.name === 'AbortError') return;
                this.error = 'Unable to search. Try again or use the search arrow.';
            } finally {
                if (version === this.version) this.loading = false;
            }
        },
        move(step) {
            if (!this.results.length) return;
            this.open = true;
            this.active = (this.active + step + this.results.length) % this.results.length;
            this.$nextTick(() => document.getElementById('rlc-search-option-' + this.active)?.scrollIntoView({ block: 'nearest' }));
        },
        select(event) {
            if (this.open &amp;&amp; this.active >= 0 &amp;&amp; this.results[this.active]) {
                event.preventDefault(); window.location.assign(this.results[this.active].url);
            }
        },
        destroy() { clearTimeout(this.timer); this.controller?.abort(); }
     }"
     data-endpoint="{{ route('beneficiaries.suggestions') }}"
     x-on:click.outside="open = false"
     x-on:keydown.escape.prevent.stop="open = false; active = -1"
     x-on:focusout="if (!$el.contains($event.relatedTarget)) open = false">
    <form method="GET" action="{{ route('beneficiaries.index') }}" class="rlc-search">
        <x-rlcp-icon name="search" />
        <label class="sr-only" for="dashboard-beneficiary-search">Search beneficiaries by name or ID</label>
        <input id="dashboard-beneficiary-search" name="search" type="search" maxlength="100"
               placeholder="Search beneficiaries or ID..." autocomplete="off" spellcheck="false"
               role="combobox" aria-autocomplete="list" aria-controls="rlc-search-results"
               :aria-expanded="open.toString()"
               :aria-activedescendant="open &amp;&amp; active >= 0 ? 'rlc-search-option-' + active : null"
               x-model="term" x-on:input="changed()"
               x-on:focus="if (term.trim()) open = true"
               x-on:keydown.arrow-down.prevent="move(1)"
               x-on:keydown.arrow-up.prevent="move(-1)"
               x-on:keydown.enter="select($event)">
        <button type="submit" aria-label="Search beneficiaries"><x-rlcp-icon name="arrow" /></button>
    </form>
    <div x-cloak x-show="open" x-transition.opacity.duration.150ms class="rlc-search-dropdown">
        <p class="rlc-search-message" role="status" aria-live="polite"
           x-show="loading || error || results.length === 0"
           x-text="loading ? 'Searching...' : (error || 'No beneficiaries found.')"></p>
        <ul id="rlc-search-results" role="listbox" aria-label="Beneficiary suggestions" :aria-busy="loading.toString()">
            <template x-for="(person, index) in results" :key="person.id">
                <li role="presentation">
                    <a :id="'rlc-search-option-' + index" :href="person.url"
                       role="option" :aria-selected="(active === index).toString()"
                       class="rlc-search-result" :class="{ 'rlc-search-selected': active === index }"
                       x-on:mouseenter="active = index" x-on:mousedown.prevent>
                        <span class="rlc-search-avatar" x-data="{ failed: false }">
                            <template x-if="person.photo_url &amp;&amp; !failed">
                                <img :src="person.photo_url" alt="" x-on:error="failed = true">
                            </template>
                            <span x-show="!person.photo_url || failed" x-text="person.name.trim().charAt(0).toUpperCase()"></span>
                        </span>
                        <span class="rlc-search-person">
                            <strong x-text="person.name"></strong>
                            <span x-text="'ID: ' + person.beneficiary_id"></span>
                        </span>
                        <x-rlcp-icon name="chevron" />
                    </a>
                </li>
            </template>
        </ul>
        <a x-show="!loading &amp;&amp; !error &amp;&amp; results.length > 0"
           :href="{{ Illuminate\Support\Js::from(route('beneficiaries.index')) }} + '?search=' + encodeURIComponent(term.trim())"
           class="rlc-search-all">View all matching beneficiaries <span aria-hidden="true">&rarr;</span></a>
    </div>
</div>
@once
<style>
.rlc-search-wrap { position:relative; width:340px; max-width:100%; }
.rlc-search-wrap .rlc-search { width:100%; margin:0; }
.rlc-search-dropdown[x-cloak] { display:none !important; }
.rlc-search-dropdown { position:absolute; top:calc(100% + 8px); left:0; right:0; z-index:50; overflow:hidden; border:1px solid var(--rlc-border); border-radius:16px; background:var(--rlc-surface); color:var(--rlc-ink); box-shadow:0 16px 40px #0003; }
.rlc-search-dropdown ul { list-style:none; margin:0; padding:5px; max-height:340px; overflow-y:auto; }
.rlc-search-result { display:flex; align-items:center; gap:12px; padding:11px; border-radius:11px; text-decoration:none; color:var(--rlc-ink); transition:background .15s; }
.rlc-search-result:hover,.rlc-search-result:focus-visible,.rlc-search-selected { background:var(--rlc-tint); outline:2px solid transparent; }
.rlc-search-avatar { width:42px; height:42px; flex-shrink:0; border-radius:50%; background:var(--rlc-tint); color:var(--rlc-violet); display:flex; align-items:center; justify-content:center; font-weight:700; overflow:hidden; }
.rlc-search-avatar img { width:100%; height:100%; object-fit:cover; }
.rlc-search-person { flex:1; min-width:0; }
.rlc-search-person strong { display:block; font-size:13px; overflow-wrap:anywhere; }
.rlc-search-person>span { display:block; margin-top:3px; font-size:11px; color:var(--rlc-muted); }
.rlc-search-result>.rlc-icon { width:16px; color:var(--rlc-muted); flex-shrink:0; }
.rlc-search-message { margin:0; padding:18px; font-size:13px; color:var(--rlc-muted); }
.rlc-search-all { display:flex; justify-content:space-between; border-top:1px solid var(--rlc-border); padding:13px 16px; font-size:12px; color:var(--rlc-violet); }
@media(max-width:1023px) { .rlc-search-wrap { margin-right:auto; } }
@media(max-width:639px) { .rlc-search-wrap { width:100%; order:3; margin:0; }.rlc-search-dropdown ul { max-height:260px; } }
@media(prefers-reduced-motion:reduce) { .rlc-search-result { transition:none; } }
</style>
@endonce
'@
$routeCode = @'
    Route::get('dashboard/beneficiary-suggestions', \App\Http\Controllers\BeneficiarySearchController::class)
        ->middleware('throttle:120,1')
        ->name('beneficiaries.suggestions');

'@
$routes = [IO.File]::ReadAllText($routePath)
$dashboard = [IO.File]::ReadAllText($dashboardPath)
$anchor = "    Route::get('dashboard',"
if (-not $routes.Contains("'beneficiaries.suggestions'")) {
    if ([regex]::Matches($routes, [regex]::Escape($anchor)).Count -ne 1) {
        throw 'Expected dashboard route not found. No changes saved.'
    }
    $routes = $routes.Insert($routes.IndexOf($anchor), $routeCode + [Environment]::NewLine)
}
if (-not $dashboard.Contains('<x-rlcp-beneficiary-search />')) {
    $pattern = '(?s)<form\b(?=[^>]*class="rlc-search")[^>]*>.*?</form>'
    if ([regex]::Matches($dashboard, $pattern).Count -ne 1) {
        throw 'Expected exactly one dashboard search form. No changes saved.'
    }
    $dashboard = [regex]::Replace($dashboard, $pattern, '<x-rlcp-beneficiary-search />')
}
$changes = @(
    @{Path=$controllerPath; Content=$controllerCode},
    @{Path=$componentPath; Content=$componentCode},
    @{Path=$routePath; Content=$routes},
    @{Path=$dashboardPath; Content=$dashboard}
)
$backupDirectory = Join-Path $root ('storage\app\search-backups\' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
$manifest = @()
for ($i=0; $i -lt $changes.Count; $i++) {
    $change = $changes[$i]
    $exists = Test-Path $change.Path
    $backup = Join-Path $backupDirectory ($i.ToString() + '.bak')
    if ($exists) { Copy-Item $change.Path $backup }
    $manifest += @{Path=$change.Path; Existed=$exists; Backup=$backup}
}
$manifest | ConvertTo-Json | Set-Content (Join-Path $backupDirectory 'manifest.json') -Encoding UTF8
try {
    foreach ($change in $changes) {
        New-Item -ItemType Directory -Path (Split-Path $change.Path) -Force | Out-Null
        [IO.File]::WriteAllText($change.Path, $change.Content, [Text.UTF8Encoding]::new($false))
    }
    php -l $controllerPath
    if ($LASTEXITCODE -ne 0) { throw 'Controller syntax check failed.' }
    php -l $routePath
    if ($LASTEXITCODE -ne 0) { throw 'Routes syntax check failed.' }
    php artisan route:list --name=beneficiaries.suggestions
    if ($LASTEXITCODE -ne 0) { throw 'Route check failed.' }
    php artisan view:clear
    if ($LASTEXITCODE -ne 0) { throw 'View clear failed.' }
    php artisan view:cache
    if ($LASTEXITCODE -ne 0) { throw 'Blade compilation failed.' }
    $check = @'
$user = \App\Models\User::findOrFail(1); \Illuminate\Support\Facades\Auth::setUser($user); $html = app(\App\Http\Controllers\DashboardController::class)->index()->render(); if (!str_contains($html, 'dashboard-beneficiary-search')) { throw new \RuntimeException('Search component missing'); } $request = \Illuminate\Http\Request::create('/dashboard/beneficiary-suggestions', 'GET', ['search' => '']); $response = app(\App\Http\Controllers\BeneficiarySearchController::class)($request); if ($response->getData(true)['results'] !== []) { throw new \RuntimeException('Empty search should return no results'); } dump('RLCP_SEARCH_RENDER_OK');
'@
    php artisan tinker --execute="$check"
    if ($LASTEXITCODE -ne 0) { throw 'Search render check failed.' }
    Write-Host "Dashboard live search installed. Backups: $backupDirectory"
} catch {
    foreach ($entry in $manifest) {
        if ($entry.Existed) { Copy-Item $entry.Backup $entry.Path -Force }
        elseif (Test-Path $entry.Path) { Remove-Item $entry.Path }
    }
    php artisan view:clear
    throw "Installation failed; original files restored. $($_.Exception.Message)"
}
