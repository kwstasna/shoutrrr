<?php

declare(strict_types=1);

use App\Mcp\Servers\ShoutrrrServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Throttle the OAuth authorize/token endpoints so consent and token-exchange
// can't be hammered (credential/consent abuse).
Route::middleware('throttle:20,1')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', ShoutrrrServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
