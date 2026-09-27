<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\ShopResource;
use App\Filament\Support\OrderState;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class OrderResource extends ShopResource
{
    protected static ?string $model = Order::class;
    protected static ?string $slug = 'orders';
    protected static ?string $modelLabel = '订单';
    protected static ?string $pluralModelLabel = '订单';
    protected static ?string $recordTitleAttribute = 'order_sn';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static string | UnitEnum | null $navigationGroup = '业务';
    protected static ?int $navigationSort = 10;

    protected static function allows(string $action, ?Model $record): bool
    {
        return in_array($action, ['viewAny', 'view'], true);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])
            ->select(['orders.id', 'order_sn', 'goods_id', 'coupon_id', 'title', 'type', 'goods_price', 'buy_amount', 'coupon_discount_price', 'wholesale_discount_price', 'total_price', 'actual_price', 'email', 'pay_id', 'buy_ip', 'trade_no', 'status', 'coupon_ret_back', 'orders.created_at', 'orders.updated_at', 'orders.deleted_at'])
            ->with([
                'goods' => fn ($q) => $q->withTrashed()->select('id', 'gd_name'),
                'pay' => fn ($q) => $q->withTrashed()->select('id', 'pay_name'),
                'coupon' => fn ($q) => $q->withTrashed()->select('id', 'coupon'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->defaultPaginationPageOption(50)->paginationPageOptions([25, 50, 100])
            ->persistFiltersInSession()->persistSearchInSession()->persistSortInSession()
            ->columns([
                TextColumn::make('order_sn')->label('订单号')->searchable()->copyable()->description(fn (Order $record) => $record->created_at?->format('m-d H:i'))->weight('medium'),
                TextColumn::make('title')->label('商品 / 数量')->searchable()->wrap()->limit(42)
                    ->description(fn (Order $record) => (OrderState::TYPES[(int) $record->type] ?? '未知').' · '.$record->buy_amount.' 件'),
                TextColumn::make('email')->label('客户邮箱')->searchable()->copyable()->limit(32)->tooltip(fn (Order $record) => $record->email),
                TextColumn::make('actual_price')->label('订单金额')->numeric(decimalPlaces: 2)->sortable()->alignEnd(),
                TextColumn::make('payment')->label('支付记录')->state(fn (Order $record) => OrderState::payment((int) $record->status, $record->trade_no))
                    ->description(fn (Order $record) => $record->pay?->pay_name ?? '未选渠道')
                    ->color(fn (Order $record) => in_array((int) $record->status, [1, -1], true) || blank($record->trade_no) ? 'gray' : 'success'),
                TextColumn::make('status')->label('交付')->badge()->sortable()
                    ->formatStateUsing(fn ($state) => OrderState::delivery((int) $state))->color(fn ($state) => OrderState::color((int) $state)),
                TextColumn::make('trade_no')->label('渠道交易号')->searchable()->copyable()->placeholder('未记录')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('更新')->dateTime('Y-m-d H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])->filters([
                SelectFilter::make('status')->label('订单状态')->options(OrderState::LABELS),
                SelectFilter::make('type')->label('交付方式')->options(OrderState::TYPES),
                SelectFilter::make('goods_id')->label('商品')->relationship('goods', 'gd_name')->searchable()->preload(),
                SelectFilter::make('pay_id')->label('支付渠道')->relationship('pay', 'pay_name')->searchable()->preload(),
                Filter::make('created_at')->label('下单日期')->schema([
                    DatePicker::make('from')->label('开始日期'), DatePicker::make('until')->label('结束日期')->afterOrEqual('from'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('orders.created_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('orders.created_at', '<=', $date))),
                TrashedFilter::make()->label('历史归档'),
            ])->recordActions([
                ViewAction::make()->label('详情'),
                OrderActions::processing(),
                ActionGroup::make([OrderActions::complete(), OrderActions::fail(), OrderActions::download()])->label('更多操作'),
            ])->emptyStateHeading('没有匹配的订单')->emptyStateDescription('可以调整筛选条件或搜索订单号、邮箱、渠道交易号。');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('订单与交付')->columns(4)->schema([
                TextEntry::make('order_sn')->label('订单号')->copyable(),
                TextEntry::make('status')->label('交付状态')->badge()->formatStateUsing(fn ($state) => OrderState::delivery((int) $state))->color(fn ($state) => OrderState::color((int) $state)),
                TextEntry::make('type')->label('交付方式')->formatStateUsing(fn ($state) => OrderState::TYPES[(int) $state] ?? '未知'),
                TextEntry::make('created_at')->label('下单时间')->dateTime('Y-m-d H:i:s'),
                TextEntry::make('title')->label('订单商品')->columnSpan(2),
                TextEntry::make('buy_amount')->label('数量'),
                TextEntry::make('updated_at')->label('最后更新')->dateTime('Y-m-d H:i:s'),
                TextEntry::make('email')->label('客户邮箱')->copyable()->columnSpan(2),
                TextEntry::make('buy_ip')->label('下单 IP')->copyable(),
                TextEntry::make('deleted_at')->label('历史归档时间')->dateTime('Y-m-d H:i')->placeholder('未归档'),
            ]),
            Section::make('支付与金额')->description('支付记录与交付状态独立展示；处理失败不代表退款，缺少交易号不视作支付凭证。')->columns(4)->schema([
                TextEntry::make('payment')->label('支付记录')->state(fn (Order $record) => OrderState::payment((int) $record->status, $record->trade_no)),
                TextEntry::make('pay.pay_name')->label('渠道')->placeholder('未选渠道'),
                TextEntry::make('trade_no')->label('渠道交易号')->placeholder('未记录')->copyable()->columnSpan(2),
                TextEntry::make('goods_price')->label('商品单价')->numeric(decimalPlaces: 2),
                TextEntry::make('total_price')->label('原始总额')->numeric(decimalPlaces: 2),
                TextEntry::make('actual_price')->label('订单金额')->numeric(decimalPlaces: 2),
                TextEntry::make('coupon.coupon')->label('优惠码')->placeholder('未使用'),
                TextEntry::make('coupon_discount_price')->label('优惠券抵扣')->numeric(decimalPlaces: 2),
                TextEntry::make('wholesale_discount_price')->label('批发优惠')->numeric(decimalPlaces: 2),
            ]),
            Section::make('敏感订单内容')->schema([
                TextEntry::make('protected_details')->hiddenLabel()->state('客户提交信息、交付卡密与查询密码不在页面中加载。需要核查时，使用「下载完整详情」并验证管理员密码。'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOrders::route('/'), 'view' => Pages\ViewOrder::route('/{record}')];
    }
}
