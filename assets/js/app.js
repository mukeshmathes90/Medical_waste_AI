/* Global UI helpers */
(function () {
    'use strict';

    // Confirm destructive actions: any element with data-confirm.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.matches && form.matches('[data-confirm]')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        }
    });

    // Auto-dismiss success alerts after a few seconds.
    document.querySelectorAll('.alert-dismissible').forEach(function (el) {
        if (el.classList.contains('alert-success')) {
            setTimeout(function () {
                if (window.bootstrap) {
                    var a = bootstrap.Alert.getOrCreateInstance(el);
                    a.close();
                }
            }, 6000);
        }
    });

    // Client-side table filter: <input data-table-filter="#tableId" />
    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        var table = document.querySelector(input.getAttribute('data-table-filter'));
        if (!table) return;
        input.addEventListener('input', function () {
            var term = input.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.style.display = tr.textContent.toLowerCase().indexOf(term) > -1 ? '' : 'none';
            });
        });
    });

    // Copy-to-clipboard buttons: <button data-copy="text">
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
                var old = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check2"></i>';
                setTimeout(function () { btn.innerHTML = old; }, 1500);
            });
        });
    });
})();
