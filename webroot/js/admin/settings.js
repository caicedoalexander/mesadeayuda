document.addEventListener('DOMContentLoaded', function () {
    function bindToggleReveal(checkboxId, fieldsId) {
        const checkbox = document.getElementById(checkboxId);
        const fields = document.getElementById(fieldsId);
        if (!checkbox || !fields) return;
        checkbox.addEventListener('change', function () {
            fields.hidden = !this.checked;
        });
    }

    bindToggleReveal('whatsapp_enabled', 'whatsapp-config-fields');
    bindToggleReveal('n8n_enabled', 'n8n-config-fields');

    function bindConnectionTest(buttonId) {
        const btn = document.getElementById(buttonId);
        if (!btn) {
            return;
        }
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Probando...';
            btn.classList.add('disabled');

            fetch(btn.href)
                .then(response => response.json())
                .then(data => {
                    alert((data.success ? '✅ ' : '❌ ') + data.message);
                })
                .catch(error => {
                    alert('❌ Error al probar la conexión: ' + error.message);
                })
                .finally(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('disabled');
                });
        });
    }

    bindConnectionTest('test-whatsapp-btn');
    bindConnectionTest('test-n8n-btn');

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) {
                return;
            }
            const buttonText = submitBtn.textContent.trim();
            let message = 'Guardando configuración...';
            if (buttonText.includes('Autorizar')) {
                message = 'Autorizando con Google...';
            } else if (buttonText.includes('Usuario')) {
                message = 'Guardando usuario...';
            } else if (buttonText.includes('Etiqueta')) {
                message = 'Guardando etiqueta...';
            } else if (buttonText.includes('Plantilla')) {
                message = 'Guardando plantilla...';
            }

            if (typeof LoadingSpinner !== 'undefined') {
                LoadingSpinner.show(message);
            }
        });
    });
});
