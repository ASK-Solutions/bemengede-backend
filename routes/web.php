<?php

use Illuminate\Support\Facades\Route;

// The API serves JSON only. The website lives in /web and the app in /mobile.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'docs' => url('/api/v1'),
]));
