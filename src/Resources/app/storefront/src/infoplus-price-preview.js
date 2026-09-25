document.addEventListener('DOMContentLoaded', function () {
    function parseNumber(val) {
        if (val === null || val === undefined) return 0;
        if (typeof val === 'number') return val;
        var s = String(val).trim();
        if (!s) return 0;
        s = s.replace(',', '.');
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }

    function formatMoney(amount, currency) {
        try {
            var lang = document.documentElement.getAttribute('lang') || 'en-US';
            var cur = currency || 'USD';
            return new Intl.NumberFormat(lang, { style: 'currency', currency: cur }).format(amount);
        } catch {
            return '$' + (Number(amount) || 0).toFixed(2);
        }
    }

    function findQuantityInput(container) {
        var root = container || document;
        var q = root.querySelector('input[name$="[quantity]"]');
        if (q) return q;
        q = root.querySelector('input[name="quantity"]');
        return q;
    }

    function findFieldLabel(el) {
        var wrapper = el.closest('.infoplus-customfield');
        if (!wrapper) return '';
        var lbl = wrapper.querySelector('label');
        return lbl ? lbl.textContent.trim() : '';
    }

    function cleanOptionText(opt) {
        if (!opt) return '';
        var t = opt.textContent || '';
        // remove appended price " (+$10.00)"
        return t.replace(/\s*\(\+\$[^)]+\)\s*$/, '').trim();
    }

    function init() {
        var form = document.querySelector('form[action*="checkout/line-item/add"], form[action*="/checkout/cart"], form[action*="/cart/line-item/add"]');
        var cfg = document.getElementById('infoplus-customfields-form');
        var preview = document.getElementById('infoplus-price-preview');
        if (!cfg || !preview) return;
        var baseUnit = parseNumber(preview.getAttribute('data-base-unit-price'));
        var productName = preview.getAttribute('data-product-name') || 'Product';
        var currency = preview.getAttribute('data-currency') || 'USD';
        var qtyInput = findQuantityInput(form) || findQuantityInput(null);
        var buyBtn = form ? form.querySelector('.btn-buy') : null;
        var lastHtml = null;
        var lastBtn = null;

        function calcBreakdown() {
            var lines = [];
            lines.push({ label: productName, amount: Math.max(0, baseUnit) });

            var inputs = cfg.querySelectorAll('input, select, textarea');
            inputs.forEach(function (el) {
                var name = (el.getAttribute('name') || '').toLowerCase();
                var type = (el.getAttribute('data-type') || '').toLowerCase();
                if (name.indexOf('infoplus_') !== 0) return;

                var fieldLabel = findFieldLabel(el);

                if (type === 'money' || (type === '' && name.indexOf('price') >= 0)) {
                    if (el.type !== 'checkbox') {
                        var moneyVal = Math.max(0, parseNumber(el.value));
                        if (moneyVal) lines.push({ label: fieldLabel, amount: moneyVal });
                    }
                    return;
                }

                if (type === 'number') {
                    var nsp = Math.max(0, parseNumber(el.getAttribute('data-static-price')));
                    var hasVal = (String(el.value).trim() !== '');
                    if (nsp && hasVal) {
                        lines.push({ label: fieldLabel, amount: nsp });
                    }
                    return;
                }

                if (type === 'boolean') {
                    var bsp = Math.max(0, parseNumber(el.getAttribute('data-static-price')));
                    if (bsp && el.checked) {
                        lines.push({ label: fieldLabel, amount: bsp });
                    }
                    return;
                }

                if (type === 'select') {
                    var selected = el.options[el.selectedIndex];
                    if (selected && selected.value) {
                        var attr = selected.getAttribute('data-price');
                        var addAmount;
                        if (attr !== null && attr !== undefined && String(attr).trim() !== '') {
                            addAmount = Math.max(0, parseNumber(attr));
                        } else {
                            addAmount = Math.max(0, parseNumber(el.getAttribute('data-static-price')));
                        }
                        if (addAmount) {
                            var optText = cleanOptionText(selected);
                            var label = fieldLabel ? (fieldLabel + ' - ' + optText) : optText;
                            lines.push({ label: label, amount: addAmount });
                        }
                    }
                    return;
                }

                var staticPrice = Math.max(0, parseNumber(el.getAttribute('data-static-price')));
                if (staticPrice && String(el.value).trim() !== '') {
                    lines.push({ label: fieldLabel, amount: staticPrice });
                }
            });
            return lines;
        }

        function updateBuyButton(total) {
            if (!buyBtn) return;
            var baseLabel = buyBtn.getAttribute('data-base-label');
            if (!baseLabel) {
                baseLabel = (buyBtn.textContent || '').trim();
                buyBtn.setAttribute('data-base-label', baseLabel);
            }
            buyBtn.textContent = baseLabel + ' - ' + formatMoney(total, currency);
        }

        function render() {
            var quantity = parseInt((qtyInput && qtyInput.value) ? qtyInput.value : '1', 10);
            if (!isFinite(quantity) || quantity <= 0) quantity = 1;

            var lines = calcBreakdown();
            var perUnitTotal = lines.reduce(function (acc, l) { return acc + (l.amount || 0); }, 0);
            var total = perUnitTotal * quantity;

            var html = '<div class="infoplus-breakdown">';
            lines.forEach(function (l) {
                html += '<div class="infoplus-line" style="display:flex;justify-content:space-between;"><span>' + l.label + '</span><span>' + formatMoney(l.amount, currency) + '</span></div>';
            });
            html += '<div class="infoplus-subtotal" style="margin-top:6px;display:flex;justify-content:space-between;font-weight:700;"><span>Subtotal</span><span>'  + formatMoney(perUnitTotal, currency) + ' x ' + quantity + ' = ' + formatMoney(total, currency) +'</span></div>';
            html += '</div>';

            if (lastHtml !== html) {
                lastHtml = html;
                preview.innerHTML = html;
            }

            updateBuyButton(total);
        }

        cfg.addEventListener('input', render);
        cfg.addEventListener('change', render);
        if (qtyInput) {
            qtyInput.addEventListener('input', render);
            qtyInput.addEventListener('change', render);
        }
        if (form) {
            form.addEventListener('input', function (e) {
                if (qtyInput && e.target === qtyInput) render();
            });
        }

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
});
