(() => {
    'use strict';

    const root = document.querySelector('[data-scan-progress]');
    if (!(root instanceof HTMLElement)) return;

    const endpoint = root.dataset.scanEndpoint || '';
    if (endpoint === '') return;

    const stateNode = root.querySelector('[data-scan-state]');
    const stageNode = root.querySelector('[data-scan-stage]');
    const countNode = root.querySelector('[data-scan-count]');
    const meter = root.querySelector('[data-scan-meter]');
    const errorNode = root.querySelector('[data-scan-error]');
    const reload = root.querySelector('[data-scan-reload]');
    const knownStates = ['queued', 'running', 'completed', 'partial', 'failed'];
    let timer = null;
    let request = null;
    let failures = 0;

    const stop = () => {
        if (timer !== null) window.clearTimeout(timer);
        timer = null;
        request?.abort();
        request = null;
    };

    const schedule = () => {
        if (timer !== null) window.clearTimeout(timer);
        timer = window.setTimeout(refresh, document.hidden ? 8000 : 2500);
    };

    const showError = (message) => {
        if (!(errorNode instanceof HTMLElement)) return;
        errorNode.textContent = message;
        errorNode.hidden = message === '';
    };

    const render = (payload) => {
        const job = payload && typeof payload.job === 'object' ? payload.job : null;
        if (!job) {
            stop();
            return;
        }

        const state = knownStates.includes(String(job.state)) ? String(job.state) : 'queued';
        if (stateNode instanceof HTMLElement) {
            knownStates.forEach((value) => stateNode.classList.remove(value));
            stateNode.classList.add(state);
            stateNode.textContent = state.charAt(0).toUpperCase() + state.slice(1);
        }
        if (stageNode instanceof HTMLElement) stageNode.textContent = String(job.stage || 'Analysis status unavailable');

        const current = Math.max(0, Number(job.progress_current) || 0);
        const total = Math.max(0, Number(job.progress_total) || 0);
        const terminal = Boolean(job.terminal);
        if (countNode instanceof HTMLElement) countNode.textContent = !terminal && total > 0 ? `${Math.min(current, total)} of ${total} scan stages` : '';
        if (meter instanceof HTMLProgressElement) {
            meter.hidden = terminal || total < 1;
            meter.max = Math.max(1, total);
            meter.value = Math.min(current, total);
        }

        showError(typeof job.error === 'string' ? job.error : '');
        if (reload instanceof HTMLElement) reload.hidden = !job.terminal || !payload.evidence_ready;

        if (terminal) stop();
        else schedule();
    };

    async function refresh() {
        if (request !== null) return;
        const controller = new AbortController();
        request = controller;
        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                signal: controller.signal,
            });
            const body = await response.text();
            let payload;
            try { payload = JSON.parse(body); } catch { payload = {}; }
            if (!response.ok) throw new Error(String(payload.error || 'Scan status is temporarily unavailable.'));
            failures = 0;
            render(payload);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            failures += 1;
            if (failures >= 2) showError(error instanceof Error ? error.message : 'Scan status is temporarily unavailable.');
            schedule();
        } finally {
            if (request === controller) request = null;
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && timer !== null) {
            window.clearTimeout(timer);
            timer = window.setTimeout(refresh, 100);
        }
    });
    window.addEventListener('pagehide', stop, { once: true });
    refresh();
})();
