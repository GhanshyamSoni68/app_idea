<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Health check with a database check (docs/01 §8). Outside /v1, no auth.
Route::get('/up', function () {
    try {
        DB::select('select 1');
    } catch (\Throwable) {
        return response()->json(['status' => 'unavailable'], 503);
    }

    return response()->json(['status' => 'ok']);
});

require __DIR__.'/v1.php';

// The single-page app: every other GET is served the shell and routed in the browser.
Route::get('/{path?}', fn () => view('app'))->where('path', '^(?!v1/|up$).*$');
