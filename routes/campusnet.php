<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BandwidthAnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoredDeviceController;
use App\Http\Controllers\NetworkInterfaceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\TrafficHistoryController;
use App\Http\Controllers\TvDisplayAdminController;
use App\Http\Controllers\TvDisplayController;
use App\Http\Controllers\TvDisplayPairingController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsureTvDisplayAccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CampusNet routes
|--------------------------------------------------------------------------
| Every route is behind authentication and a permission gate, except the TV wallboard
| routes below, which also accept a paired display. The gates are defined in
| CampusNetServiceProvider from the UserRole permission matrix.
*/

// TV wallboard: a signed-in user with tv-display.view, or a paired display (cookie) may view it.
Route::get('tv-display/pair/{code}', [TvDisplayPairingController::class, 'redeem'])
    ->where('code', '[A-Za-z0-9]{40}')
    ->middleware('throttle:10,1')
    ->name('tv-display.pair');

Route::middleware(EnsureTvDisplayAccess::class)->group(function () {
    Route::get('tv-display', [TvDisplayController::class, 'show'])->name('tv-display.show');

    Route::get('tv-display/data', [TvDisplayController::class, 'data'])
        ->middleware('throttle:120,1')
        ->name('tv-display.data');
});

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('can:dashboard.view')
        ->name('campusnet.dashboard');

    Route::get('traffic-history', [TrafficHistoryController::class, 'index'])
        ->middleware('can:traffic.view')
        ->name('traffic-history.index');

    Route::get('bandwidth-analytics', [BandwidthAnalyticsController::class, 'index'])
        ->middleware('can:traffic.view')
        ->name('bandwidth-analytics.index');

    Route::get('interfaces', [NetworkInterfaceController::class, 'index'])
        ->middleware('can:interfaces.view')
        ->name('interfaces.index');

    Route::patch('interfaces/{interface}', [NetworkInterfaceController::class, 'update'])
        ->whereNumber('interface')
        ->middleware('can:interfaces.manage')
        ->name('interfaces.update');

    Route::get('alerts', [AlertController::class, 'index'])
        ->middleware('can:alerts.view')
        ->name('alerts.index');

    Route::patch('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])
        ->whereNumber('alert')
        ->middleware('can:alerts.manage')
        ->name('alerts.acknowledge');

    Route::patch('alerts/{alert}/resolve', [AlertController::class, 'resolve'])
        ->whereNumber('alert')
        ->middleware('can:alerts.manage')
        ->name('alerts.resolve');

    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('can:reports.view')
        ->name('reports.index');

    // At most 20 exports a minute per user.
    Route::get('reports/download', [ReportController::class, 'download'])
        ->middleware(['can:reports.export', 'throttle:20,1'])
        ->name('reports.download');

    // /settings/* belongs to the starter kit (profile, security, teams), so CampusNet settings live elsewhere.
    Route::get('system-settings', [SystemSettingsController::class, 'edit'])
        ->middleware('can:settings.view')
        ->name('system-settings.edit');

    Route::patch('system-settings', [SystemSettingsController::class, 'update'])
        ->middleware('can:settings.manage')
        ->name('system-settings.update');

    Route::get('users', [UserController::class, 'index'])
        ->middleware('can:users.manage')
        ->name('users.index');

    Route::patch('users/{user}', [UserController::class, 'update'])
        ->whereNumber('user')
        ->middleware('can:users.manage')
        ->name('users.update');

    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('can:audit.view')
        ->name('audit-logs.index');

    // TV display access management (Super Administrator only).
    Route::get('tv-displays', [TvDisplayAdminController::class, 'index'])
        ->middleware('can:tv-display.manage')
        ->name('tv-displays.index');

    Route::post('tv-displays', [TvDisplayAdminController::class, 'store'])
        ->middleware(['can:tv-display.manage', 'throttle:10,1'])
        ->name('tv-displays.store');

    Route::delete('tv-displays/{display}', [TvDisplayAdminController::class, 'destroy'])
        ->whereNumber('display')
        ->middleware('can:tv-display.manage')
        ->name('tv-displays.destroy');

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