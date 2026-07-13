<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Http\Middleware\CacheApiResponses;
use App\Support\CronSettings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Register only the jobs enabled in storage/app/cron-settings.json, each on
        // its own cron expression. The system cron must run `php artisan schedule:run`
        // every minute for these to actually fire.
        try {
            foreach (CronSettings::JOBS as $job) {
                $config = CronSettings::job($job);
                if (! ($config['active'] ?? false) || empty($config['cron'])) {
                    continue;
                }

                $schedule->command("sync:run {$job}")
                    ->cron($config['cron'])
                    ->withoutOverlapping()
                    ->runInBackground();
            }
        } catch (\Throwable) {
            // Never let a malformed schedule config break the console kernel.
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [CacheApiResponses::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
