<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ShopMcpAccess
{
    public function handle(Request $request, Closure $next)
    {
        $deny = fn (string $code, int $status) => response()->json(['error' => $code], $status)->header('Cache-Control', 'no-store');
        $bearer = $request->bearerToken();
        if (! is_string($bearer) || strlen($bearer) < 32 || strlen($bearer) > 256) return $deny('unauthenticated', 401)->header('WWW-Authenticate', 'Bearer');
        try {
            $id = \Illuminate\Support\Facades\DB::table('shop_mcp_tokens')->where('token_hash', hash('sha256', $bearer))->value('id');
            $request->attributes->set('shop_mcp_token_id', $id);
            $token = \App\Mcp\Access::token();
        } catch (\App\Mcp\OperationException $e) {
            return $deny($e->reasonCode, $e->reasonCode === 'unauthenticated' ? 401 : 403);
        } catch (\Throwable) { return $deny('unavailable', 503); }
        $origin = $request->header('Origin');
        if ($origin !== null && ! in_array($origin, config('shop_mcp.allowed_origins', []), true)) return $deny('origin_rejected', 403);
        $limit = 'shop-mcp:'.$token->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limit, config('shop_mcp.requests_per_minute', 120))) return $deny('rate_limited', 429);
        \Illuminate\Support\Facades\RateLimiter::hit($limit, 60);
        if ($request->method() !== 'POST') return $deny('method_not_allowed', 405)->header('Allow', 'POST');
        if (strtolower(explode(';', $request->header('Content-Type', ''))[0]) !== 'application/json') return $deny('content_type_required', 415);
        if (strlen($request->getContent()) > config('shop_mcp.max_body_bytes')) return $deny('body_too_large', 413);
        $previous = auth('admin')->user();
        auth('admin')->setUser(\App\Models\AdminUser::findOrFail($token->admin_id));
        try { return $next($request)->header('Cache-Control', 'no-store'); }
        finally { $previous ? auth('admin')->setUser($previous) : auth('admin')->forgetUser(); }
    }
}
