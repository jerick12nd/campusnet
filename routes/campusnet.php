<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoredDeviceController;
use App\Http\Controllers\TrafficHistoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CampusNet routes
|--------------------------------------------------------------------------
| Every route is behind authentication and a permission gate. The gates are defined
| in CampusNetServiceProvider from the UserRole permission matrix.
*/

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('can:dashboard.view')
        ->name('campusnet.dashboard');

    Route::get('traffic-history', [TrafficHistoryController::class, 'index'])
        ->middleware('can:traffic.view')
        ->name('traffic-history.index');

    Route::prefix('devices')->name('devices.')->controller(MonitoredDeviceController::class)->group(function () {
        Route::get('/', 'index')->middleware('can:devices.view')->name('index');
        Route::get('create', 'create')->middleware('can:devices.manage')->name('create');
        Route::post('/', 'store')->middleware('can:devices.manage')->name('store');

        Route::get('{device}', 'show')->whereNumber('device')->middleware('can:devices.view')->name('show');
        Route::get('{device}/edit', 'edit')->whereNumber('device')->middleware('can:devices.manage')->name('edit');
        Route::put('{device}', 'update')->whereNumber('device')->middleware('can:devices.manage')->name('update');
        Route::delete('{device}', 'destroy')->whereNumber('device')->middleware('can:devices.manage')->name('destroy');
        Route::patch('{device}/toggle', 'toggle')->whereNumber('device')->middleware('can:devices.manage')->name('toggle');

        // At most 10 tests a minute per user, so the test cannot be used to hammer a device.
        Route::post('{device}/test-connection', 'testConnection')
            ->whereNumber('device')
            ->middleware(['can:devices.manage', 'throttle:10,1'])
            ->name('test-connection');
    });
});