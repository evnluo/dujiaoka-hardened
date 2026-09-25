@extends('unicorn.layouts.default')
@section('content')
    <div class="w-full flex justify-center">
        <div class="notice container flex justify-center items-center" >
            <div class="mt-3 max-w-4xl">
                <div class="jumbotron jumbotron-fluid p-4 border border-zinc-200 rounded-lg">
                    <div class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-info"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <h4 class="mb-0">{{ __('dujiaoka.site_announcement') }}</h4>
                    </div>
                    <div class="prose prose-zinc max-w-4xl">{!! dujiaoka_config_get('notice') !!}</div>
                </div>
            </div>
        </div>
    </div>


    <!-- main start -->
    <section>
        <style>
            .tab-button.active {
                background-color: rgb(113, 113, 122);
                color: #fff;
            }
        </style>
        <!-- category start -->
        <div class="container mx-auto px-4">
            <div class="flex justify-center">
                <div class="w-full">
                    <div class="flex justify-center gap-2 py-4">
                        <button class="tab-button  px-4 py-2 rounded-lg border border-zinc-200 hover:bg-zinc-100 active" data-tab="group-all">
                            {{ __('dujiaoka.group_all') }}
                        </button>
                        @foreach($data as $index => $group)
                            <button class="tab-button px-4 py-2 rounded-lg border border-zinc-200 hover:bg-zinc-100" data-tab="group-{{ $index }}">
                                {{ $group['gp_name'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- goods section -->
        <div class="container mx-auto px-4">
            <div class="mb-20">
                <div class="tab-content">
                    <!-- All Products Tab -->
                    <div class="tab-pane active" id="group-all">
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                            @foreach($data as $index => $group)
                                @foreach($group['goods'] as $goods)
                                    <div class="relative">
                                        <div class="rounded-lg border border-gray-200 overflow-hidden">
                                            <img src="{{ picture_url($goods['picture']) }}" class="w-full h-48 object-cover" alt="{{ $goods['gd_name'] }}">
                                            <div class="p-4">
                                                @if($goods['type'] == \App\Models\Goods::AUTOMATIC_DELIVERY)
                                                    <span class="inline-flex items-center bg-green-500 text-white text-sm px-2 py-1 rounded mb-2">
                                                        <i class="ali-icon">&#xe7db;</i>
                                                        {{ __('goods.fields.automatic_delivery') }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center bg-yellow-500 text-white text-sm px-2 py-1 rounded mb-2">
                                                        <i class="ali-icon">&#xe74b;</i>
                                                        {{ __('goods.fields.manual_processing') }}
                                                    </span>
                                                @endif
                                                <h6 class="text-lg font-semibold truncate">{{ $goods['gd_name'] }}</h6>
                                                
                                                <div class="flex space-x-2 mt-2">
                                                    <button class="px-3 py-1 text-sm border border-green-500 text-green-500 rounded-lg hover:bg-green-50">
                                                        <i class="ali-icon">&#xe703;</i>
                                                        <strong>{{ $goods['actual_price'] }}</strong>
                                                    </button>
                                                    @if($goods['wholesale_price_cnf'])
                                                        <button class="px-3 py-1 text-sm border border-yellow-500 text-yellow-500 rounded-lg hover:bg-yellow-50">
                                                            <i class="ali-icon">&#xe77d;</i>
                                                            {{ __('dujiaoka.home_discount') }}
                                                        </button>
                                                    @endif
                                                </div>
                                                
                                                <div class="mt-4 flex items-center justify-between">
                                                    <p class="text-sm text-gray-500">{{__('goods.fields.in_stock')}}：{{ $goods['in_stock'] }}</p>
                                                    <a href="{{ url("/buy/{$goods['id']}") }}" 
                                                       class="px-4 py-2 bg-zinc-600 text-white rounded-lg hover:bg-zinc-700">
                                                        <i class="ali-icon">&#xe7d8;</i>
                                                        {{ __('dujiaoka.order_now') }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </div>

                    <!-- Individual Group Tabs -->
                    @foreach($data as $index => $group)
                        <div class="tab-pane hidden" id="group-{{ $index }}">
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                                @foreach($group['goods'] as $goods)
                                    <div class="relative">
                                        <div class="rounded-lg border border-gray-200 overflow-hidden">
                                            <img src="{{ picture_url($goods['picture']) }}" class="w-full h-48 object-cover" alt="{{ $goods['gd_name'] }}">
                                            <div class="p-4">
                                                @if($goods['type'] == \App\Models\Goods::AUTOMATIC_DELIVERY)
                                                    <span class="inline-flex items-center bg-green-500 text-white text-sm px-2 py-1 rounded mb-2">
                                                        <i class="ali-icon">&#xe7db;</i>
                                                        {{ __('goods.fields.automatic_delivery') }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center bg-yellow-500 text-white text-sm px-2 py-1 rounded mb-2">
                                                        <i class="ali-icon">&#xe74b;</i>
                                                        {{ __('goods.fields.manual_processing') }}
                                                    </span>
                                                @endif
                                                <h6 class="text-lg font-semibold truncate">{{ $goods['gd_name'] }}</h6>
                                                
                                                <div class="flex space-x-2 mt-2">
                                                    <button class="px-3 py-1 text-sm border border-green-500 text-green-500 rounded-lg hover:bg-green-50">
                                                        <i class="ali-icon">&#xe703;</i>
                                                        <strong>{{ $goods['actual_price'] }}</strong>
                                                    </button>
                                                    @if($goods['wholesale_price_cnf'])
                                                        <button class="px-3 py-1 text-sm border border-yellow-500 text-yellow-500 rounded-lg hover:bg-yellow-50">
                                                            <i class="ali-icon">&#xe77d;</i>
                                                            {{ __('dujiaoka.home_discount') }}
                                                        </button>
                                                    @endif
                                                </div>
                                                
                                                <div class="mt-4 flex items-center justify-between">
                                                    <p class="text-sm text-gray-500">{{__('goods.fields.in_stock')}}：{{ $goods['in_stock'] }}</p>
                                                    <a href="{{ url("/buy/{$goods['id']}") }}" 
                                                       class="px-4 py-2 bg-zinc-600 text-white rounded-lg hover:bg-zinc-700">
                                                        <i class="ali-icon">&#xe7d8;</i>
                                                        {{ __('dujiaoka.order_now') }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    <!-- main end -->
@stop

@section('js')
    <script>
        // Tab switching functionality
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', () => {
                // Remove active class from all buttons and panes
                document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.add('hidden'));
                
                // Add active class to clicked button and show corresponding pane
                button.classList.add('active');
                const tabId = button.getAttribute('data-tab');
                document.getElementById(tabId).classList.remove('hidden');
            });
        });

        // Search functionality
        document.getElementById("searchBtn")?.addEventListener("click", function(e) {
            const searchContent = document.getElementById("searchText").value.trim();
            if(searchContent !== "") {
                document.querySelectorAll(".relative").forEach(item => {
                    if(item.textContent.includes(searchContent)) {
                        item.style.display = "";
                    } else {
                        item.style.display = "none";
                    }
                });
            } else {
                document.querySelectorAll(".relative").forEach(item => {
                    item.style.display = "";
                });
            }
        });
    </script>
@stop
