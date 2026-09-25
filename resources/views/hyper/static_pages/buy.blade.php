@extends('hyper.layouts.seo')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-6 gap-4">
    <div class="buy-shop hyper-sm-last col-span-1 md:col-span-2">
        <div class="px-4 py-8 sticky ">
            <form id="buy-form" action="{{ url('create-order') }}" method="post">
                {{ csrf_field() }}
                <div class="form-group mb-4 flex items-center gap-4">
                    <div class="flex-1 flex flex-col gap-2">
                        <h3 class="text-xl font-bold">
                            {{-- 商品名称 --}}
                            {{ $gd_name }}
                        </h3>
                        <div class="">
                            @if($type == \App\Models\Goods::AUTOMATIC_DELIVERY)
                                {{-- 自动发货 --}}
                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-md">{{ __('hyper.buy_automatic_delivery') }}</span>
                            @else
                                {{-- 人工发货 --}}
                                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-md">{{ __('hyper.buy_charge') }}</span>
                            @endif
                            {{-- 库存 --}}
                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-md">
                                @if($in_stock > 200)
                                    {{ __('hyper.buy_full_stock') }}
                                @else
                                    {{ __('hyper.buy_in_stock') }}({{ $in_stock }})
                                @endif
                            </span>
                            @if($buy_limit_num > 0)
                                <span class="px-2 py-1 bg-zinc-100 text-zinc-800 rounded-md"> {{__('hyper.buy_purchase_restrictions')}}({{ $buy_limit_num }})</span>
                            @endif
                        </div>
                    </div>
                    @if(isset($picture))
                    <div class="form-group mb-4 w-12 h-12">
                        <div class="w-full overflow-hidden rounded-lg mb-3">
                            <img src="/uploads/{{ $picture }}" alt="{{ $gd_name }}" class="w-full h-auto object-cover rounded-lg border border-zinc-200">
                        </div>
                    </div>
                    @endif
                </div>

                @if(!empty($wholesale_price_cnf) && is_array($wholesale_price_cnf))
                    <div class="form-group">
                        <div id="wholesale-price-container" class="bg-zinc-50 rounded-md px-3 py-2 border border-zinc-200 transition-all duration-500 ease-in-out overflow-hidden">
                            @foreach($wholesale_price_cnf as $ws)
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-zinc-600">
                                        {{ __('hyper.buy_purchase') }} 
                                        <span class="font-medium">{{ $ws['number'] }}</span> 
                                        {{__('hyper.buy_the_above')}}
                                    </span>
                                    <span class="text-zinc-800 font-medium">
                                        {{ $ws['price'] }} {{__('hyper.buy_each')}}
                                    </span>
                                </div>
                                @unless($loop->last)
                                    <div class="border-b border-zinc-200 my-1"></div>
                                @endunless
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="form-group">
                    <div class="flex items-baseline gap-3">
                        <span class="text-2xl font-bold text-red-600">
                            {{ __('hyper.global_currency') }}<span id="display-price">{{ $actual_price }}</span>
                        </span>
                        @if($actual_price != $retail_price)
                            <span class="text-zinc-400 line-through text-sm">
                                {{ __('hyper.global_currency') }}{{ $retail_price }}
                            </span>
                        @endif
                    </div>
                    <div id="total-price-container" class="mt-2 hidden">
                        <div class="bg-zinc-50 border border-zinc-200 rounded-lg p-3">
                            <div class="flex items-center justify-between">
                                <span class="text-zinc-600">{{ __('hyper.global_currency') }}<span id="display-unit-price">{{ $actual_price }}</span> × <span id="display-quantity">1</span></span>
                                <span class="font-medium text-zinc-800">= {{ __('hyper.global_currency') }}<span id="display-total-price" class="text-red-600 font-bold">{{ $actual_price }}</span></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    {{-- 电子邮箱 --}}
                    <div class="buy-title mb-2">{{ __('hyper.buy_email') }}</div>
                    <input type="hidden" name="gid" value="{{ $id }}">
                    {{-- 接收卡密或通知 --}}
                    <input type="email" 
                           name="email" 
                           class="w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500" 
                           placeholder="{{ __('hyper.buy_input_account') }}">
                </div>
                <div class="form-group">
                    {{-- 购买数量 --}}
                    <div class="buy-title mb-2">{{ __('hyper.buy_purchase_quantity') }}</div>
                    <div class="flex items-center">
                        <button type="button" 
                                class="px-3 py-2 w-10 h-10 bg-zinc-100 border border-zinc-300 rounded-l-md hover:bg-zinc-200 focus:outline-none" 
                                onclick="decrementCount()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>
                        </button>
                        <input type="number" 
                               id="quantity-input"
                               name="by_amount" 
                               value="1" 
                               min="1" 
                               max="999"
                               class="w-20 px-3 py-2 h-10 bg-white border-y border-zinc-300 text-center text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500 focus:!outline-none focus:!border-none focus:shadow-none"
                               onchange="validateQuantity(this)"
                               oninput="validateQuantity(this)">
                        <button type="button" 
                                class="px-3 py-2 w-10 h-10 bg-zinc-100 border border-zinc-300 rounded-r-md hover:bg-zinc-200 focus:outline-none" 
                                onclick="incrementCount()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                        </button>
                    </div>
                </div>
                <script>
                    function decrementCount() {
                        const input = document.getElementById('quantity-input');
                        if (input.value > input.min) {
                            input.value = parseInt(input.value) - 1;
                            updateTotalPrice();
                        }
                    }
                    
                    function incrementCount() {
                        const input = document.getElementById('quantity-input');
                        const maxStock = {{ $in_stock }};
                        const buyLimit = {{ $buy_limit_num > 0 ? $buy_limit_num : 'Infinity' }};
                        const maxValue = Math.min(maxStock, buyLimit, 999);
                        
                        if (parseInt(input.value) < maxValue) {
                            input.value = parseInt(input.value) + 1;
                            updateTotalPrice();
                        }
                    }
                    
                    function validateQuantity(input) {
                        const maxStock = {{ $in_stock }};
                        const buyLimit = {{ $buy_limit_num > 0 ? $buy_limit_num : 'Infinity' }};
                        const maxValue = Math.min(maxStock, buyLimit, 999);
                        
                        // Ensure value is at least 1
                        if (input.value < 1) {
                            input.value = 1;
                        }
                        
                        // Ensure value doesn't exceed max
                        if (input.value > maxValue) {
                            input.value = maxValue;
                        }
                        
                        updateTotalPrice();
                    }
                    
                    function updateTotalPrice() {
                        const quantity = parseInt(document.getElementById('quantity-input').value);
                        let unitPrice = {{ $actual_price }};
                        
                        // Calculate unit price based on wholesale price configuration if available
                        @if(!empty($wholesale_price_cnf) && is_array($wholesale_price_cnf))
                            // Sort wholesale configurations by number in descending order
                            const wholesalePrices = [
                                @foreach($wholesale_price_cnf as $ws)
                                    { number: {{ $ws['number'] }}, price: {{ $ws['price'] }} },
                                @endforeach
                            ].sort((a, b) => b.number - a.number);
                            
                            // Find applicable wholesale price
                            for (const ws of wholesalePrices) {
                                if (quantity >= ws.number) {
                                    unitPrice = ws.price;
                                    break;
                                }
                            }
                        @endif
                        
                        const totalPrice = (unitPrice * quantity).toFixed(2);
                        
                        document.getElementById('display-unit-price').textContent = unitPrice.toFixed(2);
                        document.getElementById('display-quantity').textContent = quantity;
                        document.getElementById('display-total-price').textContent = totalPrice;
                        document.getElementById('display-price').textContent = unitPrice.toFixed(2);
                        document.getElementById('final-total-price').textContent = totalPrice;
                        
                        // Show total price containers if quantity > 1
                        const totalPriceContainer = document.getElementById('total-price-container');
                        const finalTotalContainer = document.getElementById('final-total-container');
                        
                        if (quantity > 1) {
                            // Calculate savings if wholesale price is applied
                            const regularPrice = {{ $actual_price }};
                            let savingsHTML = '';
                            
                            if (unitPrice < regularPrice) {
                                const regularTotal = (regularPrice * quantity).toFixed(2);
                                const savings = (regularTotal - totalPrice).toFixed(2);
                                const savingsPercent = (100 * (savings / regularTotal)).toFixed(1);
                                
                                savingsHTML = `
                                    <div class="mt-2 text-green-700 font-medium">
                                        <div class="flex items-center justify-between">
                                            <span>{{ __('hyper.buy_you_save') }}</span>
                                            <span>{{ __('hyper.global_currency') }}${savings} (${savingsPercent}%)</span>
                                        </div>
                                    </div>
                                `;
                            }
                            
                            // Update the total price container with both price and savings info
                            document.getElementById('total-price-container').innerHTML = `
                                <div class="bg-zinc-50 border border-zinc-200 rounded-lg p-3">
                                    <div class="flex items-center justify-between">
                                        <span class="text-zinc-600">{{ __('hyper.global_currency') }}<span id="display-unit-price">${unitPrice.toFixed(2)}</span> × <span id="display-quantity">${quantity}</span></span>
                                        <span class="font-medium text-zinc-800">= {{ __('hyper.global_currency') }}<span id="display-total-price" class="text-red-600 font-bold">${totalPrice}</span></span>
                                    </div>
                                    ${savingsHTML}
                                </div>
                            `;
                            
                            totalPriceContainer.classList.remove('hidden');
                            finalTotalContainer.classList.remove('hidden');
                        } else {
                            totalPriceContainer.classList.add('hidden');
                            finalTotalContainer.classList.add('hidden');
                        }
                    }
                    
                    // Initialize on page load
                    document.addEventListener('DOMContentLoaded', function() {
                        document.getElementById('display-unit-price').textContent = {{ $actual_price }};
                        updateTotalPrice();
                        
                        // Set up scroll event to handle wholesale price list fade
                        const wholesalePriceContainer = document.getElementById('wholesale-price-container');
                        if (wholesalePriceContainer) {
                            // Store the original height to use for transitions
                            let originalHeight = wholesalePriceContainer.scrollHeight + 'px';
                            wholesalePriceContainer.style.maxHeight = originalHeight;
                            
                            let lastScrollTop = 0;
                            window.addEventListener('scroll', function() {
                                // Check if device is mobile (screen width less than 768px)
                                const isMobile = window.innerWidth < 768;
                                
                                // Only apply minimization on desktop devices
                                if (isMobile) {
                                    return;
                                }
                                
                                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                                
                                // Check if we're scrolling down and beyond a certain threshold
                                if (scrollTop > 100 && scrollTop > lastScrollTop) {
                                    // Fade out the wholesale price container
                                    wholesalePriceContainer.style.opacity = '0';
                                    wholesalePriceContainer.style.maxHeight = '0';
                                    wholesalePriceContainer.style.paddingTop = '0';
                                    wholesalePriceContainer.style.paddingBottom = '0';
                                    wholesalePriceContainer.style.marginBottom = '0';
                                    wholesalePriceContainer.style.marginTop = '0';
                                    wholesalePriceContainer.style.borderWidth = '0';
                                    
                                    // Ensure parent form-group has no margin when condensed
                                    wholesalePriceContainer.parentNode.style.marginBottom = '0';
                                    wholesalePriceContainer.parentNode.style.marginTop = '0';
                                } else if (scrollTop < 50 || scrollTop < lastScrollTop) {
                                    // Fade in the wholesale price container when scrolling back up
                                    wholesalePriceContainer.style.opacity = '1';
                                    wholesalePriceContainer.style.maxHeight = originalHeight;
                                    wholesalePriceContainer.style.paddingTop = '';
                                    wholesalePriceContainer.style.paddingBottom = '';
                                    wholesalePriceContainer.style.marginBottom = '';
                                    wholesalePriceContainer.style.marginTop = '';
                                    wholesalePriceContainer.style.borderWidth = '';
                                    
                                    // Restore parent form-group margins
                                    wholesalePriceContainer.parentNode.style.marginBottom = '';
                                    wholesalePriceContainer.parentNode.style.marginTop = '';
                                }
                                
                                lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
                            });
                        }
                    });
                </script>
                @if(dujiaoka_config_get('is_open_search_pwd') == \App\Models\Goods::STATUS_OPEN)
                <div class="form-group">
                    {{-- 查询密码 --}}
                    <div class="buy-title mb-2">{{ __('hyper.buy_search_password') }}</div>
                    {{-- 查询订单密码 --}}
                    <input type="text" 
                           name="search_pwd" 
                           class="w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500" 
                           placeholder="{{ __('hyper.buy_input_search_password') }}">
                </div>
                @endif
                @if(isset($open_coupon))
                    <div class="form-group bg-zinc-100 rounded-md p-2 flex flex-col gap-2 border border-zinc-200">
                        {{-- 优惠码 --}}
                        <div class="flex items-center justify-between cursor-pointer" onclick="toggleCoupon()">
                            <div class="buy-title">{{ __('hyper.buy_promo_code') }}<span class="text-zinc-500 text-sm">{{ __('hyper.buy_optional') }}</span></div>
                            <svg id="coupon-arrow" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="transition-transform rotate-90">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        {{-- 您有优惠码吗？ --}}
                        <input type="text" 
                               name="coupon_code" 
                               id="coupon-input"
                               class="hidden w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500" 
                               placeholder="{{ __('hyper.buy_input_promo_code') }}">
                    </div>
                @endif
                @if($type == \App\Models\Goods::MANUAL_PROCESSING && is_array($other_ipu))
                    @foreach($other_ipu as $ipu)
                        <div class="form-group">
                            <div class="buy-title mb-2">{{ $ipu['desc'] }}</div>
                            <input type="text" 
                                   name="{{ $ipu['field'] }}" 
                                   @if($ipu['rule'] !== false) required @endif 
                                   class="w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500" 
                                   placeholder="{{ $ipu['placeholder'] }}">
                        </div>
                    @endforeach
                @endif
                @if(dujiaoka_config_get('is_open_geetest') == \App\Models\Goods::STATUS_OPEN )
                    <div class="form-group">
                        {{-- 极验证 --}}
                        <div class="buy-title mb-2">{{ __('hyper.buy_behavior_verification') }}</div>
                        <div id="geetest-captcha"></div>
                        <p id="wait-geetest-captcha" class="show text-zinc-500 text-sm">loading...</p>
                    </div>
                @endif
                @if(dujiaoka_config_get('is_open_img_code') == \App\Models\Goods::STATUS_OPEN)
                    <div class="form-group">
                        {{-- 图形验证码 --}}
                        <div class="buy-title mb-2">{{ __('hyper.buy_verify_code') }}</div>
                        <div class="flex gap-2">
                            <div class="flex-1">
                                <input type="text" 
                                       name="img_verify_code" 
                                       class="w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500" 
                                       placeholder="{{ __('hyper.buy_verify_code') }}">
                            </div>
                            <div class="buy-captcha flex-shrink-0">
                                <img class="h-[38px] rounded-md cursor-pointer" 
                                     src="{{ captcha_src('buy') . time() }}" 
                                     onclick="refresh()">
                            </div>
                        </div>
                        <script>
                            function refresh(){
                                $('img[class*="cursor-pointer"]').attr('src','{{ captcha_src('buy') }}'+Math.random());
                            }
                        </script>
                    </div>
                @endif
                <div class="form-group">
                    {{-- 支付方式 --}}
                    <div class="buy-title mb-2">{{ __('hyper.buy_payment_method') }}</div>
                    <div class="w-full">
                        <input type="hidden" name="payway" value="{{ $payways[0]['id'] ?? 0 }}">
                        <style>
                            .pay-type.active{
                                border-color: #27272a !important;
                                background-color: #f4f4f5;
                                color: #18181b;
                            }
                        </style>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($payways as $key => $way)
                                <div class="btn pay-type border border-zinc-300 flex flex-row items-center justify-center gap-2 rounded-full p-2 text-center cursor-pointer hover:border-zinc-500 transition-colors @if($key == 0) active @endif"
                                     data-type="{{ $way['pay_check'] }}" 
                                     data-id="{{ $way['id'] }}" 
                                     data-name="{{ $way['pay_name'] }}">
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="mt-8">
                    {{-- 提交订单 --}}
                    <button type="submit" 
                            class="w-full px-6 py-3 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors flex items-center justify-center gap-2 text-base font-medium" 
                            id="submit">
                        {{ __('hyper.buy_order_now') }}
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-arrow-right"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="m12 16 4-4-4-4"/></svg>
                    </button>
                    <div id="final-total-container" class="hidden mt-2 text-center text-sm text-zinc-600">
                        {{ __('hyper.buy_total') }}: <span class="font-medium text-red-600">{{ __('hyper.global_currency') }}<span id="final-total-price">{{ $actual_price }}</span></span>
                    </div>
                </div>
            </form>
        </div> <!-- end card-->
    </div>
    <div class="p-4 sm:p-8 bg-white rounded-2xl border border-zinc-200 prose prose-zinc max-w-5xl prose-headings:mt-0 prose-headings:mb-0 prose-p:mt-0 prose-p:mb-2 prose-img:my-2 prose-img:rounded-lg prose-a:text-zinc-600 col-span-1 md:col-span-4 w-full shadow-sm">
        {{-- 商品详情 --}}
        <h5 class="text-xl font-bold !mb-4">{{ __('hyper.buy_product_desciption') }}</h5>
        {!! $description !!}
    </div>
