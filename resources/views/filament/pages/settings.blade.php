<x-filament-panels::page>
    <form wire:submit="save" class="shop-form">
        {{ $this->form }}
        <div class="shop-form-actions">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">保存设置</x-filament::button>
            <span class="shop-help">密钥字段留空表示保持不变。不会显示当前密钥。</span>
        </div>
    </form>
</x-filament-panels::page>
