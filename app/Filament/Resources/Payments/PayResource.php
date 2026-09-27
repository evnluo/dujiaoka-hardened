<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Support\ActionFeedback;
use App\Filament\Resources\ShopResource;
use App\Filament\Support\AdminAccess;
use App\Models\Pay;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class PayResource extends ShopResource
{
    protected static ?string $model = Pay::class;
    protected static ?string $slug = 'payments';
    protected static ?string $modelLabel = '支付渠道';
    protected static ?string $pluralModelLabel = '支付渠道';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-credit-card';
    protected static string | UnitEnum | null $navigationGroup = '配置';
    protected static ?int $navigationSort = 50;

    protected static function allows(string $action, ?Model $record): bool
    {
        if ($action === 'update') { return $record && $record->pay_handleroute === '/pay/yipay' && ! $record->trashed(); }
        return in_array($action, ['viewAny', 'view', 'create'], true);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])
            ->select(['id', 'pay_name', 'pay_check', 'pay_method', 'pay_client', 'merchant_id', 'pay_handleroute', 'is_open', 'created_at', 'updated_at', 'deleted_at'])
            ->selectRaw("CASE WHEN merchant_pem IS NOT NULL AND merchant_pem <> '' THEN 1 ELSE 0 END AS has_credentials");
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('易支付 / Yipay')->description('只支持 /pay/yipay。旧渠道只保留供历史订单查阅，不可重新启用。')->columns(2)->schema([
                TextInput::make('pay_name')->label('显示名称')->required()->maxLength(200),
                Select::make('pay_check')->label('支付标识')->options(['alipay' => '支付宝 · alipay', 'wxpay' => '微信支付 · wxpay', 'qqpay' => 'QQ 钱包 · qqpay'])
                    ->required()->disabled(fn (?Pay $record) => $record !== null)->dehydrated(fn (?Pay $record) => $record === null)
                    ->helperText('创建后固定，避免存量订单回调发生错配。'),
                TextInput::make('merchant_id')->label('商户 ID')->required()->maxLength(200),
                Select::make('pay_client')->label('适用设备')->options([1 => '电脑', 2 => '手机', 3 => '全部设备'])->default(3)->required(),
                Toggle::make('is_open')->label('启用渠道')->default(false),
            ]),
            Section::make('连接与密钥')->description('现有网关地址和密钥不会发送到浏览器。编辑时留空保持不变；输入新值后保存替换。')->schema([
                TextInput::make('merchant_key')->label('网关地址（HTTPS）')->password()->autocomplete('new-password')->maxLength(2000)
                    ->formatStateUsing(fn () => null)->required(fn (?Pay $record) => $record === null)->dehydrated(fn ($state) => filled($state))
                    ->rules(['url:https'])->helperText('易支付提交地址，例如 https://您的网关/submit.php。'),
                TextInput::make('merchant_pem')->label('商户签名密钥')->password()->autocomplete('new-password')->maxLength(10000)
                    ->formatStateUsing(fn () => null)->required(fn (?Pay $record) => $record === null)->dehydrated(fn ($state) => filled($state)),
            ]),
        ]);
    }

    public static function saveGateway(array $data, ?Pay $record = null): Pay
    {
        AdminAccess::authorize();
        return DB::transaction(function () use ($data, $record): Pay {
            $pay = $record ? Pay::query()->lockForUpdate()->findOrFail($record->id) : new Pay();
            if ($record && $pay->pay_handleroute !== '/pay/yipay') {
                throw ValidationException::withMessages(['pay_name' => '旧支付渠道只读，不允许启用。']);
            }
            foreach (['pay_name', 'merchant_id', 'pay_client', 'is_open'] as $key) {
                if (array_key_exists($key, $data)) { $pay->{$key} = $data[$key]; }
            }
            if (! $record) {
                $pay->pay_check = $data['pay_check'];
                if (Pay::withTrashed()->where('pay_check', $pay->pay_check)->exists()) {
                    throw ValidationException::withMessages(['pay_check' => '该支付标识已有记录（包括旧渠道）。请编辑对应的现有易支付渠道。']);
                }
            }
            foreach (['merchant_key', 'merchant_pem'] as $key) {
                if (filled($data[$key] ?? null)) { $pay->{$key} = $data[$key]; }
            }
            $pay->pay_handleroute = '/pay/yipay';
            $pay->pay_method = Pay::METHOD_JUMP;
            if (! filter_var($pay->merchant_key, FILTER_VALIDATE_URL) || parse_url($pay->merchant_key, PHP_URL_SCHEME) !== 'https' || blank($pay->merchant_pem)) {
                throw ValidationException::withMessages(['merchant_key' => '请配置有效的 HTTPS 网关地址及商户签名密钥。']);
            }
            $pay->save();
            return $pay;
        });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('is_open', 'desc')->columns([
            TextColumn::make('pay_name')->label('渠道')->searchable(),
            TextColumn::make('pay_check')->label('标识')->searchable(),
            TextColumn::make('merchant_id')->label('商户 ID')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('pay_handleroute')->label('处理路由')->description(fn (Pay $record) => $record->pay_handleroute === '/pay/yipay' ? '受支持 · Yipay' : '旧渠道 · 只读'),
            TextColumn::make('pay_client')->label('设备')->formatStateUsing(fn ($state) => [1 => '电脑', 2 => '手机', 3 => '全部设备'][(int) $state] ?? '未知'),
            IconColumn::make('has_credentials')->label('密钥已配置')->boolean(),
            IconColumn::make('is_open')->label('启用')->boolean(),
            TextColumn::make('updated_at')->label('更新')->dateTime('Y-m-d H:i')->sortable(),
        ])->filters([TernaryFilter::make('is_open')->label('启用状态'), TrashedFilter::make()->label('历史归档')])
            ->recordActions([EditAction::make()->label('配置')->using(fn (Pay $record, array $data) => ActionFeedback::run(fn () => self::saveGateway($data, $record)))])
            ->emptyStateHeading('暂无支付渠道')->emptyStateDescription('添加易支付配置后，再启用对应渠道。');
    }

    public static function getPages(): array { return ['index' => Pages\ManagePayments::route('/')]; }
}
