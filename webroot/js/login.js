document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('form');
    if (!form) {
        return;
    }
    form.addEventListener('submit', function () {
        if (typeof LoadingSpinner !== 'undefined') {
            LoadingSpinner.show('Iniciando sesión...');
        }
    });
});
