document.addEventListener('DOMContentLoaded', function () {
    const colorPicker = document.getElementById('tag-color');
    const colorHex = document.getElementById('color-hex');
    const previewWrapper = document.getElementById('preview-wrapper');
    const previewText = document.getElementById('preview-text');
    const nameInput = document.querySelector('input[name="name"]');

    if (!colorPicker || !previewWrapper || !nameInput) {
        return;
    }

    function applyColor(value) {
        previewWrapper.style.setProperty('--swatch', value);
        if (colorHex) {
            colorHex.value = value.toUpperCase();
        }
    }

    colorPicker.addEventListener('input', function () {
        applyColor(this.value);
    });

    nameInput.addEventListener('input', function () {
        if (previewText) {
            previewText.textContent = this.value || 'Nombre de etiqueta';
        }
    });

    applyColor(colorPicker.value);
});
