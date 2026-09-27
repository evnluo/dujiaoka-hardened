<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class SensitiveActions
{
    public static function passwordField(): TextInput
    {
        return TextInput::make('current_password')->label('当前管理员密码')->password()
            ->autocomplete('current-password')->required()->maxLength(1024)
            ->rules([fn () => function (string $attribute, $value, \Closure $fail): void {
                try { self::confirm(['current_password' => $value]); }
                catch (ValidationException $exception) { $fail($exception->validator->errors()->first()); }
            }])
            ->helperText('敏感内容只在验证密码后下载，不会出现在列表或页面数据中。');
    }

    public static function confirm(#[\SensitiveParameter] array $data): void
    {
        AdminAccess::authorize();
        $key = 'admin-sensitive:'.auth('admin')->id().':'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['current_password' => '验证次数过多，请一分钟后再试。']);
        }
        RateLimiter::hit($key, 60);
        $user = auth('admin')->user()->fresh();
        if (! $user || ! Hash::check((string) ($data['current_password'] ?? ''), $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => '密码不正确。']);
        }
        RateLimiter::clear($key);
    }

    public static function download(string $filename, string $content): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, $filename, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
