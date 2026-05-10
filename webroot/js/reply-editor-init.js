(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const data = window.replyEditorData || {};
        const initialTo = Array.isArray(data.to) ? data.to : [];
        const initialCc = Array.isArray(data.cc) ? data.cc : [];
        const systemEmail = (data.systemEmail || '').toLowerCase();

        if (window.EmailRecipients) {
            window.EmailRecipients.systemEmail = systemEmail;
            if (typeof window.EmailRecipients.init === 'function') {
                window.EmailRecipients.init(initialTo, initialCc);
            }
        }
    });
})();
