@extends('hyper.layouts.default')
@section('content')
<div class="my-4 sm:my-8">
    <div class="">
        <div class="flex flex-col items-center justify-center">
            <h4 class="text-xl sm:text-2xl font-bold mb-4">
                {{ __('hyper.searchOrder_title') }}
            </h4>
            <div class="w-fit max-w-4xl bg-white rounded-xl text-center border border-zinc-200 shadow-sm p-4 mb-8">
                <p class="text-zinc-600">
                    {{ __('hyper.searchOrder_query_tips') }}
                </p>
            </div>
        </div>
    </div>
</div>

<div class="mb-2 flex flex-row items-center justify-center gap-2 sm:gap-4 bg-white rounded-full border border-zinc-200 shadow-sm w-fit mx-auto p-1">
    <a href="#order-number" class="custom-tab px-2 sm:px-4 py-1 sm:py-2 rounded-full bg-white text-zinc-800 hover:text-zinc-900 active transition-all duration-300" data-bs-toggle="tab" aria-expanded="false" role="tab" data-toggle="tab">
        {{ __('hyper.searchOrder_order_search_by_number') }}
    </a>
    <a href="#email" class="custom-tab px-2 sm:px-4 py-1 sm:py-2 rounded-full bg-white text-zinc-800 hover:text-zinc-900 transition-all duration-300" data-bs-toggle="tab" aria-expanded="false" role="tab" data-toggle="tab">
        {{ __('hyper.searchOrder_order_search_by_email') }}
    </a>
    <a href="#browser" class="custom-tab px-2 sm:px-4 py-1 sm:py-2 rounded-full bg-white text-zinc-800 hover:text-zinc-900 transition-all duration-300" data-bs-toggle="tab" aria-expanded="false" role="tab" data-toggle="tab">
        {{ __('hyper.searchOrder_order_search_by_ie') }}
    </a>
</div>

<div class="tab-content p-2 sm:p-4">
    {{-- Order Number Search Form --}}
    <div class="tab-pane active" id="order-number">
        <div class="bg-white rounded-xl shadow-sm border border-zinc-200 p-6 max-w-2xl mx-auto">
            <form action="{{ url('search-order-by-sn') }}" method="post" class="flex flex-col gap-6">
                {{ csrf_field() }}
                <div class="mt-0">
                    <div class="buy-title mb-2">{{ __('hyper.searchOrder_order_number') }}</div>
                    <input type="text" name="order_sn" required 
                        class="w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500"
                        placeholder="{{ __('hyper.searchOrder_input_order_number') }}">
                </div>
                <div class="flex gap-4">
                    <button type="submit" class="flex-1 px-6 py-3 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors flex items-center justify-center gap-2 text-base font-medium">
                        {{ __('hyper.searchOrder_search_now') }}
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-arrow-right"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="m12 16 4-4-4-4"/></svg>
                    </button>
                    <button type="reset" class="flex-1 px-6 py-3 bg-white border border-zinc-300 text-zinc-700 rounded-full hover:!bg-zinc-50 transition-colors flex items-center justify-center gap-2 text-base font-medium hover:text-zinc-800 ">
                        {{ __('hyper.searchOrder_reset_order') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Email Search Form --}}
    <div class="tab-pane hidden" id="email">
        <div class="bg-white rounded-xl border border-zinc-200 shadow-sm p-6 max-w-2xl mx-auto">
            <form action="{{ url('search-order-by-email') }}" method="post" class="flex flex-col gap-6">
                {{ csrf_field() }}
                <div>
                    <div class="buy-title mb-2">{{ __('hyper.searchOrder_email') }}</div>
                    <input type="email" name="email" required 
                        class="w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500"
                        placeholder="{{ __('hyper.searchOrder_input_email') }}">
                </div>
                @if(dujiaoka_config_get('is_open_search_pwd', \App\Models\BaseModel::STATUS_CLOSE) == \App\Models\BaseModel::STATUS_OPEN)
                <div>
                    <div class="buy-title mb-2">{{ __('hyper.searchOrder_search_password') }}</div>
                    <input type="password" name="search_pwd" required 
                        class="w-full px-3 py-2 bg-white border border-zinc-300 rounded-md text-sm focus:outline-none focus:border-zinc-500 focus:ring-1 focus:ring-zinc-500"
                        placeholder="{{ __('hyper.searchOrder_input_query_password') }}">
                </div>
                @endif
                <div class="flex gap-4">
                    <button type="submit" class="flex-1 px-6 py-3 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors flex items-center justify-center gap-2 text-base font-medium">
                        {{ __('hyper.searchOrder_search_now') }}
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-arrow-right"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="m12 16 4-4-4-4"/></svg>
                    </button>
                    <button type="reset" class="flex-1 px-6 py-3 bg-white border border-zinc-300 text-zinc-700 rounded-full hover:!bg-zinc-50 transition-colors flex items-center justify-center gap-2 text-base font-medium hover:text-zinc-800 ">
                        {{ __('hyper.searchOrder_reset_order') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Browser Search Form --}}
    <div class="tab-pane hidden" id="browser">
        <div class="bg-white rounded-xl shadow-sm border border-zinc-200 p-6 max-w-2xl mx-auto">
            <form action="{{ url('search-order-by-browser') }}" method="post">
                {{ csrf_field() }}
                <button type="submit" class="w-full px-6 py-3 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors flex items-center justify-center gap-2 text-base font-medium">
                    {{ __('hyper.searchOrder_search_now') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-circle-arrow-right"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="m12 16 4-4-4-4"/></svg>
                </button>
            </form>
        </div>
    </div>
</div>

<style>
.custom-tab.active {
    border-color: #000 !important;
    background-color: #18181b !important;
    color: #fff !important;
}
</style>

@section('js')
<script>
    // Updated tab switching code to match your home page
    $('.custom-tab').on('click', function(e) {
        e.preventDefault();
        // Remove active class from all tabs and panes
        $('.custom-tab').removeClass('active');
        $('.tab-pane').removeClass('active').addClass('hidden');
        
        // Add active class to clicked tab
        $(this).addClass('active');
        
        // Show corresponding tab content
        const target = $(this).attr('href');
        $(target).addClass('active').removeClass('hidden');
    });
</script>
@endsection
@stop
