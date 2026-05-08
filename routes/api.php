<?php

use App\Http\Controllers\CvController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
	'status' => 'ok',
	'service' => 'laravel-proxy',
]));

Route::get('/cv', [CvController::class, 'staleSafe']);
Route::post('/cv/cache/clear', [CvController::class, 'clear']);
