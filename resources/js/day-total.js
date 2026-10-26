/**
 * Сума за день у меню оновлюється просто в браузері, без запиту на сервер:
 * ціна кожної страви чи комплексу лежить у data-price, а замовляється
 * одна порція — галочкою (комплекс, додаткові) або радіокнопкою (група вибору).
 *
 * Сервер малює стартову суму сам, тож без JS сторінка теж лишається коректною.
 */

const money = new Intl.NumberFormat('uk-UA', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function dayTotal(form) {
    let total = 0;

    form.querySelectorAll('[data-price]').forEach((field) => {
        if (field.checked) {
            total += Number.parseFloat(field.dataset.price) || 0;
        }
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
