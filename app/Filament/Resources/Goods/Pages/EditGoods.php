<?php
namespace App\Filament\Resources\Goods\Pages;
use App\Filament\Resources\Goods\GoodsResource;
use Filament\Resources\Pages\EditRecord;
class EditGoods extends EditRecord
{
    protected static string $resource = GoodsResource::class;
    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        return GoodsResource::persistEdit($record, $data);
    }
    protected function getRedirectUrl(): string { return static::getResource()::getUrl(); }
}
