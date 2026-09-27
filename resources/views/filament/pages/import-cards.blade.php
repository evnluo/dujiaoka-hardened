<x-filament-panels::page>
    <form wire:submit="import" class="shop-form">
        {{ $this->form }}
        <div class="shop-form-actions">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="import,data.file">导入到可用库存</x-filament::button>
            <span class="shop-help">导入后即可销售。不会修改已售出的卡密。</span>
        </div>
    </form>
</x-filament-panels::page>
