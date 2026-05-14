/**
 * Select2 Initialization and Configuration
 * Sistema de Soporte
 */

(function($) {
    'use strict';

    // Configuration por defecto
    const defaultConfig = {
        theme: 'bootstrap-5',
        width: '100%',
        language: 'es',
        placeholder: 'Sin asignar',
        allowClear: true,
        minimumResultsForSearch: 10, // Show search only if 10+ options
        dropdownAutoWidth: false,
    };

    // Inicializar Select2 cuando el documento esté listo. Después de
    // inicializar, exponer una bandera global y disparar un CustomEvent
    // 'select2:ready' para que los módulos dependientes (tickets-view,
    // bulk-actions) puedan registrar sus listeners sin depender de un
    // setTimeout arbitrario.
    $(document).ready(function() {
        initializeSelect2();
        window.__select2Ready = true;
        document.dispatchEvent(new CustomEvent('select2:ready'));
    });

    // Función principal de inicialización
    function initializeSelect2() {
        // Selectores simples
        $('select:not(.select2-hidden-accessible):not([data-select2-ignore])').each(function() {
            const $select = $(this);
            const config = { ...defaultConfig };

            if ($select.data('placeholder')) {
                config.placeholder = $select.data('placeholder');
            }

            if ($select.data('allow-clear') === false) {
                config.allowClear = false;
            }

            if ($select.data('tags')) {
                config.tags = true;
                config.tokenSeparators = [','];
            }

            // Table agent picker: render avatar + name, dashed orange "Asignar"
            // chip as placeholder. Lives next to the design tokens in DESIGN.md.
            if ($select.hasClass('table-agent-select')) {
                config.placeholder = 'Asignar';
                config.allowClear = false;
                config.minimumResultsForSearch = 5;
                config.templateResult = window.agentOptionTemplate;
                config.templateSelection = window.agentSelectionTemplate;
                config.dropdownCssClass = 'agent-picker-dropdown';
                config.selectionCssClass = 'agent-picker-selection';
                config.containerCssClass = 'agent-picker-container';
            }

            // Inicializar Select2
            $select.select2(config);
        });

        // Selectores con búsqueda de usuarios (AJAX)
        $('.select2-users').each(function() {
            const $select = $(this);
            $select.select2({
                ...defaultConfig,
                ajax: {
                    url: '/admin/settings/users.json',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            q: params.term,
                            page: params.page || 1
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.results,
                            pagination: {
                                more: (params.page * 30) < data.total_count
                            }
                        };
                    },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: 'Buscar usuario...'
            });
        });

        // Selectores con búsqueda de etiquetas (AJAX)
        $('.select2-tags').each(function() {
            const $select = $(this);
            $select.select2({
                ...defaultConfig,
                tags: true,
                tokenSeparators: [','],
                placeholder: 'Agregar etiquetas...'
            });
        });

        // Selectores múltiples con límite
        $('.select2-multiple-limit').each(function() {
            const $select = $(this);
            const maxSelections = $select.data('max-selections') || 5;

            $select.select2({
                ...defaultConfig,
                multiple: true,
                maximumSelectionLength: maxSelections,
                placeholder: `Selecciona hasta ${maxSelections} opciones`
            });
        });
    }

    // Re-inicializar Select2 en contenido dinámico
    window.reinitializeSelect2 = function(container) {
        const $container = container ? $(container) : $(document);
        $container.find('select:not(.select2-hidden-accessible):not([data-select2-ignore])').each(function() {
            const $select = $(this);
            if (!$select.hasClass('select2-hidden-accessible')) {
                $select.select2(defaultConfig);
            }
        });
    };

    // Template personalizado para opciones con iconos
    window.select2TemplateWithIcon = function(state) {
        if (!state.id) {
            return state.text;
        }

        const icon = $(state.element).data('icon');
        if (!icon) {
            return state.text;
        }

        const $state = $(
            '<span><i class="bi bi-' + icon + '"></i> ' + state.text + '</span>'
        );
        return $state;
    };

    // ─── Agent picker (table inline assignment) ───────────────────
    // Deterministic palette so the same name always gets the same color.
    const AGENT_AVATAR_PALETTE = [
        '#00A85E', // admin-green
        '#CD6A15', // admin-orange
        '#0066cc', // admin-blue
        '#7c3aed', // violet
        '#0891b2', // cyan
        '#dc3545', // danger
        '#6366f1', // indigo
    ];

    function hashString(s) {
        let h = 0;
        for (let i = 0; i < s.length; i++) {
            h = (h * 31 + s.charCodeAt(i)) | 0;
        }
        return Math.abs(h);
    }

    function buildAgentAvatar(name, size) {
        if (!name) return '';
        const initials = name.trim().split(/\s+/)
            .map(function(w) { return w[0] || ''; })
            .slice(0, 2)
            .join('')
            .toUpperCase();
        const color = AGENT_AVATAR_PALETTE[hashString(name) % AGENT_AVATAR_PALETTE.length];
        return '<span class="agent-avatar" style="width:' + size + 'px;height:' + size + 'px;' +
            'background:' + color + ';font-size:' + Math.round(size * 0.42) + 'px">' +
            $('<span/>').text(initials).html() + '</span>';
    }

    window.agentSelectionTemplate = function(state) {
        if (!state.id) {
            // Empty value (or "Sin asignar" empty option) renders as the
            // dashed orange "Asignar" pill from the design system.
            return $('<span class="agent-assign-pill"><i class="bi bi-plus-lg"></i> Asignar</span>');
        }
        const html = '<span class="agent-chip">' +
            buildAgentAvatar(state.text, 22) +
            '<span class="agent-name">' + $('<span/>').text(state.text).html() + '</span>' +
            '</span>';
        return $(html);
    };

    window.agentOptionTemplate = function(state) {
        if (!state.id) {
            return state.text;
        }
        const html = '<span class="agent-chip option">' +
            buildAgentAvatar(state.text, 26) +
            '<span class="agent-name">' + $('<span/>').text(state.text).html() + '</span>' +
            '</span>';
        return $(html);
    };

    // Template personalizado para resultados con avatar
    window.select2TemplateWithAvatar = function(state) {
        if (!state.id) {
            return state.text;
        }

        const avatar = $(state.element).data('avatar');
        const email = $(state.element).data('email');

        if (!avatar) {
            return state.text;
        }

        const $state = $(
            '<div class="d-flex align-items-center">' +
                '<img src="' + avatar + '" class="rounded-circle me-2" style="width: 32px; height: 32px;" />' +
                '<div>' +
                    '<div>' + state.text + '</div>' +
                    (email ? '<small class="text-muted">' + email + '</small>' : '') +
                '</div>' +
            '</div>'
        );
        return $state;
    };

})(jQuery);
