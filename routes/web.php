<?php

use App\Http\Controllers\AccountNotificationController;
use App\Http\Controllers\AccountRequestController;
use App\Http\Controllers\AccountStatusController;
use App\Http\Controllers\ActivityAttendanceController;
use App\Http\Controllers\ActivityAttendanceExportController;
use App\Http\Controllers\AssistanceTransactionController;
use App\Http\Controllers\BeneficiaryBulkArchiveController;
use App\Http\Controllers\BeneficiaryBulkRestoreController;
use App\Http\Controllers\BeneficiaryCaseworkerAssignmentController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\BeneficiaryNfcController;
use App\Http\Controllers\BeneficiarySearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardNotificationController;
use App\Http\Controllers\MyCaseloadController;
use App\Http\Controllers\NfcAttendanceController;
use App\Http\Controllers\NfcLookupController;
use App\Http\Controllers\ProgramActivityController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReportPdfExportController;
use App\Http\Middleware\EnsureStaffAccess;
use App\Models\Beneficiary;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');
Route::view('/about', 'public.about')->name('about');
Route::view('/programs', 'public.programs')->name('programs');
Route::view('/community/activities', 'public.activities')->name('public.activities.index');
Route::view('/application', 'public.application')->name('program-application');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegistrationController::class, 'show'])->name('register');
    Route::get('register/code', [RegistrationController::class, 'showCode'])->name('registration.code');
    Route::post('register/code', [RegistrationController::class, 'verifyCode'])
        ->middleware('throttle:10,1')->name('registration.code.verify');
    Route::post('register', [RegistrationController::class, 'requestLink'])
        ->middleware('throttle:registration-email')->name('registration.request');
    Route::get('register/verify/{token}', [RegistrationController::class, 'verify'])
        ->middleware('throttle:60,1')->name('registration.verify');
    Route::get('register/complete', [RegistrationController::class, 'showComplete'])->name('registration.complete');
    Route::post('register/complete', [RegistrationController::class, 'complete'])
        ->middleware('throttle:10,1')->name('registration.complete.store');
    Route::get('register/awaiting-approval', [RegistrationController::class, 'awaitingApproval'])->name('registration.awaiting-approval');
});

Route::get('account/status', AccountStatusController::class)->middleware('auth')->name('account.status');

Route::middleware(['auth', 'throttle:120,1'])->prefix('account/notifications')->name('account.notifications.')->group(function () {
    Route::get('/', [AccountNotificationController::class, 'index'])->name('index');
    Route::get('unread-count', [AccountNotificationController::class, 'count'])->name('count');
    Route::post('read-all', [AccountNotificationController::class, 'readAll'])->name('read-all');
    Route::post('{notification}/read', [AccountNotificationController::class, 'read'])->whereUuid('notification')->name('read');
});

