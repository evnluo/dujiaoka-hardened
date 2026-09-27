<?php
namespace App\Filament\Resources\Orders\Pages;
use App\Filament\Resources\Orders\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;
    protected function getHeaderActions(): array
    {
        return [OrderActions::processing(), OrderActions::complete(), OrderActions::fail(), OrderActions::download(),
            Action::make('back')->label('返回订单')->color('gray')->url(OrderResource::getUrl())];
    }
}
