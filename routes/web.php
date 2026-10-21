<?php

use App\Http\Controllers\AttendantDirectoryController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('atendentes', [AttendantDirectoryController::class, 'index'])->name('attendants.index');
Route::get('atendentes/{attendant}', [AttendantDirectoryController::class, 'show'])
    ->whereNumber('attendant')
    ->name('attendants.show');

Route::get('trabalhe-conosco', [PageController::class, 'workWithUs'])->name('work-with-us.index');
Route::get('termos', [PageController::class, 'terms'])->name('legal.terms');
Route::get('privacidade', [PageController::class, 'privacy'])->name('legal.privacy');
Route::get('contato', [PageController::class, 'contact'])->name('contact');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('admin', [DashboardController::class, 'admin'])->middleware('role:admin')->name('admin.dashboard');
    Route::get('attendant', [DashboardController::class, 'attendant'])->middleware('role:attendant')->name('attendant.dashboard');
    Route::get('client', [DashboardController::class, 'client'])->middleware('role:client')->name('client.dashboard');
});
