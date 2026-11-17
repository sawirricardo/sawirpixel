<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ProfitReportController;
use App\Http\Controllers\Admin\ProfitReportExportController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CurrentOrganizationController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::post('/generations/estimate', GenerationEstimateController::class)
    ->middleware('throttle:60,1')
    ->name('generations.estimate');

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::get('/invitations/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::put('/organizations/{organization}/current', [CurrentOrganizationController::class, 'update'])->name('organizations.switch');

    Route::get('/organization/members', [MemberController::class, 'index'])->name('members.index');
    Route::patch('/organization/members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('/organization/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

    Route::post('/organization/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
    Route::put('/organization/invitations/{invitation}', [InvitationController::class, 'update'])->middleware('throttle:20,1')->name('invitations.resend');
    Route::delete('/organization/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::get('/admin/reports/profit', [ProfitReportController::class, 'index'])->name('admin.reports.profit');
    Route::get('/admin/reports/profit/generations.csv', ProfitReportExportController::class)->name('admin.reports.profit.export');
});
