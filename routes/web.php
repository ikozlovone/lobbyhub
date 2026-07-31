<?php

use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
|
| Server-rendered pages next to the data they read, so none of this becomes a
| public API endpoint that would have to be defended separately.
|
| There is no authentication yet: the URL is the whole of the access control,
| which means anyone who guesses /admin sees the catalog's internals and a list
| of everyone who has signed in. Deliberate for now, and the first thing to
| close — an nginx allow/deny on this prefix is two lines, a real login not much
| more.
|
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [MonitoringController::class, 'index'])->name('monitoring');
    Route::get('users', [UserController::class, 'index'])->name('users');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
});
