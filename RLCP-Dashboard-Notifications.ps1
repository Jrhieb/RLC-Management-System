$ErrorActionPreference = 'Stop'
$root = 'C:\rlcsystem\app'
Set-Location $root
$controllerPath = Join-Path $root 'app\Http\Controllers\DashboardNotificationController.php'
$componentPath = Join-Path $root 'resources\views\components\rlcp-notifications.blade.php'
$routePath = Join-Path $root 'routes\web.php'
$viewPath = Join-Path $root 'resources\views\dashboard.blade.php'
$controllerCode = @'
<?php
namespace App\Http\Controllers;

use App\Models\ActivityAttendance;
use App\Models\AssistanceTransaction;
use App\Models\Beneficiary;
use App\Models\ProgramActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DashboardNotificationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', Beneficiary::class);
        Gate::authorize('viewAny', ProgramActivity::class);
        $items = collect();
        foreach (ActivityAttendance::query()->with(['activity', 'beneficiary'])->latest('created_at')->latest('id')->limit(8)->get() as $record) {
            if (! $record->activity || ! Gate::allows('view', $record->activity)) continue;
            $items->push([
                'id' => 'attendance-'.$record->id,
                'title' => 'Attendance recorded',
                'detail' => ($record->beneficiary?->full_name ?? 'Beneficiary unavailable').' — '.$record->activity->title,
                'url' => route('activities.show', $record->activity),
                'time' => $record->created_at->copy()->timezone('Asia/Manila')->format('m/d/Y g:i A').' PHT',
                'sort' => $record->created_at->getTimestamp(),
            ]);
        }
        foreach (AssistanceTransaction::query()->with('beneficiary')->latest('created_at')->latest('id')->limit(8)->get() as $record) {
            $beneficiary = $record->beneficiary;
            if ($beneficiary && ! Gate::allows('view', $beneficiary)) continue;
            $items->push([
                'id' => 'assistance-'.$record->id,
                'title' => 'Assistance release recorded',
                'detail' => ($beneficiary?->full_name ?? 'Beneficiary unavailable').' — '.$record->item_name,
                'url' => $beneficiary && ! $beneficiary->trashed()
                    ? route('beneficiaries.show', $beneficiary)
                    : route('reports.index', ['date_from' => $record->released_at->copy()->timezone('Asia/Manila')->toDateString(), 'date_to' => $record->released_at->copy()->timezone('Asia/Manila')->toDateString()]),
                'time' => $record->created_at->copy()->timezone('Asia/Manila')->format('m/d/Y g:i A').' PHT',
                'sort' => $record->created_at->getTimestamp(),
            ]);
        }
        foreach (ProgramActivity::query()->latest('created_at')->latest('id')->limit(8)->get() as $record) {
            if (! Gate::allows('view', $record)) continue;
            $items->push([
                'id' => 'activity-'.$record->id,
                'title' => 'New activity added',
                'detail' => $record->title,
                'url' => route('activities.show', $record),
                'time' => $record->created_at->copy()->timezone('Asia/Manila')->format('m/d/Y g:i A').' PHT',
                'sort' => $record->created_at->getTimestamp(),
            ]);
        }
        return response()->json(['items' => $items->sortByDesc('sort')->take(8)->values()])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
