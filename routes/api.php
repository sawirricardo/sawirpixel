<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\EstimateController;
use App\Http\Controllers\Api\GenerationController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/**
 * The API, authenticated with personal access tokens from the profile page. Each route names the scope
 * it needs; the organization comes from the token.
 */
Route::name('api.')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    $scope = fn (TokenAbility $ability): string => CheckAbilities::class.':'.$ability->value;

    Route::get('/user', CurrentUserController::class)->name('user');

    Route::post('/estimates', EstimateController::class)->middleware($scope(TokenAbility::GenerationsRead))->name('estimates');
    Route::get('/generations', [GenerationController::class, 'index'])->middleware($scope(TokenAbility::GenerationsRead))->name('generations.index');
    Route::get('/generations/{generation}', [GenerationController::class, 'show'])->middleware($scope(TokenAbility::GenerationsRead))->name('generations.show');
    Route::post('/generations', [GenerationController::class, 'store'])->middleware($scope(TokenAbility::GenerationsCreate))->name('generations.store');

    Route::get('/credits', CreditController::class)->middleware($scope(TokenAbility::CreditsRead))->name('credits');
});
