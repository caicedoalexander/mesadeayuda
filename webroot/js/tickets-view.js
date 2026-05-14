/**
 * Ticket view interactions: comment-type switch, status selector,
 * file picker preview and recipients toggle. All handlers are
 * registered via delegated [data-action] listeners so templates can
 * stay free of inline onclick handlers (CSP-friendly).
 */
(function () {
    'use strict';

    /**
     * Set comment type (public response or internal note)
     */
    function setCommentType(type) {
        document.getElementById('comment-type').value = type;

        const textarea = document.getElementById('comment-textarea');
        const editorContainer = document.getElementById('editor-container');
        const typeLabel = document.getElementById('comment-type-label');
        const typeIcon = document.getElementById('comment-type-icon');
        const recipientsText = document.getElementById('comment-type-recipients');

        if (type === 'internal') {
            // Internal note: yellow background
            textarea.placeholder = 'Escribe una nota interna...';
            editorContainer.classList.add('internal-note-mode');

            // Update dropdown label and icon
            if (typeLabel) typeLabel.textContent = 'Nota interna';
            if (typeIcon) typeIcon.className = 'bi bi-pencil-square';

            // Hide recipients text
            if (recipientsText) recipientsText.style.display = 'none';
        } else {
            // Public response: white background
            textarea.placeholder = 'Escribe tu respuesta aquí...';
            editorContainer.classList.remove('internal-note-mode');

            // Update dropdown label and icon
            if (typeLabel) typeLabel.textContent = 'Respuesta pública';
            if (typeIcon) typeIcon.className = 'bi bi-reply-fill';

            // Show recipients text
            if (recipientsText) recipientsText.style.display = 'block';
        }

        // Show/hide email recipients section based on comment type
        const recipientsSection = document.getElementById('email-recipients-section');
        if (recipientsSection) {
            if (type === 'public') {
                recipientsSection.style.display = 'block';
                // Reset to collapsed view when switching to public
                const collapsed = document.getElementById('recipients-collapsed');
                const expanded = document.getElementById('recipients-expanded');
                if (collapsed && expanded) {
                    collapsed.style.display = 'block';
                    expanded.style.display = 'none';
                }
            } else {
                recipientsSection.style.display = 'none';
            }
        }
    }

    /**
     * Set entity status in dropdown
     * Updates both the hidden input and the visual dropdown
     */
    function setStatus(status) {
        // Update hidden input
        document.getElementById('status-hidden').value = status;

        // ✨ Get status configuration from PHP (injected by controller)
        const statusConfig = window.ticketViewData.statusConfig;
        const config = statusConfig[status] || Object.values(statusConfig)[0];

        // Update dropdown button appearance
        const statusIcon = document.getElementById('status-icon');
        const statusLabel = document.getElementById('status-label');
        const statusDropdown = document.getElementById('status-dropdown');

        if (statusIcon) {
            statusIcon.className = `bi ${config.icon}`;
            statusIcon.style.color = config.color;
        }

        if (statusLabel) {
            statusLabel.textContent = `Enviar como ${config.label}`;
        }

        if (statusDropdown) {
            statusDropdown.setAttribute('data-current-status', status);
        }
    }

    // File management
    let selectedFiles = [];

    // XSS sink guard: file names come from the user's filesystem and end up
    // interpolated into innerHTML below (title attribute and text content).
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const iconMap = {
            // Images
            'jpg': 'bi-file-earmark-image',
            'jpeg': 'bi-file-earmark-image',
            'png': 'bi-file-earmark-image',
            'gif': 'bi-file-earmark-image',
            'bmp': 'bi-file-earmark-image',
            'webp': 'bi-file-earmark-image',
            // Documents
            'pdf': 'bi-file-earmark-pdf',
            'doc': 'bi-file-earmark-word',
            'docx': 'bi-file-earmark-word',
            'xls': 'bi-file-earmark-excel',
            'xlsx': 'bi-file-earmark-excel',
            'ppt': 'bi-file-earmark-ppt',
            'pptx': 'bi-file-earmark-ppt',
            // Text
            'txt': 'bi-file-earmark-text',
            'csv': 'bi-file-earmark-spreadsheet',
            // Archives
            'zip': 'bi-file-earmark-zip',
            'rar': 'bi-file-earmark-zip',
            '7z': 'bi-file-earmark-zip',
        };
        return iconMap[ext] || 'bi-file-earmark';
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    function handleFileSelect(event) {
        const input = event.target;
        const newFiles = Array.from(input.files);

        // Add new files to the selected files array
        newFiles.forEach(file => {
            // Check if file already exists (by name and size)
            const exists = selectedFiles.some(f =>
                f.name === file.name && f.size === file.size
            );

            if (!exists) {
                selectedFiles.push(file);
            }
        });

        updateFileList();
        updateFileInput();
    }

    function removeFile(index) {
        selectedFiles.splice(index, 1);
        updateFileList();
        updateFileInput();
    }

    function updateFileList() {
        const fileList = document.getElementById('file-list');

        if (selectedFiles.length === 0) {
            fileList.innerHTML = '';
            return;
        }

        let html = '';
        selectedFiles.forEach((file, index) => {
            const icon = getFileIcon(file.name);
            const size = formatFileSize(file.size);
            const safeName = escapeHtml(file.name);

            html += `
            <div class="file-item">
                <span class="file-item-icon"><i class="bi ${icon}"></i></span>
                <div class="file-item-info">
                    <div class="file-item-name" title="${safeName}">${safeName}</div>
                    <div class="file-item-size">${size}</div>
                </div>
                <button type="button" class="file-item-remove" data-action="remove-file" data-file-index="${index}" data-tip="Quitar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;
        });

        fileList.innerHTML = html;
    }

    function updateFileInput() {
        const input = document.getElementById('file-input');
        const dataTransfer = new DataTransfer();

        selectedFiles.forEach(file => {
            dataTransfer.items.add(file);
        });

        input.files = dataTransfer.files;
    }

    // Spinner: Show when submitting comment/response or changing status
    const replyForm = document.getElementById('reply-form');
    if (replyForm) replyForm.addEventListener('submit', function (e) {
        const commentBody = document.getElementById('comment-textarea').value.trim();
        const commentType = document.getElementById('comment-type').value;
        const statusHidden = document.getElementById('status-hidden');
        const currentStatus = window.ticketViewData.currentStatus;
        const newStatus = statusHidden ? statusHidden.value : currentStatus;
        const hasStatusChange = newStatus !== currentStatus;

        // Determine appropriate message
        let message = '';

        if (commentBody || selectedFiles.length > 0) {
            // Has comment or files
            message = commentType === 'public' ? 'Enviando respuesta...' : 'Guardando nota interna...';
        } else if (hasStatusChange) {
            // Only status change (no comment or files)
            message = `Cambiando estado...`;
        }

        // Show spinner if there's something to process
        if (message) {
            LoadingSpinner.show(message);
        }
    });

    // Spinner: Show when assigning entity with Select2. Run after Select2
    // finishes initializing (event from select2-init.js) so the .on()
    // handler attaches to the wrapped element, not the raw <select>.
    function bindAgentSelectSpinner() {
        const $agentSelect = $('#agent-select');
        if (!$agentSelect.length) {
            return;
        }
        $agentSelect.on('select2:select select2:clear', function (e) {
            const form = document.getElementById('assign-form');
            if (!form) return;

            let agentName = '';
            if (e.type === 'select2:clear' || this.value === '') {
                LoadingSpinner.show('Desasignando ticket...');
            } else {
                const selectedOption = this.options[this.selectedIndex];
                agentName = selectedOption ? selectedOption.text : '';
                LoadingSpinner.show(`Asignando a ${agentName}...`);
            }
            form.submit();
        });
    }
    if (window.__select2Ready) {
        bindAgentSelectSpinner();
    } else {
        document.addEventListener('select2:ready', bindAgentSelectSpinner, { once: true });
    }

    // Toggle recipients view (collapsed/expanded)
    function toggleRecipients(recipientsId) {
        const collapsed = document.getElementById(recipientsId + '-collapsed');
        const expanded = document.getElementById(recipientsId + '-expanded');

        if (collapsed.style.display === 'none') {
            // Currently expanded, collapse it
            collapsed.style.display = 'block';
            expanded.style.display = 'none';
        } else {
            // Currently collapsed, expand it
            collapsed.style.display = 'none';
            expanded.style.display = 'block';
        }
    }

    // Initialize email recipients section visibility on page load
    document.addEventListener('DOMContentLoaded', function() {
        const commentType = document.getElementById('comment-type');
        const recipientsSection = document.getElementById('email-recipients-section');
        if (commentType && recipientsSection) {
            recipientsSection.style.display = (commentType.value === 'public') ? 'block' : 'none';
        }

        // File picker: replace inline onchange='handleFileSelect(event)'.
        const fileInput = document.getElementById('file-input');
        if (fileInput) {
            fileInput.addEventListener('change', handleFileSelect);
        }

        bindComposerDropzone();
    });

    // Drag-and-drop attachments into the composer body. The overlay
    // (.app-drop-overlay) is shown only while a file is being dragged over.
    function bindComposerDropzone() {
        const dropzone = document.getElementById('editor-container');
        const overlay  = document.getElementById('composer-drop-overlay');
        if (!dropzone || !overlay) {
            return;
        }

        let dragDepth = 0;
        const containsFiles = (e) => {
            const types = e.dataTransfer && e.dataTransfer.types;
            if (!types) return false;
            for (let i = 0; i < types.length; i++) {
                if (types[i] === 'Files') return true;
            }
            return false;
        };

        dropzone.addEventListener('dragenter', function (e) {
            if (!containsFiles(e)) return;
            e.preventDefault();
            dragDepth++;
            overlay.classList.add('is-active');
        });

        dropzone.addEventListener('dragover', function (e) {
            if (!containsFiles(e)) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
        });

        dropzone.addEventListener('dragleave', function (e) {
            if (!containsFiles(e)) return;
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) {
                overlay.classList.remove('is-active');
            }
        });

        dropzone.addEventListener('drop', function (e) {
            if (!containsFiles(e)) return;
            e.preventDefault();
            dragDepth = 0;
            overlay.classList.remove('is-active');

            const files = Array.from(e.dataTransfer.files || []);
            files.forEach(file => {
                const exists = selectedFiles.some(f => f.name === file.name && f.size === file.size);
                if (!exists) selectedFiles.push(file);
            });
            updateFileList();
            updateFileInput();
        });
    }

    // Delegated click handler for every [data-action] declared in the
    // reply editor and comments thread. Replaces the inline onclick=""
    // attributes that used to live in the PHP templates.
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-action]');
        if (!trigger) {
            return;
        }
        switch (trigger.dataset.action) {
            case 'comment-type':
                event.preventDefault();
                setCommentType(trigger.dataset.commentType);
                document.querySelectorAll('.composer-tab').forEach(t => t.classList.remove('active'));
                trigger.classList.add('active');
                break;
            case 'focus-reassign':
                event.preventDefault();
                {
                    const sel = document.getElementById('agent-select');
                    if (sel) {
                        sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        const $sel = window.jQuery ? window.jQuery(sel) : null;
                        if ($sel && $sel.data('select2')) {
                            $sel.select2('open');
                        } else {
                            sel.focus();
                        }
                    }
                }
                break;
            case 'set-status':
                event.preventDefault();
                setStatus(trigger.dataset.statusKey);
                break;
            case 'toggle-recipients':
                event.preventDefault();
                toggleRecipients(trigger.dataset.recipientsId);
                break;
            case 'expand-recipients':
                if (typeof window.expandRecipients === 'function') {
                    window.expandRecipients();
                }
                break;
            case 'collapse-recipients':
                event.preventDefault();
                if (typeof window.collapseRecipients === 'function') {
                    window.collapseRecipients();
                }
                break;
            case 'remove-file':
                event.preventDefault();
                removeFile(Number(trigger.dataset.fileIndex));
                break;
        }
    });
})();
