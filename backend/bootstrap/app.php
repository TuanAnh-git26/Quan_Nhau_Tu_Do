<?php

use App\Http\Middleware\EnsureBranchScope;
use App\Http\Middleware\MeasureApiPerformance;
use App\Http\Middleware\RequireUseCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session + XSRF cookie, không dùng token trong localStorage.
        $middleware->statefulApi();
        $middleware->alias([
            'uc' => RequireUseCase::class,
            'branch.scope' => EnsureBranchScope::class,
        ]);
        // Chỉ phát thống kê khi APP_ENV=local.
        $middleware->append(MeasureApiPerformance::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
    })
    ->create();
