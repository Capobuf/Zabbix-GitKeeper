/* Read-only polling; mutations use native forms and the Zabbix CSRF token. */
document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('gitkeeper-overview')) return;
    let previous = document.getElementById('gitkeeper-overview').dataset.fingerprint;
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
            const fingerprint = JSON.stringify([state.last_request ?? null, state.state ?? null, state.device ?? null,
                state.last_commit ?? null, state.categories ?? null, state.account ?? null]);
            if (previous !== undefined && previous !== fingerprint
                    && !dirty && !document.querySelector('form input:focus, form select:focus')) {
                window.location.reload();
                return;
            }
            previous = fingerprint;
            for (const key of ['state', 'github', 'account', 'error']) {
                const element = document.getElementById('gitkeeper-' + key);
                if (element) element.textContent = state[key] ?? '-';
            }
        } catch (_) {
            const element = document.getElementById('gitkeeper-error');
            if (element) element.textContent = 'Status unavailable. Reload the page and check your session.';
        }
        setTimeout(poll, 5000);
    }
    setTimeout(poll, 5000);
});
