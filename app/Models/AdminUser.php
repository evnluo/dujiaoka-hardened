<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

class AdminUser extends Authenticatable implements FilamentUser, HasName
{
    protected $table = 'admin_users';
    protected $hidden = ['password', 'remember_token'];
    protected $fillable = ['username', 'name', 'password'];
    protected $casts = ['password' => 'hashed'];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->exists && $panel->getId() === 'admin'
            && DB::table('admin_role_users')
                ->join('admin_roles', 'admin_roles.id', '=', 'admin_role_users.role_id')
                ->where('admin_role_users.user_id', $this->getKey())
                ->where('admin_roles.slug', 'administrator')
                ->exists();
    }

    public function getFilamentName(): string
    {
        return $this->name ?: $this->username;
    }
}
