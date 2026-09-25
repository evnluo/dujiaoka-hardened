<!-- Footer Start -->
<footer class="bg-white border-t mt-auto flex flex-col items-center">
    <div class="container mx-auto px-4 sm:px-6 py-8">
        <div class="flex flex-col md:flex-row md:justify-between">
        <div class="hidden md:block">
                <div class="text-gray-600 text-sm text-left md:text-left">
                    {!! dujiaoka_config_get('footer') !!}
                </div>
            </div>
            <div class="mb-4 md:mb-0">  
                <div class="text-gray-600 text-sm text-right">
                    Powered by Dujiaoka. Modified by Evan.
                    <div class="text-zinc-500">
                        <a class="underline underline-offset-2 hover:underline text-zinc-800 hover:text-zinc-900 transition-all duration-300" href="https://evannotfound.com/contact" target="_blank">Contact me</a> if you want to buy this template.
                    </div>
                </div>
            </div>

        </div>
    </div>
    <div class="fixed bottom-8 right-8 opacity-0 pointer-events-none transition-all duration-300" id="back-to-top">
        <button class="bg-zinc-800 hover:bg-zinc-900 text-white p-3 rounded-full shadow-md transition-all duration-200">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14"/><path d="m18 13-6-6-6 6"/><path d="M12 7v14"/></svg>
        </button>
    </div>
</footer>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const backToTop = document.getElementById('back-to-top');
        
        function checkScroll() {
            if (window.pageYOffset > 200) {
                backToTop.style.opacity = '1';
                backToTop.style.pointerEvents = 'auto';
            } else {
                backToTop.style.opacity = '0';
                backToTop.style.pointerEvents = 'none';
            }
        }

        // Initial check
        checkScroll();
        
        // Add scroll event listener
        window.addEventListener('scroll', checkScroll);

        backToTop.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    });
</script>
<!-- end Footer -->
