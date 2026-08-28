/**
 * Випадні меню в шапці зроблені на <details>. Браузер сам їх відкриває,
 * але не закриває кліком повз — доробляємо це тут.
 */

export default function initHeaderMenus(root = document) {
    const menus = () => Array.from(root.querySelectorAll('[data-header-menu]'));

    document.addEventListener('click', (event) => {
        menus().forEach((menu) => {
            if (menu.open && !menu.contains(event.target)) {
                menu.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            menus().forEach((menu) => menu.removeAttribute('open'));
        }
    });

    // Два відкритих меню поруч виглядають як помилка — лишаємо одне.
    menus().forEach((menu) => {
        menu.addEventListener('toggle', () => {
            if (!menu.open) {
                return;
            }

            menus().forEach((other) => {
                if (other !== menu) {
                    other.removeAttribute('open');
                }
            });
        });
    });
}
