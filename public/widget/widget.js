/**
 * Hostorio AI Chatbot — embeddable widget.
 *
 * Drop-in usage:
 *
 *   <script src="https://example.com/widget/widget.js"
 *           data-endpoint="https://example.com/api/chat"
 *           data-accent="#2563eb"
 *           data-position="bottom-right"
 *           data-token="<signed identity token, optional>"
 *           defer></script>
 *
 * Design notes
 * ------------
 * No dependencies and no build step, because the deliverable has to install on
 * shared cPanel hosting by upload alone.
 *
 * The whole UI lives in a **shadow root**. That is not decoration: this widget
 * gets dropped into arbitrary WordPress themes, and without isolation the
 * host page's CSS reshapes the widget (or the widget's CSS leaks into the
 * page). Shadow DOM is the only reliable way to prevent both.
 *
 * On "streaming": this posts once and renders the reply progressively, which
 * reads as live typing. It is *not* token streaming. Real SSE would need the
 * provider layer to stream too, and shared hosts routinely buffer responses
 * through mod_deflate and FastCGI, which breaks SSE in ways that are painful to
 * diagnose from a support ticket. One reliable request beats a fragile stream.
 */
(function () {
    'use strict';

    if (window.__hostorioChatLoaded) {
        return;
    }
    window.__hostorioChatLoaded = true;

    var script = document.currentScript || (function () {
        var all = document.getElementsByTagName('script');
        return all[all.length - 1];
    })();

    var data = (script && script.dataset) || {};

    /** Resolve the API base from the script src when not given explicitly. */
    function defaultEndpoint() {
        if (!script || !script.src) {
            return '/api/chat';
        }
        try {
            var url = new URL(script.src, window.location.href);
            return url.origin + url.pathname.replace(/\/widget\/[^/]*$/, '') + '/api/chat';
        } catch (e) {
            return '/api/chat';
        }
    }

    var settings = {
        endpoint: data.endpoint || defaultEndpoint(),
        configEndpoint: data.configEndpoint || '',
        token: data.token || '',
        accent: data.accent || '',
        accentText: data.accentText || '',
        position: data.position || '',
        title: data.title || '',
        subtitle: data.subtitle || '',
        welcome: data.welcome || '',
        placeholder: data.placeholder || '',
        launcherLabel: data.launcherLabel || '',
        typewriter: data.typewriter === undefined ? null : data.typewriter !== 'false',
        openOnLoad: data.open === 'true'
    };

    if (!settings.configEndpoint) {
        settings.configEndpoint = settings.endpoint.replace(/\/chat$/, '/widget/config');
    }

    var config = {
        title: 'Support',
        subtitle: 'Ask us anything',
        welcome: 'Hi! How can I help?',
        placeholder: 'Type your message…',
        launcherLabel: 'Chat with support',
        accent: '#2563eb',
        accentText: '#ffffff',
        position: 'bottom-right',
        suggestions: [],
        typewriter: true,
        branding: false
    };

    var STORAGE_KEY = 'hoai_conversation_id';
    var STORAGE_KEY_OPEN = 'hoai_widget_open';
    var STORAGE_KEY_FORM = 'hoai_form_data';
    var conversationId = null;
    var formData = null;

    try {
        conversationId = window.sessionStorage.getItem(STORAGE_KEY);
    } catch (e) {
        // Private browsing or a blocked storage partition. The chat still
        // works; it just starts a new thread each page load.
        conversationId = null;
    }

    /**
     * Whether the panel was left open before the visitor navigated to this
     * page. Without this, a same-site link click looks like the chat was
     * reset, because the widget itself is torn down and rebuilt on every
     * page load — only sessionStorage survives that.
     */
    function wasOpenBefore() {
        try {
            return window.sessionStorage.getItem(STORAGE_KEY_OPEN) === '1';
        } catch (e) {
            return false;
        }
    }

    function rememberOpenState(open) {
        try {
            if (open) {
                window.sessionStorage.setItem(STORAGE_KEY_OPEN, '1');
            } else {
                window.sessionStorage.removeItem(STORAGE_KEY_OPEN);
            }
        } catch (e) { /* storage unavailable; state just won't survive navigation */ }
    }

    var host, root, panel, launcher, log, input, form, sendButton, statusLine, formOverlay, formNameInput, formEmailInput, formDepartmentSelect;
    var isOpen = false;
    var isBusy = false;
    var hasMessages = false;
    var formSubmitted = false;

    var reduceMotion = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false;

    // ── Styles ───────────────────────────────────────────────────────────────
    function styles() {
        return [
            ':host { all: initial; }',
            '*, *::before, *::after { box-sizing: border-box; }',
            '.wrap {',
            '  position: fixed; bottom: 20px; z-index: 2147483000;',
            '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;',
            '  font-size: 15px; line-height: 1.5; color: #111827;',
            '}',
            '.wrap.right { right: 20px; align-items: flex-end; }',
            '.wrap.left  { left: 20px;  align-items: flex-start; }',
            '.launcher {',
            '  display: flex; align-items: center; gap: 10px;',
            '  border: 0; border-radius: 999px; cursor: pointer;',
            '  padding: 14px 20px; font: inherit; font-weight: 600;',
            '  background: var(--accent); color: var(--accent-text);',
            '  box-shadow: 0 6px 24px rgba(0,0,0,.22);',
            '  transition: transform .15s ease, box-shadow .15s ease;',
            '}',
            '.launcher:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,0,0,.26); }',
            '.launcher:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }',
            '.launcher svg { width: 20px; height: 20px; flex: none; }',
            '.panel {',
            '  display: none; flex-direction: column; overflow: hidden;',
            '  width: 380px; height: 560px; max-height: calc(100vh - 40px);',
            '  background: #fff; border-radius: 16px;',
            '  box-shadow: 0 12px 48px rgba(0,0,0,.24); border: 1px solid #e5e7eb;',
            '}',
            '.panel.open { display: flex; }',
            '.header {',
            '  display: flex; align-items: center; gap: 12px; padding: 16px;',
            '  background: var(--accent); color: var(--accent-text); flex: none;',
            '}',
            '.header h2 { margin: 0; font-size: 16px; font-weight: 700; }',
            '.header p { margin: 2px 0 0; font-size: 13px; opacity: .85; }',
            '.header .close {',
            '  margin-left: auto; background: transparent; border: 0; cursor: pointer;',
            '  color: inherit; padding: 6px; border-radius: 8px; line-height: 0;',
            '}',
            '.header .close:hover { background: rgba(255,255,255,.18); }',
            '.header .close:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }',
            '.header .close svg { width: 18px; height: 18px; }',
            '.log { flex: 1 1 auto; overflow-y: auto; padding: 16px; background: #f9fafb; }',
            '.msg { display: flex; margin-bottom: 12px; }',
            '.msg .bubble {',
            '  max-width: 85%; padding: 10px 14px; border-radius: 14px;',
            '  white-space: pre-wrap; overflow-wrap: anywhere;',
            '}',
            '.msg.user { justify-content: flex-end; }',
            '.msg.user .bubble { background: var(--accent); color: var(--accent-text); border-bottom-right-radius: 4px; }',
            '.msg.bot .bubble { background: #fff; border: 1px solid #e5e7eb; border-bottom-left-radius: 4px; }',
            '.msg.error .bubble { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }',
            '.bubble a { color: inherit; text-decoration: underline; }',
            '.msg.bot .bubble a { color: #1d4ed8; }',
            '.bubble ul { margin: 6px 0; padding-left: 20px; }',
            '.sources { margin-top: 8px; font-size: 12px; color: #6b7280; }',
            '.sources strong { display: block; margin-bottom: 2px; font-weight: 600; }',
            '.typing { display: flex; gap: 4px; align-items: center; padding: 4px 2px; }',
            '.typing span {',
            '  width: 7px; height: 7px; border-radius: 50%; background: #9ca3af;',
            '  animation: blink 1.2s infinite ease-in-out;',
            '}',
            '.typing span:nth-child(2) { animation-delay: .2s; }',
            '.typing span:nth-child(3) { animation-delay: .4s; }',
            '@keyframes blink { 0%, 80%, 100% { opacity: .25; } 40% { opacity: 1; } }',
            '@media (prefers-reduced-motion: reduce) {',
            '  .typing span { animation: none; opacity: .6; }',
            '  .launcher { transition: none; }',
            '}',
            '.suggestions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }',
            '.suggestions button {',
            '  font: inherit; font-size: 13px; cursor: pointer;',
            '  background: #fff; border: 1px solid #d1d5db; border-radius: 999px;',
            '  padding: 7px 12px; color: #374151;',
            '}',
            '.suggestions button:hover { border-color: var(--accent); color: var(--accent); }',
            '.form-overlay { display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.3); border-radius: 16px; z-index: 1000; }',
            '.form-overlay.show { display: flex; align-items: center; justify-content: center; }',
            '.form-card {',
            '  background: #fff; border-radius: 12px; padding: 24px; max-width: 90%; width: 340px;',
            '  box-shadow: 0 10px 40px rgba(0,0,0,.3); flex: none;',
            '}',
            '.form-card h3 { margin: 0 0 8px; font-size: 18px; font-weight: 700; }',
            '.form-card p { margin: 0 0 20px; font-size: 13px; color: #6b7280; }',
            '.form-group { margin-bottom: 16px; }',
            '.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #374151; }',
            '.form-group input, .form-group select {',
            '  width: 100%; padding: 10px 12px; font: inherit; font-size: 14px;',
            '  border: 1px solid #d1d5db; border-radius: 8px; background: #fff;',
            '}',
            '.form-group input:focus, .form-group select:focus {',
            '  outline: 2px solid var(--accent); outline-offset: -1px; border-color: transparent;',
            '}',
            '.form-actions { display: flex; gap: 10px; justify-content: flex-end; }',
            '.form-actions button {',
            '  padding: 10px 16px; border: 0; border-radius: 8px; font: inherit; font-weight: 600;',
            '  cursor: pointer; font-size: 13px;',
            '}',
            '.form-actions .btn-primary {',
            '  background: var(--accent); color: var(--accent-text);',
            '}',
            '.form-actions .btn-primary:hover { opacity: .9; }',
            '.form-actions .btn-secondary {',
            '  background: #f3f4f6; color: #374151;',
            '}',
            '.form-actions .btn-secondary:hover { background: #e5e7eb; }',
            '.composer { flex: none; border-top: 1px solid #e5e7eb; background: #fff; padding: 10px; }',
            '.composer form { display: flex; gap: 8px; align-items: flex-end; }',
            '.composer textarea {',
            '  flex: 1; resize: none; font: inherit; color: inherit;',
            '  border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px;',
            '  max-height: 120px; min-height: 42px; background: #fff;',
            '}',
            '.composer textarea:focus { outline: 2px solid var(--accent); outline-offset: -1px; border-color: transparent; }',
            '.composer button {',
            '  flex: none; border: 0; border-radius: 10px; cursor: pointer;',
            '  width: 42px; height: 42px; line-height: 0;',
            '  background: var(--accent); color: var(--accent-text);',
            '}',
            '.composer button[disabled] { opacity: .5; cursor: not-allowed; }',
            '.composer button:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }',
            '.composer button svg { width: 18px; height: 18px; }',
            '.status { font-size: 12px; color: #6b7280; padding: 4px 2px 0; min-height: 16px; }',
            '.branding { font-size: 11px; color: #9ca3af; text-align: center; padding-top: 6px; }',
            // Full screen on phones: a 380px panel on a 360px viewport is unusable.
            '@media (max-width: 480px) {',
            '  .wrap { bottom: 0; right: 0; left: 0; }',
            '  .wrap.right, .wrap.left { right: 0; left: 0; }',
            '  .panel { width: 100vw; height: 100vh; max-height: 100vh; border-radius: 0; border: 0; }',
            '  .launcher { position: fixed; bottom: 16px; right: 16px; }',
            '  .wrap.left .launcher { right: auto; left: 16px; }',
            '}',
            '.sr-only {',
            '  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;',
            '  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;',
            '}'
        ].join('\n');
    }

    function icon(paths, extra) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', extra || '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');

        paths.forEach(function (d) {
            var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            p.setAttribute('d', d);
            svg.appendChild(p);
        });

        return svg;
    }

    // ── Safe rendering ───────────────────────────────────────────────────────
    /**
     * Render answer text into DOM nodes.
     *
     * Everything is built with createTextNode and createElement — innerHTML is
     * never used on model output. The model's reply is influenced by retrieved
     * content, which includes customer-written ticket subjects, so treating it
     * as trusted markup would turn a prompt injection into stored XSS on the
     * hosting company's own site.
     */
    function renderText(container, text) {
        var lines = String(text).split('\n');
        var list = null;

        lines.forEach(function (line) {
            var bullet = /^\s*[-*]\s+(.*)$/.exec(line);

            if (bullet) {
                if (!list) {
                    list = document.createElement('ul');
                    container.appendChild(list);
                }
                var li = document.createElement('li');
                appendInline(li, bullet[1]);
                list.appendChild(li);
                return;
            }

            list = null;

            if (line.trim() === '') {
                container.appendChild(document.createElement('br'));
                return;
            }

            var div = document.createElement('div');
            appendInline(div, line);
            container.appendChild(div);
        });
    }

    /** Inline formatting: **bold** and bare URLs, as real nodes. */
    function appendInline(parent, text) {
        var pattern = /(\*\*[^*]+\*\*)|(https?:\/\/[^\s<>"')]+)/g;
        var lastIndex = 0;
        var match;

        while ((match = pattern.exec(text)) !== null) {
            if (match.index > lastIndex) {
                parent.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
            }

            if (match[1]) {
                var strong = document.createElement('strong');
                strong.textContent = match[1].slice(2, -2);
                parent.appendChild(strong);
            } else {
                var href = match[2];
                var anchor = document.createElement('a');
                // Only http(s) reaches here, so javascript: URLs are impossible.
                anchor.href = href;
                anchor.textContent = href;
                anchor.target = '_blank';
                anchor.rel = 'noopener noreferrer nofollow';
                parent.appendChild(anchor);
            }

            lastIndex = pattern.lastIndex;
        }

        if (lastIndex < text.length) {
            parent.appendChild(document.createTextNode(text.slice(lastIndex)));
        }
    }

    // ── Messages ─────────────────────────────────────────────────────────────
    function addMessage(role, text, options) {
        options = options || {};

        var row = document.createElement('div');
        row.className = 'msg ' + role;

        var bubble = document.createElement('div');
        bubble.className = 'bubble';
        row.appendChild(bubble);

        log.appendChild(row);
        hasMessages = true;

        if (options.progressive && config.typewriter && !reduceMotion) {
            revealProgressively(bubble, text, options.sources);
        } else {
            renderText(bubble, text);
            if (options.sources) {
                appendSources(bubble, options.sources);
            }
        }

        scrollToBottom();

        return bubble;
    }

    /**
     * Reveal the answer in chunks so it reads as live typing.
     *
     * Purely cosmetic — the full text already arrived. It is capped so a long
     * answer never takes more than a couple of seconds to finish drawing;
     * making someone wait for an animation they cannot skip is worse than
     * showing the text at once.
     */
    function revealProgressively(bubble, text, sources) {
        var total = text.length;
        var steps = Math.min(40, Math.max(8, Math.ceil(total / 25)));
        var size = Math.ceil(total / steps);
        var shown = 0;

        function step() {
            shown = Math.min(total, shown + size);

            while (bubble.firstChild) {
                bubble.removeChild(bubble.firstChild);
            }

            renderText(bubble, text.slice(0, shown));
            scrollToBottom();

            if (shown < total) {
                window.setTimeout(step, 40);
            } else if (sources) {
                appendSources(bubble, sources);
                scrollToBottom();
            }
        }

        step();
    }

    function appendSources(bubble, sources) {
        if (!sources || !sources.length) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'sources';

        var label = document.createElement('strong');
        label.textContent = sources.length === 1 ? 'Source' : 'Sources';
        wrap.appendChild(label);

        sources.forEach(function (source) {
            var line = document.createElement('div');
            line.textContent = String(source);
            wrap.appendChild(line);
        });

        bubble.appendChild(wrap);
    }

    function showTyping() {
        var row = document.createElement('div');
        row.className = 'msg bot';
        row.dataset.typing = 'true';

        var bubble = document.createElement('div');
        bubble.className = 'bubble';

        var dots = document.createElement('div');
        dots.className = 'typing';
        dots.setAttribute('aria-label', 'Assistant is typing');

        for (var i = 0; i < 3; i++) {
            dots.appendChild(document.createElement('span'));
        }

        bubble.appendChild(dots);
        row.appendChild(bubble);
        log.appendChild(row);
        scrollToBottom();

        return row;
    }

    function scrollToBottom() {
        log.scrollTop = log.scrollHeight;
    }

    function setBusy(busy) {
        isBusy = busy;
        sendButton.disabled = busy;
        input.disabled = busy;
        statusLine.textContent = busy ? 'Thinking…' : '';
    }

    function showSuggestions() {
        if (!config.suggestions || !config.suggestions.length) {
            return;
        }

        var row = document.createElement('div');
        row.className = 'msg bot';

        var wrap = document.createElement('div');
        wrap.className = 'suggestions';

        config.suggestions.forEach(function (suggestion) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = suggestion;
            button.addEventListener('click', function () {
                row.remove();
                send(suggestion);
            });
            wrap.appendChild(button);
        });

        row.appendChild(wrap);
        log.appendChild(row);
    }

    // ── Networking ───────────────────────────────────────────────────────────
    function send(text) {
        text = String(text || '').trim();

        if (text === '' || isBusy) {
            return;
        }

        addMessage('user', text);
        input.value = '';
        autoGrow();
        setBusy(true);

        var typing = showTyping();

        var payload = { message: text };

        if (conversationId) {
            payload.conversation_id = conversationId;
        }

        // Include form data on first message only
        if (formData && !conversationId) {
            payload.visitor_name = formData.name;
            payload.visitor_email = formData.email;
            payload.visitor_department = formData.department;
        }

        var headers = { 'Content-Type': 'application/json' };

        if (settings.token) {
            headers['X-Chat-Token'] = settings.token;
        }

        fetch(settings.endpoint, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (body) {
                return { status: response.status, body: body };
            }).catch(function () {
                return { status: response.status, body: null };
            });
        }).then(function (result) {
            typing.remove();
            setBusy(false);

            if (result.body && result.body.ok) {
                if (result.body.conversation_id) {
                    conversationId = result.body.conversation_id;
                    try {
                        window.sessionStorage.setItem(STORAGE_KEY, conversationId);
                    } catch (e) { /* storage unavailable; carry on */ }
                }

                addMessage('bot', result.body.answer || '', {
                    progressive: true,
                    sources: result.body.sources
                });

                announce('Reply received.');
                return;
            }

            addMessage('error', errorMessage(result));
            announce('The assistant could not reply.');
        }).catch(function () {
            typing.remove();
            setBusy(false);
            addMessage('error', 'I could not reach the server. Please check your connection and try again.');
        }).then(function () {
            if (!isOpen) {
                return;
            }
            input.focus();
        });
    }

    /**
     * Turn a failure into something a customer can act on.
     *
     * The server deliberately keeps its own messages non-technical, so they are
     * shown as-is where present; these are the fallbacks for the cases where a
     * response body never arrived.
     */
    function errorMessage(result) {
        if (result.body && result.body.error && result.body.error.message) {
            return result.body.error.message;
        }

        if (result.status === 429) {
            return 'You are sending messages a bit too quickly. Please wait a moment and try again.';
        }

        if (result.status >= 500) {
            return 'Something went wrong on our side. Please try again shortly.';
        }

        return 'Sorry, I could not answer that. Please try again.';
    }

    /** Announce to screen readers without moving focus. */
    function announce(message) {
        statusLine.textContent = message;
        window.setTimeout(function () {
            if (statusLine.textContent === message) {
                statusLine.textContent = '';
            }
        }, 1500);
    }

    // ── Panel ────────────────────────────────────────────────────────────────
    function open() {
        isOpen = true;
        panel.classList.add('open');
        launcher.setAttribute('aria-expanded', 'true');
        launcher.style.display = 'none';
        rememberOpenState(true);

        if (!formSubmitted) {
            showForm();
            return;
        }

        if (!hasMessages) {
            addMessage('bot', config.welcome);
            showSuggestions();
        }

        input.focus();
    }

    function close() {
        isOpen = false;
        panel.classList.remove('open');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.style.display = '';
        rememberOpenState(false);
        launcher.focus();
    }

    function autoGrow() {
        input.style.height = 'auto';
        input.style.height = Math.min(120, input.scrollHeight) + 'px';
    }

    function loadFormData() {
        try {
            var stored = window.sessionStorage.getItem(STORAGE_KEY_FORM);
            formData = stored ? JSON.parse(stored) : null;
        } catch (e) {
            formData = null;
        }
    }

    function saveFormData(data) {
        try {
            window.sessionStorage.setItem(STORAGE_KEY_FORM, JSON.stringify(data));
        } catch (e) { /* storage unavailable */ }
        formData = data;
    }

    function showForm() {
        if (formSubmitted || !formOverlay) {
            return;
        }
        formOverlay.classList.add('show');
        formNameInput.focus();
    }

    function hideForm() {
        if (formOverlay) {
            formOverlay.classList.remove('show');
        }
    }

    function submitForm() {
        var name = formNameInput.value.trim();
        var email = formEmailInput.value.trim();
        var department = formDepartmentSelect.value;

        if (name === '' || email === '') {
            alert('Please fill in all required fields.');
            return;
        }

        var dept = String(department || 'sales').toLowerCase();

        // Services department requires WHMCS login (checked via token)
        if (dept === 'services' && !settings.token) {
            window.location.href = 'https://my.hostorio.com/clientarea.php?action=login&redirect=/clientarea.php';
            return;
        }

        saveFormData({ name: name, email: email, department: dept });
        formSubmitted = true;
        hideForm();

        // Show welcome message and suggestions
        if (!hasMessages) {
            addMessage('bot', config.welcome);
            showSuggestions();
        }

        input.focus();
    }

    // ── Build ────────────────────────────────────────────────────────────────
    function build() {
        host = document.createElement('div');
        host.id = 'hostorio-chat-widget';
        // attachShadow keeps the host page's CSS out and the widget's CSS in.
        root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

        var style = document.createElement('style');
        style.textContent = styles();
        root.appendChild(style);

        var wrap = document.createElement('div');
        wrap.className = 'wrap ' + (config.position === 'bottom-left' ? 'left' : 'right');
        wrap.style.setProperty('--accent', config.accent);
        wrap.style.setProperty('--accent-text', config.accentText);

        // Launcher
        launcher = document.createElement('button');
        launcher.type = 'button';
        launcher.className = 'launcher';
        launcher.setAttribute('aria-expanded', 'false');
        launcher.setAttribute('aria-label', config.launcherLabel);
        launcher.appendChild(icon(['M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z']));
        launcher.appendChild(document.createTextNode(config.launcherLabel));
        launcher.addEventListener('click', open);

        // Panel
        panel = document.createElement('div');
        panel.className = 'panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'false');
        panel.setAttribute('aria-label', config.title);

        var header = document.createElement('div');
        header.className = 'header';

        var titles = document.createElement('div');
        var h2 = document.createElement('h2');
        h2.textContent = config.title;
        titles.appendChild(h2);

        if (config.subtitle) {
            var sub = document.createElement('p');
            sub.textContent = config.subtitle;
            titles.appendChild(sub);
        }

        header.appendChild(titles);

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'close';
        closeButton.setAttribute('aria-label', 'Close chat');
        closeButton.appendChild(icon(['M18 6 6 18', 'm6 6 12 12']));
        closeButton.addEventListener('click', close);
        header.appendChild(closeButton);

        log = document.createElement('div');
        log.className = 'log';
        log.setAttribute('role', 'log');
        log.setAttribute('aria-live', 'polite');
        log.setAttribute('aria-atomic', 'false');

        var composer = document.createElement('div');
        composer.className = 'composer';

        form = document.createElement('form');

        input = document.createElement('textarea');
        input.rows = 1;
        input.placeholder = config.placeholder;
        input.setAttribute('aria-label', 'Message');
        input.addEventListener('input', autoGrow);
        input.addEventListener('keydown', function (event) {
            // Enter sends; Shift+Enter is a newline. Matches every chat app a
            // customer has used, so it needs no explanation.
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                send(input.value);
            }
        });

        sendButton = document.createElement('button');
        sendButton.type = 'submit';
        sendButton.setAttribute('aria-label', 'Send message');
        sendButton.appendChild(icon(['m22 2-7 20-4-9-9-4Z', 'M22 2 11 13']));

        form.appendChild(input);
        form.appendChild(sendButton);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send(input.value);
        });

        statusLine = document.createElement('div');
        statusLine.className = 'status';
        statusLine.setAttribute('role', 'status');
        statusLine.setAttribute('aria-live', 'polite');

        composer.appendChild(form);
        composer.appendChild(statusLine);

        if (config.branding) {
            var branding = document.createElement('div');
            branding.className = 'branding';
            branding.textContent = 'AI assistant — answers may be imperfect';
            composer.appendChild(branding);
        }

        panel.appendChild(header);
        panel.appendChild(log);
        panel.appendChild(composer);

        // Form overlay
        formOverlay = document.createElement('div');
        formOverlay.className = 'form-overlay';
        formOverlay.setAttribute('role', 'dialog');
        formOverlay.setAttribute('aria-modal', 'true');
        formOverlay.setAttribute('aria-label', 'Pre-chat form');

        var formCard = document.createElement('div');
        formCard.className = 'form-card';

        var formTitle = document.createElement('h3');
        formTitle.textContent = 'Please tell us about yourself';
        formCard.appendChild(formTitle);

        var formIntro = document.createElement('p');
        formIntro.textContent = 'Fill in a few details to help us assist you better.';
        formCard.appendChild(formIntro);

        // Name field
        var nameGroup = document.createElement('div');
        nameGroup.className = 'form-group';
        var nameLabel = document.createElement('label');
        nameLabel.textContent = 'Your Name *';
        formNameInput = document.createElement('input');
        formNameInput.type = 'text';
        formNameInput.placeholder = 'e.g., John Doe';
        formNameInput.required = true;
        nameGroup.appendChild(nameLabel);
        nameGroup.appendChild(formNameInput);
        formCard.appendChild(nameGroup);

        // Email field
        var emailGroup = document.createElement('div');
        emailGroup.className = 'form-group';
        var emailLabel = document.createElement('label');
        emailLabel.textContent = 'Your Email *';
        formEmailInput = document.createElement('input');
        formEmailInput.type = 'email';
        formEmailInput.placeholder = 'e.g., john@example.com';
        formEmailInput.required = true;
        emailGroup.appendChild(emailLabel);
        emailGroup.appendChild(formEmailInput);
        formCard.appendChild(emailGroup);

        // Department field
        var deptGroup = document.createElement('div');
        deptGroup.className = 'form-group';
        var deptLabel = document.createElement('label');
        deptLabel.textContent = 'Department *';
        formDepartmentSelect = document.createElement('select');
        formDepartmentSelect.required = true;

        var salesOption = document.createElement('option');
        salesOption.value = 'sales';
        salesOption.textContent = 'Sales';
        formDepartmentSelect.appendChild(salesOption);

        var servicesOption = document.createElement('option');
        servicesOption.value = 'services';
        servicesOption.textContent = 'Services';
        formDepartmentSelect.appendChild(servicesOption);

        deptGroup.appendChild(deptLabel);
        deptGroup.appendChild(formDepartmentSelect);
        formCard.appendChild(deptGroup);

        // Form buttons
        var formActions = document.createElement('div');
        formActions.className = 'form-actions';

        var submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'btn-primary';
        submitBtn.textContent = 'Start Chat';
        submitBtn.addEventListener('click', submitForm);
        submitBtn.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitForm();
            }
        });

        formActions.appendChild(submitBtn);
        formCard.appendChild(formActions);

        formOverlay.appendChild(formCard);
        panel.appendChild(formOverlay);

        wrap.appendChild(panel);
        wrap.appendChild(launcher);
        root.appendChild(wrap);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpen) {
                close();
            }
        });

        document.body.appendChild(host);

        if (settings.openOnLoad) {
            open();
        }
    }

    /**
     * Redisplay a resumed conversation's messages after a page navigation.
     *
     * The conversation itself already continues server-side purely from the
     * id stored in sessionStorage — the model sees the prior turns regardless
     * of this. What is missing without it is the visible log: without
     * re-rendering, every new page looks like the chat forgot everything,
     * even though it did not.
     *
     * Always resolves; a failed or empty fetch just leaves the log empty,
     * same as a first-ever visit.
     */
    function loadHistory() {
        if (!conversationId) {
            return Promise.resolve();
        }

        var url = settings.endpoint.replace(/\/chat$/, '/chat/history')
            + '?conversation_id=' + encodeURIComponent(conversationId);

        var headers = {};

        if (settings.token) {
            headers['X-Chat-Token'] = settings.token;
        }

        return fetch(url, { method: 'GET', headers: headers })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                if (!body || !body.ok || !Array.isArray(body.messages)) {
                    return;
                }

                body.messages.forEach(function (message) {
                    addMessage(message.role === 'user' ? 'user' : 'bot', String(message.content || ''));
                });
            })
            .catch(function () {
                // Unreachable or the conversation no longer exists — carry on
                // with an empty log rather than blocking the widget on this.
            });
    }

    // ── Boot ─────────────────────────────────────────────────────────────────
    function applyOverrides() {
        ['title', 'subtitle', 'welcome', 'placeholder', 'launcherLabel', 'accent', 'accentText', 'position']
            .forEach(function (key) {
                if (settings[key]) {
                    config[key] = settings[key];
                }
            });

        if (settings.typewriter !== null) {
            config.typewriter = settings.typewriter;
        }
    }

    function boot() {
        // Load stored form data if it exists from a previous session
        loadFormData();
        if (formData) {
            formSubmitted = true;
        }

        // Server config first, data- attributes on top: central branding with a
        // per-page escape hatch.
        fetch(settings.configEndpoint, { method: 'GET' })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                if (body && body.ok && body.config) {
                    Object.keys(body.config).forEach(function (key) {
                        if (body.config[key] !== null && body.config[key] !== undefined) {
                            config[key] = body.config[key];
                        }
                    });
                }
            })
            .catch(function () {
                // Unreachable config is not fatal — the defaults above are a
                // complete, usable widget.
            })
            .then(function () {
                applyOverrides();
                build();

                return loadHistory();
            })
            .then(function () {
                if (settings.openOnLoad || wasOpenBefore()) {
                    open();
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
