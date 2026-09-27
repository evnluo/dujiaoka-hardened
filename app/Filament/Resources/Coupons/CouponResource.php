<?php

namespace App\Filament\Resources\Coupons;

use App\Filament\Resources\ShopResource;
use App\Filament\Support\AdminAccess;
use App\Filament\Support\CatalogActions;
use App\Models\Coupon;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CouponResource extends ShopResource
{
    protected static ?string $model = Coupon::class;
    protected static ?string $slug = 'coupons';
    protected static ?string $modelLabel = '优惠券';
    protected static ?string $pluralModelLabel = '优惠券';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-ticket';
    protected static string | UnitEnum | null $navigationGroup = '业务';
    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->with('goods');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('coupon')->label('优惠码')->required()->maxLength(150)->unique(ignoreRecord: true)
                ->disabled(fn (?Coupon $record) => $record !== null)->dehydrated(fn (?Coupon $record) => $record === null),
            TextInput::make('discount')->label('抵扣金额')->numeric()->minValue(0.01)->maxValue(99999999.99)->step(0.01)->required(),
            Select::make('goods')->label('适用商品')->relationship('goods', 'gd_name')->multiple()->searchable()->preload()->required()->columnSpanFull()
                ->helperText('至少选择一个商品；未关联的商品不能使用此码。'),
            TextInput::make('ret')->label('可用次数')->integer()->minValue(1)->maxValue(1000000)->default(1)->required()->visibleOn('create')
                ->helperText('已创建优惠券使用「增加次数」，不覆盖正在扣减的余量。'),
            Toggle::make('is_open')->label('启用')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('coupon')->label('优惠码')->searchable()->copyable(),
            TextColumn::make('discount')->label('抵扣金额')->numeric(decimalPlaces: 2)->sortable()->alignEnd(),
            TextColumn::make('goods.gd_name')->label('适用商品')->listWithLineBreaks()->limitList(2)->expandableLimitedList(),
            TextColumn::make('ret')->label('剩余次数')->numeric()->sortable()->alignEnd()->color(fn ($state) => (int) $state < 1 ? 'danger' : 'gray'),
            TextColumn::make('is_use')->label('使用记录')->formatStateUsing(fn ($state) => (int) $state === 2 ? '已使用过' : '未使用'),
            IconColumn::make('is_open')->label('启用')->boolean(),
            TextColumn::make('updated_at')->label('更新')->dateTime('Y-m-d H:i')->sortable(),
        ])->filters([TernaryFilter::make('is_open')->label('启用状态'), TrashedFilter::make()->label('归档记录')])
            ->recordActions([
                EditAction::make()->using(fn (Model $record, array $data) => static::persistEdit($record, $data))->label('编辑')->visible(fn (Coupon $record) => ! $record->trashed()),
                Action::make('addUses')->label('增加次数')->color('gray')->visible(fn (Coupon $record) => ! $record->trashed())
                    ->schema([TextInput::make('count')->label('增加可用次数')->integer()->minValue(1)->maxValue(1000000)->required()->default(1)])
                    ->action(function (Coupon $record, array $data): void {
                        AdminAccess::authorize();
                        Coupon::query()->whereKey($record->id)->increment('ret', (int) $data['count']);
                        Notification::make()->title('可用次数已增加')->success()->send();
                    }),
                Action::make('archive')->label('归档')->color('gray')->requiresConfirmation()->visible(fn (Coupon $record) => ! $record->trashed())
                    ->modalDescription('该码将无法使用，历史订单中的优惠信息保留。')
                    ->action(function (Coupon $record): void {
                        AdminAccess::authorize(); $record->delete();
                        Notification::make()->title('优惠券已归档')->success()->send();
                    }),
                RestoreAction::make()->label('恢复'),
            ])->toolbarActions([BulkActionGroup::make([CatalogActions::visibility(true), CatalogActions::visibility(false)])])
            ->emptyStateHeading('暂无优惠券')->emptyStateDescription('创建固定金额优惠码并关联适用商品。');
    }

    public static function getPages(): array { return ['index' => Pages\ManageCoupons::route('/')]; }
}
