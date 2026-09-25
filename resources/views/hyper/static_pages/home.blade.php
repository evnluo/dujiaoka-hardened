@extends('hyper.layouts.default')
@section('content')
<div class="my-4 sm:my-8">
    <div class="">
        <div class="flex flex-col items-center justify-center">
            <!-- <h4 class="text-xl sm:text-2xl font-bold mb-2">
                {{ __('hyper.notice_announcement') }}
            </h4> -->
            <div class="w-full prose prose-zinc max-w-4xl prose-headings:mt-2 prose-headings:mb-0 prose-p:mt-0 prose-p:mb-2 prose-img:my-2 prose-a:text-zinc-600">
                {!! dujiaoka_config_get('notice') !!}
            </div>
        </div>
    </div>
</div>
<div class="mb-2 w-full flex flex-row items-center justify-center gap-2 sm:gap-4 px-2 sm:px-0">
<style>
    .custom-tab.active {
        border-color: #000 !important;
        background-color: #18181b !important;
        color: #fff !important;
    }
</style>

    <a href="#group-all" class="custom-tab px-2 sm:px-4 py-1 sm:py-2 rounded-full border border-zinc-300 bg-white text-zinc-800 hover:text-zinc-900 active transition-all duration-300" data-bs-toggle="tab" aria-expanded="false" role="tab" data-toggle="tab">
        <span class="tab-title">
        {{-- 全部 --}}
        {{ __('hyper.home_whole') }}
        </span>
    </a>
    @foreach($data as  $index => $group)
    <a href="#group-{{ $group['id'] }}" class="custom-tab px-2 sm:px-4 py-1 sm:py-2 rounded-full border border-zinc-300 bg-white text-zinc-800 hover:text-zinc-900 transition-all duration-300" data-bs-toggle="tab" aria-expanded="false" role="tab" data-toggle="tab">
        <span class="tab-title">
            {{ $group['gp_name'] }}
        </span>
        <div class="img-checkmark">
            <img src="/assets/hyper/images/check.png">
        </div>
    </a>
    @endforeach
</div>

