$ErrorActionPreference = 'Stop'
Set-Location 'C:\rlcsystem\app'
$viewPath = Join-Path (Get-Location) 'resources\views\beneficiaries\index.blade.php'
$routePath = Join-Path (Get-Location) 'routes\web.php'
$controllerPath = Join-Path (Get-Location) 'app\Http\Controllers\BeneficiaryBulkArchiveController.php'
$view = [IO.File]::ReadAllText($viewPath).Replace("`r`n", "`n")
$routes = [IO.File]::ReadAllText($routePath)
if ($view.Contains('rlcp-bulk-archive-controls')) { throw 'Bulk actions already installed. No changes saved.' }
function Replace-Once([string]$text, [string]$anchor, [string]$replacement) {
    if ([regex]::Matches($text, [regex]::Escape($anchor)).Count -ne 1) { throw "Expected exactly one layout anchor: $anchor. No changes saved." }
    return $text.Replace($anchor, $replacement)
}
$controllerCode = @'
<?php
namespace App\Http\Controllers;

use App\Models\Beneficiary;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BeneficiaryBulkArchiveController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Gate::authorize('viewArchived', Beneficiary::class);
        $validated = $request->validate([
            'beneficiary_ids' => ['required', 'array', 'min:1', 'max:100'],
            'beneficiary_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $ids = $validated['beneficiary_ids'];
        $count = DB::transaction(function () use ($ids) {
            $beneficiaries = Beneficiary::query()->whereIn('id', $ids)
                ->orderBy('id')->lockForUpdate()->get();
            if ($beneficiaries->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'beneficiary_ids' => 'Some selected records are unavailable or already archived. Refresh the page and select again.',
                ]);
            }
            foreach ($beneficiaries as $beneficiary) {
                Gate::authorize('delete', $beneficiary);
            }
            foreach ($beneficiaries as $beneficiary) {
                $beneficiary->delete();
            }
            return $beneficiaries->count();
        });
        return redirect()->route('beneficiaries.index')->with('success',
            $count.' '.($count === 1 ? 'beneficiary archived.' : 'beneficiaries archived.'));
    }
}

'@
$state = @'
@php
        $archiveIds = $beneficiaries->getCollection()
            ->filter(fn ($beneficiary) => \Illuminate\Support\Facades\Gate::allows('delete', $beneficiary))
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    @endphp
    <div class="rlc-dashboard rlc-registry" x-data="{
        mode: 'list', selecting: false, selected: [], pending: [], saving: false,
        pageIds: {{ \Illuminate\Support\Js::from($archiveIds) }},
        selectAll() { this.selecting = true; this.selected = [...this.pageIds]; },
        clearSelection() { this.selected = []; this.selecting = false; },
        confirmArchive(ids) { this.pending = [...ids]; this.$refs.archiveDialog.showModal(); }
    }">
'@
$controls = @'
{{-- rlcp-bulk-archive-controls --}}
                @can('viewArchived', \App\Models\Beneficiary::class)
                    <details class="rlcr-more" x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false">
                        <summary aria-label="Selection options" title="Selection options">&#8943;</summary>
                        <div class="rlcr-menu">
                            <button type="button" x-on:click="selecting = true; $el.closest('details').open = false">Select records</button>
                            <button type="button" x-on:click="selectAll(); $el.closest('details').open = false">Select all on this page</button>
                            <button type="button" x-on:click="clearSelection(); $el.closest('details').open = false">Clear selection</button>
                        </div>
                    </details>
                @endcan

'@
$bar = @'
@if ($errors->has('beneficiary_ids') || $errors->has('beneficiary_ids.*'))
                <div class="rlcr-bulk-error" role="alert">{{ $errors->first('beneficiary_ids') ?: $errors->first('beneficiary_ids.*') }}</div>
            @endif
            @can('viewArchived', \App\Models\Beneficiary::class)
                <div class="rlcr-selection" x-cloak x-show="selecting">
                    <label><input type="checkbox" :checked="pageIds.length > 0 &amp;&amp; selected.length === pageIds.length"
                        x-effect="$el.indeterminate = selected.length > 0 &amp;&amp; selected.length < pageIds.length"
                        x-on:change="selected = $event.target.checked ? [...pageIds] : []" :disabled="!pageIds.length"> Select all on this page</label>
                    <span x-text="selected.length + ' selected'"></span>
                    <button type="button" class="rlcr-button rlcr-button-secondary" :disabled="selected.length === 0"
                        x-on:click="confirmArchive(selected)">Archive Selected</button>
                    <button type="button" class="rlcr-button rlcr-button-secondary" x-on:click="clearSelection()">Cancel</button>
                </div>
            @endcan

