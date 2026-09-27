<?php
namespace App\Filament\Widgets;
use App\Filament\Support\AdminAccess;
use App\Models\Order;
use Filament\Widgets\Widget;
class OrderQueue extends Widget
{
    protected static bool $isLazy = false;
    protected string $view = 'filament.widgets.order-queue';
    protected int | string | array $columnSpan = 'full';
    public static function canView(): bool { return AdminAccess::allowed(); }
    protected function getViewData(): array
    {
        AdminAccess::authorize();
        return ['pending' => Order::query()->where('type', 2)->whereIn('status', [2, 3])->count(),
            'attention' => Order::query()->whereIn('status', [5, 6])->count(),
            'today' => Order::query()->whereDate('created_at', today())->count()];
    }
}
