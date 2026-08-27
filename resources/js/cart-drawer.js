/**
 * Кошик-шухляда: виїжджає згори по натисканню на плашку в шапці.
 *
 * Вміст рендериться сервером — тут лише показ і приховування,
 * щоб сторінка лишалася робочою навіть якщо JS не завантажився.
 */
export default function initCartDrawer() {
    const drawer = document.querySelector('[data-cart-drawer]');
    const overlay = document.querySelector('[data-cart-overlay]');

    if (!drawer || !overlay) {
        return;
    }

    const openers = document.querySelectorAll('[data-cart-open]');
    const closers = document.querySelectorAll('[data-cart-close]');

    const open = () => {
        drawer.classList.remove('-translate-y-full');
        overlay.classList.remove('pointer-events-none', 'opacity-0');
        document.body.classList.add('overflow-hidden');
    };

    const close = () => {
        drawer.classList.add('-translate-y-full');
        overlay.classList.add('pointer-events-none', 'opacity-0');
        document.body.classList.remove('overflow-hidden');
    };

    openers.forEach((el) => el.addEventListener('click', (event) => {
        event.preventDefault();
        open();
    }));

    closers.forEach((el) => el.addEventListener('click', close));
    overlay.addEventListener('click', close);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });
}
