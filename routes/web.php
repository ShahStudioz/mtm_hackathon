<?php

use App\Http\Controllers\PlanDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PlanDashboardController::class, 'index'])->name('home');
Route::get('/export/{type}', [PlanDashboardController::class, 'export'])->name('plan.export');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [PlanDashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
