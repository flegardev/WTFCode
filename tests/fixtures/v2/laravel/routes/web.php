<?php

use App\Http\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

Route::get('/account', [AccountController::class, 'show'])->middleware('auth')->name('account.show');
