<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Laravel\Horizon\HorizonApplicationServiceProvider;

$providers = [
    AppServiceProvider::class,
];

if (class_exists(HorizonApplicationServiceProvider::class)) {
    $providers[] = HorizonServiceProvider::class;
}

return $providers;
