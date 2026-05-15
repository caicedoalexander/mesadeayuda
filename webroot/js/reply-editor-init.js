(function () {
    'use strict';

    /**
     * Apply a formatting command to the contenteditable editor. Most
     * commands are delegated to document.execCommand (deprecated but
     * still the only cross-browser way to format a contenteditable
     * without pulling a full editor library). Quote/code/link are
     * handled manually because execCommand doesn't cover them well.
     */
    function applyFormat(editor, kind) {
        editor.focus();
        const sel = window.getSelection();
        const hasRange = sel && sel.rangeCount > 0 && editor.contains(sel.anchorNode);
        switch (kind) {
            case 'bold':      document.execCommand('bold'); break;
            case 'italic':    document.execCommand('italic'); break;
            case 'underline': document.execCommand('underline'); break;
            case 'ul':        document.execCommand('insertUnorderedList'); break;
            case 'ol':        document.execCommand('insertOrderedList'); break;
            case 'quote':     wrapBlock(editor, 'blockquote'); break;
            case 'code':      wrapInline(editor, 'code'); break;
            case 'link': {
                const url = window.prompt('URL del enlace:', 'https://');
                if (!url) return;
                if (hasRange && !sel.isCollapsed) {
                    document.execCommand('createLink', false, url);
                } else {
                    document.execCommand('insertHTML', false,
                        '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener">' + escapeHtml(url) + '</a>');
                }
                break;
            }
            default: return;
        }
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function wrapInline(editor, tag) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (!editor.contains(range.commonAncestorContainer)) return;
        if (range.collapsed) {
            const el = document.createElement(tag);
            el.textContent = '​';
            range.insertNode(el);
            const r = document.createRange();
            r.selectNodeContents(el);
            sel.removeAllRanges();
            sel.addRange(r);
        } else {
            const text = range.toString();
            const el = document.createElement(tag);
            el.textContent = text;
            range.deleteContents();
            range.insertNode(el);
            sel.removeAllRanges();
            const r = document.createRange();
            r.setStartAfter(el);
            r.collapse(true);
            sel.addRange(r);
        }
    }

    function wrapBlock(editor, tag) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (!editor.contains(range.commonAncestorContainer)) return;
        const html = range.toString() || 'Cita';
        const el = document.createElement(tag);
        el.textContent = html;
        range.deleteContents();
        range.insertNode(el);
        sel.removeAllRanges();
        const r = document.createRange();
        r.setStartAfter(el);
        r.collapse(true);
        sel.addRange(r);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function escapeAttr(s) { return escapeHtml(s); }

    /**
     * Format the remaining-char count compactly: "1.4k", "320".
     */
    function formatRemaining(n) {
        if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        return String(n);
    }

    function updateCounter(editor, counter, max) {
        const used = (editor.innerText || '').length;
        const remaining = max - used;
        counter.textContent = formatRemaining(Math.max(remaining, 0)) + ' restantes';
        counter.classList.toggle('is-near-limit', remaining >= 0 && remaining <= max * 0.1);
        counter.classList.toggle('is-over-limit', remaining < 0);
    }

    /**
     * Mirror the editor HTML into the hidden form field that ships
     * the body to the server. Empty editor → empty string so the
     * server validates as "no content" rather than "<br>" / "<p></p>".
     */
    function syncHidden(editor, hidden) {
        const html = editor.innerHTML.trim();
        const text = (editor.innerText || '').trim();
        hidden.value = text === '' ? '' : html;
    }

    function bindToolbar(editor) {
        const toolbar = document.getElementById('composer-toolbar');
        if (!toolbar) return;
        toolbar.addEventListener('mousedown', function (ev) {
            // Prevent toolbar buttons from stealing focus from the editor
            // (otherwise execCommand has no selection to act on).
            if (ev.target.closest('button')) ev.preventDefault();
        });
        toolbar.addEventListener('click', function (ev) {
            const btn = ev.target.closest('[data-rt]');
            if (!btn) return;
            ev.preventDefault();
            applyFormat(editor, btn.getAttribute('data-rt'));
            refreshToolbarState(editor, toolbar);
        });
    }

    /**
     * Walk up from `node` to `editor`, returning the first ancestor
     * matching one of the given tag names (lowercased). Used to detect
     * formats that execCommand doesn't expose via queryCommandState
     * (blockquote, inline code, link).
     */
    function closestTag(editor, node, tagNames) {
        let n = node;
        if (n && n.nodeType === 3) n = n.parentNode;
        while (n && n !== editor) {
            if (n.nodeType === 1 && tagNames.indexOf(n.tagName.toLowerCase()) !== -1) return n;
            n = n.parentNode;
        }
        return null;
    }

    /**
     * Reflect the format under the caret on the toolbar buttons. Called
     * after every selectionchange/keyup/mouseup/input/click while the
     * editor has the selection.
     */
    function refreshToolbarState(editor, toolbar) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const anchor = sel.anchorNode;
        if (!anchor || !editor.contains(anchor)) return;

        const states = {
            bold:      safeQueryState('bold'),
            italic:    safeQueryState('italic'),
            underline: safeQueryState('underline'),
            ul:        safeQueryState('insertUnorderedList'),
            ol:        safeQueryState('insertOrderedList'),
            quote:     !!closestTag(editor, anchor, ['blockquote']),
            code:      !!closestTag(editor, anchor, ['code']),
            link:      !!closestTag(editor, anchor, ['a']),
        };

        Object.keys(states).forEach(function (kind) {
            const btn = toolbar.querySelector('[data-rt="' + kind + '"]');
            if (!btn) return;
            const on = !!states[kind];
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function safeQueryState(cmd) {
        try { return document.queryCommandState(cmd); } catch (e) { return false; }
    }

    function bindStateTracking(editor) {
        const toolbar = document.getElementById('composer-toolbar');
        if (!toolbar) return;
        const refresh = function () { refreshToolbarState(editor, toolbar); };
        editor.addEventListener('keyup', refresh);
        editor.addEventListener('mouseup', refresh);
        editor.addEventListener('input', refresh);
        editor.addEventListener('focus', refresh);
        // selectionchange fires globally; ignore when the editor doesn't own the selection.
        document.addEventListener('selectionchange', function () {
            const sel = window.getSelection();
            if (sel && sel.anchorNode && editor.contains(sel.anchorNode)) refresh();
        });
    }

    function bindCounter(editor) {
        const counter = document.getElementById('composer-char-counter');
        if (!counter) return;
        const max = parseInt(editor.getAttribute('data-max') || '5000', 10);
        const tick = function () { updateCounter(editor, counter, max); };
        editor.addEventListener('input', tick);
        tick();
    }

    function bindHiddenSync(editor) {
        const hidden = document.getElementById('comment-body-hidden');
        if (!hidden) return;
        const sync = function () { syncHidden(editor, hidden); };
        editor.addEventListener('input', sync);
        const form = document.getElementById('reply-form');
        if (form) form.addEventListener('submit', sync, true);
        sync();
    }

    function bindSubmitShortcut(editor) {
        const form = document.getElementById('reply-form');
        if (!form) return;
        editor.addEventListener('keydown', function (ev) {
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
            document.dispatchEvent(new CustomEvent('composer:open-template-picker'));
        });
    }

    function bindEmojiButton(editor) {
        const btn = document.getElementById('emoji-btn');
        if (!btn) return;
        const palette = ['🙂', '👍', '🙏', '✅', '⚠️', '❤️', '🚀', '📎'];
        btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            editor.focus();
            const choice = palette[Math.floor(Math.random() * palette.length)];
            document.execCommand('insertText', false, choice);
            editor.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    /**
     * Force pasted content to plain text. The HTML we ship is generated
     * by the toolbar — pasting arbitrary HTML from elsewhere (Word,
     * Outlook, Gmail) would smuggle styles and break sanitization.
     */
    function bindPlainPaste(editor) {
        editor.addEventListener('paste', function (ev) {
            ev.preventDefault();
            const text = (ev.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
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

        const editor = document.getElementById('comment-textarea');
        if (!editor) return;

        bindToolbar(editor);
        bindStateTracking(editor);
        bindCounter(editor);
        bindHiddenSync(editor);
        bindSubmitShortcut(editor);
        bindTemplatePicker();
        bindEmojiButton(editor);
        bindPlainPaste(editor);
    });
})();
