/**
 * Loading Spinner Controller
 * Sistema de Soporte
 *
 * Uso:
 * LoadingSpinner.show('Guardando datos...');
 * LoadingSpinner.hide();
 *
 * Con formularios:
 * LoadingSpinner.showOnSubmit('#myForm', 'Enviando solicitud...');
 */

(function(window) {
    'use strict';

    const LoadingSpinner = {
        spinner: null,
        messageElement: null,
        ANIMATION_DURATION: 200, // ms

        /**
         * Inicializar el spinner (buscar el elemento en el DOM)
         */
        init: function() {
            if (!this.spinner) {
                this.spinner = document.getElementById('loading-spinner');
                if (this.spinner) {
                    this.messageElement = this.spinner.querySelector('.loading-message');
                }
            }
            return !!this.spinner;
        },

        /**
         * Mostrar el spinner
         * @param {string} message - Mensaje opcional a mostrar
         */
        show: function(message) {
            if (!this.init()) {
                return;
            }

            // Actualizar mensaje si se proporciona
            if (message && this.messageElement) {
                this.messageElement.textContent = message;
            }

            // Mostrar spinner
            this.spinner.style.display = 'flex';
            // Forzar reflow para que la animación funcione
            void this.spinner.offsetWidth;
            this.spinner.classList.add('show');

            // Prevenir scroll del body
            document.body.style.overflow = 'hidden';
        },

        /**
         * Ocultar el spinner
         */
        hide: function() {
            if (!this.spinner) {
                return;
            }

            // Animación de salida
            this.spinner.classList.remove('show');
            this.spinner.classList.add('hiding');

            // Ocultar después de la animación
            setTimeout(() => {
                this.spinner.style.display = 'none';
                this.spinner.classList.remove('hiding');
                document.body.style.overflow = '';
            }, this.ANIMATION_DURATION);
        },

        /**
         * Mostrar spinner automáticamente al enviar un formulario
         * @param {string} formSelector - Selector CSS del formulario
         * @param {string} message - Mensaje a mostrar
         */
        showOnSubmit: function(formSelector, message) {
            const form = document.querySelector(formSelector);
            if (!form) {
                return;
            }

            // MEMORY LEAK FIX: Prevent duplicate listeners
            if (form.dataset.spinnerInitialized === 'true') {
                return;
            }
            form.dataset.spinnerInitialized = 'true';

            form.addEventListener('submit', () => {
                // Solo mostrar si el formulario es válido
                if (form.checkValidity()) {
                    this.show(message || 'Enviando...');
                }
            });
        },

        /**
         * Mostrar spinner durante una petición AJAX
         * @param {Promise} promise - Promesa de la petición
         * @param {string} message - Mensaje a mostrar
         * @returns {Promise} - La misma promesa para encadenar
         */
        showDuring: async function(promise, message) {
            this.show(message);

            try {
                const result = await promise;
                this.hide();
                return result;
            } catch (error) {
                this.hide();
                throw error;
            }
        },

        /**
         * Mostrar spinner por un tiempo específico
         * @param {number} duration - Duración en milisegundos
         * @param {string} message - Mensaje a mostrar
         */
        showFor: function(duration, message) {
            this.show(message);
            setTimeout(() => this.hide(), duration);
        }
    };

    // Exportar al objeto window
    window.LoadingSpinner = LoadingSpinner;

    // Auto-inicializar cuando el DOM esté listo
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => LoadingSpinner.init());
    } else {
        LoadingSpinner.init();
    }

    // Protección contra navegación hacia atrás (back button)
    // Ocultar spinner cuando la página se carga desde el cache (bfcache)
    window.addEventListener('pageshow', function(event) {
        // Si la página viene del cache (usuario presionó botón atrás)
        if (event.persisted) {
            LoadingSpinner.hide();
        }
    });

    // Ocultar spinner cuando la página se vuelve visible
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            // Dar un pequeño delay para asegurar que el DOM esté listo
            setTimeout(() => LoadingSpinner.hide(), 100);
        }
    });

    // Ocultar spinner cuando la página termina de cargar
    window.addEventListener('load', function() {
        // Si hay algún spinner activo al cargar la página, ocultarlo
        setTimeout(() => LoadingSpinner.hide(), 200);
    });

    // Manejar evento pagehide (cuando el usuario abandona la página)
    window.addEventListener('pagehide', function() {
        // Asegurar que el spinner esté oculto antes de salir
        LoadingSpinner.hide();
    });

})(window);
