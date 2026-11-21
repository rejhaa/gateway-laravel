<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\RejectBearerToken;
use App\Systems\Mail\FileMailbox;
use App\Systems\Mail\Mailbox;
use App\Systems\Mail\PdfText;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Mailbox::class, fn (Application $app): FileMailbox => new FileMailbox(
            (string) config('gateway.mailbox.path'),
            $app->make(PdfText::class),
            $app->make(Cache::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::authorizationView('mcp.authorize');

        // The approve and reject actions run on Livewire's update endpoint, so Claude's token is kept out there too.
        Livewire::setUpdateRoute(
            fn (array $handle, string $path): RoutingRoute => Route::post($path, $handle)->middleware(['web', RejectBearerToken::class]),
        );
    }
}
