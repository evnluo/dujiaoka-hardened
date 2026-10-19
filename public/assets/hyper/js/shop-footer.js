(() => {
    const button = document.querySelector('[data-shop-back-to-top]');
    if (!button) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    });
})();
