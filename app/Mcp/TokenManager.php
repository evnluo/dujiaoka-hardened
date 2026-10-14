<?php

namespace App\Mcp;

use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TokenManager
{
    private function authenticate(string $username, string $password): AdminUser
    {
        $admin = AdminUser::where('username', $username)->lockForUpdate()->first();
        if (! $admin || ! Hash::check($password, $admin->password) || ! $admin->canAccessPanel(Filament::getPanel('admin'))) throw new OperationException('bootstrap_auth_failed');
        return $admin;
    }
    public function import(string $username, string $password, string $token, array $scopes, string $name, int $days, ?int $revoke = null): int
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $token) || ! $scopes || array_diff($scopes, Access::SCOPES) || count(array_unique($scopes)) !== count($scopes) || $days < 1 || $days > 365 || $name === '' || strlen($name) > 100) throw new OperationException('invalid_bootstrap_input');
        return DB::transaction(function () use ($username, $password, $token, $scopes, $name, $days, $revoke): int {
            $admin = $this->authenticate($username, $password);
            if ($revoke !== null) $this->revokeOwned($admin->id, $revoke);
            return DB::table('shop_mcp_tokens')->insertGetId(['admin_id' => $admin->id, 'name' => $name, 'token_hash' => hash('sha256', $token), 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR), 'expires_at' => now()->addDays($days), 'created_at' => now()]);
        });
    }
    public function revoke(string $username, string $password, int $id): void
    {
        DB::transaction(function () use ($username, $password, $id): void { $this->revokeOwned($this->authenticate($username, $password)->id, $id); });
    }
    private function revokeOwned(int $admin, int $id): void
    {
        $q = DB::table('shop_mcp_tokens')->where('admin_id', $admin)->where('id', $id);
        if (! (clone $q)->lockForUpdate()->first()) throw new OperationException('token_not_found');
        $q->update(['revoked_at' => now()]);
    }
}