<div class="tab-content">
    <div class="tab-pane active" id="group-all">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-6 p-2 sm:p-4">
            @php
                $allGoods = collect();
                foreach($data as $group) {
                    $allGoods = $allGoods->concat($group['goods']);
                }
                $sortedGoods = $allGoods->sortByDesc('ord');
            @endphp
            
            @foreach($sortedGoods as $goods)
                @if($goods['in_stock'] > 0)
                <a href="{{ url("/buy/{$goods['id']}") }}" class="group relative bg-white rounded-xl shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden border border-zinc-200 flex flex-col gap-4">
                @else
                <a href="javascript:void(0);" onclick="sell_out_tip()" class="group relative bg-white rounded-xl shadow-sm overflow-hidden border border-zinc-200 flex flex-col gap-4">
                @endif
                    <div class="aspect-square w-full overflow-hidden flex-shrink-0 relative">
                        <div class="loading-spinner absolute inset-0 flex items-center justify-center bg-zinc-50 transition-opacity duration-200">
                            <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <style>.spinner_P7sC{transform-origin:center;animation:spinner_svv2 .75s infinite linear}@keyframes spinner_svv2{100%{transform:rotate(360deg)}}</style>
                                <path d="M10.14,1.16a11,11,0,0,0-9,8.92A1.59,1.59,0,0,0,2.46,12,1.52,1.52,0,0,0,4.11,10.7a8,8,0,0,1,6.66-6.61A1.42,1.42,0,0,0,12,2.69h0A1.57,1.57,0,0,0,10.14,1.16Z" class="spinner_P7sC"/>
                            </svg>
                        </div>
                        <img 
                            class="w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-300 scale-[1.01] opacity-0 transition-opacity duration-150"
                            data-src="{{ picture_url($goods['picture']) }}"
                        >
                    </div>
                    <div class="px-4 pb-4 flex flex-col justify-between h-full">
                        <div class="flex flex-col justify-between mb-4 gap-2">
                            <h3 class="font-medium text-zinc-900 line-clamp-2 text-base sm:text-lg leading-tight">
                                {{ $goods['gd_name'] }}
                            </h3>
                            <div class="flex items-center gap-2 flex-row">
                                {{-- 发货方式 --}}
                                @if($goods['type'] == \App\Models\Goods::AUTOMATIC_DELIVERY)
                                    <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-green-100 text-green-500">
                                        {{ __('goods.fields.automatic_delivery') }}
                                    </div>
                                @else
                                    <!-- <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-yellow-100 text-yellow-500">
                                        {{ __('goods.fields.manual_processing') }}
                                    </div> -->
                                @endif

                                {{-- 库存 --}}
                                @if($goods['in_stock'] == 0)
                                    <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-red-100 text-red-500">
                                        {{ __('hyper.home_out_of_stock') }}
                                    </div>
                                @elseif($goods['in_stock'] < 200)
                                    <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-zinc-100">
                                        {{ __('hyper.buy_in_stock') }}({{ $goods['in_stock'] }})
                                    </div>
                                @endif
                            </div>  
                        </div>

                        <div class="flex items-center justify-between flex-row">
                            <div class="text-zinc-500 text-2xl font-bold text-zinc-900">
                                {{ __('hyper.global_currency') }}
                                {{ $goods['actual_price'] }}
                            </div>
                            <div class="rounded-full bg-zinc-100 p-2 @if($goods['in_stock'] > 0) group-hover:bg-zinc-900 group-hover:text-white @else opacity-50 cursor-not-allowed @endif transition-colors duration-300">
                                @if($goods['in_stock'] > 0)
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                    </svg>
                                @else
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4">
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
    </div>
    @foreach($data as $index => $group)
        <div class="tab-pane" id="group-{{ $group['id'] }}">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-6 p-2 sm:p-4">
                @foreach($group['goods'] as $goods)
                    @if($goods['in_stock'] > 0)
                    <a href="{{ url("/buy/{$goods['id']}") }}" class="group relative bg-white rounded-xl shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden border border-zinc-200 flex flex-col gap-4">
                    @else
                    <a href="javascript:void(0);" onclick="sell_out_tip()" class="group relative bg-white rounded-xl shadow-sm overflow-hidden border border-zinc-200 flex flex-col gap-4">
                    @endif
                        <div class="aspect-square w-full overflow-hidden flex-shrink-0 relative">
                            <div class="loading-spinner absolute inset-0 flex items-center justify-center bg-zinc-50 transition-opacity duration-200">
                                <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <style>.spinner_P7sC{transform-origin:center;animation:spinner_svv2 .75s infinite linear}@keyframes spinner_svv2{100%{transform:rotate(360deg)}}</style>
                                    <path d="M10.14,1.16a11,11,0,0,0-9,8.92A1.59,1.59,0,0,0,2.46,12,1.52,1.52,0,0,0,4.11,10.7a8,8,0,0,1,6.66-6.61A1.42,1.42,0,0,0,12,2.69h0A1.57,1.57,0,0,0,10.14,1.16Z" class="spinner_P7sC"/>
                                </svg>
                            </div>
                            <img 
                                class="w-full h-full object-cover object-center group-hover:scale-105 transition-transform duration-300 scale-[1.01] opacity-0 transition-opacity duration-150"
                                data-src="{{ picture_url($goods['picture']) }}"
                            > 
                        </div>
                        <div class="px-4 pb-4 flex flex-col justify-between h-full">
                            <div class="flex flex-col justify-between mb-4 gap-2">
                                <h3 class="font-medium text-zinc-900 line-clamp-2 text-base sm:text-lg leading-tight">
                                    {{ $goods['gd_name'] }}
                                </h3>
                                <div class="flex items-center gap-2 flex-row">
                                    @if($goods['type'] == \App\Models\Goods::AUTOMATIC_DELIVERY)
                                        <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-green-100 text-green-500">
                                            {{ __('goods.fields.automatic_delivery') }}
                                        </div>
                                    @else
                                        <!-- <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-yellow-100 text-yellow-500">
                                            {{ __('goods.fields.manual_processing') }}
                                        </div> -->
                                    @endif

                                    @if($goods['in_stock'] == 0)
                                        <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-red-100 text-red-500">
                                            {{ __('hyper.home_out_of_stock') }}
                                        </div>
                                    @elseif($goods['in_stock'] < 200)
                                        <div class="w-fit text-zinc-500 text-xs rounded-lg px-2 py-1 flex-shrink-0 bg-zinc-100">
                                            {{ __('hyper.buy_in_stock') }}({{ $goods['in_stock'] }})
                                        </div>
                                    @endif
                                </div>  
                            </div>

                            <div class="flex items-center justify-between flex-row">
                                <div class="text-zinc-500 text-2xl font-bold text-zinc-900">
                                    {{ __('hyper.global_currency') }}
                                    {{ $goods['actual_price'] }}
                                </div>
                                <div class="rounded-full bg-zinc-100 p-2 @if($goods['in_stock'] > 0) group-hover:bg-zinc-900 group-hover:text-white @else opacity-50 cursor-not-allowed @endif transition-colors duration-300">
                                    @if($goods['in_stock'] > 0)
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="w-4 h-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                        </svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4">
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
        </div>
    @endforeach
</div>
@stop 
@section('js')
<script>
    // Lazy load images
    document.addEventListener("DOMContentLoaded", function() {
        const images = document.querySelectorAll('img[data-src]');
        
        const loadImage = (img) => {
            const src = img.getAttribute('data-src');
            if (!src) return;
            
            img.onload = () => {
                img.classList.remove('opacity-0');
                const spinner = img.previousElementSibling;
                if (spinner && spinner.classList.contains('loading-spinner')) {
                    spinner.remove();
                }
            };
            img.src = src;
            img.removeAttribute('data-src');
        };

        // Use Intersection Observer for lazy loading
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        loadImage(entry.target);
                        imageObserver.unobserve(entry.target);
                    }
                });
            });

            images.forEach(img => imageObserver.observe(img));
        } else {
            // Fallback for browsers that don't support Intersection Observer
            images.forEach(loadImage);
        }
    });

    // Updated tab switching code
    $('.custom-tab').on('click', function(e) {
        e.preventDefault();
        // Remove active class from all tabs and panes
        $('.custom-tab').removeClass('active');
        $('.tab-pane').removeClass('active');
        
        // Add active class to clicked tab
        $(this).addClass('active');
        
        // Show corresponding tab content
        const target = $(this).attr('href');
        $(target).addClass('active');
    });

    $("#search").on("input",function(e){
        var txt = $("#search").val();
        if($.trim(txt)!="") {
            $(".category").hide().filter(":contains('"+txt+"')").show();
        } else {
            $(".category").show();
        }
    });
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