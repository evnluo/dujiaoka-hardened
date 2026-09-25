@extends('hyper.layouts.default')

@section('content')
<div class="min-h-[60vh] flex items-center justify-center p-4">
    <div class="w-full max-w-lg">
        {{-- Error Card --}}
        <div class="bg-white rounded-xl shadow-sm border border-zinc-200 overflow-hidden">
            {{-- Error Header --}}
            <div class="p-6 text-center border-b border-zinc-200">
                <div class="w-16 h-16 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-red-600">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                </div>
                <h4 class="text-xl font-bold text-zinc-900 mb-2">
                    {{ __('hyper.error_error') }}
                </h4>
                <p class="text-red-600 font-medium">
                    {{ $content }}
                </p>
            </div>

            {{-- Action Button --}}
            <div class="p-6 bg-zinc-50 flex items-center justify-center">
                @if(!$url)
                    <a href="javascript:history.back(-1);" 
                       class="inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m12 19-7-7 7-7"/>
                            <path d="M19 12H5"/>
                        </svg>
                        {{ __('hyper.error_back_btn') }}
                    </a>
                @else
                    <a href="{{ $url }}" 
                       class="inline-flex items-center justify-center gap-2 px-6 py-2.5 bg-zinc-800 text-white rounded-full hover:bg-zinc-900 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m12 19-7-7 7-7"/>
                            <path d="M19 12H5"/>
                        </svg>
                        {{ __('hyper.error_back_btn') }}
                    </a>
                @endif
            </div>
        </div>

        {{-- Support Link --}}
        <div class="mt-6 text-center">
            <a href="https://me.ohevan.com" 
               target="_blank"
               class="inline-flex items-center justify-center gap-2 text-sm text-zinc-500 hover:text-zinc-700">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                    <path d="M12 17h.01"/>
                </svg>
                需要帮助？联系我
            </a>
        </div>
    </div>
</div>
@stop