'@
$checkbox = @'
@can('delete', $beneficiary)
                                            <input class="rlcr-checkbox" type="checkbox" x-cloak x-show="selecting"
                                                value="{{ $beneficiary->id }}" x-model="selected"
                                                aria-label="{{ 'Select '.$beneficiary->full_name }}">
                                        @endcan

'@
$actions = @'
<td class="rlcr-row-actions">
                                    <details class="rlcr-more" x-on:click.outside="$el.open = false" x-on:keydown.escape="$el.open = false">
                                        <summary aria-label="{{ 'Actions for '.$beneficiary->full_name }}" title="Beneficiary actions">&#8943;</summary>
                                        <div class="rlcr-menu">
                                            @can('view', $beneficiary)
                                                <a href="{{ route('beneficiaries.show', $beneficiary) }}">View Profile</a>
                                            @endcan
                                            @can('update', $beneficiary)
                                                <a href="{{ route('beneficiaries.edit', $beneficiary) }}">Edit Profile</a>
                                            @endcan
                                            @can('delete', $beneficiary)
                                                <button type="button" x-on:click="confirmArchive(['{{ $beneficiary->id }}']); $el.closest('details').open = false">Archive</button>
                                            @endcan
                                        </div>
                                    </details>
                                </td>
'@
$modal = @'
@can('viewArchived', \App\Models\Beneficiary::class)
        <dialog x-ref="archiveDialog" class="rlcr-archive-dialog" aria-labelledby="rlcr-archive-title"
            x-on:cancel="if (saving) $event.preventDefault()">
            <h2 id="rlcr-archive-title">Archive selected beneficiaries?</h2>
            <p><strong x-text="pending.length"></strong> selected. Profiles, photos, attendance, and assistance history will be retained. You can restore them from View Archived.</p>
            <form method="POST" action="{{ route('beneficiaries.bulk-archive') }}"
                x-on:submit="if (saving) { $event.preventDefault(); } else { saving = true; }">
                @csrf
                <template x-for="id in pending" :key="id"><input type="hidden" name="beneficiary_ids[]" :value="id"></template>
                <div class="rlcr-actions">
                    <button type="button" class="rlcr-button rlcr-button-secondary" :disabled="saving" x-on:click="$refs.archiveDialog.close()">Cancel</button>
                    <button type="submit" class="rlcr-button rlcr-button-primary" :disabled="saving || !pending.length" :aria-busy="saving">
                        <span x-cloak x-show="saving" class="rlcr-saving-spinner" aria-hidden="true"></span>
                        <span x-text="saving ? 'Archiving...' : 'Archive'"></span>
                    </button>
                </div>
                <p role="status" x-cloak x-show="saving">Saving changes. Please wait...</p>
            </form>
        </dialog>
        @endcan

