/**
 * Flash Messages Auto-Hide
 * Sistema de Soporte
 */

(function() {
    'use strict';

    // Configuración
    const AUTO_HIDE_DELAY = 3000; // 3 segundos
    const ANIMATION_DURATION = 300; // 0.3 segundos

    /**
     * Inicializar auto-ocultamiento de flash messages
     */
    function initFlashMessages() {
        const flashMessages = document.querySelectorAll('.flash-message');

        flashMessages.forEach(function(message) {
            // Auto-ocultar después del delay
            setTimeout(function() {
                hideFlashMessage(message);
            }, AUTO_HIDE_DELAY);

            // También permitir cerrar manualmente con el botón close
            const closeButton = message.querySelector('.app-toast-close, .btn-close');
            if (closeButton) {
                closeButton.addEventListener('click', function() {
                    hideFlashMessage(message);
                });
            }
        });
    }

    /**
     * Ocultar un flash message con animación
     */
    function hideFlashMessage(message) {
        // Agregar clase de animación
        message.classList.add('hiding');

        // Remover del DOM después de la animación
        setTimeout(function() {
            message.remove();
        }, ANIMATION_DURATION);
    }

    // Inicializar cuando el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFlashMessages);
    } else {
        initFlashMessages();
    }

    // También inicializar para contenido dinámico
    window.initFlashMessages = initFlashMessages;

})();
