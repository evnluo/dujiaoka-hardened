(() => {
    const categories = document.querySelector('[data-shop-categories]');
    const products = document.getElementById('shop-products');
    if (!categories || !products) return;

    const buttons = Array.from(categories.querySelectorAll('button[data-category]'));
    const cards = Array.from(products.querySelectorAll('[data-product-group]'));

    function revealButton(button) {
        const strip = categories.getBoundingClientRect();
        const bounds = button.getBoundingClientRect();
        // Only scroll this strip, never the page. Synchronous, with no queued animation.
        if (bounds.left < strip.left + 4) categories.scrollLeft += bounds.left - strip.left - 4;
        else if (bounds.right > strip.right - 4) categories.scrollLeft += bounds.right - strip.right + 4;
    }

    function select(button) {
        const category = button.dataset.category;
        buttons.forEach(candidate => candidate.setAttribute('aria-pressed', String(candidate === button)));
        cards.forEach(card => {
            card.hidden = category !== 'all' && card.dataset.productGroup !== category;
        });
        revealButton(button);
    }

    buttons.forEach((button, index) => {
        button.addEventListener('click', () => select(button));
        button.addEventListener('focus', () => revealButton(button));
        button.addEventListener('keydown', event => {
            let next;
            switch (event.key) {
                case 'ArrowRight': next = (index + 1) % buttons.length; break;
                case 'ArrowLeft': next = (index - 1 + buttons.length) % buttons.length; break;
                case 'Home': next = 0; break;
                case 'End': next = buttons.length - 1; break;
                default: return; // Native Enter/Space and Tab behavior stays intact.
            }
            event.preventDefault();
            buttons[next].focus({ preventScroll: true });
            select(buttons[next]);
        });
    });
    categories.hidden = false;

    products.querySelectorAll('img[data-fallback-src]').forEach(image => {
        function fallback() {
            const source = image.dataset.fallbackSrc;
            if (!source) return;
            delete image.dataset.fallbackSrc;
            image.src = source; // One attempt only, including cached failures before script startup.
        }
        image.addEventListener('error', fallback);
        if (image.complete && image.naturalWidth === 0) fallback();
    });
})();
