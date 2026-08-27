/**
 * Сума за день у меню оновлюється просто в браузері, без запиту на сервер:
 * ціна кожної страви лежить у data-price, кількість — у значенні select'а,
 * а для групи вибору береться окреме поле «Порцій».
 *
 * Сервер малює стартову суму сам, тож без JS сторінка теж лишається коректною.
 */

const money = new Intl.NumberFormat('uk-UA', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function portions(form, sectionId) {
    const field = form.querySelector(`[data-choice-qty="${sectionId}"]`);

    return Number.parseInt(field?.value ?? '1', 10) || 0;
}

function dayTotal(form) {
    let total = 0;

    form.querySelectorAll('[data-price]').forEach((field) => {
        const price = Number.parseFloat(field.dataset.price) || 0;

        if (field.type === 'radio') {
            if (field.checked) {
                total += price * portions(form, field.dataset.section);
            }

            return;
        }

        total += price * (Number.parseInt(field.value, 10) || 0);
    });

    return total;
}

function refresh(form) {
    const output = form.querySelector('[data-day-total]');

    if (output !== null) {
        output.textContent = `${money.format(dayTotal(form))} грн`;
    }
}

export default function initDayTotals(root = document) {
    root.querySelectorAll('[data-day-form]').forEach((form) => {
        form.addEventListener('change', () => refresh(form));

        refresh(form);
    });
}
