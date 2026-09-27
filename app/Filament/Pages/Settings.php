<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminAccess;
use App\Support\ShopSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use UnitEnum;

class Settings extends Page
{
    protected static ?string $title = '店铺设置';
    protected static ?string $slug = 'settings';
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string | UnitEnum | null $navigationGroup = '配置';
    protected static ?int $navigationSort = 70;
    protected string $view = 'filament.pages.settings';
    public ?array $data = [];

    public const SECRETS = ['password', 'server_jiang_token', 'telegram_bot_token', 'bark_token', 'qywxbot_key', 'geetest_key'];
    public const PUBLIC_FIELDS = ['title', 'img_logo', 'text_logo', 'keywords', 'description', 'template', 'language', 'manage_email', 'order_expire_time', 'is_open_anti_red', 'is_open_img_code', 'is_open_search_pwd', 'is_open_google_translate', 'notice', 'footer', 'is_open_server_jiang', 'is_open_telegram_push', 'telegram_userid', 'is_open_bark_push', 'is_open_bark_push_url', 'bark_server', 'is_open_qywxbot_push', 'driver', 'host', 'port', 'username', 'encryption', 'from_address', 'from_name', 'geetest_id', 'is_open_geetest'];

    public static function canAccess(): bool { return AdminAccess::allowed(); }

    public function mount(): void
    {
        AdminAccess::authorize();
        $this->fillSettings();
    }

    private function fillSettings(): void
    {
        $settings = Arr::only(app(ShopSettings::class)->getAll(), self::PUBLIC_FIELDS);
        $this->form->fill(array_replace(['template' => 'hyper', 'language' => 'zh_CN', 'driver' => 'smtp', 'port' => 587, 'encryption' => 'tls', 'order_expire_time' => 5], $settings));
    }

