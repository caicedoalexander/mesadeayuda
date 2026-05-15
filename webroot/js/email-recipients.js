/**
 * Email Recipients Manager
 *
 * Handles adding/removing email recipients with tag-style UI
 */

(function() {
    'use strict';

    // Email validation regex
    const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    /**
     * Initialize recipients manager for an input field
     * @param {string} inputId - ID of the text input
     * @param {string} containerId - ID of the tag-input-container wrapper
     * @param {string} hiddenInputId - ID of the hidden input for form submission
     * @param {array} initialRecipients - Optional initial recipients to load
     */
    function initializeRecipientInput(inputId, containerId, hiddenInputId, initialRecipients = []) {
        const input = document.getElementById(inputId);
        const container = document.getElementById(containerId);
        const hiddenInput = document.getElementById(hiddenInputId);

        if (!input || !container || !hiddenInput) return;

        // MEMORY LEAK FIX: Prevent double initialization
        if (input.dataset.recipientsInitialized === 'true') {
            return;
        }
        input.dataset.recipientsInitialized = 'true';

        // Initialize with initial data if provided
        const recipients = [...initialRecipients];

        /**
         * Add a recipient email
         */
        function addRecipient(email) {
            email = email.trim().toLowerCase();

            // SECURITY: Block system email to prevent email loops
            if (window.EmailRecipients && window.EmailRecipients.systemEmail) {
                const systemEmail = window.EmailRecipients.systemEmail.toLowerCase();
                if (email === systemEmail) {
                    alert('⚠️ No puedes agregar el correo del sistema como destinatario.\n\nEsto causaría un loop de correos infinito.');
                    return false;
                }
            }

            // Validate email format
            if (!EMAIL_REGEX.test(email)) {
                return false;
            }

            // Check for duplicates
            if (recipients.some(r => r.email === email)) {
                return false;
            }

            // Add to array
            const recipient = {
                name: email,
                email: email
            };
            recipients.push(recipient);

            // Update UI
            renderTags();
            updateHiddenInput();

            return true;
        }

        /**
         * Remove a recipient by email
         */
        function removeRecipient(email) {
            const index = recipients.findIndex(r => r.email === email);
            if (index > -1) {
                recipients.splice(index, 1);
                renderTags();
                updateHiddenInput();
            }
        }

        /**
         * Render tags in the container (before the input)
         */
        function renderTags() {
            // Remove existing tags (keep only the input element)
            const existingTags = container.querySelectorAll('.email-tag');
            existingTags.forEach(tag => tag.remove());

            recipients.forEach(recipient => {
                const tag = document.createElement('span');
                tag.className = 'email-tag';

                const text = document.createElement('span');
                text.className = 'email-tag-text';
                text.textContent = recipient.email;
                tag.appendChild(text);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'email-tag-remove';
                removeBtn.setAttribute('aria-label', 'Eliminar ' + recipient.email);
                removeBtn.innerHTML = '<i class="bi bi-x"></i>';
                removeBtn.onclick = (e) => {
                    e.stopPropagation();
                    removeRecipient(recipient.email);
                };
                tag.appendChild(removeBtn);

                container.insertBefore(tag, input);
            });

            if (typeof window.updateRecipientsSummary === 'function') {
                window.updateRecipientsSummary();
            }
        }

        /**
         * Update hidden input with JSON data
         */
        function updateHiddenInput() {
            hiddenInput.value = recipients.length > 0 ? JSON.stringify(recipients) : '';
        }

        /**
         * Process input value and extract emails
         */
        function processInput() {
            const value = input.value;
            if (!value.trim()) return;

            // Split by comma, semicolon, or space
            const emails = value.split(/[,;\s]+/).filter(e => e.trim());

            let added = false;
            emails.forEach(email => {
                if (addRecipient(email)) {
                    added = true;
                }
            });

            // Clear input if any email was added
            if (added) {
                input.value = '';
            }
        }

        // Event listeners
        input.addEventListener('keydown', (e) => {
            // Enter, comma, or semicolon triggers adding
            if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
                e.preventDefault();
                processInput();
            }
            // Backspace on empty input removes last tag
            else if (e.key === 'Backspace' && input.value === '' && recipients.length > 0) {
                removeRecipient(recipients[recipients.length - 1].email);
            }
        });

        input.addEventListener('blur', () => {
            // Add email on blur if valid
            processInput();
        });

        // Paste handler - process pasted emails
        input.addEventListener('paste', () => {
            setTimeout(() => {
                processInput();
            }, 10);
        });

        // Click on container focuses the input
        container.addEventListener('click', (e) => {
            // Don't focus if clicking on remove button or tag
            if (e.target.closest('.btn-close') || e.target.closest('.email-tag')) {
                return;
            }
            input.focus();
        });

        // Render initial tags if recipients were provided
        if (initialRecipients.length > 0) {
            renderTags();
            updateHiddenInput();
        }
    }

    function initFields(initialToRecipients = [], initialCcRecipients = []) {
        // Initialize To field
        initializeRecipientInput('email-to', 'email-to-container', 'email-to-hidden', initialToRecipients);

        // Initialize CC field
        initializeRecipientInput('email-cc', 'email-cc-container', 'email-cc-hidden', initialCcRecipients);
    }

    // Make available globally for manual initialization
    window.EmailRecipients = {
        init: initFields,
        getRecipients: function() {
            // Return current recipients for summary updates
            const toHidden = document.getElementById('email-to-hidden');
            const ccHidden = document.getElementById('email-cc-hidden');

            const to = toHidden && toHidden.value ? JSON.parse(toHidden.value) : [];
            const cc = ccHidden && ccHidden.value ? JSON.parse(ccHidden.value) : [];

            return { to, cc };
        }
    };

    // Auto-initialize with empty data if not already initialized
    // This allows pages without initial data to work automatically
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            // Only auto-init if fields exist and haven't been initialized
            const toHidden = document.getElementById('email-to-hidden');
            if (toHidden && !toHidden.value) {
                initFields();
            }
        });
    }

    // Global functions for expand/collapse
    window.expandRecipients = function() {
        const collapsed = document.getElementById('recipients-collapsed');
        const expanded = document.getElementById('recipients-expanded');

        if (collapsed && expanded) {
            collapsed.style.display = 'none';
            expanded.style.display = 'block';
        }
    };

    window.collapseRecipients = function() {
        const collapsed = document.getElementById('recipients-collapsed');
        const expanded = document.getElementById('recipients-expanded');

        if (collapsed && expanded) {
            collapsed.style.display = 'block';
            expanded.style.display = 'none';
            updateRecipientsSummary();
        }
    };

    window.updateRecipientsSummary = function() {
        const recipients = window.EmailRecipients.getRecipients();
        const allEmails = [...recipients.to, ...recipients.cc];

        // Get header element
        const headerRecipientsText = document.getElementById('comment-type-recipients-text');

        // Build summary text
        let summaryText = '';
        if (allEmails.length === 0) {
            // Restore original text (requester only)
            if (headerRecipientsText) {
                const originalText = headerRecipientsText.getAttribute('data-original-text');
                summaryText = originalText || '';
            }
        } else {
            const names = allEmails.map(r => r.name || r.email);
            summaryText = names.join(', ');
        }

        // Update collapsed view summary (if exists)
        const summaryElement = document.getElementById('recipients-summary');
        if (summaryElement) {
            if (allEmails.length === 0) {
                const originalText = headerRecipientsText?.getAttribute('data-original-text') || '';
                summaryElement.textContent = `Para: ${originalText}`;
            } else {
                const displayNames = allEmails.map(r => r.name || r.email).slice(0, 3);
                const more = allEmails.length > 3 ? ` +${allEmails.length - 3} más` : '';
                summaryElement.textContent = `Para: ${displayNames.join(', ')}${more}`;
            }
        }

        // Update header recipients text (CRITICAL: Always update)
        if (headerRecipientsText) {
            headerRecipientsText.textContent = summaryText;
        }
    };
})();
