<?php
namespace App\Filament\Resources\Goods\Pages;
use App\Filament\Resources\Goods\GoodsResource;
use Filament\Resources\Pages\CreateRecord;
class CreateGoods extends CreateRecord
{
    protected static string $resource = GoodsResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['in_stock'] = $data['in_stock'] ?? 0;
        $data['sales_volume'] = 0;
        return $data;
    }
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return GoodsResource::persistNew($data);
    }
    protected function getRedirectUrl(): string { return static::getResource()::getUrl(); }
}
