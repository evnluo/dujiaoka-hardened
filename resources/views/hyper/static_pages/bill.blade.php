@extends('hyper.layouts.default')
@section('content')
<div class="my-4 sm:my-8">
    <div class="">
        <div class="flex flex-col items-center justify-center">
            <h4 class="text-xl sm:text-2xl font-bold mb-4">
                {{ __('hyper.bill_title') }}
            </h4>
        </div>
    </div>
</div>

<div class="p-2 sm:p-4">
    <div class="max-w-2xl mx-auto space-y-6">
        {{-- Order Summary Card --}}
        <div class="bg-white rounded-xl shadow-sm border border-zinc-200 overflow-hidden">
            <div class="p-6">
                {{-- Product Info --}}
                <div class="flex items-center gap-4 pb-6 mb-6 border-b border-zinc-200">
                    <div class="w-12 h-12 bg-zinc-100 rounded-lg flex items-center justify-center flex-shrink-0 overflow-hidden rounded-lg">
                        <img 
                            class="w-full h-full object-cover object-center"
                            src="{{ picture_url($goods['picture']) }}"
                        >
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-medium text-zinc-900 mb-1 line-clamp-1">{{ $title }}</h3>
                        <p class="text-sm text-zinc-500">{{ __('hyper.bill_order_number') }}: {{ $order_sn }}</p>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-bold text-zinc-900">
                            {{ __('hyper.global_currency') }}
                                {{ $actual_price }}
                        </div>
                        @if(!empty($coupon))
                            <div class="text-sm text-zinc-400 line-through">
                                {{ __('hyper.global_currency') }}
                                {{ $goods_price }}
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Price Details --}}
                <div class="bg-zinc-50 rounded-lg p-4 space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-600">{{ __('hyper.bill_commodity_price') }}</span>
                        <span class="text-zinc-900">
                            {{ __('hyper.global_currency') }}
                            {{ $goods_price }}
                        </span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-600">{{ __('hyper.bill_purchase_quantity') }}</span>
                        <span class="text-zinc-900">x {{ $buy_amount }}</span>
                    </div>
                    @if(!empty($coupon))
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-600">{{ __('hyper.bill_promo_code') }}</span>
                        <span class="text-zinc-900">
                            {{ $coupon['coupon'] }}
                        </span>
                    </div>
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-600">{{ __('hyper.bill_discounted_price') }}</span>
                        <span class="text-green-600">
                            -{{ __('hyper.global_currency') }}
                            {{ $coupon_discount_price }}
                        </span>
                    </div>
                    @endif
                    <div class="pt-2 border-t border-zinc-200">
                        <div class="flex justify-between items-center">
                            <span class="font-medium">{{ __('hyper.bill_actual_payment') }}</span>
                            <span class="text-lg font-bold text-zinc-900">
                                {{ __('hyper.global_currency') }}
                                {{ $actual_price }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Contact Info Card --}}
        <div class="bg-white rounded-xl shadow-sm border border-zinc-200 overflow-hidden">
            <div class="p-6">
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-zinc-50 rounded-lg flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        </div>
                        <div>
                            <div class="text-sm text-zinc-500">{{ __('hyper.bill_email') }}</div>
                            <div class="text-zinc-900">{{ $email }}</div>
                        </div>
                    </div>
                    @if(!empty($info))
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-zinc-50 rounded-lg flex items-center justify-center">  
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                        <div>
                            <div class="text-sm text-zinc-500">{{ __('hyper.bill_order_information') }}</div>
                            <div class="text-zinc-900">{{ $info }}</div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Payment Method Card --}}
        <div class="bg-white rounded-xl shadow-sm border border-zinc-200 overflow-hidden">
            <div class="p-6">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-zinc-50 rounded-lg flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-zinc-600"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-500">{{ __('hyper.bill_payment_method') }}</div>
                        <div class="text-zinc-900">{{ $pay['pay_name'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Pay Button --}}
        <a href="{{ url('pay-gateway', ['handle' => urlencode($pay['pay_handleroute']),'payway' => $pay['pay_check'], 'orderSN' => $order_sn]) }}"
           class="block w-full px-6 py-3.5 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors flex items-center justify-center gap-2 text-base font-medium">
            {{ __('hyper.bill_pay_immediately') }}
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-arrow-right"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="m12 16 4-4-4-4"/></svg>
        </a>
    </div>
</div>
@stop

@section('js')
@stop
