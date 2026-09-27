<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')->label('管理员账号')->required()->maxLength(120)
            ->autocomplete('username')->autofocus();
    }

    protected function getCredentialsFromFormData(#[\SensitiveParameter] array $data): array
    {
        return ['username' => $data['username'], 'password' => $data['password']];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages(['data.username' => '账号或密码不正确，或此账号没有管理权限。']);
    }
}
