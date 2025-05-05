<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use App\Library\BackgroundJobs\Console\Commands\WorkCommand;
use App\Library\BackgroundJobs\Console\Commands\RecoverCommand;
use App\Library\BackgroundJobs\Console\Commands\StatusCommand;
use App\Library\BackgroundJobs\Contracts\Dispatcher as DispatcherContract;
use App\Library\BackgroundJobs\Services\RedisDispatcher;
use App\Library\BackgroundJobs\Services\RedisQueue;

class BackgroundJobsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Redis Queue
        $this->app->singleton(RedisQueue::class, function ($app) {
            return new RedisQueue($app['config']);
        });

        // Dispatcher
        $this->app->singleton(DispatcherContract::class, function ($app) {
            return new RedisDispatcher(
                $app->make(RedisQueue::class),
                $app['config'],
                $app
            );
        });

        // Also bind the concrete RedisDispatcher
        $this->app->singleton(RedisDispatcher::class, function ($app) {
            return $app->make(DispatcherContract::class);
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        Log::info('BackgroundJobsServiceProvider boot method called');

        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkCommand::class,
                StatusCommand::class,
                RecoverCommand::class,
            ]);
        }

        // Publish configuration file
        $this->publishes([
            __DIR__.'/../../config/background-jobs.php' => config_path('background-jobs.php'),
        ], 'background-jobs-config');
    }
} 