    private static function secret(string $key, string $label): TextInput
    {
        return TextInput::make($key)->label($label)->password()->autocomplete('new-password')->maxLength(10000)
            ->dehydrated(fn ($state) => filled($state))->helperText('留空不变。现有值不会加载到页面。');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('settings')->tabs([
                Tab::make('店铺')->schema([
                    Section::make('基本信息')->columns(2)->schema([
                        TextInput::make('title')->label('网站标题')->required()->maxLength(200),
                        TextInput::make('text_logo')->label('店铺名称')->maxLength(200),
                        Select::make('template')->label('店铺模板')->options(fn () => config('dujiaoka.templates', ['hyper' => 'Hyper']))->required(),
                        Select::make('language')->label('店铺语言')->options(fn () => config('dujiaoka.language', ['zh_CN' => '简体中文', 'zh_TW' => '繁体中文']))->required(),
                        FileUpload::make('img_logo')->label('店铺标志')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('admin')->directory('images')->visibility('public')->maxSize(2048)->columnSpanFull(),
                        TextInput::make('keywords')->label('网站关键词')->maxLength(500),
                        Textarea::make('description')->label('网站描述')->rows(2)->maxLength(2000),
                    ]),
                    Section::make('公告与页脚')->schema([
                        RichEditor::make('notice')->label('店铺公告')->toolbarButtons(['bold', 'italic', 'bulletList', 'link', 'undo', 'redo']),
                        Textarea::make('footer')->label('页脚 HTML')->rows(4)->maxLength(20000),
                    ]),
                ]),
                Tab::make('订单与安全')->schema([
                    Section::make('订单')->columns(2)->schema([
                        TextInput::make('manage_email')->label('管理员通知邮箱')->email()->maxLength(200),
                        TextInput::make('order_expire_time')->label('待支付有效期（分钟）')->integer()->required()->minValue(1)->maxValue(1440),
                        Toggle::make('is_open_img_code')->label('下单图片验证码'),
                        Toggle::make('is_open_search_pwd')->label('要求订单查询密码'),
                        Toggle::make('is_open_anti_red')->label('开启防红跳转'),
                        Toggle::make('is_open_google_translate')->label('显示 Google 翻译'),
                    ]),
                    Section::make('极验')->description('启用前请确认服务与凭证可用；图片验证码可独立配置。')->columns(2)->schema([
                        Toggle::make('is_open_geetest')->label('启用极验')->columnSpanFull(),
                        TextInput::make('geetest_id')->label('极验 ID')->maxLength(200),
                        self::secret('geetest_key', '极验密钥'),
                    ]),
                ]),
                Tab::make('邮件')->schema([
                    Section::make('SMTP 发送')->description('保存后可使用右上角「发送测试邮件」。测试只证明服务器接受发送，不保证收件箱送达。')->columns(2)->schema([
                        Select::make('driver')->label('发送方式')->options(['smtp' => 'SMTP'])->required(),
                        TextInput::make('host')->label('SMTP 主机')->maxLength(255),
                        TextInput::make('port')->label('端口')->integer()->minValue(1)->maxValue(65535),
                        Select::make('encryption')->label('加密')->options(['tls' => 'STARTTLS', 'ssl' => 'TLS / SSL', '' => '不加密（不推荐）']),
                        TextInput::make('username')->label('SMTP 用户名')->maxLength(255),
                        self::secret('password', 'SMTP 密码'),
                        TextInput::make('from_address')->label('发件邮箱')->email()->maxLength(200),
                        TextInput::make('from_name')->label('发件名称')->maxLength(200),
                    ]),
                ]),
                Tab::make('消息推送')->schema([
                    Section::make('Telegram')->columns(2)->schema([
                        Toggle::make('is_open_telegram_push')->label('启用 Telegram')->columnSpanFull(),
                        TextInput::make('telegram_userid')->label('用户 / 群组 ID')->maxLength(200),
                        self::secret('telegram_bot_token', 'Bot token'),
                    ]),
                    Section::make('Server 酱')->columns(2)->schema([
                        Toggle::make('is_open_server_jiang')->label('启用 Server 酱'), self::secret('server_jiang_token', 'SendKey'),
                    ]),
                    Section::make('Bark')->columns(2)->schema([
                        Toggle::make('is_open_bark_push')->label('启用 Bark'), Toggle::make('is_open_bark_push_url')->label('推送附带订单链接'),
                        TextInput::make('bark_server')->label('服务地址')->url()->maxLength(2000), self::secret('bark_token', 'Bark token'),
                    ]),
                    Section::make('企业微信机器人')->columns(2)->schema([
                        Toggle::make('is_open_qywxbot_push')->label('启用企业微信'), self::secret('qywxbot_key', '机器人 key'),
                    ]),
                ]),
            ])->columnSpanFull(),
        ]);
    }

    public function save(): void
    {
        AdminAccess::authorize();
        $values = Arr::only($this->form->getState(), [...self::PUBLIC_FIELDS, ...self::SECRETS]);
        foreach (self::SECRETS as $key) {
            if (blank($values[$key] ?? null)) { unset($values[$key]); }
        }
        app(ShopSettings::class)->setMany($values);
        $this->data = [];
        $this->fillSettings();
        Notification::make()->title('店铺设置已保存')->body('后台任务使用最新配置；若使用常驻队列，请确保已重启队列进程。')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('testMail')->label('发送测试邮件')->color('gray')->icon('heroicon-o-paper-airplane')
            ->modalDescription('使用已保存的 SMTP 配置发送一封真实测试邮件。未保存的表单值不会用于发送。')
            ->schema([
                TextInput::make('to')->label('收件邮箱')->email()->required()->maxLength(200),
                TextInput::make('subject')->label('标题')->required()->default("Evan's Shop 邮件测试")->maxLength(200),
                Textarea::make('body')->label('正文')->required()->default('这是一封店铺 SMTP 连接测试邮件。')->maxLength(5000),
            ])->action(function (array $data): void {
                AdminAccess::authorize();
                $s = app(ShopSettings::class)->getAll();
                if (blank($s['host'] ?? '') || blank($s['from_address'] ?? '')) {
                    Notification::make()->title('请先保存 SMTP 主机与发件邮箱')->danger()->send(); return;
                }
                try {
                    config(['mail.mailers.admin_test' => [
                        'transport' => 'smtp', 'scheme' => ($s['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                        'host' => $s['host'], 'port' => (int) ($s['port'] ?? 587),
                        'username' => $s['username'] ?? '', 'password' => $s['password'] ?? '', 'timeout' => 15,
                    ]]);
                    Mail::purge('admin_test');
                    Mail::mailer('admin_test')->raw($data['body'], function ($message) use ($data, $s): void {
                        $message->from($s['from_address'], $s['from_name'] ?? "Evan's Shop")->to($data['to'])->subject($data['subject']);
                    });
                    Notification::make()->title('邮件服务器已接受测试邮件')->body('请在收件邮箱核对送达情况。')->success()->send();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->title('测试邮件发送失败')->body('请检查 SMTP 配置和应用日志。')->danger()->send();
                } finally {
                    Mail::purge('admin_test'); config(['mail.mailers.admin_test' => null]);
                }
            })];
    }
}
