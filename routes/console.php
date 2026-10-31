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

Schedule::command('campusnet:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->description('Delete data older than the retention settings');