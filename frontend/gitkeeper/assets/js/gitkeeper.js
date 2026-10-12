/* Read-only polling; mutations use native forms and the Zabbix CSRF token. */
document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('gitkeeper-overview')) return;
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
            const response = await fetch('zabbix.php?action=gitkeeper.status', {
                credentials: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) throw new Error('Status unavailable');
            const state = await response.json();
            const apiConfigured = document.getElementById('gitkeeper-api-configured');
            if (apiConfigured) apiConfigured.textContent = state.zabbix_configured
                ? apiConfigured.dataset.configured : apiConfigured.dataset.unconfigured;
            const fingerprint = JSON.stringify([state.last_request ?? null, state.state ?? null, state.device ?? null,
                state.last_commit ?? null, state.categories ?? null, state.account ?? null]);
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
            for (const id of ['gitkeeper-error', 'gitkeeper-connection-error', 'gitkeeper-api-error']) {
                const element = document.getElementById(id);
                if (element) element.textContent = 'Status unavailable. Reload the page and check your session.';
            }
        }
        setTimeout(poll, 5000);
    }
    setTimeout(poll, 5000);
});
