document.addEventListener('DOMContentLoaded', function () {
    var addToCartForm = document.querySelector('form[action*="checkout/line-item/add"], form[action*="/checkout/cart"], form[action*="/cart/line-item/add"]');
    var infoplusForm = document.getElementById('infoplus-customfields-form');
    if (!addToCartForm || !infoplusForm) return;

    infoplusForm.addEventListener('input', function (e) {
        var t = e.target;
        if (!t || !t.getAttribute) return;
        var dtype = (t.getAttribute('data-type') || '').toLowerCase();
        if (dtype === 'money') {
            var val = (t.value || '').toString().replace(',', '.');
            var num = parseFloat(val);
            if (!isFinite(num) || num < 0) num = 0;
            t.value = String(num);
        }
    });

    addToCartForm.addEventListener('submit', function () {
        var inputs = infoplusForm.querySelectorAll('input, select, textarea');
        inputs.forEach(function (input) {
            var name = input.name;
            if (!name) return;
            var value;
            if (input.type === 'checkbox') {
                value = input.checked ? '1' : '0';
            } else {
                value = input.value;
            }
            var dtype = (input.getAttribute('data-type') || '').toLowerCase();
            if (dtype === 'money') {
                var num = parseFloat((value || '').toString().replace(',', '.'));
                if (!isFinite(num) || num < 0) num = 0;
                value = String(num);
            }
            var hidden = addToCartForm.querySelector('input[type="hidden"][name="' + name + '"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                addToCartForm.appendChild(hidden);
            }
            hidden.value = value;
        });
    });
});
