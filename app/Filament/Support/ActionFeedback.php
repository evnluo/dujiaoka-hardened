<?php

namespace App\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

final class ActionFeedback
{
    public static function run(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            Notification::make()->title('未执行操作')->body($exception->validator->errors()->first())->danger()->send();
            throw new Halt();
        }
    }
}
