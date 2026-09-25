<!-- header start -->
<header class="sticky top-0 bg-white shadow-sm border border-zinc-200 z-50">
    <div class="container mx-auto px-4 py-2">
        <div class="flex flex-row items-center justify-between">
            <!-- Logo Section -->
            <div class="flex justify-center md:justify-start">
                <div class="flex items-center gap-4">
                    <a href="/">
                        <img src="{{ picture_url(dujiaoka_config_get('img_logo')) }}" alt="Logo" class="h-12">
                    </a>
                    <!-- Brand/Text Logo -->
                    <a class="text-xl font-semibold text-gray-800" href="/">{{ dujiaoka_config_get('text_logo') }}</a>
                </div>
            </div>
            
            <!-- Navigation Section -->
            <div class="">
                <nav class="flex items-center justify-between">
                    <!-- Mobile Menu Button -->
                    <button class="md:hidden flex items-center px-3 py-2 border rounded text-gray-700 border-gray-700 hover:text-blue-500 hover:border-blue-500 focus:outline-none" type="button" aria-controls="navbarColor" aria-expanded="false" aria-label="Toggle navigation">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    
                    <!-- Navigation Links & Search -->
                    <div class="hidden md:flex md:items-center md:gap-6" id="navbarColor">
                        <ul class="flex flex-col md:flex-row md:gap-4">
                            <li>
                                <a class="text-gray-700 hover:text-blue-500 {{ \Illuminate\Support\Facades\Request::path() == '/' ? 'font-bold text-blue-500' : '' }}" href="/">
                                    {{ __('dujiaoka.home_page') }}
                                </a>
                            </li>
                            <li>
                                <a class="text-gray-700 hover:text-blue-500 {{ \Illuminate\Support\Facades\Request::url() == url('order-search') ? 'font-bold text-blue-500' : '' }}" href="{{ url('order-search') }}">
                                    {{ __('dujiaoka.order_search') }}
                                </a>
                            </li>
                        </ul>
                        
                        @if(\Illuminate\Support\Facades\Request::path() == '/')
                            <form class="flex mt-4 md:mt-0">
                                <input class="w-full px-3 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-blue-500" id="searchText" type="text" placeholder="{{ __('dujiaoka.search_goods_name') }}">
                                <button class="px-4 py-2 bg-gray-700 text-white rounded-r-md hover:bg-gray-800 focus:outline-none" type="button" id="searchBtn">
                                    <i class="ali-icon">&#xe65c;</i>
                                </button>
                            </form>
                        @endif
                    </div>
                </nav>
            </div>
        </div>
    </div>
</header>
<!-- header end -->
