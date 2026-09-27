<?php

namespace App\Http\Middleware;

use App\Models\BaseModel;
use Closure;

class DujiaoBoot
{
    public function handle($request, Closure $next)
    {
        $userAgent = (string) $request->userAgent();
        if ((str_contains($userAgent, 'QQ/') || str_contains($userAgent, 'MicroMessenger'))
            && dujiaoka_config_get('is_open_anti_red', BaseModel::STATUS_CLOSE) == BaseModel::STATUS_OPEN) {
            return response()->view('common/notencent', ['nowUri' => $request->fullUrl()]);
        }
        app()->setLocale(dujiaoka_config_get('language', 'zh_CN'));
        return $next($request);
    }
}
