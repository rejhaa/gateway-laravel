<?php

declare(strict_types=1);

use App\Http\Controllers\SignInController;
use App\Http\Middleware\RejectBearerToken;
use App\Livewire\Approvals;
use App\Livewire\AuditLog;
use App\Livewire\History;
use Illuminate\Support\Facades\Route;

/*
| The three pages decide with the web session. RejectBearerToken keeps
| Claude's token out of them, and AppServiceProvider puts it on Livewire's
| update endpoint too, where the approve and reject actions run.
*/

Route::middleware([RejectBearerToken::class, 'auth'])->group(function (): void {
    Route::livewire('/', Approvals::class)->name('approvals');
    Route::livewire('/history', History::class)->name('history');
    Route::livewire('/audit', AuditLog::class)->name('audit');
});

Route::get('/login', SignInController::class)->name('login');
