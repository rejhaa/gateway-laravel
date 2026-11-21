<?php

declare(strict_types=1);

use App\Http\Controllers\CardController;
use Illuminate\Support\Facades\Route;

/*
| The card's endpoints. bootstrap/app.php loads this file outside the web
| group, behind RejectBearerToken and CardTokenOnly, so neither a session
| cookie nor a bearer token decides here.
*/

Route::get('/card/proposals', [CardController::class, 'index'])->name('card.proposals');
Route::post('/card/proposals/{proposal}/decision', [CardController::class, 'decide'])->name('card.decision');
