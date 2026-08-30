/**
 * Клік по зображенню страви (у комплексі, групі вибору чи додатково) відкриває
 * спільну модалку з фото, назвою, описом і алергенами. Дані читаються з
 * data-dish-* атрибутів натиснутого елемента — окремого запиту не потрібно.
 */

export default function initDishModal(root = document) {
    const dialog = root.querySelector('[data-dish-modal]');

    if (dialog === null) {
        return;
    }

    const image = dialog.querySelector('[data-dish-modal-image]');
    const name = dialog.querySelector('[data-dish-modal-name]');
    const description = dialog.querySelector('[data-dish-modal-description]');
    const allergens = dialog.querySelector('[data-dish-modal-allergens]');

    root.querySelectorAll('[data-dish-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            image.src = trigger.dataset.dishImage ?? '';
            image.alt = trigger.dataset.dishName ?? '';
            name.textContent = trigger.dataset.dishName ?? '';

            const desc = trigger.dataset.dishDescription ?? '';
            description.textContent = desc;
            description.classList.toggle('hidden', desc === '');

            allergens.innerHTML = '';
            const allergenNames = (trigger.dataset.dishAllergens ?? '')
                .split('|')
                .map((a) => a.trim())
                .filter((a) => a !== '');

            allergenNames.forEach((allergenName) => {
                const badge = document.createElement('span');
                badge.className = 'badge bg-amber-50 text-amber-800';
                badge.textContent = allergenName;
                allergens.appendChild(badge);
            });

            dialog.showModal();
        });
    });

    dialog.querySelector('[data-dish-modal-close]')?.addEventListener('click', () => {
        dialog.close();
    });

    // Клік по бекдропу (сам <dialog>, не його вміст) теж закриває.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
}
