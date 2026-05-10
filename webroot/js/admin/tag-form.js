document.addEventListener('DOMContentLoaded', function () {
    const colorPicker = document.getElementById('tag-color');
    const colorHex = document.getElementById('color-hex');
    const previewBadge = document.getElementById('preview-badge');
    const previewText = document.getElementById('preview-text');
    const nameInput = document.querySelector('input[name="name"]');

    if (!colorPicker || !previewBadge || !nameInput) {
        return;
    }

    colorPicker.addEventListener('input', function () {
        const color = this.value;
        if (colorHex) {
            colorHex.value = color.toUpperCase();
        }
        previewBadge.style.backgroundColor = color;
    });

    nameInput.addEventListener('input', function () {
        if (previewText) {
            previewText.textContent = this.value || 'Nombre de etiqueta';
        }
    });
});
