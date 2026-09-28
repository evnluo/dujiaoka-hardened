<?php
namespace App\Filament\Resources\Cards\Pages;
use App\Filament\Pages\ImportCards;
use App\Filament\Resources\Cards\CarmisResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
class ManageCards extends ManageRecords
{
    protected static string $resource = CarmisResource::class;
    protected ?string $subheading = '点击卡密复制完整内容。编辑保留销售状态和历史交付；归档与恢复仅适用于未售出的非循环库存。';
    protected function getHeaderActions(): array
    {
        return [Action::make('import')->label('导入卡密')->icon('heroicon-o-arrow-up-tray')->url(ImportCards::getUrl())];
    }
}
