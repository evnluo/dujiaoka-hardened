<div class="w-full py-4 px-2 sm:px-4">
    <div class="container flex flex-row justify-between items-center">
        <!-- LOGO -->
        <a href="/" class="flex flex-row items-center gap-2">
            <img src="{{ picture_url(dujiaoka_config_get('img_logo')) }}" alt="Logo" class="h-8 md:h-12 rounded-md">
            <div class="logo-title text-base md:text-xl">{{ dujiaoka_config_get('text_logo') }}</div>
        </a>

        <!-- Order Search Button - Hidden on mobile, visible on larger screens -->
        <a class="hidden md:flex border border-zinc-300 bg-white text-zinc-800 hover:text-zinc-900 px-4 py-2 rounded-full items-center gap-2 hover:!bg-zinc-100 transition-all duration-300" href="{{ url('order-search') }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-text-search"><path d="M21 6H3"/><path d="M10 12H3"/><path d="M10 18H3"/><circle cx="17" cy="15" r="3"/><path d="m21 19-1.9-1.9"/></svg>
            <span>查询订单</span>
        </a>

        <!-- Mobile Order Search Button -->
        <a class="md:hidden flex gap-1 px-3 py-2 border border-zinc-300 bg-white text-zinc-800 hover:text-zinc-900 p-2 rounded-full items-center hover:bg-zinc-300 transition-all duration-300" href="{{ url('order-search') }}">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-text-search"><path d="M21 6H3"/><path d="M10 12H3"/><path d="M10 18H3"/><circle cx="17" cy="15" r="3"/><path d="m21 19-1.9-1.9"/></svg>
            <span>查询订单</span>
        </a>
    </div>
</div>