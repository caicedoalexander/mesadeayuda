/**
 * Marquee Text Animation
 * Sistema de Soporte
 *
 * Maneja animaciones de texto tipo marquesina para títulos largos
 */

(function(window) {
    'use strict';

    const MarqueeText = {
        /**
         * Inicializar marquee en elementos específicos
         * @param {string} containerSelector - Selector del contenedor
         * @param {string} textSelector - Selector del texto a animar
         * @param {Object} options - Opciones de configuración
         */
        init: function(containerSelector, textSelector, options) {
            const defaults = {
                speed: 50, // pixels por segundo
                minDuration: 8, // duración mínima en segundos
                hoverDelay: 0, // delay antes de iniciar (en ms)
                resetOnLeave: true // resetear posición al quitar hover
            };

            const settings = { ...defaults, ...options };
            const container = document.querySelector(containerSelector);
            const text = document.querySelector(textSelector);

            if (!container || !text) {
                return;
            }

            // MEMORY LEAK FIX: Prevent double initialization
            if (container.dataset.marqueeInitialized === 'true') {
                return;
            }
            container.dataset.marqueeInitialized = 'true';

            // Store timeout reference for cleanup
            let initTimeout;
            let hoverTimeout;

            // Solo activar si el texto es más largo que el contenedor
            initTimeout = setTimeout(() => {
                const textWidth = text.scrollWidth;
                const containerWidth = container.clientWidth;

                if (textWidth > containerWidth) {
                    // Calcular duración basada en la longitud del texto
                    const duration = Math.max(settings.minDuration, textWidth / settings.speed);
                    text.style.animationDuration = duration + 's';

                    // Agregar clase marquee
                    text.classList.add('marquee-text');

                    // Evento mouseenter
                    container.addEventListener('mouseenter', function() {
                        if (settings.hoverDelay > 0) {
                            hoverTimeout = setTimeout(function() {
                                text.classList.add('marquee-active');
                            }, settings.hoverDelay);
                        } else {
                            text.classList.add('marquee-active');
                        }
                    });

                    // Evento mouseleave
                    container.addEventListener('mouseleave', function() {
                        if (hoverTimeout) {
                            clearTimeout(hoverTimeout);
                            hoverTimeout = null;
                        }

                        text.classList.remove('marquee-active');

                        if (settings.resetOnLeave) {
                            // Resetear animación
                            text.style.animation = 'none';
                            setTimeout(() => {
                                text.style.animation = '';
                            }, 10);
                        }
                    });
                } else {
                    // Si el texto cabe, no aplicar marquee
                    text.style.animation = 'none';
                }
            }, 100); // Pequeño delay para asegurar que el DOM está renderizado

            // Return cleanup function (optional - for manual cleanup if needed)
            return function cleanup() {
                if (initTimeout) clearTimeout(initTimeout);
                if (hoverTimeout) clearTimeout(hoverTimeout);
            };
        },

        /**
         * Inicializar múltiples marquees
         * @param {Array} elements - Array de objetos con containerSelector y textSelector
         */
        initMultiple: function(elements) {
            elements.forEach(element => {
                this.init(element.container, element.text, element.options || {});
            });
        }
    };

    // Exportar al objeto window
    window.MarqueeText = MarqueeText;

})(window);
