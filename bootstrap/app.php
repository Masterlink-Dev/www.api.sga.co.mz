<?php

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
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tipo'            => \App\Http\Middleware\ExigeTipo::class,       // ->middleware('tipo:3')
            'senha.alterada'  => \App\Http\Middleware\SenhaAlterada::class,   // bloqueia enquanto a senha for a inicial
            'super.principal' => \App\Http\Middleware\SoSuperPrincipal::class, // só o super administrador principal
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
