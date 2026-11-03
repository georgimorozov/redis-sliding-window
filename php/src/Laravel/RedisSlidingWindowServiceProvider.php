<?php

declare(strict_types=1);

namespace GeorgiMorozov\RedisSlidingWindow\Laravel;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/** Auto-discovered (composer.json extra.laravel): the config, the Limiters registry and the middleware alias. */
final class RedisSlidingWindowServiceProvider extends ServiceProvider
{
    public const MIDDLEWARE = 'sliding-window';
    private const CONFIG = __DIR__ . '/../../config/redis-sliding-window.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'redis-sliding-window');
        $this->app->singleton(Limiters::class);
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG => $this->app->configPath('redis-sliding-window.php')], 'redis-sliding-window-config');
        $router = $this->app->make('router');
        if ($router instanceof Router) {
            $router->aliasMiddleware(self::MIDDLEWARE, LimitRequests::class);
        }
    }
}
