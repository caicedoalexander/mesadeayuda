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

    /**
     * Lightweight emoji picker. Built lazily on first open, anchored
     * to the trigger button, dismissed on outside click / Escape /
     * selection. The picker remembers the editor's caret range so the
     * insertion lands where the user left it (focus moves to the
     * picker buttons while it's open).
     */
    const EMOJI_GROUPS = [
        { label: 'Caras', emojis: ['😀','😄','🙂','😉','😊','😅','😂','🤣','😍','😘','😎','🤔','😐','😴','😢','😭','😡','🤯','🥳','🙃'] },
        { label: 'Gestos', emojis: ['👍','👎','👌','✌️','🤝','🙏','👏','🙌','💪','👋','🤞','✋','🫡','👀','🫶','🤘'] },
        { label: 'Objetos', emojis: ['📎','📌','📝','📅','📞','💻','🖥️','⌨️','🖱️','📱','💡','🔧','🔒','🔑','📦','📬','🛠️','🧰'] },
        { label: 'Señales', emojis: ['✅','❌','⚠️','❗','❓','✔️','✖️','⭐','🔥','💯','🚀','⏰','📈','📉','💬','🔔','🆕','✨'] },
        { label: 'Corazones', emojis: ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','💖','💗','💞','💕','💘','💝'] },
    ];

    function insertAtCaret(editor, range, text) {
        editor.focus();
        const sel = window.getSelection();
        if (range && editor.contains(range.startContainer)) {
            sel.removeAllRanges();
            sel.addRange(range);
        }
        document.execCommand('insertText', false, text);
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function buildEmojiPopover(editor, anchorBtn) {
        const pop = document.createElement('div');
        pop.className = 'composer-emoji-popover';
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-label', 'Seleccionar emoji');

        const tabs = document.createElement('div');
        tabs.className = 'composer-emoji-tabs';
        const grid = document.createElement('div');
        grid.className = 'composer-emoji-grid';

        let savedRange = null;
        const renderGroup = function (group) {
            grid.innerHTML = '';
            group.emojis.forEach(function (em) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'composer-emoji-cell';
                b.textContent = em;
                b.setAttribute('aria-label', em);
                b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
                b.addEventListener('click', function () {
                    insertAtCaret(editor, savedRange, em);
                    close();
                });
                grid.appendChild(b);
            });
        };

        EMOJI_GROUPS.forEach(function (g, i) {
            const t = document.createElement('button');
            t.type = 'button';
            t.className = 'composer-emoji-tab' + (i === 0 ? ' is-active' : '');
            t.textContent = g.label;
            t.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            t.addEventListener('click', function () {
                tabs.querySelectorAll('.composer-emoji-tab').forEach(function (x) { x.classList.remove('is-active'); });
                t.classList.add('is-active');
                renderGroup(g);
            });
            tabs.appendChild(t);
        });

        pop.appendChild(tabs);
        pop.appendChild(grid);
        renderGroup(EMOJI_GROUPS[0]);

        const close = function () {
            pop.remove();
            document.removeEventListener('mousedown', onOutside, true);
            document.removeEventListener('keydown', onKey, true);
        };
        const onOutside = function (ev) {
            if (!pop.contains(ev.target) && ev.target !== anchorBtn) close();
        };
        const onKey = function (ev) {
            if (ev.key === 'Escape') { ev.preventDefault(); close(); }
        };

        pop.__open = function (range) {
            savedRange = range ? range.cloneRange() : null;
            document.body.appendChild(pop);
            const r = anchorBtn.getBoundingClientRect();
            // Anchor above the button, aligned to its left edge, with viewport clamping.
            const top = window.scrollY + r.top - pop.offsetHeight - 6;
            let left = window.scrollX + r.left;
            const maxLeft = window.scrollX + document.documentElement.clientWidth - pop.offsetWidth - 8;
            if (left > maxLeft) left = maxLeft;
            if (left < 8) left = 8;
            pop.style.top = Math.max(top, window.scrollY + 8) + 'px';
            pop.style.left = left + 'px';
            document.addEventListener('mousedown', onOutside, true);
            document.addEventListener('keydown', onKey, true);
        };
        pop.__close = close;
        return pop;
    }

    function bindEmojiButton(editor) {
        const btn = document.getElementById('emoji-btn');
        if (!btn) return;
        let popover = null;

        btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (popover && document.body.contains(popover)) {
                popover.__close();
                popover = null;
                return;
            }
            // Snapshot the editor's caret BEFORE shifting focus into the popover.
            const sel = window.getSelection();
            const range = (sel && sel.rangeCount && editor.contains(sel.anchorNode)) ? sel.getRangeAt(0) : null;
            popover = buildEmojiPopover(editor, btn);
            popover.__open(range);
        });
    }

    /**
     * Force pasted content to plain text, then linkify any URLs in
     * the pasted text in a single execCommand('insertHTML') call so
     * undo treats it as one step. The HTML we ship is generated by
     * the toolbar — pasting arbitrary HTML from elsewhere (Word,
     * Outlook, Gmail) would smuggle styles and break sanitization.
     */
    function bindPlainPaste(editor) {
        editor.addEventListener('paste', function (ev) {
            ev.preventDefault();
            const text = (ev.clipboardData || window.clipboardData).getData('text/plain');
            if (!text) return;
            if (URL_RX.test(text)) {
                document.execCommand('insertHTML', false, linkifyText(text));
            } else {
                document.execCommand('insertText', false, text);
            }
        });
    }

    // ── Autolink ────────────────────────────────────────────────
    // Captures http(s)/www URLs that aren't already wrapped in an <a>.
    // The trailing-punctuation set is excluded from the match so things
    // like "ver https://foo.com." don't swallow the period.
    const URL_RX = /\b((?:https?:\/\/|www\.)[^\s<>"']+[^\s<>"'.,;:!?)\]}])/gi;

    function escapeHtmlForLink(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function normalizeHref(raw) {
        return /^https?:\/\//i.test(raw) ? raw : 'http://' + raw;
    }

    function linkifyText(text) {
        return escapeHtmlForLink(text).replace(URL_RX, function (m) {
            const href = normalizeHref(m);
            return '<a href="' + escapeHtmlForLink(href) + '" target="_blank" rel="noopener">' + escapeHtmlForLink(m) + '</a>';
        });
    }

    /**
     * Walk text nodes inside the editor that are NOT already inside
     * an <a>, and convert any URL → <a>. Skips the text node that
     * currently holds the caret to avoid yanking it mid-typing.
     */
    function autolinkEditor(editor, opts) {
        const skipActiveNode = !!(opts && opts.skipActive);
        const sel = window.getSelection();
        const activeNode = (sel && sel.anchorNode && editor.contains(sel.anchorNode)) ? sel.anchorNode : null;

        const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node.nodeValue || !URL_RX.test(node.nodeValue)) {
                    URL_RX.lastIndex = 0;
                    return NodeFilter.FILTER_REJECT;
                }
                URL_RX.lastIndex = 0;
                // Skip text that already lives inside an <a>.
                let p = node.parentNode;
                while (p && p !== editor) {
                    if (p.nodeType === 1 && p.tagName.toLowerCase() === 'a') return NodeFilter.FILTER_REJECT;
                    p = p.parentNode;
                }
                if (skipActiveNode && node === activeNode) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            },
        });

        const targets = [];
        let n;
        while ((n = walker.nextNode())) targets.push(n);
        if (!targets.length) return false;

        targets.forEach(function (textNode) {
            const text = textNode.nodeValue;
            const frag = document.createDocumentFragment();
            let lastIndex = 0;
            URL_RX.lastIndex = 0;
            let match;
            while ((match = URL_RX.exec(text)) !== null) {
                if (match.index > lastIndex) {
                    frag.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
                }
                const a = document.createElement('a');
                a.href = normalizeHref(match[0]);
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = match[0];
                frag.appendChild(a);
                lastIndex = match.index + match[0].length;
            }
            if (lastIndex < text.length) {
                frag.appendChild(document.createTextNode(text.slice(lastIndex)));
            }
            textNode.parentNode.replaceChild(frag, textNode);
        });
        return true;
    }

    /**
     * Autolink the URL that just ended (the user typed space/enter
     * right after it). Operates on the text node immediately before
     * the caret so the caret stays put.
     */
    function autolinkBeforeCaret(editor) {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        if (!editor.contains(range.startContainer)) return;
        const node = range.startContainer;
        if (node.nodeType !== 3) return;

        // Don't touch text already inside an <a>.
        let p = node.parentNode;
        while (p && p !== editor) {
            if (p.nodeType === 1 && p.tagName.toLowerCase() === 'a') return;
            p = p.parentNode;
        }

        const text = node.nodeValue;
        const offset = range.startOffset;
        // Find last URL ending before the caret position.
        URL_RX.lastIndex = 0;
        let match;
        let last = null;
        while ((match = URL_RX.exec(text)) !== null) {
            if (match.index + match[0].length <= offset) last = match;
            else break;
        }
        if (!last) return;

        const before = text.slice(0, last.index);
        const url = last[0];
        const after = text.slice(last.index + url.length);

        const a = document.createElement('a');
        a.href = normalizeHref(url);
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = url;

        const parent = node.parentNode;
        const afterNode = document.createTextNode(after);
        parent.replaceChild(afterNode, node);
        parent.insertBefore(a, afterNode);
        if (before) parent.insertBefore(document.createTextNode(before), a);

        // Place the caret at the start of the trailing text node so the
        // space/newline the user just typed continues to feel natural.
        const r = document.createRange();
        r.setStart(afterNode, 0);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        editor.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function bindAutolink(editor) {
        editor.addEventListener('keyup', function (ev) {
            if (ev.key === ' ' || ev.key === 'Enter') {
                autolinkBeforeCaret(editor);
            }
        });
        editor.addEventListener('blur', function () {
            autolinkEditor(editor, { skipActive: false });
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
        bindEmojiButton(editor);
        bindPlainPaste(editor);
        bindAutolink(editor);
    });
})();
