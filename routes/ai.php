<?php

use App\Http\Middleware\ShopMcpAccess;
use App\Mcp\ShopServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware(ShopMcpAccess::class)->group(function (): void {
    Mcp::web('/api/admin/mcp', ShopServer::class);
    Route::match(['PUT', 'PATCH', 'OPTIONS'], '/api/admin/mcp', fn () => response('', 405)->header('Allow', 'POST'));
});
