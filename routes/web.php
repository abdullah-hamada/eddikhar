<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| The dashboard is a single-page React application. Every non-API URL
| renders the same Blade shell which mounts the React router.
*/

Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|up|build|storage|vendor|_ignition).*$');
