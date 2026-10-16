<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\TrustedProxy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
            'admin' => EnsureAdmin::class,
            'api.token' => AuthenticateApiToken::class,
        ]);

        /*
         * Proxy tepercaya.
         *
         * Kosong secara default, dan itu disengaja. Memasok '*' akan membuat
         * siapa pun bebas memalsukan X-Forwarded-For, dan itu justru
         * membatalkan fitur pembatasan IP pada token API.
         *
         * Di belakang reverse proxy, isi TRUSTED_PROXIES dengan IP proxy
         * sungguhan. Tanpa itu, $request->ip() mengembalikan IP proxy dan
         * pemeriksaan allowed_ips menilai klien yang salah.
         */
        $middleware->trustProxies(
            at: TrustedProxy::list(),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
