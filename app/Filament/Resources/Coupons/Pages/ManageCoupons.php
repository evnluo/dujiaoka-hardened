<?php
namespace App\Filament\Resources\Coupons\Pages;
use App\Filament\Resources\Coupons\CouponResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
class ManageCoupons extends ManageRecords
{
    protected static string $resource = CouponResource::class;
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => CouponResource::persistNew($data))->label('添加优惠券')->mutateDataUsing(function (array $data): array { $data['is_use'] = 1; return $data; })];
    }
}
