<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Filament\Resources\ShopResource;
use App\Models\Emailtpl;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class EmailtplResource extends ShopResource
{
    protected static ?string $model = Emailtpl::class;
    protected static ?string $slug = 'email-templates';
    protected static ?string $modelLabel = '邮件模板';
    protected static ?string $pluralModelLabel = '邮件模板';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-envelope';
    protected static string | UnitEnum | null $navigationGroup = '配置';
    protected static ?int $navigationSort = 60;

    protected static function allows(string $action, ?Model $record): bool
    {
        return in_array($action, ['viewAny', 'view', 'create', 'update'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('tpl_name')->label('邮件标题')->required()->maxLength(150),
            TextInput::make('tpl_token')->label('模板标识')->required()->maxLength(50)->unique(ignoreRecord: true)
                ->regex('/^[a-zA-Z0-9_-]+$/')->disabled(fn (?Emailtpl $record) => $record !== null)
                ->dehydrated(fn (?Emailtpl $record) => $record === null)->helperText('程序按此标识发送邮件，创建后不可更改。'),
            Textarea::make('tpl_content')->label('邮件 HTML')->required()->rows(20)->maxLength(60000)
                ->helperText('保留模板占位符：{webname}、{weburl}、{order_id}、{ord_title}、{ord_price}、{ord_info}、{created_at}。自动发货另支持 {product_name}、{buy_amount}。'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('tpl_name')->label('邮件标题')->searchable()->wrap(),
            TextColumn::make('tpl_token')->label('模板标识')->searchable()->copyable(),
            TextColumn::make('updated_at')->label('更新')->dateTime('Y-m-d H:i')->sortable(),
        ])->recordActions([EditAction::make()->using(fn (Model $record, array $data) => static::persistEdit($record, $data))->label('编辑模板')->modalWidth('5xl')])
            ->emptyStateHeading('暂无邮件模板')->emptyStateDescription('请导入店铺邮件模板，确保订单通知可以正常发送。');
    }

    public static function getPages(): array { return ['index' => Pages\ManageEmailTemplates::route('/')]; }
}
