<?php
namespace App\Filament\Resources\Cards\Pages;
use App\Filament\Pages\ImportCards;
use App\Filament\Resources\Cards\CarmisResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
class ManageCards extends ManageRecords
{
    protected static string $resource = CarmisResource::class;
    protected ?string $subheading = '卡密默认隐藏。已售出和循环卡密只读，防止重复发货。';
    protected function getHeaderActions(): array
    {
        return [Action::make('import')->label('导入卡密')->icon('heroicon-o-arrow-up-tray')->url(ImportCards::getUrl())];
    }
}
