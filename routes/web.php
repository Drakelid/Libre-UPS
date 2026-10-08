<?php

use Drakelid\UpsBattery\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('plugin/ups-battery')->group(function (): void {
    Route::get('report', [ReportController::class, 'page'])->name('ups-battery.report');
    Route::get('data', [ReportController::class, 'data'])->name('ups-battery.data');
    Route::get('matrix', [ReportController::class, 'matrix'])->name('ups-battery.matrix');
    Route::get('options', [ReportController::class, 'options'])->name('ups-battery.options');
    Route::get('assets/report.js', [ReportController::class, 'script'])->name('ups-battery.script');

    Route::get('views', [ReportController::class, 'views'])->name('ups-battery.views');
    Route::post('views', [ReportController::class, 'saveView'])->name('ups-battery.views.save');
    Route::post('views/delete', [ReportController::class, 'deleteView'])->name('ups-battery.views.delete');
});
