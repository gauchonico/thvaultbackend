<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Payments\PaymentGateway::class, fn () => match (config('payments.driver')) {
            'pesapal' => throw new \RuntimeException('The Pesapal gateway has not been built yet.'),
            default   => new \App\Payments\FakeGateway(),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