'@
$componentCode = @'
<div class="rlc-notifications" data-endpoint="{{ route('dashboard.notifications') }}"
 x-data="{
    open: false, loading: false, error: '', items: [], timer: null, request: null,
    toggle() {
        this.open = !this.open;
        if (this.open) {
            this.load();
            this.timer = setInterval(() => { if (this.open) this.load(); }, 30000);
        } else { clearInterval(this.timer); }
    },
    close() { this.open = false; clearInterval(this.timer); },
    async load() {
        if (this.loading) return;
        this.loading = true; this.error = '';
        this.request = new AbortController();
        try {
            const response = await fetch(this.$root.dataset.endpoint, {
                credentials: 'same-origin', signal: this.request.signal,
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) throw new Error('Request failed');
            const data = await response.json();
            if (!Array.isArray(data.items)) throw new Error('Invalid response');
            this.items = data.items;
        } catch (error) {
            if (error.name !== 'AbortError') this.error = 'Unable to load updates. Please try again.';
        } finally { this.loading = false; }
    },
    destroy() { clearInterval(this.timer); this.request?.abort(); }
 }"
 x-on:click.outside="close()" x-on:keydown.escape.prevent.stop="close()"
 x-on:focusout="if (!$el.contains($event.relatedTarget)) close()">
    <button type="button" class="rlc-theme-button" aria-label="Notifications"
            aria-controls="rlc-notification-panel" :aria-expanded="open.toString()"
            x-on:click="toggle()">
        <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
        </svg>
    </button>
    <section id="rlc-notification-panel" class="rlc-notification-panel" aria-label="Latest system updates"
             x-cloak x-show="open" x-transition.opacity.duration.150ms>
        <div class="rlc-notification-heading">
            <div><h2>Notifications</h2><p>Latest recorded activity</p></div>
            <button type="button" x-on:click="load()" :disabled="loading" aria-label="Refresh notifications">
                <span x-text="loading ? 'Loading...' : 'Refresh'">Refresh</span>
            </button>
        </div>
        <p class="rlc-notification-message" role="status" aria-live="polite"
           x-show="error || items.length === 0"
           x-text="loading ? 'Loading updates...' : (error || 'No updates yet.')"></p>
        <ul :aria-busy="loading.toString()">
            <template x-for="item in items" :key="item.id">
                <li><a :href="item.url">
                    <strong x-text="item.title"></strong>
                    <span x-text="item.detail"></span>
                    <small x-text="item.time"></small>
                </a></li>
            </template>
        </ul>
        <p class="rlc-notification-footer">Up to 8 latest entries · Refreshes while open</p>
    </section>
</div>
@once
<style>
.rlc-notifications { position:relative; flex-shrink:0; }
.rlc-notification-panel[x-cloak] { display:none !important; }
.rlc-notification-panel { position:absolute; top:calc(100% + 10px); right:0; z-index:60; width:380px; max-width:calc(100vw - 28px); border-radius:18px; overflow:hidden; border:1px solid var(--rlc-border); background:var(--rlc-surface); color:var(--rlc-ink); box-shadow:0 18px 45px #0004; }
.rlc-notification-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:17px; border-bottom:1px solid var(--rlc-border); }
.rlc-notification-heading h2 { margin:0; font-size:15px; font-weight:700; }
.rlc-notification-heading p { margin:4px 0 0; font-size:11px; color:var(--rlc-muted); }
.rlc-notification-heading button { border:0; padding:7px 9px; border-radius:8px; font-size:11px; background:var(--rlc-tint); color:var(--rlc-violet); }
.rlc-notification-heading button:disabled { opacity:.6; }
.rlc-notification-panel ul { margin:0; padding:6px; list-style:none; max-height:min(430px,60vh); overflow-y:auto; }
.rlc-notification-panel li a { display:block; padding:13px 11px; border-radius:11px; color:var(--rlc-ink); text-decoration:none; transition:background .15s; }
.rlc-notification-panel li a:hover,.rlc-notification-panel li a:focus-visible { background:var(--rlc-tint); }
.rlc-notification-panel strong { display:block; font-size:12px; }
.rlc-notification-panel li span { display:block; font-size:12px; margin-top:4px; overflow-wrap:anywhere; }
.rlc-notification-panel small { display:block; margin-top:7px; font-size:10px; color:var(--rlc-muted); }
.rlc-notification-message { padding:18px; margin:0; font-size:12px; color:var(--rlc-muted); }
.rlc-notification-footer { margin:0; padding:12px 16px; border-top:1px solid var(--rlc-border); font-size:10px; color:var(--rlc-muted); }
@media(max-width:639px) { .rlc-notifications { order:2; }.rlc-notification-panel { width:calc(100vw - 28px); } }
@media(prefers-reduced-motion:reduce) { .rlc-notification-panel li a { transition:none; } }
</style>
@endonce
'@
$routes = [IO.File]::ReadAllText($routePath)
$view = [IO.File]::ReadAllText($viewPath)
$route = @'
    Route::get('dashboard/notifications', \App\Http\Controllers\DashboardNotificationController::class)
        ->middleware('throttle:60,1')->name('dashboard.notifications');

