@extends('hyper.layouts.default')
@section('content')
<div class="my-4 sm:my-8">
    <div class="">
        <div class="flex flex-col items-center justify-center">
            <h4 class="text-xl sm:text-2xl font-bold mb-4">
                {{ __('hyper.orderinfo_title') }}
            </h4>
        </div>
    </div>
</div>

<div class="space-y-6 p-2 sm:p-4">
    @foreach($orders as $order)
        <div class="bg-white rounded-xl shadow-sm border border-zinc-200 overflow-hidden @if( $order['status'] == \App\Models\Order::STATUS_EXPIRED ) opacity-70 pointer-events-none @endif">
            {{-- Order Header --}}
            <div class="px-6 py-4 border-b border-zinc-200 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-zinc-100 rounded-lg flex items-center justify-center flex-shrink-0 overflow-hidden rounded-lg">
                        <img 
                            class="w-full h-full object-cover object-center"
                            src="{{ picture_url($order['picture']) }}"
                        >
                    </div>
                    <div>
                        <div class="text-sm text-zinc-500">{{ __('hyper.orderinfo_order_number') }}</div>
                        <div class="font-medium text-zinc-900">{{ $order['order_sn'] }}</div>
                    </div>
                </div>
                <div>
                    <span class="px-3 py-1.5 text-xs font-medium rounded-full
                        @switch($order['status'])
                            @case(\App\Models\Order::STATUS_EXPIRED)
                                bg-zinc-100 text-zinc-800
                                @break
                            @case(\App\Models\Order::STATUS_WAIT_PAY)
                                bg-yellow-100 text-yellow-800
                                @break
                            @case(\App\Models\Order::STATUS_PENDING)
                                bg-blue-100 text-blue-800
                                @break
                            @case(\App\Models\Order::STATUS_PROCESSING)
                                bg-indigo-100 text-indigo-800
                                @break
                            @case(\App\Models\Order::STATUS_COMPLETED)
                                bg-green-100 text-green-800
                                @break
                            @case(\App\Models\Order::STATUS_FAILURE)
                                bg-red-100 text-red-800
                                @break
                            @default
                                bg-zinc-100 text-zinc-800
                        @endswitch">
                        @switch($order['status'])
                            @case(\App\Models\Order::STATUS_EXPIRED)
                                {{ __('hyper.orderinfo_status_expired') }}
                                @break
                            @case(\App\Models\Order::STATUS_WAIT_PAY)
                                {{ __('hyper.orderinfo_status_wait_pay') }}
                                @break
                            @case(\App\Models\Order::STATUS_PENDING)
                                {{ __('hyper.orderinfo_status_pending') }}
                                @break
                            @case(\App\Models\Order::STATUS_PROCESSING)
                                {{ __('hyper.orderinfo_status_processed') }}
                                @break
                            @case(\App\Models\Order::STATUS_COMPLETED)
                                {{ __('hyper.orderinfo_status_completed') }}
                                @break
                            @case(\App\Models\Order::STATUS_FAILURE)
                                {{ __('hyper.orderinfo_status_failed') }}
                                @break
                            @default
                                {{ __('hyper.orderinfo_status_abnormal') }}
                        @endswitch
                    </span>
                </div>
            </div>

            {{-- Order Content --}}
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    {{-- Order Details --}}
                    <div class="space-y-6">
                        {{-- Basic Info --}}
                        <div class="space-y-3">
                            <div class="flex items-baseline justify-between">
                                <span class="text-zinc-500">{{ __('hyper.orderinfo_order_title') }}</span>
                                <span class="text-zinc-800 font-medium">{{ $order['title'] }}</span>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-zinc-500">{{ __('hyper.orderinfo_commodity_price') }}</span>
                                <span class="text-zinc-800 font-medium">
                                    {{ __('hyper.global_currency') }}
                                    {{ $order['goods_price'] }}
                                </span>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-zinc-500">{{ __('hyper.orderinfo_number_of_orders') }}</span>
                                <span class="text-zinc-800 font-medium">x {{ $order['buy_amount'] }}</span>
                            </div>
                            @if($order['coupon_id'])
                            <div class="flex items-baseline justify-between">
                                <span class="text-zinc-500">{{ __('hyper.bill_discounted_price') }}                                     
                                    <span class="text-zinc-300 font-medium">
                                        ({{__('hyper.bill_promo_code')}}: {{ $order['coupon']['coupon'] }})
                                    </span>
                                </span>
                                <span class="text-green-800 font-medium">
                                    -{{ __('hyper.global_currency') }}
                                    <span>
                                        {{ $order['coupon_discount_price'] }}
                                </span>
                            </div>
                            @endif  
                            <div class="flex items-baseline justify-between">
                                <span class="text-zinc-500">{{ __('hyper.orderinfo_total_order_price') }}</span>
                                <span class="text-xl font-bold text-red-600">
                                    {{ __('hyper.global_currency') }}
                                    {{ $order['actual_price'] }}
                                </span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-zinc-500">{{ __('hyper.orderinfo_order_class') }}</span>
                                @if($order['type'] == \App\Models\Order::AUTOMATIC_DELIVERY)
                                    <span class="px-2 py-1 bg-green-100 text-green-800 rounded-md text-xs">
                                        {{ __('hyper.orderinfo_automatic_delivery') }}
                                    </span>
                                @else
                                    <span class="px-2 py-1 bg-red-100 text-red-800 rounded-md text-xs">
                                        {{ __('hyper.orderinfo_charge') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Order Type & Contact --}}
                        <div class="space-y-4">
                            <hr class="border-zinc-200">

                            <h2 class="text-base font-medium text-zinc-900">
                                {{ __('hyper.orderinfo_payment_info') }}
                            </h2>

                            {{-- Contact & Purchase Info --}}
                            <div class="space-y-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-zinc-50 rounded-lg flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-400"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                    </div>
                                    <div>
                                        <div class="text-sm text-zinc-500">{{ __('hyper.orderinfo_email') }}</div>
                                        <div class="text-zinc-800">{{ $order['email'] }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-zinc-50 rounded-lg flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-400"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                                    </div>
                                    <div>
                                        <div class="text-sm text-zinc-500">{{ __('hyper.orderinfo_payment_method') }}</div>
                                        <div class="text-zinc-800">{{ $order['pay']['pay_name'] ?? '' }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-zinc-50 rounded-lg flex items-center justify-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-400"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    </div>
                                    <div>
                                        <div class="text-sm text-zinc-500">{{ __('hyper.orderinfo_order_time') }}</div>
                                        <div class="text-zinc-800">{{ $order['created_at'] }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Card Info --}}
                    <div class="space-y-4">
                        <h5 class="text-base font-medium text-zinc-900">
                            {{ __('hyper.orderinfo_carmi') }}
                        </h5>
                        <div class="relative">
                            <textarea class="w-full px-4 py-3 bg-zinc-50 border border-zinc-200 rounded-lg text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500" rows="5" readonly>{{$order['info']}}</textarea>
                            <button class="kami-btn mt-2 px-4 py-2 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors flex items-center justify-center gap-2 text-sm" data-clipboard-text="{{$order['info']}}">
                                {{ __('hyper.orderinfo_copy_carmi') }}
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            @if($order['status'] == \App\Models\Order::STATUS_EXPIRED)
                <div class="border-t border-zinc-200">
                    <div class="px-6 py-4 flex items-center gap-2 bg-amber-50">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                        <p class="text-sm text-amber-800">
                            如果订单显示过期，但是您已支付，请联系我 
                            <a href="https://me.ohevan.com" target="_blank" class="text-amber-900 hover:text-amber-700 underline underline-offset-2 font-medium">
                                (me.ohevan.com)
                            </a> 
                            补单
                        </p>
                    </div>
                </div>
            @endif
        </div>
    @endforeach

    @if(!count($orders))
        <div class="flex flex-col items-center justify-center py-12 px-4">
            <div class="text-center">
                <h4 class="text-xl font-medium text-red-600 mb-4">
                    {{ __('hyper.orderinfo_order_information') }}
                </h4>
                <a href="javascript:history.back(-1);" class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                    {{ __('hyper.error_back_btn') }}
                </a>
            </div>
        </div>
    @endif
</div>

@stop

@section('js')
<script src="/assets/hyper/js/clipboard.min.js"></script>
<script>
    var clipboard = new ClipboardJS('.kami-btn');
    clipboard.on('success', function(e) {
        Toastify({
            text: "{{ __('hyper.orderinfo_copy_success') }}",
            duration: 3000,
            close: true,
            gravity: "top",
            position: "center",
            style: {
                background: "linear-gradient(to right, #10b981, #059669)",
                borderRadius: "10px",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                gap: "10px",
                paddingRight: "10px",
            }
        }).showToast();
    });
    clipboard.on('error', function(e) {
        Toastify({
            text: "{{ __('hyper.orderinfo_copy_error') }}",
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
    });
</script>
@stop