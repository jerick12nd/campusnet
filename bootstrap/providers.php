<?php

use App\Providers\AppServiceProvider;
use App\Providers\CampusNetServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    CampusNetServiceProvider::class,
];