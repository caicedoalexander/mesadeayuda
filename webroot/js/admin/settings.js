document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('whatsapp_enabled')?.addEventListener('change', function () {
        const fields = document.getElementById('whatsapp-config-fields');
        if (fields) {
            fields.style.display = this.checked ? 'block' : 'none';
        }
    });

    document.getElementById('n8n_enabled')?.addEventListener('change', function () {
        const fields = document.getElementById('n8n-config-fields');
        if (fields) {
            fields.style.display = this.checked ? 'block' : 'none';
        }
    });

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
