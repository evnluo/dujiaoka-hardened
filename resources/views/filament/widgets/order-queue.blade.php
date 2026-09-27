<x-filament-widgets::widget>
    <dl class="shop-summary" aria-label="订单队列概况">
        <div><dt>待人工处理</dt><dd>{{ number_format($pending) }}</dd></div>
        <div><dt>失败 / 异常</dt><dd>{{ number_format($attention) }}</dd></div>
        <div><dt>今日下单</dt><dd>{{ number_format($today) }}</dd></div>
    </dl>
</x-filament-widgets::widget>
