/**
 * Bulk Actions Module
 * Módulo JavaScript compartido para acciones masivas en Tickets
 * Extraído de templates inline scripts para mejor mantenibilidad
 */

(function() {
    'use strict';

    // Variables globales
    let selectedItems = [];
    let entityType = 'ticket'; // Default, será configurado por initBulkActions()

    /**
     * Inicializar bulk actions
     * @param {string} type - 'ticket'
     */
    window.initBulkActions = function(type) {
        entityType = type;
        selectedItems = [];

        // Esperar a que el DOM esté listo
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setupEventListeners();
            });
        } else {
            setupEventListeners();
        }
    };

    /**
     * Configurar event listeners
     */
    function setupEventListeners() {
        // Checkboxes individuales
        const checkboxes = document.querySelectorAll('.row-check');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', handleIndividualCheck);
        });

        // Checkbox "Seleccionar todo"
        const checkAll = document.getElementById('checkAll');
        if (checkAll) {
            checkAll.addEventListener('change', handleCheckAll);
        }

        // Formularios de bulk actions (si existen)
        const bulkForms = ['bulkAssignForm', 'bulkPriorityForm', 'bulkTagForm', 'bulkDeleteForm'];
        bulkForms.forEach(formId => {
            const form = document.getElementById(formId);
            if (form) {
                form.addEventListener('submit', function() {
                    const action = formId.replace('bulk', '').replace('Form', '').toLowerCase();
                    const message = getLoadingMessage(action);
                    LoadingSpinner.show(message);
                });
            }
        });

        // Search form
        const searchForm = document.getElementById('searchForm');
        if (searchForm) {
            searchForm.addEventListener('submit', function() {
                LoadingSpinner.show('Buscando tickets...');
            });
        }

        // Table assignment forms (Select2)
        setupTableAssignments();
    }

    /**
     * Manejar click en checkbox individual
     */
    function handleIndividualCheck(event) {
        const itemId = parseInt(this.value);

        if (this.checked) {
            if (!selectedItems.includes(itemId)) {
                selectedItems.push(itemId);
            }
        } else {
            selectedItems = selectedItems.filter(id => id !== itemId);
        }

        updateSelectionUI();
    }

    /**
     * Manejar "Seleccionar todo"
     */
    function handleCheckAll(event) {
        const checkboxes = document.querySelectorAll('.row-check');

        if (this.checked) {
            selectedItems = [];
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
                selectedItems.push(parseInt(checkbox.value));
            });
        } else {
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectedItems = [];
        }

        updateSelectionUI();
    }

    /**
     * Actualizar UI de selección
     */
    function updateSelectionUI() {
        const count = selectedItems.length;
        const bulkBar = document.getElementById('bulkActionsBar');
        const selectedCount = document.getElementById('selectedCount');

        if (count > 0) {
            bulkBar.classList.remove('d-none');
            selectedCount.textContent = count;
        } else {
            bulkBar.classList.add('d-none');
        }

        // Actualizar el checkbox "Seleccionar todo"
        const checkAll = document.getElementById('checkAll');
        const allCheckboxes = document.querySelectorAll('.row-check');
        if (checkAll) {
            checkAll.checked = allCheckboxes.length > 0 && selectedItems.length === allCheckboxes.length;
        }
    }

    /**
     * Limpiar selección
     */
    window.clearSelection = function() {
        selectedItems = [];
        document.querySelectorAll('.row-check').forEach(checkbox => {
            checkbox.checked = false;
        });
        const checkAll = document.getElementById('checkAll');
        if (checkAll) {
            checkAll.checked = false;
        }
        updateSelectionUI();
    };

    /**
     * Manejar acciones masivas
     */
    window.bulkAction = function(action) {
        if (selectedItems.length === 0) {
            alert('Por favor seleccione al menos un ticket');
            return;
        }

        const itemsIdsStr = selectedItems.join(',');
        const count = selectedItems.length;
        const entityIdsField = 'ticket_ids';
        const entityIdsIdPrefix = 'Ticket';

        switch (action) {
            case 'assign':
                document.getElementById(`assign${entityIdsIdPrefix}Ids`).value = itemsIdsStr;
                document.getElementById('assignCount').textContent = count;
                new bootstrap.Modal(document.getElementById('bulkAssignModal')).show();
                break;

            case 'changePriority':
                document.getElementById(`priority${entityIdsIdPrefix}Ids`).value = itemsIdsStr;
                document.getElementById('priorityCount').textContent = count;
                new bootstrap.Modal(document.getElementById('bulkPriorityModal')).show();
                break;

            case 'addTag':
                // Solo para tickets
                if (entityType === 'ticket') {
                    document.getElementById('tagTicketIds').value = itemsIdsStr;
                    document.getElementById('tagCount').textContent = count;
                    new bootstrap.Modal(document.getElementById('bulkTagModal')).show();
                }
                break;

            case 'delete':
                document.getElementById(`delete${entityIdsIdPrefix}Ids`).value = itemsIdsStr;
                document.getElementById('deleteCount').textContent = count;
                new bootstrap.Modal(document.getElementById('bulkDeleteModal')).show();
                break;
        }
    };

    /**
     * Configurar asignación desde la tabla con Select2
     */
    function setupTableAssignments() {
        setTimeout(function() {
            $('.table-agent-select').each(function() {
                const $select = $(this);

                if (!$select.prop('disabled')) {
                    $select.on('select2:select select2:clear', function(e) {
                        const form = this.closest('.table-assign-form');
                        if (!form) return;

                        let agentName = '';

                        // Si es evento 'clear' o valor vacío
                        if (e.type === 'select2:clear' || this.value === '') {
                            LoadingSpinner.show('Desasignando ticket...');
                        } else {
                            // Obtener el texto de la opción seleccionada
                            const selectedOption = this.options[this.selectedIndex];
                            agentName = selectedOption ? selectedOption.text : '';
                            LoadingSpinner.show(`Asignando a ${agentName}...`);
                        }

                        // Enviar el formulario
                        form.submit();
                    });
                }
            });
        }, 500); // Esperar 500ms para que Select2 se inicialice
    }

    /**
     * Obtener mensaje de loading según la acción
     */
    function getLoadingMessage(action) {
        const entityLabel = 'tickets';

        const messages = {
            assign: `Asignando ${entityLabel}...`,
            priority: 'Cambiando prioridad...',
            tag: 'Agregando etiqueta...',
            delete: `Eliminando ${entityLabel}...`
        };

        return messages[action] || 'Procesando...';
    }

})();
