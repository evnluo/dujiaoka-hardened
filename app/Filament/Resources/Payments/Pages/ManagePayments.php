<?php
namespace App\Filament\Resources\Payments\Pages;
use App\Filament\Support\ActionFeedback;
use App\Filament\Resources\Payments\PayResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
class ManagePayments extends ManageRecords
{
    protected static string $resource = PayResource::class;
    protected ?string $subheading = '当前仅支持易支付。配置变更不修改历史订单，也不模拟支付成功。';
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('添加易支付渠道')->using(fn (array $data) => ActionFeedback::run(fn () => PayResource::saveGateway($data)))];
    }
}
