(function () {
    'use strict';

    /**
     * Wrap or transform the textarea selection with markdown syntax.
     * Supports inline wrappers (bold/italic/underline/code), block prefixes
     * (lists/quote) and link insertion via prompt.
     */
    function applyMarkdown(textarea, kind) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;
        const selected = value.slice(start, end);
        let before = '';
        let after = '';
        let payload = selected;
        let cursorOffset = null;

        switch (kind) {
            case 'bold':      before = '**'; after = '**'; break;
            case 'italic':    before = '*';  after = '*';  break;
            case 'underline': before = '__'; after = '__'; break;
            case 'code':      before = '`';  after = '`';  break;
            case 'ul':
            case 'ol':
            case 'quote': {
                const prefix = kind === 'ul' ? '- ' : kind === 'ol' ? '1. ' : '> ';
                const lines = (selected || '').split('\n');
                payload = lines.map((line) => prefix + line).join('\n');
                if (!selected) cursorOffset = prefix.length;
                break;
            }
            case 'link': {
                const url = window.prompt('URL del enlace:', 'https://');
                if (!url) return;
                payload = '[' + (selected || 'texto') + '](' + url + ')';
                break;
            }
            default:
                return;
        }

        const insert = before + payload + after;
        textarea.setRangeText(insert, start, end, 'end');
        if (cursorOffset !== null) {
            const pos = start + cursorOffset;
            textarea.setSelectionRange(pos, pos);
        } else if (!selected && (before || after)) {
            const pos = start + before.length;
            textarea.setSelectionRange(pos, pos);
        }
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Format the remaining-char count compactly: "1.4k", "320".
     */
    function formatRemaining(n) {
        if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        return String(n);
    }

    function updateCounter(textarea, counter, max) {
        const used = textarea.value.length;
        const remaining = max - used;
        counter.textContent = formatRemaining(Math.max(remaining, 0)) + ' restantes';
        counter.classList.toggle('is-near-limit', remaining >= 0 && remaining <= max * 0.1);
        counter.classList.toggle('is-over-limit', remaining < 0);
    }

    function bindToolbar(textarea) {
        const toolbar = document.getElementById('composer-toolbar');
        if (!toolbar) return;
        toolbar.addEventListener('click', function (ev) {
            const btn = ev.target.closest('[data-rt]');
            if (!btn) return;
            ev.preventDefault();
            applyMarkdown(textarea, btn.getAttribute('data-rt'));
        });
    }

    function bindCounter(textarea) {
        const counter = document.getElementById('composer-char-counter');
        if (!counter) return;
        const max = parseInt(textarea.getAttribute('data-max') || textarea.getAttribute('maxlength') || '5000', 10);
        const tick = function () { updateCounter(textarea, counter, max); };
        textarea.addEventListener('input', tick);
        tick();
    }

    function bindSubmitShortcut(textarea) {
        const form = document.getElementById('reply-form');
        if (!form) return;
        textarea.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && (ev.metaKey || ev.ctrlKey)) {
                ev.preventDefault();
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
    }

    function bindTemplatePicker() {
        const btn = document.querySelector('[data-action="open-template-picker"]');
        if (!btn) return;
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            // Hook para integración futura con email_templates. Por ahora
            // emite un evento custom — el picker se conecta cuando exista.
            document.dispatchEvent(new CustomEvent('composer:open-template-picker'));
        });
    }

    function bindEmojiButton(textarea) {
        const btn = document.getElementById('emoji-btn');
        if (!btn) return;
        const palette = ['🙂', '👍', '🙏', '✅', '⚠️', '❤️', '🚀', '📎'];
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            const choice = palette[Math.floor(Math.random() * palette.length)];
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            textarea.setRangeText(choice, start, end, 'end');
            textarea.focus();
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

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

        const textarea = document.getElementById('comment-textarea');
        if (!textarea) return;

        bindToolbar(textarea);
        bindCounter(textarea);
        bindSubmitShortcut(textarea);
        bindTemplatePicker();
        bindEmojiButton(textarea);
    });
})();
