<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor;

class ShopRichEditor extends RichEditor
{
    public function getOriginalFileAttachmentPaths(): array
    {
        if (! ($this->getLivewire() instanceof \App\Filament\Pages\Settings) || $this->getName() !== 'notice') {
            return parent::getOriginalFileAttachmentPaths();
        }

        // Settings are not an Eloquent record; authorize only IDs in the saved notice.
        $content = app(\App\Support\ShopSettings::class)->get('notice');
        if (blank($content)) {
            return [];
        }
        $ids = [];
        $this->getTipTapEditor()->setContent($content)
            ->descendants(function (object $node) use (&$ids): void {
                if ($node->type === 'image' && filled($node->attrs->id ?? null)) {
                    $ids[] = $node->attrs->id;
                }
            });

        return $ids;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'attachFiles', 'undo', 'redo'])
            ->resizableImages()
            ->fileAttachmentsDisk('admin')
            ->fileAttachmentsDirectory('images/rich-content')
            ->fileAttachmentsVisibility('public')
            ->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->fileAttachmentsMaxSize(4096)
            ->preventFileAttachmentPathTampering();
    }
}
