<?php
namespace App\Filament\Resources\Orders\Pages;
use App\Filament\Resources\Orders\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function hydrate(): void
    {
        // Livewire restores model properties with SELECT *, not the resource projection.
        // Reapply the resource query, including the admin-visible historical contents.
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
        parent::hydrate();
    }

    protected function getHeaderActions(): array
    {
        return [OrderActions::processing(), OrderActions::complete(), OrderActions::fail(), OrderActions::download(), OrderActions::archive(), OrderActions::restore(),
            Action::make('back')->label('返回订单')->color('gray')->url(OrderResource::getUrl())];
    }
}
