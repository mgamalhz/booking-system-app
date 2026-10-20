<?php

use App\Http\Controllers\NPlusOneDemoController;
use Illuminate\Support\Facades\Route;

Route::get('/demo/n-plus-one', NPlusOneDemoController::class);

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
    ]);
});