'@
$extraCss = @'

    /* rlcp-bulk-archive-style */
    .rlcr-directory { overflow:visible; }
    .rlcr-directory-top { justify-content:flex-start; }
    .rlcr-view-switch { margin-left:auto; }
    .rlcr-more { position:relative; display:inline-block; }
    .rlcr-more summary { display:flex; align-items:center; justify-content:center; width:38px; height:38px; border:1px solid var(--rlc-border); border-radius:10px; cursor:pointer; list-style:none; font-size:26px; line-height:1; }
    .rlcr-more summary::-webkit-details-marker { display:none; }
    .rlcr-more[open] { z-index:20; }
    .rlcr-menu { position:absolute; right:0; top:calc(100% + 5px); width:200px; padding:6px; background:#fff; border:1px solid var(--rlc-border); border-radius:12px; box-shadow:0 8px 24px #0002; }
    .dark .rlcr-menu { background:#191a23; }
    .rlcr-menu>a,.rlcr-menu>button { display:block; width:100%; text-align:left; padding:10px; border-radius:7px; font-size:12px; }
    .rlcr-menu>a:hover,.rlcr-menu>button:hover { background:var(--rlc-tint); }
    .rlcr-selection { display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:14px 24px; border-top:1px solid var(--rlc-border); background:var(--rlc-soft); font-size:12px; }
    .rlcr-selection label { display:flex; align-items:center; gap:8px; }
    .rlcr-checkbox,.rlcr-selection input { width:17px; height:17px; accent-color:#7439f7; flex-shrink:0; }
    .rlcr-button:disabled { opacity:.5; cursor:wait; transform:none; }
    .rlcr-bulk-error { margin:0 24px 16px; color:#b91c1c; font-size:13px; }
    .rlcr-archive-dialog { margin:auto; max-width:480px; width:calc(100% - 32px); padding:26px; border-radius:20px; background:var(--rlc-surface); color:var(--rlc-ink); border:1px solid var(--rlc-border); }
    .rlcr-archive-dialog::backdrop { background:#0007; }
    .rlcr-archive-dialog h2 { font-size:20px; font-weight:700; }
    .rlcr-archive-dialog p { font-size:13px; line-height:1.7; margin:15px 0; }
    .rlcr-saving-spinner { width:16px; height:16px; border:2px solid #fff6; border-top-color:white; border-radius:50%; animation:rlcr-spin .7s linear infinite; }
    @keyframes rlcr-spin { to { transform:rotate(360deg); } }
    @media(prefers-reduced-motion:reduce) { .rlcr-saving-spinner { animation:none; } }

'@

$updatedView = Replace-Once $view '<div class="rlc-dashboard rlc-registry" x-data="{ mode: ''list'' }">' $state
$updatedView = Replace-Once $updatedView '            </div>
            <div class="rlcr-filter-row">' ($controls + "`n" + '            </div>
            <div class="rlcr-filter-row">')
$updatedView = Replace-Once $updatedView '            @if ($beneficiaries->count())' ($bar + "`n" + '            @if ($beneficiaries->count())')
$updatedView = Replace-Once $updatedView '<div class="rlcr-person">' ('<div class="rlcr-person">' + "`n" + $checkbox)
$actionStart = $updatedView.IndexOf('<td class="rlcr-row-actions">')
if ($actionStart -lt 0) { throw 'Row actions not found. No changes saved.' }
$actionEnd = $updatedView.IndexOf('</td>', $actionStart)
if ($actionEnd -lt 0) { throw 'Row actions ending not found. No changes saved.' }
$updatedView = $updatedView.Substring(0,$actionStart) + $actions + $updatedView.Substring($actionEnd + 5)
$updatedView = Replace-Once $updatedView '        </section>' ('        </section>' + "`n" + $modal)
$updatedView = Replace-Once $updatedView '    </style>' ($extraCss + "`n" + '    </style>')
$routeAnchor = "Route::middleware(['auth', 'verified'])->group(function () {"
if ($routes.Contains("'beneficiaries.bulk-archive'")) { throw 'Bulk route already exists. No changes saved.' }
$routeCode = @'
    Route::post('beneficiaries/bulk-archive', \App\Http\Controllers\BeneficiaryBulkArchiveController::class)
        ->name('beneficiaries.bulk-archive');
'@
$updatedRoutes = Replace-Once $routes $routeAnchor ($routeAnchor + "`n" + $routeCode)
$stamp = Get-Date -Format 'yyyyMMddHHmmssfff'
$changes = @(
    @{Path=$viewPath; Content=$updatedView},
    @{Path=$routePath; Content=$updatedRoutes},
    @{Path=$controllerPath; Content=$controllerCode}
)
foreach ($change in $changes) {
    $change.Existed = Test-Path $change.Path
    $change.Backup = $change.Path + '.' + $stamp + '.bak'
    if ($change.Existed) { Copy-Item $change.Path $change.Backup -ErrorAction Stop }
}
try {
    foreach ($change in $changes) { [IO.File]::WriteAllText($change.Path,$change.Content,[Text.UTF8Encoding]::new($false)) }
    php -l $controllerPath
    if ($LASTEXITCODE -ne 0) { throw 'Controller syntax check failed.' }
    php -l $routePath
    if ($LASTEXITCODE -ne 0) { throw 'Route syntax check failed.' }
    php artisan view:clear
    if ($LASTEXITCODE -ne 0) { throw 'View clear failed.' }
    php artisan view:cache
    if ($LASTEXITCODE -ne 0) { throw 'View compile failed.' }
    $renderTest = @'
$user = \App\Models\User::findOrFail(1); \Illuminate\Support\Facades\Auth::setUser($user); $request = \Illuminate\Http\Request::create('/beneficiaries','GET'); \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag()); $html = app(\App\Http\Controllers\BeneficiaryController::class)->index($request)->render(); if (!str_contains($html,'Select all on this page')) { throw new \RuntimeException('Selection controls missing.'); } if (!\Illuminate\Support\Facades\Route::has('beneficiaries.bulk-archive')) { throw new \RuntimeException('Bulk route missing.'); } dump('RLCP_BULK_ARCHIVE_RENDER_OK');
'@
    php artisan tinker --execute="$renderTest"
    if ($LASTEXITCODE -ne 0) { throw 'Render check failed.' }
    Write-Host 'Three-dot menus and bulk archive installed. Refresh Beneficiaries.'
    Write-Host 'Select All applies to the current page. No beneficiary records were changed by installation.'
} catch {
    foreach ($change in $changes) {
        if ($change.Existed) { Copy-Item $change.Backup $change.Path -Force }
        elseif (Test-Path $change.Path) { Remove-Item $change.Path }
    }
    php artisan view:clear
    throw "Installation failed; original files restored. $($_.Exception.Message)"
}
