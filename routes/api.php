<?php

use App\Http\Controllers\DiligenceController;
use Illuminate\Support\Facades\Route;

Route::post('/diligences', [DiligenceController::class, 'store']);
Route::get('/diligences/{diligence}', [DiligenceController::class, 'show']);
Route::post('/diligences/{diligence}/items/{item}/retry', [DiligenceController::class, 'retryItem'])->scopeBindings();
Route::post('/diligences/{diligence}/evaluate', [DiligenceController::class, 'evaluate']);
