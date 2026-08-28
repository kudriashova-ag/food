/**
 * Повідомлення після звичайного переходу (оформлення, скасування, вихід)
 * показуємо тим самим спливаючим вікном, що й додавання в кошик.
 *
 * Розмітку віддає сервер прихованою — так повідомлення лишається в HTML
 * для читачів екрана й не блимає, поки вантажиться JS.
 */

import toast from './toast';

export default function initFlashToasts(root = document) {
    root.querySelectorAll('[data-flash]').forEach((node) => {
        const text = node.querySelector('span')?.textContent.trim() ?? '';

        toast(text, node.dataset.flash === 'error' ? 'error' : 'success');
        node.remove();
    });
}
