<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Cards\CarmisResource;
use App\Filament\Support\AdminAccess;
use App\Filament\Support\InventoryOperations;
use App\Models\Goods;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImportCards extends Page
{
    protected static ?string $title = '导入卡密';
    protected static ?string $slug = 'cards/import';
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.import-cards';
    public ?array $data = [];

    public static function canAccess(): bool { return AdminAccess::allowed(); }
    public function mount(): void { AdminAccess::authorize(); $this->form->fill(); }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('goods_id')->label('自动发货商品')->required()->searchable()
                ->options(fn () => Goods::query()->where('type', Goods::AUTOMATIC_DELIVERY)->pluck('gd_name', 'id')),
            Toggle::make('is_loop')->label('循环发货（同一内容可交付给多位客户）')->default(false)
                ->helperText('仅用于允许重复交付的内容。入库后不可改为一次性；停止销售请下架商品。'),
            Textarea::make('cards')->label('粘贴卡密')->rows(12)->maxLength(5 * 1024 * 1024)
                ->helperText('每行一条。自动忽略空行及重复卡密；已售出、归档的卡密也不会重新入库。'),
            FileUpload::make('file')->label('或上传 TXT 文件')->acceptedFileTypes(['text/plain'])->maxSize(5120)
                ->disk('local')->visibility('private')->storeFiles(false)->previewable(false)
                ->helperText('UTF-8，最多 5 MB / 10,000 行。只选一种导入方式。文件不进入公开目录。'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label('返回库存')->color('gray')->url(CarmisResource::getUrl())];
    }

    public function import(): void
    {
        AdminAccess::authorize();
        $data = $this->form->getState();
        $file = $data['file'] ?? null;
        if (filled($data['cards'] ?? null) === ($file instanceof TemporaryUploadedFile)) {
            throw ValidationException::withMessages(['data.cards' => '请粘贴卡密或上传文件，二选一。']);
        }
        try {
            $content = $file instanceof TemporaryUploadedFile ? $file->get() : (string) ($data['cards'] ?? '');
            $result = InventoryOperations::import((int) $data['goods_id'], $content, (bool) ($data['is_loop'] ?? false));
        } catch (ValidationException $exception) {
            $this->addError('data.cards', $exception->validator->errors()->first());
            return;
        } finally {
            if ($file instanceof TemporaryUploadedFile) { $file->delete(); }
            $this->data['cards'] = null;
            $this->data['file'] = null;
        }
        Notification::make()->title('卡密导入完成')->body("新增 {$result['added']} 条，跳过已有 {$result['skipped']} 条；输入中的重复行已合并。")
            ->success()->send();
        $this->redirect(CarmisResource::getUrl());
    }
}
