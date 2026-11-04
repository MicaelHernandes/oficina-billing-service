<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APCng;
use Prometheus\Storage\InMemory;

class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CollectorRegistry::class, function () {
            if (config('metrics.storage') === 'apcu' && extension_loaded('apcu')) {
                return new CollectorRegistry(new APCng);
            }

            return new CollectorRegistry(new InMemory);
        });
    }
}
