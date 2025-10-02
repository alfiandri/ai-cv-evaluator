<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\Auth\TokenController;

Route::post('/auth/token', [TokenController::class, 'issue']);

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('/upload', [UploadController::class, 'upload']);
    Route::post('/evaluate', [EvaluationController::class, 'evaluate']);
    Route::get('/result/{id}', [EvaluationController::class, 'result']);
});
