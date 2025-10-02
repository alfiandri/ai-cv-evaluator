<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\EvaluationController;

Route::post('/upload', [UploadController::class, 'upload']);
Route::post('/evaluate', [EvaluationController::class, 'evaluate']);
Route::get('/result/{id}', [EvaluationController::class, 'result']);
