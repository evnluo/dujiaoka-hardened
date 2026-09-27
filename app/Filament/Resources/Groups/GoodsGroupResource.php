<?php

namespace App\Filament\Resources\Groups;

use App\Filament\Resources\ShopResource;
use App\Filament\Support\AdminAccess;
use App\Filament\Support\CatalogActions;
use App\Models\GoodsGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class GoodsGroupResource extends ShopResource
{
    protected static ?string $model = GoodsGroup::class;
    protected static ?string $slug = 'groups';
    protected static ?string $modelLabel = '分类';
    protected static ?string $pluralModelLabel = '商品分类';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-folder';
    protected static string | UnitEnum | null $navigationGroup = '业务';
    protected static ?int $navigationSort = 25;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class])->withCount('goods');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('gp_name')->label('分类名称')->required()->maxLength(200),
            TextInput::make('ord')->label('排序权重')->integer()->required()->default(1)->helperText('数值越大越靠前。'),
            Toggle::make('is_open')->label('店铺中显示')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('ord', 'desc')->columns([
            TextColumn::make('gp_name')->label('分类')->searchable(),
            TextColumn::make('goods_count')->label('商品数')->numeric()->alignEnd(),
            TextColumn::make('ord')->label('排序')->sortable()->alignEnd(),
            IconColumn::make('is_open')->label('显示')->boolean(),
            TextColumn::make('updated_at')->label('更新')->dateTime('Y-m-d H:i')->sortable(),
        ])->filters([TrashedFilter::make()->label('归档记录')])->recordActions([
            EditAction::make()->using(fn (Model $record, array $data) => static::persistEdit($record, $data))->label('编辑')->visible(fn (GoodsGroup $record) => ! $record->trashed()),
            Action::make('archive')->label('归档')->color('gray')->requiresConfirmation()
                ->visible(fn (GoodsGroup $record) => ! $record->trashed())
                ->modalDescription('只有不含商品的分类可以归档。请先移动该分类的商品。')
                ->action(function (GoodsGroup $record): void {
                    AdminAccess::authorize();
                    DB::transaction(function () use ($record): void {
                        $group = GoodsGroup::query()->lockForUpdate()->findOrFail($record->id);
                        if ($group->goods()->withTrashed()->exists()) {
                            Notification::make()->title('分类仍有商品')->body('请先移动该分类的商品，包括归档商品。')->danger()->send();
                            throw new \Filament\Support\Exceptions\Halt();
                        }
                        GoodsGroup::withoutEvents(fn () => $group->delete());
                    });
                    Notification::make()->title('分类已归档')->success()->send();
                }),
            RestoreAction::make()->label('恢复'),
        ])->toolbarActions([BulkActionGroup::make([CatalogActions::visibility(true), CatalogActions::visibility(false)])])
            ->emptyStateHeading('暂无分类')->emptyStateDescription('添加分类，便于店铺组织商品。');
    }

    public static function getPages(): array { return ['index' => Pages\ManageGroups::route('/')]; }
}
