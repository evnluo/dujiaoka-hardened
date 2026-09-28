<?php

namespace App\Filament\Resources\Goods;

use App\Filament\Resources\ShopResource;
use App\Filament\Resources\Cards\CarmisResource;
use App\Filament\Support\AdminAccess;
use App\Filament\Support\CatalogActions;
use App\Filament\Support\OrderState;
use App\Models\Carmis;
use App\Models\Goods;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class GoodsResource extends ShopResource
{
    protected static ?string $model = Goods::class;
    protected static ?string $slug = 'goods';
    protected static ?string $modelLabel = '商品';
    protected static ?string $pluralModelLabel = '商品';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cube';
    protected static string | UnitEnum | null $navigationGroup = '业务';
    protected static ?int $navigationSort = 20;
    protected static ?string $recordTitleAttribute = 'gd_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])
            ->with('group')->withCount(['carmis' => fn (Builder $q) => $q->where('status', Carmis::STATUS_UNSOLD)]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Group::make()->extraAttributes(['class' => 'shop-product-layout'])->schema([
                Group::make()->schema([
                    Section::make('商品内容')->schema([
                        TextInput::make('gd_name')->label('商品名称')->required()->maxLength(200),
                        TextInput::make('gd_description')->label('简短描述')->required()->maxLength(200),
                        RichEditor::make('description')->label('商品详情')->minHeight('8rem')->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo']),
                        FileUpload::make('picture')->label('商品图片')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('admin')->directory('images')->visibility('public')->maxSize(4096)->preventFilePathTampering(),
                    ]),
                    Section::make('定价')->schema([
                        Group::make()->columns(2)->extraAttributes(['class' => 'shop-price-fields'])->schema([
                            TextInput::make('actual_price')->label('销售单价')->numeric()->minValue(0)->maxValue(99999999.99)->step(0.01)->required()->default(0),
                            TextInput::make('retail_price')->label('划线原价')->numeric()->minValue(0)->maxValue(99999999.99)->step(0.01)->default(0),
                        ]),
                        Textarea::make('wholesale_price_cnf')->label('批量优惠')->rows(3)->helperText('每行：起购数量=单价，例如 10=8.50。留空不设批量优惠。')
                            ->rules([fn () => function (string $attribute, $value, \Closure $fail): void {
                                foreach (preg_split('/\R/', trim((string) $value)) as $line) {
                                    if ($line !== '' && ! preg_match('/^[1-9]\d*=\d+(?:\.\d{1,2})?$/', trim($line))) {
                                        $fail('批发价每行应为数量=单价，例如 10=8.50。');
                                    }
                                }
                            }]),
                    ]),
                    Section::make('交付与购买要求')->key('fulfillment', isInheritable: false)->schema([
                        Select::make('type')->label('交付方式')->options(OrderState::TYPES)->default(1)->required()->live()
                            ->disabled(fn (?Goods $record): bool => $record !== null)->dehydrated(fn (?Goods $record): bool => $record === null)
                            ->helperText('已有商品不切换交付类型，避免存量订单与库存错配。'),
                        TextInput::make('in_stock')->label('初始人工库存')->integer()->minValue(0)->default(0)->required()
                            ->visible(fn (Get $get, ?Goods $record): bool => $record === null && (int) $get('type') === 2)
                            ->dehydrated(fn (?Goods $record): bool => $record === null),
                        TextEntry::make('available_stock')->label('当前可用库存')->inlineLabel()
                            ->state(fn (Goods $record): int => (int) $record->type === 1 ? $record->carmis()->where('status', Carmis::STATUS_UNSOLD)->count() : (int) $record->fresh()->in_stock)
                            ->visible(fn (?Goods $record): bool => $record !== null),
                        TextEntry::make('stock_help')->label('库存说明')->hiddenLabel()->state('自动库存由未售出卡密计算。保存商品不会覆盖库存；人工库存通过「补充库存」增加。'),
                        TextInput::make('buy_limit_num')->label('单次限购')->integer()->minValue(0)->required()->default(0)->helperText('0 表示不限购。')->extraFieldWrapperAttributes(['class' => 'shop-short-field']),
                        RichEditor::make('buy_prompt')->label('购买提示')->minHeight('6rem')->toolbarButtons(['bold', 'bulletList', 'link', 'undo', 'redo']),
                        Textarea::make('other_ipu_cnf')->label('购买附加字段')->rows(3)->helperText('保持原格式：字段名=中文说明=是否必填=提示；每行一个字段，例如 account=账号=true=请输入账号。'),
                    ])->footerActions([
                        self::addStockAction(),
                        Action::make('inventory')->label('管理此商品卡密')->color('gray')->icon('heroicon-o-key')
                            ->visible(fn (?Goods $record): bool => $record !== null && (int) $record->type === 1)
                            ->url(fn (Goods $record): string => CarmisResource::getUrl('index', ['filters' => ['goods_id' => ['value' => $record->id]]])),
                    ]),
                ]),
                Group::make()->schema([
                    Section::make('发布')->schema([
                        Toggle::make('is_open')->label('上架销售')->default(false),
                        Select::make('group_id')->label('分类')->relationship('group', 'gp_name')->searchable()->preload()->required(),
                        TextInput::make('ord')->label('排序权重')->integer()->default(1)->required()->helperText('数值越大越靠前。'),
                    ]),
                    Section::make('搜索与集成')->collapsible()->collapsed()->schema([
                        TextInput::make('gd_keywords')->label('搜索关键词')->maxLength(200)->default('')->dehydrateStateUsing(fn ($state) => $state ?? ''),
                        Textarea::make('api_hook')->label('订单回调地址')->rows(3)->helperText('每行一个 HTTPS URL；仅配置您信任的服务。'),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function addStockAction(): Action
    {
        return Action::make('addStock')->label('补充库存')->color('gray')
            ->visible(fn (?Goods $record): bool => $record !== null && ! $record->trashed() && (int) $record->type === 2)
            ->schema([TextInput::make('quantity')->label('增加人工库存')->integer()->minValue(1)->maxValue(1000000)->required()])
            ->action(function (Goods $record, array $data): void {
                AdminAccess::authorize();
                Goods::query()->whereKey($record->id)->where('type', 2)->increment('in_stock', (int) $data['quantity']);
                Notification::make()->title('人工库存已补充')->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->defaultPaginationPageOption(50)->paginationPageOptions([25, 50, 100])
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gd_name')->label('商品')->searchable()->sortable()->description(fn (Goods $record) => $record->group?->gp_name ?? '无分类')->wrap(),
                TextColumn::make('type')->label('交付')->formatStateUsing(fn ($state) => OrderState::TYPES[(int) $state] ?? '未知'),
                TextColumn::make('actual_price')->label('售价')->numeric(decimalPlaces: 2)->sortable()->alignEnd(),
                TextColumn::make('available_stock')->label('可用库存')->state(fn (Goods $record) => (int) $record->type === 1 ? $record->carmis_count : $record->in_stock)
                    ->numeric()->alignEnd()->color(fn ($state) => (int) $state === 0 ? 'danger' : 'gray'),
                TextColumn::make('sales_volume')->label('销量')->numeric()->sortable()->alignEnd(),
                IconColumn::make('is_open')->label('上架')->boolean(),
                TextColumn::make('ord')->label('排序')->sortable()->alignEnd(),
                TextColumn::make('updated_at')->label('更新')->dateTime('m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('group_id')->label('分类')->relationship('group', 'gp_name')->searchable()->preload(),
                SelectFilter::make('type')->label('交付方式')->options(OrderState::TYPES),
                TernaryFilter::make('is_open')->label('上架状态'),
                TrashedFilter::make()->label('归档记录'),
            ])
            ->recordActions([
                EditAction::make()->label('编辑')->visible(fn (Goods $record) => ! $record->trashed()),
                self::addStockAction(),
                Action::make('archive')->label('归档')->color('gray')->requiresConfirmation()->visible(fn (Goods $record) => ! $record->trashed())
                    ->modalDescription('商品将从店铺移除。保留全部卡密和历史订单，可在归档筛选中恢复。')
                    ->action(function (Goods $record): void {
                        AdminAccess::authorize();
                        DB::transaction(function () use ($record): void {
                            $goods = Goods::query()->lockForUpdate()->findOrFail($record->id);
                            Goods::withoutEvents(fn () => $goods->delete());
                        });
                        Notification::make()->title('商品已归档，库存保留')->success()->send();
                    }),
                RestoreAction::make()->label('恢复'),
            ])
            ->toolbarActions([BulkActionGroup::make([CatalogActions::visibility(true), CatalogActions::visibility(false)])])
            ->emptyStateHeading('暂无商品')->emptyStateDescription('先创建分类，再添加商品与库存。');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListGoods::route('/'), 'create' => Pages\CreateGoods::route('/create'), 'edit' => Pages\EditGoods::route('/{record}/edit')];
    }
}
