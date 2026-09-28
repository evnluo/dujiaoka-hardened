<?php

namespace App\Filament\Support;

use Filament\Tables\Contracts\HasTable;

final class BulkSelection
{
    public static function ids(HasTable $livewire): array
    {
        AdminAccess::authorize();
        // Explicit selections retain missing / newly ineligible IDs for honest reporting.
        // Select-all is bounded before loading IDs, never silently truncated to the UI cap.
        return $livewire->isTrackingDeselectedTableRecords
            ? $livewire->getSelectedTableRecordsQuery(false)->limit(1001)->toBase()->pluck($livewire->getTable()->getQuery()->getModel()->getQualifiedKeyName())->all()
            : $livewire->selectedTableRecords;
    }

    public static function clear(HasTable $livewire): void
    {
        $livewire->selectedTableRecords = [];
        $livewire->deselectedTableRecords = [];
        $livewire->isTrackingDeselectedTableRecords = false;
        $livewire->deselectAllTableRecords();
    }
}
