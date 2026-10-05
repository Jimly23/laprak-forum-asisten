<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::get('/classes', [ClassroomController::class, 'index']);
    Route::get('/courses', [CourseController::class, 'index']);
    Route::post('/submissions', [SubmissionController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/submissions', [SubmissionController::class, 'index']);
        Route::get('/submissions/{submission}', [SubmissionController::class, 'show']);
        Route::get('/submissions/{submission}/file', [SubmissionController::class, 'file']);
        Route::put('/submissions/{submission}/grade', [GradeController::class, 'store']);

        Route::get('/grades', [GradeController::class, 'index']);
        Route::get('/grades/export', [GradeController::class, 'export']);
        Route::get('/grades/export-all', [GradeController::class, 'exportAll']);

        Route::post('/classes', [ClassroomController::class, 'store']);
        Route::get('/classes/{classroom}', [ClassroomController::class, 'show']);
        Route::patch('/classes/{classroom}', [ClassroomController::class, 'update']);
        Route::delete('/classes/{classroom}', [ClassroomController::class, 'destroy']);

        Route::post('/courses', [CourseController::class, 'store']);
        Route::get('/courses/{course}', [CourseController::class, 'show']);
        Route::patch('/courses/{course}', [CourseController::class, 'update']);
        Route::delete('/courses/{course}', [CourseController::class, 'destroy']);

        Route::middleware('super_admin')->group(function () {
            Route::get('/admins', [AdminController::class, 'index']);
            Route::post('/admins', [AdminController::class, 'store']);
            Route::delete('/admins/{admin}', [AdminController::class, 'destroy']);
        });
    });
});
