<?php
namespace App\Filament\Resources\EmailTemplates\Pages;
use App\Filament\Resources\EmailTemplates\EmailtplResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
class ManageEmailTemplates extends ManageRecords
{
    protected static string $resource = EmailtplResource::class;
    protected ?string $subheading = '编辑真实发送内容。系统模板不能删除，标识不能改名。';
    protected function getHeaderActions(): array { return [CreateAction::make()->using(fn (array $data) => EmailtplResource::persistNew($data))->label('添加模板')->modalWidth('5xl')]; }
}
