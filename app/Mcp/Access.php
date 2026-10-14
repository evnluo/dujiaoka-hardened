<?php

namespace App\Mcp;

use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

final class Access
{
    public const SCOPES = ['read', 'catalog', 'inventory', 'inventory:content', 'orders', 'orders:content', 'billing', 'settings', 'operations'];

    public static function token(bool $lock = false): object
    {
        $id = request()->attributes->get('shop_mcp_token_id');
        $query = DB::table('shop_mcp_tokens')->where('id', $id);
        $token = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $token || $token->revoked_at || ! $token->expires_at || now()->greaterThanOrEqualTo($token->expires_at)) throw new OperationException('unauthenticated');
        $user = AdminUser::find($token->admin_id);
        if (! $user || ! $user->canAccessPanel(Filament::getPanel('admin'))) throw new OperationException('forbidden_admin');
        return $token;
    }
    public static function authorize(string $scope, bool $lock = false): object
    {
        $token = self::token($lock);
        if (! in_array($scope, json_decode($token->scopes, true) ?? [], true)) throw new OperationException('forbidden_scope');
        return $token;
    }
}
