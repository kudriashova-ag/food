/**
 * Спливаючі повідомлення: показуємо результат дії там, де людина зараз дивиться,
 * не перемальовуючи сторінку.
 *
 * Тости складаються стовпчиком, зникають самі й прибираються з DOM після анімації.
 */

const VISIBLE_MS = 3500;

function container() {
    let node = document.querySelector('[data-toasts]');

    if (node === null) {
        node = document.createElement('div');
        node.setAttribute('data-toasts', '');
        node.className = 'pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-2 px-4';
        document.body.append(node);
    }

    return node;
}

const icons = {
    success: '<path d="M20 6 9 17l-5-5"/>',
    error: '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
};

export default function toast(message, type = 'success') {
    if (!message) {
        return;
    }

    const node = document.createElement('div');

    node.className = [
        'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border px-4 py-3',
        'text-sm shadow-lg transition duration-300 ease-out',
        '-translate-y-3 opacity-0',
        type === 'error'
            ? 'border-red-200 bg-red-50 text-red-900'
            : 'border-emerald-200 bg-emerald-50 text-emerald-900',
    ].join(' ');

    node.setAttribute('role', 'status');
    node.innerHTML = `
        <svg class="mt-0.5 h-5 w-5 shrink-0 ${type === 'error' ? 'text-red-600' : 'text-emerald-600'}"
             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[type] ?? icons.success}</svg>
        <span></span>
    `;
    node.querySelector('span').textContent = message;

    container().append(node);

    // Наступний кадр — інакше браузер застосує кінцевий стан одразу, без переходу.
    requestAnimationFrame(() => {
        node.classList.remove('-translate-y-3', 'opacity-0');
    });

    setTimeout(() => {
        node.classList.add('-translate-y-3', 'opacity-0');
        node.addEventListener('transitionend', () => node.remove(), { once: true });
    }, VISIBLE_MS);
}
