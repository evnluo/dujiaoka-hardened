<?php

namespace App\Filament\Support;

use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

final class CatalogActions
{
    /** Shared setter: callers own their transaction and scope/selection checks. */
    public static function setVisibility(\Illuminate\Database\Eloquent\Model $record, bool $visible): void
    {
        AdminAccess::authorize();
        if (! in_array($record::class, [\App\Models\Goods::class, \App\Models\GoodsGroup::class, \App\Models\Coupon::class], true)) {
            throw new \InvalidArgumentException('Unsupported catalog record.');
        }
        $record->is_open = $visible ? 1 : 0;
        if ($record->isDirty('is_open')) { $record->save(); }
    }

    public static function visibility(bool $visible): BulkAction
    {
        return BulkAction::make($visible ? 'publish' : 'unpublish')->label($visible ? '启用所选' : '停用所选')
            ->color($visible ? 'primary' : 'gray')->requiresConfirmation()
            ->modalDescription('只更改可见状态，不删除商品、库存或历史记录。')
            ->authorize(fn (): bool => AdminAccess::allowed())
            ->action(function (Collection $records) use ($visible): void {
                AdminAccess::authorize();
                foreach ($records as $record) {
                    self::setVisibility($record, $visible);
                }
                Notification::make()->title('已更新所选记录')->success()->send();
            })->deselectRecordsAfterCompletion();
    }
}
