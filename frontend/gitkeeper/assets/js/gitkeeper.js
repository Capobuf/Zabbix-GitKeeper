/* Read-only polling; mutations use native forms and the Zabbix CSRF token. */
document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('gitkeeper-overview')) return;
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-gitkeeper-tab]');
        if (!link) return;
        const header = document.getElementById('tab_' + link.dataset.gitkeeperTab);
        if (header) {
            event.preventDefault();
            header.click();
            header.focus();
        }
    });
    const syncForm = document.getElementById('gitkeeper-form-sync');
    if (syncForm) syncForm.addEventListener('submit', () => {
        const feedback = document.getElementById('gitkeeper-sync-feedback');
        feedback.textContent = feedback.dataset.submitting;
    });
    const configurationFeedback = document.getElementById('gitkeeper-configuration-feedback');
    document.getElementById('gitkeeper-form-configuration')?.addEventListener('submit', () => {
        configurationFeedback.textContent = configurationFeedback.dataset.submitting;
    });
    const syncIndicator = document.getElementById('gitkeeper-synchronization');
    const syncFeedback = document.getElementById('gitkeeper-sync-feedback');
    const syncRequest = document.getElementById('gitkeeper-overview').dataset.syncRequest ?? '';
    const updateSynchronization = info => {
        if (!syncIndicator || !info) return;
        const busy = ['Queued', 'Saving', 'Running', 'Publishing'].includes(info.state);
        syncIndicator.textContent = info.label;
        syncIndicator.classList.remove('green', 'red', 'grey');
        syncIndicator.classList.add(['Incomplete', 'Failed'].includes(info.state) ? 'red' : busy ? 'grey' : 'green');
        syncFeedback.textContent = info.message;
        configurationFeedback.textContent = info.message;
        document.getElementById('gitkeeper-next-step').textContent = info.state === 'Incomplete' && info.missing.length
            ? info.missing[0][0] + ' ' + info.missing[0][1] + '.' : info.message;
        const missing = document.getElementById('gitkeeper-sync-requirements');
        missing.replaceChildren(...info.missing.map(([reason, action, tab]) => {
            const item = document.createElement('li');
            item.append(document.createTextNode(reason + ' '));
            const link = document.createElement('a');
            link.href = '#' + tab;
            link.dataset.gitkeeperTab = tab;
            link.textContent = action;
            item.append(link);
            return item;
        }));
        missing.hidden = info.ready || busy;
        document.getElementById('gitkeeper-sync-blocked').hidden = info.ready || busy;
        document.getElementById('gitkeeper-submit-sync').disabled = !info.ready || busy;
        const save = document.getElementById('gitkeeper-submit-save');
        save.disabled = save.dataset.prerequisitesReady !== '1' || !info.can_save || busy;
    };
    const updateCategories = categories => {
        const table = document.getElementById('gitkeeper-category-results');
        if (!table) return;
        const results = categories ?? {};
        table.hidden = !Object.keys(results).length;
        for (const category of ['hosts', 'templates', 'actions', 'media_types', 'users']) {
            const result = results[category];
            document.getElementById('gitkeeper-category-row-' + category).hidden = !result;
            document.getElementById('gitkeeper-category-state-' + category).textContent = result?.state ?? '-';
            document.getElementById('gitkeeper-category-objects-' + category).textContent = result?.objects ?? '-';
            document.getElementById('gitkeeper-category-progress-' + category).textContent = result?.total != null
                ? (result.processed ?? 0) + ' / ' + result.total : '-';
            document.getElementById('gitkeeper-category-excluded-' + category).textContent = result?.excluded_fields?.length ?? 0;
        }
    };
    const verificationPanel = document.getElementById('gitkeeper-api-verification');
    const apiRequest = verificationPanel?.dataset.requestId ?? '';
    const updateVerification = (state, error = '') => {
        if (!verificationPanel) return;
        const titles = JSON.parse(verificationPanel.dataset.titles);
        const messages = JSON.parse(verificationPanel.dataset.messages);
        if (!titles[state]) state = 'Unavailable';
        const title = document.getElementById('gitkeeper-api-verification-title');
        title.textContent = titles[state];
        title.classList.remove('green', 'red', 'grey');
        title.classList.add(state === 'Verified' ? 'green' : state === 'Failed' ? 'red' : 'grey');
        document.getElementById('gitkeeper-api-verification-message').textContent = messages[state];
        document.getElementById('gitkeeper-api-verification-error').textContent = error ?? '';
        document.getElementById('gitkeeper-api-verification-next').hidden = state !== 'Verified';
    };
    for (const id of ['gitkeeper-form-bootstrap', 'gitkeeper-form-api']) {
        document.getElementById(id)?.addEventListener('submit', () => updateVerification('Submitting'));
    }
    const apiUrls = ['gitkeeper-bootstrap-url', 'gitkeeper-existing-url'].map(id => document.getElementById(id));
    const apiSource = document.getElementById('gitkeeper-api-url-source');
    const apiWarning = document.getElementById('gitkeeper-api-url-warning');
    if (!apiUrls[0].value) {
        const detected = new URL('api_jsonrpc.php', window.location.href).href;
        apiUrls.forEach(input => { input.value = detected; });
        apiSource.textContent = apiSource.dataset.currentLabel;
    }
    const updateApiWarning = () => {
        apiWarning.textContent = apiUrls[0].value.startsWith('http:')
            ? apiWarning.dataset.httpWarning : '';
    };
    updateApiWarning();
    apiUrls.forEach(input => input.addEventListener('input', () => {
        apiUrls.forEach(other => { if (other !== input) other.value = input.value; });
        apiSource.textContent = apiSource.dataset.customLabel;
        updateApiWarning();
    }));
    const tlsChecks = ['gitkeeper-bootstrap-tls', 'gitkeeper-existing-tls'].map(id => document.getElementById(id));
    tlsChecks.forEach(input => input.addEventListener('change', () => {
        tlsChecks.forEach(other => { if (other !== input) other.checked = input.checked; });
    }));
    document.getElementById('gitkeeper-use-loopback').addEventListener('click', () => {
        try {
            const local = new URL(apiUrls[0].value);
            local.hostname = '127.0.0.1';
            apiUrls.forEach(input => { input.value = local.href; });
            apiSource.textContent = apiSource.dataset.customLabel;
            dirty = true;
            updateApiWarning();
        } catch (_) {
            apiUrls[0].reportValidity();
        }
    });
    const copy = document.getElementById('gitkeeper-copy-install');
    if (copy) {
        copy.addEventListener('click', async () => {
            const command = document.getElementById('gitkeeper-install-command');
            const result = document.getElementById('gitkeeper-copy-result');
            try {
                await writeTextClipboard(command.value);
                result.textContent = result.dataset.copied;
            } catch (_) {
                command.focus();
                command.select();
                result.textContent = result.dataset.fallback;
            }
        });
    }
    // PHP escapes URL slashes in JSON; compare normalized values to avoid reloading
    // every poll while a device authorization URL is present.
    let previous = JSON.stringify(JSON.parse(document.getElementById('gitkeeper-overview').dataset.fingerprint));
    let dirty = false;
    document.addEventListener('input', () => { dirty = true; });
    document.addEventListener('change', () => { dirty = true; });
    let stopped = false;
    window.addEventListener('pagehide', () => { stopped = true; });
    async function poll() {
        if (stopped) return;
        try {
            const response = await fetch('zabbix.php?action=gitkeeper.status'
                + (apiRequest ? '&api_request=' + encodeURIComponent(apiRequest) : '')
                + (syncRequest ? '&sync_request=' + encodeURIComponent(syncRequest) : ''), {
                credentials: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) throw new Error('Status unavailable');
            const state = await response.json();
            if (syncIndicator && !state.synchronization) throw new Error('Status unavailable');
            // These results must stay live even when form edits defer a full page reload.
            updateSynchronization(state.synchronization);
            updateCategories(state.categories);
            updateVerification(state.zabbix_verification?.state ?? (state.zabbix_configured ? 'Verified' : 'Not configured'),
                state.zabbix_verification?.error);
            const apiConfigured = document.getElementById('gitkeeper-api-configured');
            if (apiConfigured) apiConfigured.textContent = state.zabbix_configured
                ? apiConfigured.dataset.configured : apiConfigured.dataset.unconfigured;
            const fingerprint = JSON.stringify([state.last_request ?? null, state.state ?? null, state.device ?? null,
                state.last_commit ?? null, state.account ?? null]);
            if (previous !== undefined && previous !== fingerprint
                    && !dirty && !document.querySelector('form input:focus, form select:focus')) {
                window.location.reload();
                return;
            }
            // Keep the rendered fingerprint until reload. A change observed while an
            // input has focus must still trigger refresh once focus leaves the form.
            for (const key of ['state', 'github', 'account', 'error']) {
                for (const prefix of ['gitkeeper-', 'gitkeeper-connection-', 'gitkeeper-api-']) {
                    const element = document.getElementById(prefix + key);
                    if (element) element.textContent = key === 'github' && prefix === 'gitkeeper-connection-'
                        ? (state.github === 'Ready' ? element.dataset.connected
                            : state.device ? element.dataset.pending : element.dataset.disconnected)
                        : state[key] ?? '-';
                }
            }
        } catch (_) {
            updateVerification('Unavailable');
            if (syncIndicator) {
                syncIndicator.textContent = syncFeedback.dataset.unavailable;
                syncIndicator.classList.remove('green', 'red');
                syncIndicator.classList.add('grey');
                syncFeedback.textContent = syncFeedback.dataset.unavailable;
                configurationFeedback.textContent = syncFeedback.dataset.unavailable;
            }
            for (const id of ['gitkeeper-error', 'gitkeeper-connection-error', 'gitkeeper-api-error']) {
                const element = document.getElementById(id);
                if (element) element.textContent = 'Status unavailable. Reload the page and check your session.';
            }
        }
        setTimeout(poll, 5000);
    }
    setTimeout(poll, 5000);
});
