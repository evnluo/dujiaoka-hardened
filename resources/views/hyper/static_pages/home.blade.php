@extends('hyper.layouts.default')
@section('content')
<link rel="stylesheet" href="/assets/hyper/css/home-catalog.css?v=2">
<div class="my-4 sm:my-8">
    <div class="flex flex-col items-center justify-center">
        <div class="w-full prose prose-zinc max-w-4xl prose-headings:mt-2 prose-headings:mb-0 prose-p:mt-0 prose-p:mb-2 prose-img:my-2 prose-a:text-zinc-600">
            @include('common.rich-content', ['content' => dujiaoka_config_get('notice')])
        </div>
    </div>
</div>

<div class="shop-category-slot">
<div class="shop-categories" data-shop-categories role="group" aria-label="{{ __('goods.fields.group_id') }}" hidden>
    <button type="button" data-category="all" aria-pressed="true" aria-controls="shop-products">{{ __('hyper.home_whole') }}</button>
    @foreach($data as $group)
        <button type="button" data-category="{{ $group['id'] }}" aria-pressed="false" aria-controls="shop-products">{{ $group['gp_name'] }}</button>
    @endforeach
</div>
</div>

@php
    $allGoods = collect();
    foreach($data as $group) {
        $allGoods = $allGoods->concat(collect($group['goods'])->map(function ($goods) use ($group) {
            $goods['catalog_group_id'] = $group['id'];
            return $goods;
        }));
    }
    $sortedGoods = $allGoods->sortByDesc('ord');
@endphp
<div id="shop-products" class="shop-products grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-6 p-2 sm:p-4">
    @foreach($sortedGoods as $goods)
        @if($goods['in_stock'] > 0)
        <a href="{{ url("/buy/{$goods['id']}") }}" data-product-group="{{ $goods['catalog_group_id'] }}" class="group relative bg-white rounded-xl shadow-sm hover:shadow-md overflow-hidden border border-zinc-200 flex flex-col gap-4">
        @else
        <a href="javascript:void(0);" onclick="sell_out_tip()" data-product-group="{{ $goods['catalog_group_id'] }}" class="group relative bg-white rounded-xl shadow-sm overflow-hidden border border-zinc-200 flex flex-col gap-4">
        @endif
            <div class="shop-product-media">
                <img src="{{ picture_url($goods['picture']) }}"
                     alt="{{ $goods['gd_name'] }}"
                     width="1" height="1"
                     loading="{{ $loop->index < 2 ? 'eager' : 'lazy' }}"
                     fetchpriority="{{ $loop->first ? 'high' : 'auto' }}"
                     decoding="async"
                     data-fallback-src="{{ url('assets/common/images/default.jpg') }}">
            </div>
            <div class="shop-product-details px-4 pb-4 flex flex-col justify-between h-full">
                <div class="flex flex-col justify-between mb-4 gap-2">
                    <h3 class="font-medium text-zinc-900 line-clamp-2 text-base sm:text-lg leading-tight">
                        {{ $goods['gd_name'] }}
                    </h3>
                    <div class="shop-product-badges flex items-center gap-2 flex-row">
                        @if($goods['type'] == \App\Models\Goods::AUTOMATIC_DELIVERY)
                            <div class="w-fit text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-green-100 text-green-500">
                                {{ __('goods.fields.automatic_delivery') }}
                            </div>
                        @endif
                        @if($goods['in_stock'] == 0)
                            <div class="w-fit text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-red-100 text-red-500">
                                {{ __('hyper.home_out_of_stock') }}
                            </div>
                        @elseif($goods['in_stock'] < 200)
                            <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-zinc-100">
                                {{ __('hyper.buy_in_stock') }}({{ $goods['in_stock'] }})
                            </div>
                        @endif
                    </div>
                </div>
                <div class="shop-product-price flex items-center justify-between flex-row">
                    <div class="text-2xl font-bold text-zinc-900">
                        {{ __('hyper.global_currency') }} {{ $goods['actual_price'] }}
                    </div>
                    <div class="rounded-full bg-zinc-100 p-2 @if($goods['in_stock'] > 0) group-hover:bg-zinc-900 group-hover:text-white @else opacity-50 cursor-not-allowed @endif">
                        @if($goods['in_stock'] > 0)
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        @else
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="9" x2="15" y1="15" y2="9"/>
                            </svg>
                        @endif
                    </div>
                </div>
            </div>
        </a>
    @endforeach
</div>
@stop
@section('js')
<script src="/assets/hyper/js/home-catalog.js?v=1"></script>
<script>
    function sell_out_tip() {
        Toastify({
            text: "{{ __('hyper.home_sell_out_tip') }}",
            duration: 3000,
            close: true,
            gravity: "top",
            position: "center",
            style: {
                background: "linear-gradient(to right, #f87171, #ef4444)",
                borderRadius: "10px",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                gap: "10px",
                paddingRight: "10px",
            }
        }).showToast();
    }
</script>
@stop