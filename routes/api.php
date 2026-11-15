<?php

use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\GoogleController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
 * API REST do app móvel. Tudo fica sob /api/v1; o prefixo /api vem do
 * `withRouting(api: ...)` em bootstrap/app.php. A autenticação é sempre Bearer
 * token (Sanctum); a sessão web do site continua nas rotas de routes/web.php.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        /*
         * Login/cadastro/Google: limite próprio por IP, além do throttle:api
         * global. A sessão web não é criada nem consultada.
         */
        Route::middleware('throttle:api-auth')->group(function (): void {
            Route::post('login', LoginController::class)->name('login');
            Route::post('register', RegisterController::class)->name('register');
            Route::post('google', GoogleController::class)->name('google');
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', MeController::class)->name('me');
            Route::post('logout', LogoutController::class)->name('logout');
            Route::post('email/verification-notification', EmailVerificationNotificationController::class)
                ->middleware('throttle:6,1')
                ->name('email.verification-notification');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        // Alias da etapa 02, mantido para não quebrar clientes já publicados.
        Route::get('/me', MeController::class)->name('me');
    });
});
