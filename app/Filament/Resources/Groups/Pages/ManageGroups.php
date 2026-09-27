<?php
namespace App\Filament\Resources\Groups\Pages;
use App\Filament\Resources\Groups\GoodsGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
class ManageGroups extends ManageRecords
{
    protected static string $resource = GoodsGroupResource::class;
    protected function getHeaderActions(): array { return [CreateAction::make()->using(fn (array $data) => GoodsGroupResource::persistNew($data))->label('添加分类')]; }
}
