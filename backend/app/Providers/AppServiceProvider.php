<?php

namespace App\Providers;

use App\Auth\TaiKhoanProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Auth::provider('qntd_accounts', fn ($app, array $config) => new TaiKhoanProvider());
    }
}