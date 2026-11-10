<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command('campusnet:poll')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->description('Poll monitored devices that are due');

Schedule::command('campusnet:evaluate-alerts')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->description('Raise and resolve alerts from the stored measurements');

Schedule::command('campusnet:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->description('Delete data older than the retention settings');

Schedule::command('network:scan')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->description('Scan office subnets for online devices');

Schedule::command('network:prune')
    ->dailyAt('03:30')
    ->timezone('Asia/Manila')
    ->withoutOverlapping()
    ->description('Delete network scan data older than 30 days');