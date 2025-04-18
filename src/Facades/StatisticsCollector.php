<?php

namespace BlaxSoftware\LaravelWebSockets\Facades;

use BlaxSoftware\LaravelWebSockets\Contracts\StatisticsCollector as StatisticsCollectorInterface;
use Illuminate\Support\Facades\Facade;

class StatisticsCollector extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return StatisticsCollectorInterface::class;
    }
}
