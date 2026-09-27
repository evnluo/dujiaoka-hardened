<?php

namespace App\Filament\Resources;

use App\Filament\Support\AdminAccess;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

abstract class ShopResource extends Resource
{
    protected static bool $isGloballySearchable = false;

    public static function getAuthorizationResponse(string | UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;
        $allowed = AdminAccess::allowed() && static::allows($action, $record);

        return $allowed ? Response::allow() : Response::deny('无权执行此操作。');
    }

    public static function persistNew(array $data): Model
    {
        static::getCreateAuthorizationResponse()->authorize();
        $model = static::getModel();
        $record = new $model();
        $record->forceFill($data)->save();

        return $record;
    }

    public static function persistEdit(Model $record, array $data): Model
    {
        static::getEditAuthorizationResponse($record)->authorize();
        $record->forceFill($data)->save();

        return $record;
    }

    protected static function allows(string $action, ?Model $record): bool
    {
        return in_array($action, ['viewAny', 'view', 'create', 'update', 'restore'], true);
    }
}
