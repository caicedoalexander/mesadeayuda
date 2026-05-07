/**
 * Generic Lazy Loading for Entity History (Tickets)
 * PERFORMANCE FIX: Only loads history when user scrolls to history section
 *
 * Usage: Include this script in any entity view template that has a history section
 * The script auto-detects the entity type and ID from the container's data attributes
 */

(function() {
    'use strict';

    /**
     * Load entity history via AJAX
     * @param {string} entityType - Entity type (ticket)
     * @param {number} entityId - Entity ID
     */
    function loadEntityHistory(entityType, entityId) {
        const container = document.getElementById('history-container');
        const loader = document.getElementById('history-loader');
        const content = document.getElementById('history-content');

        // Check if already loaded
        if (container.dataset.loaded === 'true') {
            return;
        }

        // Mark as loaded to prevent duplicate requests
        container.dataset.loaded = 'true';

        // Build endpoint URL based on entity type (handle proper pluralization)
        const pluralMap = {
            'ticket': 'tickets'
        };
        const endpoint = `/${pluralMap[entityType] || entityType + 's'}/history/${entityId}`;

        // Fetch history from AJAX endpoint
        fetch(endpoint, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to load history');
            }
            return response.json();
        })
        .then(data => {
            // Hide loader
            loader.style.display = 'none';

            // Extract history array from response (CakePHP wraps it in an object)
            const history = data.history || data;

            // Render history
            if (!history || history.length === 0) {
                content.innerHTML = `<p class="text-muted small">No hay historial de cambios para ${getEntityLabel(entityType)}.</p>`;
            } else {
                content.innerHTML = renderHistory(history, entityType);
            }

            // Show content
            content.style.display = 'block';
        })
        .catch(() => {
            loader.innerHTML = '<p class="text-danger small">Error al cargar el historial.</p>';
        });
    }

    /**
     * Get entity label for display
     * @param {string} entityType - Entity type
     * @returns {string} Display label
     */
    function getEntityLabel(entityType) {
        const labels = {
            'ticket': 'este ticket'
        };
        return labels[entityType] || 'esta entidad';
    }

    /**
     * Get badge HTML for status/priority/type values
     * @param {string} fieldName - Field name (status, priority, type)
     * @param {string} value - Field value
     * @param {string} entityType - Entity type (ticket)
     * @param {boolean} strikethrough - Apply strikethrough styling
     * @returns {string} Badge HTML or plain text
     */
    function getFieldBadge(fieldName, value, entityType, strikethrough = false) {
        if (!value) return '';

        const colors = getFieldColors();
        let color = null;
        let label = value;

        // Get color based on field type
        if (fieldName === 'status') {
            color = colors.status[entityType]?.[value.toLowerCase()];
            label = colors.statusLabels[entityType]?.[value.toLowerCase()] || value;
        } else if (fieldName === 'priority') {
            color = colors.priority[value.toLowerCase()];
            label = colors.priorityLabels[value.toLowerCase()] || value;
        }

        // Return badge if color found, otherwise plain text
        if (color) {
            const style = strikethrough
                ? `background-color: ${color}; color: white; border-radius: 8px; padding: 0.25rem 0.5rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; opacity: 0.6; text-decoration: line-through;`
                : `background-color: ${color}; color: white; border-radius: 8px; padding: 0.25rem 0.5rem; font-size: 0.7rem; font-weight: 600; text-transform: uppercase;`;
            return `<span class="badge" style="${style}">${escapeHtml(label)}</span>`;
        }

        return strikethrough
            ? `<span class="text-decoration-line-through">${escapeHtml(value)}</span>`
            : `<span>${escapeHtml(value)}</span>`;
    }

    /**
     * Get color definitions matching StatusHelper.php
     * @returns {Object} Color and label definitions
     */
    function getFieldColors() {
        return {
            priority: {
                'baja': '#6c757d',
                'media': '#0dcaf0',
                'alta': '#ffc107',
                'urgente': '#dc3545'
            },
            priorityLabels: {
                'baja': 'Baja',
                'media': 'Media',
                'alta': 'Alta',
                'urgente': 'Urgente'
            },
            status: {
                ticket: {
                    'nuevo': '#ffc107',
                    'abierto': '#dc3545',
                    'pendiente': '#0d6efd',
                    'resuelto': '#198754',
                    'convertido': '#6c757d'
                }
            },
            statusLabels: {
                ticket: {
                    'nuevo': 'Nuevo',
                    'abierto': 'Abierto',
                    'pendiente': 'Pendiente',
                    'resuelto': 'Resuelto',
                    'convertido': 'Convertido'
                }
            },
        };
    }

    /**
     * Render history HTML from JSON data
     * @param {Array} history - History entries
     * @param {string} entityType - Entity type (ticket)
     * @returns {string} HTML string
     */
    function renderHistory(history, entityType) {
        let html = '<div class="timeline">';

        history.forEach(entry => {
            // Icon based on field changed
            let icon = 'circle-fill';
            let iconColor = 'text-secondary';

            if (entry.field_name === 'status') {
                icon = 'arrow-repeat';
                iconColor = 'text-primary';
            } else if (entry.field_name === 'assignee_id') {
                icon = 'person-fill';
                iconColor = 'text-success';
            } else if (entry.field_name === 'priority') {
                icon = 'exclamation-triangle-fill';
                iconColor = 'text-warning';
            }

            html += `
                <div class="timeline-item mb-3">
                    <div class="d-flex gap-2">
                        <div class="timeline-icon flex-shrink-0 position-relative">
                            <i class="bi bi-${icon} ${iconColor}" style="font-size: 12px; position: absolute; top: 0;"></i>
                        </div>
                        <div class="flex-grow-1" style="margin-left: 18px;">
                            <div class="small mb-1">
                                <strong>${escapeHtml(entry.user.name)}</strong>
                            </div>
            `;

            if (entry.description) {
                html += `<div class="small text-muted mb-1">${escapeHtml(entry.description)}</div>`;
            } else {
                const fieldName = entry.field_name.replace(/_/g, ' ');
                const isColoredField = ['status', 'priority', 'type'].includes(entry.field_name);

                html += `<div class="small text-muted mb-1">`;
                html += `<strong>${escapeHtml(fieldName.charAt(0).toUpperCase() + fieldName.slice(1))}:</strong> `;

                if (isColoredField) {
                    // Show colored badges for status/priority/type
                    if (entry.old_value) {
                        html += getFieldBadge(entry.field_name, entry.old_value, entityType, true) + ' → ';
                    }
                    html += getFieldBadge(entry.field_name, entry.new_value, entityType, false);
                } else {
                    // Plain text for other fields
                    if (entry.old_value) {
                        html += `<span class="text-decoration-line-through">${escapeHtml(entry.old_value)}</span> → `;
                    }
                    html += `<span>${escapeHtml(entry.new_value)}</span>`;
                }
                html += `</div>`;
            }

            // Format timestamp
            const date = new Date(entry.created);
            const formattedDate = formatRelativeTime(date);

            html += `
                            <div class="small text-muted fw-bold">${formattedDate}</div>
                        </div>
                    </div>
                </div>
            `;
        });

        html += '</div>';
        return html;
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Format date as relative time (e.g., "hace 2 horas")
     * @param {Date} date - Date to format
     * @returns {string} Formatted date
     */
    function formatRelativeTime(date) {
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHour = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHour / 24);

        if (diffSec < 60) {
            return 'hace unos segundos';
        } else if (diffMin < 60) {
            return `hace ${diffMin} minuto${diffMin !== 1 ? 's' : ''}`;
        } else if (diffHour < 24) {
            return `hace ${diffHour} hora${diffHour !== 1 ? 's' : ''}`;
        } else if (diffDay < 7) {
            return `hace ${diffDay} día${diffDay !== 1 ? 's' : ''}`;
        } else {
            // Format as date for older entries
            return date.toLocaleDateString('es-ES', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    }

    /**
     * Initialize lazy loading with Intersection Observer
     */
    function initLazyLoading() {
        const container = document.getElementById('history-container');

        if (!container) {
            return; // Not on an entity view page with history
        }

        // Get entity type and ID from data attributes
        const entityType = container.dataset.entityType;
        const entityId = container.dataset.entityId;

        if (!entityType || !entityId) {
            console.error('Missing entity-type or entity-id data attributes on #history-container');
            return;
        }

        // Use Intersection Observer for efficient lazy loading
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && container.dataset.loaded === 'false') {
                    loadEntityHistory(entityType, entityId);
                    observer.unobserve(container); // Stop observing once loaded
                }
            });
        }, {
            root: null, // viewport
            rootMargin: '50px', // Load 50px before it becomes visible
            threshold: 0.1 // Trigger when 10% visible
        });

        observer.observe(container);
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLazyLoading);
    } else {
        initLazyLoading();
    }
})();
