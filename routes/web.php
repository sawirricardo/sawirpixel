<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ProfitReportController;
use App\Http\Controllers\Admin\ProfitReportExportController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\ApiCatalogController;
use App\Http\Controllers\Auth\EmailVerificationNoticeController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\CurrentOrganizationController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MarkNotificationsReadController;
use App\Http\Controllers\McpServerCardController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationLimitsController;
use App\Http\Controllers\OrganizationSettingsController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ReceivedInvitationController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PersonalAccessTokenController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RegeneratePersonalAccessTokenController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\AdvertiseDiscovery;
use App\Http\Middleware\NegotiateMarkdown;
use App\Http\Middleware\NoIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->middleware([AdvertiseDiscovery::class, NegotiateMarkdown::class.':home'])->name('home');
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/.well-known/api-catalog', ApiCatalogController::class)->name('api-catalog');
Route::get('/.well-known/mcp/server-card.json', McpServerCardController::class)->name('mcp.server-card');
Route::get('/terms', [LegalController::class, 'show'])->defaults('document', 'terms')->middleware([AdvertiseDiscovery::class, NegotiateMarkdown::class.':terms'])->name('terms');
Route::get('/privacy', [LegalController::class, 'show'])->defaults('document', 'privacy')->middleware([AdvertiseDiscovery::class, NegotiateMarkdown::class.':privacy'])->name('privacy');

Route::post('/generations/estimate', GenerationEstimateController::class)
    ->middleware('throttle:60,1')
    ->name('generations.estimate');

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::get('/invitations/{token}', [InvitationAcceptanceController::class, 'show'])->middleware(NoIndex::class)->name('invitations.show');
Route::post('/invitations/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'verified', 'throttle:10,1'])
    ->name('invitations.accept');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->middleware(NoIndex::class)->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.store');
});

Route::middleware(['auth', NoIndex::class])->group(function (): void {
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');
    Route::post('/notifications/read', MarkNotificationsReadController::class)->name('notifications.read');
    Route::get('/invitations/received/{invitation}', [ReceivedInvitationController::class, 'show'])->name('invitations.received');
    Route::post('/invitations/received/{invitation}', [ReceivedInvitationController::class, 'store'])
        ->middleware(['verified', 'throttle:10,1'])
        ->name('invitations.received.accept');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', EmailVerificationNoticeController::class)->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])->middleware('throttle:6,1')->name('verification.send');

    Route::get('/settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/settings/password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
    Route::post('/settings/tokens', [PersonalAccessTokenController::class, 'store'])->middleware('throttle:20,1')->name('tokens.store');
    Route::patch('/settings/tokens/{token}', [PersonalAccessTokenController::class, 'update'])->name('tokens.update');
    Route::delete('/settings/tokens/{token}', [PersonalAccessTokenController::class, 'destroy'])->name('tokens.destroy');
    Route::post('/settings/tokens/{token}/regenerate', RegeneratePersonalAccessTokenController::class)->middleware('throttle:20,1')->name('tokens.regenerate');

    Route::get('/credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('/credits/purchases', CreditPurchaseController::class)->middleware(['verified', 'throttle:20,1'])->name('credits.purchase');

    Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::put('/organizations/{organization}/current', [CurrentOrganizationController::class, 'update'])->name('organizations.switch');

    Route::get('/organization/settings', [OrganizationSettingsController::class, 'edit'])->name('organization.settings');
    Route::patch('/organization/settings', [OrganizationSettingsController::class, 'update'])->name('organization.update');
    Route::delete('/organization/settings', [OrganizationSettingsController::class, 'destroy'])->name('organization.destroy');
    Route::patch('/organization/limits', [OrganizationLimitsController::class, 'update'])->name('organization.limits');
    Route::redirect('/organization/members', '/organization/settings')->name('members.index');
    Route::patch('/organization/members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('/organization/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

    Route::post('/organization/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
    Route::put('/organization/invitations/{invitation}', [InvitationController::class, 'update'])->middleware('throttle:20,1')->name('invitations.resend');
    Route::delete('/organization/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->middleware('verified')->name('generations.store');
    Route::get('/generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');

});

/*
 * Operator-only area. Everything under /admin requires a user listed in APP_ADMINS; the access check
 * lives here, on the group, so a new admin route can't forget it. Horizon is served at /admin/horizon
 * and enforces the same gate through Horizon::auth.
 */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'can:accessAdmin', NoIndex::class])->group(function (): void {
    Route::get('/reports/profit', [ProfitReportController::class, 'index'])->name('reports.profit');
    Route::get('/reports/profit/generations.csv', ProfitReportExportController::class)->name('reports.profit.export');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
});