Route::middleware(['auth', 'verified', EnsureStaffAccess::class])->group(function () {
    Route::get('account-requests', [AccountRequestController::class, 'index'])
        ->can('reviewAccountRequests', User::class)->name('account-requests.index');
    Route::post('account-requests/{user}/review', [AccountRequestController::class, 'review'])
        ->can('reviewAccountRequests', User::class)->name('account-requests.review');

    Route::post('beneficiaries/assign-caseworker', BeneficiaryCaseworkerAssignmentController::class)
        ->name('beneficiaries.assign-caseworker');

    Route::post('beneficiaries/bulk-restore', BeneficiaryBulkRestoreController::class)
        ->name('beneficiaries.bulk-restore');
    Route::get('beneficiaries/archived/{beneficiary}/photo', [BeneficiaryBulkRestoreController::class, 'photo'])
        ->whereNumber('beneficiary')
        ->name('beneficiaries.archived-photo');

    Route::post('beneficiaries/bulk-archive', BeneficiaryBulkArchiveController::class)
        ->name('beneficiaries.bulk-archive');
    Route::get('reports/export/{type}', ReportExportController::class)
        ->whereIn('type', ['attendance', 'assistance'])
        ->name('reports.export');

    Route::get('reports/pdf/{type}', ReportPdfExportController::class)
        ->whereIn('type', ['attendance', 'assistance'])
        ->name('reports.pdf');

    Route::get('reports', [ReportController::class, 'index'])
        ->name('reports.index');

    Route::get('nfc', [NfcLookupController::class, 'index'])
        ->name('nfc.index');

    Route::post('nfc/lookup', [NfcLookupController::class, 'lookup'])
        ->name('nfc.lookup');

    Route::get('dashboard/beneficiary-suggestions', BeneficiarySearchController::class)
        ->middleware('throttle:120,1')
        ->name('beneficiaries.suggestions');

    Route::get('dashboard/notifications', DashboardNotificationController::class)
        ->middleware('throttle:60,1')->name('dashboard.notifications');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('dashboard/caseload', [DashboardController::class, 'caseload'])
        ->middleware('throttle:30,1')->name('dashboard.caseload');
    Route::get('my-caseload', [MyCaseloadController::class, 'index'])->name('my-caseload.index');

    Route::get('attendance', [ProgramActivityController::class, 'attendance'])
        ->name('attendance.index');

    Route::get('activities', [ProgramActivityController::class, 'index'])
        ->name('activities.index');

    Route::get('activities/create', [ProgramActivityController::class, 'create'])
        ->name('activities.create');

    Route::post('activities', [ProgramActivityController::class, 'store'])
        ->name('activities.store');

    Route::post('activities/{activity}/attendance', [ActivityAttendanceController::class, 'store'])
        ->name('activities.attendance.store');

    Route::get('activities/{activity}/edit', [ProgramActivityController::class, 'edit'])
        ->name('activities.edit');

    Route::put('activities/{activity}', [ProgramActivityController::class, 'update'])
        ->name('activities.update');

    Route::get('activities/{activity}/attendance/export', ActivityAttendanceExportController::class)
        ->name('activities.attendance.export');

    Route::post('activities/{activity}/attendance/nfc', [NfcAttendanceController::class, 'store'])
        ->name('activities.attendance.nfc');

    Route::get('activities/{activity}', [ProgramActivityController::class, 'show'])
        ->name('activities.show');

    Route::get('beneficiaries/archived', [BeneficiaryController::class, 'archived'])
        ->name('beneficiaries.archived');

    Route::delete('beneficiaries/{beneficiary}', [BeneficiaryController::class, 'destroy'])
        ->name('beneficiaries.destroy');

    Route::post('beneficiaries/{beneficiary}/restore', [BeneficiaryController::class, 'restore'])
        ->name('beneficiaries.restore');

    Route::get('beneficiaries', [BeneficiaryController::class, 'index'])
        ->name('beneficiaries.index');

    Route::get('beneficiaries/create', [BeneficiaryController::class, 'create'])
        ->name('beneficiaries.create');

    Route::post('beneficiaries/nfc/check', [BeneficiaryNfcController::class, 'check'])
        ->name('beneficiaries.nfc.check');

    Route::post('beneficiaries', [BeneficiaryController::class, 'store'])
        ->name('beneficiaries.store');

    Route::get('beneficiaries/{beneficiary}/edit', [BeneficiaryController::class, 'edit'])
        ->name('beneficiaries.edit');

    Route::put('beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update'])
        ->name('beneficiaries.update');

    Route::get('beneficiaries/{beneficiary}/assistance/create', [AssistanceTransactionController::class, 'create'])
        ->name('beneficiaries.assistance.create');

    Route::post('beneficiaries/{beneficiary}/assistance', [AssistanceTransactionController::class, 'store'])
        ->name('beneficiaries.assistance.store');

    Route::put('beneficiaries/{beneficiary}/nfc', [BeneficiaryNfcController::class, 'update'])
        ->name('beneficiaries.nfc.update');

    Route::delete('beneficiaries/{beneficiary}/nfc', [BeneficiaryNfcController::class, 'destroy'])
        ->name('beneficiaries.nfc.destroy');

    Route::get('beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show'])
        ->name('beneficiaries.show');
});

require __DIR__.'/settings.php';

Route::get('/beneficiaries/{beneficiary}/photo', function (Beneficiary $beneficiary) {
    $disk = Storage::disk('local');

    abort_unless(
        $beneficiary->photo_path && $disk->exists($beneficiary->photo_path),
        404
    );

    return $disk->response($beneficiary->photo_path, null, [
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->middleware(['auth', 'verified', EnsureStaffAccess::class])->can('view', 'beneficiary')->name('beneficiaries.photo');
