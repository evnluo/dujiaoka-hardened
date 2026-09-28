<?php
namespace App\Filament\Resources\Orders\Pages;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
    protected ?string $subheading = '核对支付记录，处理交付。自动发货及支付确认由系统完成。';
    public function getSubheading(): ?string
    {
        return $this->subheading.' 今日下单 '.Order::query()->whereDate('created_at', today())->count().'。';
    }
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('全部'),
            'manual' => Tab::make('待人工处理')->badge(fn () => Order::query()->where('type', 2)->whereIn('status', [2, 3])->count())->modifyQueryUsing(fn (Builder $query) => $query->where('type', 2)->whereIn('status', [2, 3])),
            'attention' => Tab::make('失败 / 异常')->badge(fn () => Order::query()->whereIn('status', [5, 6])->count())->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [5, 6])),
            'unpaid' => Tab::make('待支付')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 1)),
            'completed' => Tab::make('已完成')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 4)),
        ];
    }
}