</div>
<div class="modal fade" id="buy_prompt" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                {{-- 购买提示 --}}
                <h5 class="modal-title" id="myCenterModalLabel">{{ __('hyper.buy_purchase_tips') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">×</button>
            </div>
            <div class="modal-body">
                {!! $buy_prompt !!}
            </div>
        </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
</div><!-- /.modal -->
<div class="modal fade" id="img-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: none;">
        <img id="img-zoom" style="border-radius: 5px;">
    </div><!-- /.modal-dialog -->
</div><!-- /.modal -->
@stop
@section('js')
<script>
    $('#submit').click(function(){
        if($("input[name='email']").val() == ''){
            {{-- 邮箱不能为空 --}}
            $.NotificationApp.send("{{ __('hyper.buy_warning') }}","{{ __('hyper.buy_empty_mailbox') }}","top-center","rgba(0,0,0,0.2)","info");
            return false;
        }
        if($("input[name='by_amount']").val() == 0 ){
            {{-- 购买数量不能为0 --}}
            $.NotificationApp.send("{{ __('hyper.buy_warning') }}","{{ __('hyper.buy_zero_quantity') }}","top-center","rgba(0,0,0,0.2)","info");
            return false;
        }
        if($("input[name='by_amount']").val() > {{ $in_stock }}){
            {{-- 数量不允许大于库存 --}}
            $.NotificationApp.send("{{ __('hyper.buy_warning') }}","{{ __('hyper.buy_exceeds_stock') }}","top-center","rgba(0,0,0,0.2)","info");
            return false;
        }
        @if($buy_limit_num > 0)
        if($("input[name='by_amount']").val() > {{ $buy_limit_num }}){
            {{-- 已超过限购数量 --}}
            $.NotificationApp.send("{{ __('hyper.buy_warning') }}","{{ __('hyper.buy_exceeds_limit') }}","top-center","rgba(0,0,0,0.2)","info");
            return false;
        }
        @endif
        @if(dujiaoka_config_get('is_open_search_pwd') == \App\Models\Goods::STATUS_OPEN)
        if($("input[name='search_pwd']").val() == 0){
            {{-- 查询密码不能为空 --}}
            $.NotificationApp.send("{{ __('hyper.buy_warning') }}","{{ __('hyper.buy_empty_query_password') }}","top-center","rgba(0,0,0,0.2)","info");
            return false;
        }
        @endif
        @if(dujiaoka_config_get('is_open_img_code') == \App\Models\Goods::STATUS_OPEN)
        if($("input[name='img_verify_code']").val() == ''){
            {{-- 验证码不能为空 --}}
            $.NotificationApp.send("{{ __('hyper.buy_warning') }}","{{ __('hyper.buy_empty_captcha') }}","top-center","rgba(0,0,0,0.2)","info");
            return false;
        }
        @endif
    });
</script>
<script>
    @if(!empty($buy_prompt))
        $('#buy_prompt').modal();
    @endif
        $(function() {
        //点击图片放大
        $("#img-zoom").click(function(){
            $('#img-modal').modal("hide");
        });
        $("#img-dialog").click(function(){
            $('#img-modal').modal("hide");
        });
        $(".buy-product img").each(function(i){
            var src = $(this).attr("src");
            $(this).click(function () {
                $("#img-zoom").attr("src", src);
                var oImg = $(this);
                var img = new Image();
                img.src = $(oImg).attr("src");
                var realWidth = img.width;
                var realHeight = img.height;
                var ww = $(window).width();
                var hh = $(window).height();
                $("#img-content").css({"top":0,"left":0,"height":"auto"});
                $("#img-zoom").css({"height":"auto"});
                $("#img-zoom").css({"margin-left":"auto"});
                $("#img-zoom").css({"margin-right":"auto"});
                if((realWidth+20)>ww){
                    $("#img-content").css({"width":"100%"});
                    $("#img-zoom").css({"width":"100%"});
                }else{
                    $("#img-content").css({"width":realWidth+20, "height":realHeight+20});
                    $("#img-zoom").css({"width":realWidth, "height":realHeight});
                }
                if((hh-realHeight-40)>0){
                    $("#img-content").css({"top":(hh-realHeight-40)/2});
                }
                if((ww-realWidth-20)>0){
                    $("#img-content").css({"left":(ww-realWidth-20)/2});
                }
                $('#img-modal').modal();
            });
        });
    });
</script>
@if(dujiaoka_config_get('is_open_geetest') == \App\Models\Goods::STATUS_OPEN )
<script src="https://static.geetest.com/static/tools/gt.js"></script>
<script>
    var geetest = function(url) {
        var handlerEmbed = function(captchaObj) {
            $("#geetest-captcha").closest('form').submit(function(e) {
                var validate = captchaObj.getValidate();
                if (!validate) {
                    $.NotificationApp.send("{{ __('hyper.buy_warning') }}","{{ __('hyper.buy_correct_verification') }}","top-center","rgba(0,0,0,0.2)","info");
                    e.preventDefault();
                }
            });
            captchaObj.appendTo("#geetest-captcha");
            captchaObj.onReady(function() {
                $("#wait-geetest-captcha")[0].className = "d-none";
            });
            captchaObj.onSuccess(function () {$('#geetest-captcha').attr("placeholder",'{{ __('dujiaoka.success_behavior_verification') }}')})

            captchaObj.appendTo("#geetest-captcha");
        };
        $.ajax({
            url: url + "?t=" + (new Date()).getTime(),
            type: "get",
            dataType: "json",
            success: function(data) {
                initGeetest({
                    width: '100%',
                    gt: data.gt,
                    challenge: data.challenge,
                    product: "popup",
                    offline: !data.success,
                    new_captcha: data.new_captcha,
                    lang: '{{ dujiaoka_config_get('language') ?? 'zh_CN' }}',
                    http: '{{ (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://" }}' + '://'
                }, handlerEmbed);
            }
        });
    };
    (function() {
        geetest('{{ '/check-geetest' }}');
    })();
</script>
@endif
<script>
    function toggleCoupon() {
        const input = document.getElementById('coupon-input');
        const arrow = document.getElementById('coupon-arrow');
        input.classList.toggle('hidden');
        arrow.style.transform = input.classList.contains('hidden') ? 'rotate(90deg)' : 'rotate(180deg)';
    }
</script>
@stop
