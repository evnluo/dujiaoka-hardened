<?php

namespace App\Filament\Support;

use App\Models\AdminUser;
use Filament\Facades\Filament;

final class AdminAccess
{
    public static function allowed(): bool
    {
        $user = auth('admin')->user();

        return $user instanceof AdminUser && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public static function authorize(): void
    {
        abort_unless(self::allowed(), 403);
    }
}