'@
if (-not $routes.Contains("'dashboard.notifications'")) {
    $anchor = "    Route::get('dashboard',"
    if ([regex]::Matches($routes,[regex]::Escape($anchor)).Count -ne 1) { throw 'Dashboard route anchor missing. No changes saved.' }
    $routes = $routes.Insert($routes.IndexOf($anchor), $route + [Environment]::NewLine)
}
if (-not $view.Contains('<x-rlcp-notifications />')) {
    $pattern = '(?s)<button\b(?=[^>]*class="rlc-theme-button")[^>]*>.*?</button>'
    if ([regex]::Matches($view,$pattern).Count -ne 1) { throw 'Expected one theme button. No changes saved.' }
    $view = [regex]::Replace($view,$pattern,'<x-rlcp-notifications />')
}
$changes = @(@{Path=$controllerPath;Content=$controllerCode},@{Path=$componentPath;Content=$componentCode},@{Path=$routePath;Content=$routes},@{Path=$viewPath;Content=$view})
$backupFolder = Join-Path $root ('storage\app\notification-backups\' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Path $backupFolder -Force | Out-Null
$manifest = @()
for ($i=0;$i -lt $changes.Count;$i++) {
    $change=$changes[$i]; $exists=Test-Path $change.Path
    $backup=Join-Path $backupFolder ($i.ToString()+'.bak')
    if ($exists) { Copy-Item $change.Path $backup }
    $manifest += @{Path=$change.Path;Existed=$exists;Backup=$backup}
}
$manifest | ConvertTo-Json | Set-Content (Join-Path $backupFolder 'manifest.json') -Encoding UTF8
try {
    foreach ($change in $changes) { [IO.File]::WriteAllText($change.Path,$change.Content,[Text.UTF8Encoding]::new($false)) }
    php -l $controllerPath
    if ($LASTEXITCODE -ne 0) { throw 'Controller syntax check failed.' }
    php -l $routePath
    if ($LASTEXITCODE -ne 0) { throw 'Routes syntax check failed.' }
    php artisan route:list --name=dashboard.notifications
    if ($LASTEXITCODE -ne 0) { throw 'Route check failed.' }
    php artisan view:clear
    if ($LASTEXITCODE -ne 0) { throw 'View clear failed.' }
    php artisan view:cache
    if ($LASTEXITCODE -ne 0) { throw 'Blade compilation failed.' }
    $check = @'
$user = \App\Models\User::findOrFail(1); \Illuminate\Support\Facades\Auth::setUser($user); $html = app(\App\Http\Controllers\DashboardController::class)->index()->render(); if (!str_contains($html, 'rlc-notification-panel')) { throw new \RuntimeException('Notification panel missing'); } $response = app(\App\Http\Controllers\DashboardNotificationController::class)(); $items = $response->getData(true)['items']; if (!is_array($items) || count($items) > 8) { throw new \RuntimeException('Invalid notification response'); } dump(['RLCP_NOTIFICATIONS_OK' => true, 'entries' => count($items)]);
'@
    php artisan tinker --execute="$check"
    if ($LASTEXITCODE -ne 0) { throw 'Notification query or render check failed.' }
    Write-Host "Notifications installed. Backups: $backupFolder"
} catch {
    foreach ($entry in $manifest) {
        if ($entry.Existed) { Copy-Item $entry.Backup $entry.Path -Force }
        elseif (Test-Path $entry.Path) { Remove-Item $entry.Path }
    }
    php artisan view:clear
    throw "Installation failed; original files restored. $($_.Exception.Message)"
}
