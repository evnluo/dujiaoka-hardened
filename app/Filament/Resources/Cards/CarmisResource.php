<?php

namespace App\Filament\Resources\Cards;

use App\Filament\Support\ActionFeedback;
use App\Filament\Resources\ShopResource;
use App\Filament\Support\InventoryOperations;
use App\Filament\Support\SensitiveActions;
use App\Models\Carmis;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CarmisResource extends ShopResource
{
    protected static ?string $model = Carmis::class;
    protected static ?string $slug = 'cards';
    protected static ?string $modelLabel = '卡密';
    protected static ?string $pluralModelLabel = '卡密库存';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-key';
    protected static string | UnitEnum | null $navigationGroup = '业务';
    protected static ?int $navigationSort = 30;

    protected static function allows(string $action, ?Model $record): bool
    {
        if ($action === 'create') { return false; }
        if (in_array($action, ['update', 'restore'], true)) {
            return $record && (int) $record->status === Carmis::STATUS_UNSOLD && (int) $record->is_loop === 0;
        }
        return parent::allows($action, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        // Do not hydrate the secret into Livewire's record or form state.
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])
            ->select(['carmis.id', 'goods_id', 'status', 'is_loop', 'carmis.created_at', 'carmis.updated_at', 'carmis.deleted_at'])
            ->with(['goods' => fn ($q) => $q->withTrashed()->select('id', 'gd_name')]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('carmi')->label('替换卡密')->rows(5)->maxLength(10000)
                ->formatStateUsing(fn () => null)->dehydrated(fn ($state) => filled($state))
                ->helperText('当前卡密不回显。留空保留原值；仅可修改未售出、非循环卡密。')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->defaultPaginationPageOption(50)->paginationPageOptions([25, 50, 100])
            ->columns([
                TextColumn::make('id')->label('库存 ID')->sortable()->searchable(),
                TextColumn::make('goods.gd_name')->label('商品')->searchable()->wrap(),
                TextColumn::make('concealed')->label('卡密内容')->state('••••••••')->color('gray'),
                TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn ($state) => (int) $state === 1 ? '未售出' : '已售出')
                    ->color(fn ($state) => (int) $state === 1 ? 'success' : 'gray'),
                IconColumn::make('is_loop')->label('循环')->boolean()->trueColor('warning')->falseColor('gray'),
                TextColumn::make('created_at')->label('入库时间')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('updated_at')->label('更新')->dateTime('m-d H:i')->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('goods_id')->label('商品')->relationship('goods', 'gd_name')->searchable()->preload(),
                SelectFilter::make('status')->label('销售状态')->options([1 => '未售出', 2 => '已售出']),
                SelectFilter::make('is_loop')->label('循环卡密')->options([0 => '一次性', 1 => '循环']),
                TrashedFilter::make()->label('归档记录'),
            ])->recordActions([
                EditAction::make()->label('替换')->using(fn (Carmis $record, array $data): Carmis => ActionFeedback::run(fn () => InventoryOperations::update($record->id, $data)))
                    ->visible(fn (Carmis $record) => ! $record->trashed() && (int) $record->status === 1 && ! $record->is_loop),
                Action::make('download')->label('下载')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->modalHeading('下载完整卡密')->modalDescription('下载文件包含明文卡密。请妥善保管，用完后删除。')
                    ->schema([SensitiveActions::passwordField()])->action(function (Carmis $record, array $data) {
                        SensitiveActions::confirm($data);
                        $card = Carmis::withTrashed()->findOrFail($record->id);
                        return SensitiveActions::download('card-'.$card->id.'.txt', (string) $card->carmi);
                    }),
                Action::make('archive')->label('归档')->color('gray')->requiresConfirmation()
                    ->visible(fn (Carmis $record) => ! $record->trashed() && (int) $record->status === 1 && ! $record->is_loop)
                    ->modalDescription('将未售出卡密移出可销售库存，不会删除历史数据。')
                    ->action(function (Carmis $record): void {
                        ActionFeedback::run(fn () => InventoryOperations::archive([$record->id]));
                        Notification::make()->title('卡密已归档')->success()->send();
                    }),
                RestoreAction::make()->label('恢复到库存'),
            ])->toolbarActions([BulkActionGroup::make([
                BulkAction::make('archive')->label('归档所选库存')->color('gray')->requiresConfirmation()
                    ->modalDescription('仅允许未售出的非循环卡密。选中已售出或循环卡密时，整次操作会被拒绝。')
                    ->action(function (Collection $records): void {
                        $count = ActionFeedback::run(fn () => InventoryOperations::archive($records->modelKeys()));
                        Notification::make()->title("已归档 {$count} 条卡密")->success()->send();
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('export')->label('下载所选卡密')->icon('heroicon-o-arrow-down-tray')
                    ->modalDescription('文件包含所选卡密明文，每行一条。最多下载 1,000 条，请妥善保管。')
                    ->schema([SensitiveActions::passwordField()])
                    ->action(function (Collection $records, array $data) {
                        SensitiveActions::confirm($data);
                        abort_if($records->count() > 1000, 422, '一次最多下载 1,000 条。');
                        $cards = Carmis::withTrashed()->whereKey($records->modelKeys())->orderBy('id')->pluck('carmi')->implode("\n");
                        return SensitiveActions::download('cards-'.now()->format('Ymd-His').'.txt', $cards);
                    })->deselectRecordsAfterCompletion(),
            ])])->emptyStateHeading('暂无卡密')->emptyStateDescription('选择自动发货商品，导入每行一条的 UTF-8 文本。');
    }

    public static function getPages(): array { return ['index' => Pages\ManageCards::route('/')]; }
}
