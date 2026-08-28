/**
 * Додавання дня в кошик без перезавантаження сторінки: сторінка меню довга,
 * і після кожного дня повертати людину нагору — зайве.
 *
 * Форма лишається звичайною: без JS браузер надішле її сам і отримає редирект.
 */

import toast from './toast';

const money = new Intl.NumberFormat('uk-UA', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/** Кошик у шапці й нижня панель показують ту саму суму — оновлюємо разом. */
function refreshCart({ count, total }) {
    document.querySelectorAll('[data-cart-count]').forEach((node) => {
        node.textContent = count;
    });

    document.querySelectorAll('[data-cart-total]').forEach((node) => {
        node.textContent = money.format(total);
    });

    document.querySelectorAll('[data-cart-empty]').forEach((node) => {
        node.hidden = count > 0;
    });

    document.querySelectorAll('[data-cart-filled]').forEach((node) => {
        node.hidden = count === 0;
    });

    // Наповнений кошик у шапці підсвічений лаймом, порожній — просто контур.
    const link = document.querySelector('[data-cart-link]');

    if (link !== null) {
        link.classList.toggle('bg-brand-500', count > 0);
        link.classList.toggle('text-deep-900', count > 0);
        link.classList.toggle('hover:bg-brand-400', count > 0);
        link.classList.toggle('border', count === 0);
        link.classList.toggle('border-ink-300', count === 0);
        link.classList.toggle('text-ink-600', count === 0);
        link.classList.toggle('hover:bg-ink-100', count === 0);
    }
}

async function submit(form) {
    const button = form.querySelector('button[type="submit"]');

    button?.setAttribute('disabled', '');

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        // Сесія могла протухнути, поки сторінка була відкрита: 419 лікується
        // тільки перезавантаженням, самотужки токен не оновити.
        if (response.status === 419) {
            window.location.reload();

            return;
        }

        const data = await response.json();

        toast(data.message, data.ok ? 'success' : 'error');

        if (data.cart) {
            refreshCart(data.cart);
        }
    } catch {
        toast('Не вдалося зв’язатися із сервером. Спробуйте ще раз.', 'error');
    } finally {
        button?.removeAttribute('disabled');
    }
}

export default function initAddToCart(root = document) {
    root.querySelectorAll('[data-day-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            submit(form);
        });
    });
}
