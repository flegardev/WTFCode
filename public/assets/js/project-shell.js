(() => {
    'use strict';

    const dialog = document.querySelector('[data-ask-dialog]');
    if (!(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function') return;

    const form = dialog.querySelector('[data-ask-form]');
    const question = dialog.querySelector('[data-ask-question]');
    const submit = dialog.querySelector('[data-ask-submit]');
    const status = dialog.querySelector('[data-ask-status]');
    const loading = dialog.querySelector('[data-ask-loading]');
    const result = dialog.querySelector('[data-ask-result]');
    const answer = dialog.querySelector('[data-ask-answer]');
    const meta = dialog.querySelector('[data-ask-meta]');
    const inference = dialog.querySelector('[data-ask-inference]');
    const evidenceSection = dialog.querySelector('[data-ask-evidence-section]');
    const evidenceList = dialog.querySelector('[data-ask-evidence]');
    const symbolsSection = dialog.querySelector('[data-ask-symbols-section]');
    const symbolsList = dialog.querySelector('[data-ask-symbols]');
    const endpoint = dialog.dataset.endpoint || form?.action || '';
    const symbolBase = dialog.dataset.symbolBase || '';
    let opener = null;
    let requestController = null;

    const setStatus = (message, isError = false) => {
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', isError);
        status.setAttribute('role', isError ? 'alert' : 'status');
    };

    const setLoading = (active) => {
        form?.setAttribute('aria-busy', active ? 'true' : 'false');
        if (submit instanceof HTMLButtonElement) submit.disabled = active;
        if (loading instanceof HTMLElement) {
            loading.hidden = !active;
            loading.setAttribute('aria-hidden', active ? 'false' : 'true');
        }
    };

    const appendMeta = (label, value, className = '') => {
        if (!meta || !value) return;
        const item = document.createElement('span');
        if (className) item.className = className;
        const term = document.createElement('b');
        term.textContent = label;
        item.append(term, document.createTextNode(String(value)));
        meta.append(item);
    };

    const renderEvidence = (items) => {
        if (!evidenceList || !evidenceSection) return;
        evidenceList.replaceChildren();
        const evidence = Array.isArray(items) ? items : [];
        evidence.forEach((item) => {
            const row = document.createElement('li');
            const title = document.createElement('strong');
            title.textContent = `[${Number(item.id) || '?'}] ${String(item.label || 'Supporting evidence')}`;
            const location = document.createElement('span');
            const line = Number(item.line) > 0 ? `:${Number(item.line)}` : '';
            location.textContent = `${String(item.path || 'Location unavailable')}${line} | ${String(item.confidence || 'static')} confidence`;
            row.append(title, location);
            evidenceList.append(row);
        });
        evidenceSection.hidden = evidence.length === 0;
    };

    const renderSymbols = (items) => {
        if (!symbolsList || !symbolsSection) return;
        symbolsList.replaceChildren();
        const symbols = Array.isArray(items) ? items : [];
        symbols.forEach((item) => {
            const link = document.createElement('a');
            link.href = `${symbolBase}${encodeURIComponent(String(item.id || ''))}`;
            const title = document.createElement('strong');
            title.textContent = String(item.name || 'Unnamed symbol');
            const detail = document.createElement('span');
            detail.textContent = `${String(item.type || 'symbol').replaceAll('_', ' ')} in ${String(item.path || 'unknown file')}`;
            link.append(title, detail);
            symbolsList.append(link);
        });
        symbolsSection.hidden = symbols.length === 0;
    };

    const renderResult = (payload) => {
        if (!result || !answer || !meta) return;
        answer.textContent = String(payload.answer || 'The scanner did not return an answer.');
        meta.replaceChildren();
        appendMeta('Confidence', payload.confidence ? String(payload.confidence) : 'Not established', `confidence ${String(payload.confidence || 'low')}`);
        appendMeta('Provider', `${String(payload.provider || 'deterministic')}${payload.provider_fallback ? ' fallback' : ''}`);
        const inferences = Array.isArray(payload.inferences) ? payload.inferences : [];
        if (inference) {
            inference.hidden = inferences.length === 0;
            inference.textContent = inferences.length === 0 ? '' : `${inferences.length} sentence${inferences.length === 1 ? '' : 's'} include explicitly labeled inference.`;
        }
        renderEvidence(payload.evidence);
        renderSymbols(payload.symbols);
        result.hidden = false;
        result.focus({ preventScroll: true });
        result.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    };

    const openDialog = (trigger = null) => {
        opener = trigger instanceof HTMLElement ? trigger : document.activeElement;
        const suggested = trigger instanceof HTMLElement ? trigger.dataset.askQuestion : '';
        if (suggested && question instanceof HTMLTextAreaElement) question.value = suggested;
        if (!dialog.open) dialog.showModal();
        document.body.classList.add('ask-is-open');
        window.requestAnimationFrame(() => question?.focus());
    };

    const closeDialog = () => {
        if (dialog.open) dialog.close();
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-ask-open]') : null;
        if (!trigger) return;
        event.preventDefault();
        openDialog(trigger);
    });

    dialog.querySelectorAll('[data-ask-close]').forEach((button) => button.addEventListener('click', closeDialog));
    dialog.querySelectorAll('[data-ask-suggestion]').forEach((button) => button.addEventListener('click', () => {
        if (!(question instanceof HTMLTextAreaElement)) return;
        question.value = button.dataset.askSuggestion || button.textContent || '';
        question.focus();
    }));

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog();
    });
    dialog.addEventListener('close', () => {
        document.body.classList.remove('ask-is-open');
        requestController?.abort();
        requestController = null;
        setLoading(false);
        if (opener instanceof HTMLElement) opener.focus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key.toLowerCase() !== 'k' || (!event.ctrlKey && !event.metaKey) || event.altKey) return;
        event.preventDefault();
        openDialog();
    });

    const macPlatform = /Mac|iPhone|iPad/.test(navigator.platform);
    document.querySelectorAll('[data-shortcut-label]').forEach((label) => { label.textContent = macPlatform ? 'Cmd K' : 'Ctrl K'; });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!(form instanceof HTMLFormElement) || !form.reportValidity() || endpoint === '') return;
        requestController?.abort();
        const controller = new AbortController();
        requestController = controller;
        setLoading(true);
        setStatus('Searching stored evidence...');
        if (result instanceof HTMLElement) result.hidden = true;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            const body = await response.text();
            let payload;
            try { payload = JSON.parse(body); } catch { payload = {}; }
            if (!response.ok) throw new Error(String(payload.error || 'The question could not be answered. Please try again.'));
            renderResult(payload);
            setStatus('Answer ready.');
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            setStatus(error instanceof Error ? error.message : 'The question could not be answered. Please try again.', true);
        } finally {
            if (requestController === controller) {
                setLoading(false);
                requestController = null;
            }
        }
    });
})();
