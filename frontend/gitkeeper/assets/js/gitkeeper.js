/* Native POST forms retain CSRF validation. Polling only reads public worker state. */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('gitkeeper');
    if (!root) return;
    const ui = JSON.parse(root.dataset.ui);
    const get = id => document.getElementById('gitkeeper-' + id);
    const text = (id, value) => { const element = get(id); if (element) element.textContent = value ?? '—'; };
    const edited = new Set();
    let rendering = false;
    let previousActive;
    let operational = false;
    let lastState = JSON.parse(root.dataset.status);
    let inFlight = false;
    let stopped = false;
    let timer;
    let requestFinished = false;
    let repositoriesKey = '';
    const busyStates = ['Queued', 'Saving', 'Running', 'Publishing'];
    const forms = [...root.querySelectorAll('form[data-gitkeeper-form]')];
    root.querySelectorAll('fieldset.collapsible').forEach(fieldset => new CFormFieldsetCollapsible(fieldset));
    const date = value => Number.isFinite(value) ? new Date(value * 1000).toLocaleString() : '—';
    const intervalParts = minutes => [Math.floor(minutes / 1440), Math.floor(minutes % 1440 / 60), minutes % 60];
    const durationInputs = ['days', 'hours', 'minutes'].map(unit => get('interval-' + unit));
    const showCustomInterval = () => {
        const custom = String(get('interval').value) === '0';
        get('custom-interval').hidden = !custom;
        durationInputs.forEach(input => { input.disabled = !custom; });
        const [days, hours, minutes] = durationInputs.map(input => Number(input.value));
        const total = days * 1440 + hours * 60 + minutes;
        durationInputs[2].setCustomValidity(custom && (total < 1 || total > ui.max_interval) ? ui.invalid_interval : '');
    };
    const setEnabled = (operation, enabled) => {
        const button = get('submit-' + operation);
        if (button) button.disabled = !enabled || button.closest('form').dataset.submitting === '1';
    };
    const escapeLabel = value => {
        const span = document.createElement('span');
        span.textContent = value;
        return span.innerHTML; // Zabbix ZSelect renders option labels as templates.
    };
    const updateRepositories = state => {
        const repos = (state.repositories ?? []).filter(repo => repo.private === true);
        const key = JSON.stringify(repos);
        const select = get('repository');
        if (!select || key === repositoriesKey || select.contains(document.activeElement)) return;
        const oldValue = select.value;
        const saved = state.settings?.repository_id;
        const value = edited.has('gitkeeper-repository') ? oldValue : saved ?? (repos.length === 1 ? repos[0].id : 0);
        const options = [{value: 0, label: escapeLabel(ui.select)}, ...repos.map(repo => ({
            value: repo.id, label: escapeLabel(repo.full_name + ' (' + repo.default_branch + ')')
        }))];
        if (value && !options.some(option => String(option.value) === String(value))) {
            options.push({value, label: escapeLabel(ui.none + ' (' + value + ')')});
        }
        // Recreate just this native custom element; retain the user's unconfirmed choice.
        const replacement = select.cloneNode(false);
        replacement.setAttribute('data-options', JSON.stringify(options));
        replacement.setAttribute('value', String(value));
        replacement.setAttribute('focusable-element-id', 'gitkeeper-repository-focus');
        select.replaceWith(replacement);
        repositoriesKey = key;
    };
    const stepFor = operation => ({set_zabbix: 1, clear_zabbix: 1, connect: 2, disconnect: 2,
        set_github_app: 2, test_github: 2, repositories: 3, select_repository: 3,
        create_repository: 3, save: 4, sync: 4})[operation];
    const render = state => {
        rendering = true;
        try {
            lastState = state;
            const sync = state.synchronization;
            const setup = state.setup;
            const ready = Boolean(state.available_categories?.length);
            const authorized = state.github === 'Ready';
            const syncBusy = busyStates.includes(sync.state);
            const current = state.current_request;
            const result = [state.last_sync, state.last_result].find(item => item?.id === root.dataset.requestId);
            const ownRunning = root.dataset.requestId && current?.id === root.dataset.requestId;
            const ownPending = root.dataset.requestId && state.request_pending;
            const obsoleteQueued = result?.state === 'Queued' && !ownRunning && !ownPending && !syncBusy;
            if ((result && result.state !== 'Queued') || (!ownRunning && !ownPending
                    && state.last_result?.id && state.last_result.id !== root.dataset.requestId)
                    || obsoleteQueued) requestFinished = true;
            const ownOperation = ownRunning ? current.operation : result?.operation ?? root.dataset.operation;
            text('operation-feedback', !root.dataset.requestId ? '' : ownPending ? ui.queued : ownRunning ? ui.running
                : result?.state === 'Failed' ? (result.error ?? ui.failed)
                : obsoleteQueued ? ui.unknown_result : result?.state === 'Queued' ? sync.message
                : result?.state === 'Completed' ? ui.done : ui.unknown_result);
            text('sync-feedback', sync.message);
            text('synchronization', sync.label);
            get('synchronization')?.classList.toggle('red', sync.state === 'Failed');
            get('synchronization')?.classList.toggle('green', sync.state === 'Completed' && !state.error);
            text('error', [...new Set([sync.error, state.error].filter(Boolean))].join('\n'));
            get('error').hidden = !(sync.error || state.error);
            text('worker-warning', state.worker_stale ? ui.stale : '');
            text('installation-state', ready ? ui.installed : state.worker_availability === 'not_installed'
                ? ui.not_installed : ui.worker_unavailable);
            text('connection', authorized ? ui.authorized : state.device ? ui.device_pending : ui.disconnected);
            text('account', state.account);
            text('connection-error', state.error && ['connect', 'repositories', 'test_github', 'create_repository', 'select_repository']
                .includes(state.operation) ? state.error : '');
            text('app-state', state.github_client_id ? ui.app_saved : ui.app_absent);
            text('installation-access', state.repositories?.length ? ui.access : ui.no_access);
            const deviceExpired = state.device && state.device.expires_at * 1000 <= Date.now();
            get('device').hidden = !state.device || deviceExpired;
            text('device-code', state.device?.user_code ?? '');
            if (deviceExpired) text('connection', ui.expired);
            const verification = state.zabbix_verification;
            const apiPending = verification?.state === 'Queued' || verification?.state === 'Running';
            text('api-verification', apiPending ? ui.api_running : verification?.state === 'Failed'
                ? ui.api_failed : state.zabbix_configured ? ui.api_verified : ui.api_absent);
            text('api-verification-error', verification?.error ?? '');
            text('last-success', date(state.last_success));
            text('next-sync', state.settings?.next_sync ? date(state.settings.next_sync) : ui.paused);
            text('frequency', state.settings?.interval ? intervalParts(state.settings.interval)
                .map((value, index) => value ? value + ' ' + (value === 1
                    ? [ui.day, ui.hour, ui.minute] : [ui.days, ui.hours, ui.minutes])[index] : '')
                .filter(Boolean).join(' ') : '—');
            const repo = state.repository;
            text('destination', repo ? repo.full_name + ' (' + repo.default_branch + ')' : ui.none);
            text('summary-repository', repo ? repo.full_name + ' (' + repo.default_branch + ')' : ui.none);
            const commit = get('commit');
            commit.hidden = !state.last_commit || !repo;
            if (state.last_commit && repo && /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repo.full_name)
                    && /^[a-f0-9]{40,64}$/.test(state.last_commit)) {
                commit.textContent = state.last_commit.slice(0, 12);
                commit.href = 'https://github.com/' + repo.full_name + '/commit/' + state.last_commit;
            }
            get('saved-repository').value = state.settings?.repository_id ?? 0;
            updateRepositories(state);
            const client = root.querySelector('[name="client_id"]');
            if (!edited.has(client.id) && document.activeElement !== client) client.value = state.github_client_id ?? '';
            const owner = root.querySelector('[name="repository_owner"]');
            if (!edited.has(owner.id) && document.activeElement !== owner) owner.value = state.account ?? '';
            for (const category of ['hosts', 'templates', 'actions', 'media_types', 'users']) {
                const result = state.categories?.[category];
                get('category-row-' + category).hidden = !result;
                text('category-state-' + category, ui.category_states[result?.state] ?? '—');
                text('category-objects-' + category, result?.objects);
                text('category-progress-' + category, result?.total != null ? (result.processed ?? 0) + ' / ' + result.total : '—');
                text('category-excluded-' + category, result?.excluded_fields?.length ?? 0);
                const check = get('category-' + category);
                check.disabled = !state.available_categories?.includes(category);
                if (!edited.has(check.id)) check.checked = (state.settings?.categories ?? state.available_categories ?? []).includes(category);
            }
            get('category-results').hidden = !Object.keys(state.categories ?? {}).length;
            text('excluded', JSON.stringify(Object.fromEntries(Object.entries(state.categories ?? {})
                .map(([category, result]) => [category, result.excluded_fields ?? []])), null, 2));
            const interval = get('interval');
            if (![interval, ...durationInputs].some(input => edited.has(input.id) || input.contains(document.activeElement))) {
                const minutes = state.settings?.interval ?? ui.default_interval;
                interval.value = ui.intervals.includes(minutes) ? minutes : 0;
                intervalParts(minutes).forEach((value, index) => { durationInputs[index].value = value; });
            }
            showCustomInterval();
            const missing = get('sync-requirements');
            missing.replaceChildren(...sync.missing.map(([reason, action, target]) => {
                const li = document.createElement('li');
                li.append(document.createTextNode(reason + ' '));
                const link = document.createElement('a');
                link.href = '#' + target;
                link.dataset.gitkeeperTarget = target;
                link.textContent = action;
                li.append(link);
                return li;
            }));
            missing.hidden = sync.ready || syncBusy;
            setEnabled('sync', ready && sync.ready && !syncBusy);
            setEnabled('save', ready && sync.can_save && !syncBusy);
            setEnabled('set_github_app', ready && !current);
            setEnabled('connect', ready && Boolean(state.github_client_id) && !authorized && (!state.device || deviceExpired) && !current);
            for (const op of ['repositories', 'test_github', 'create_repository']) setEnabled(op, ready && authorized && !current);
            setEnabled('select_repository', ready && authorized && Boolean(state.repositories?.length) && !current);
            setEnabled('create_zabbix_token', ready && !apiPending && !current);
            setEnabled('set_zabbix', ready && !apiPending && !current);
            setEnabled('clear_zabbix', ready && state.zabbix_configured && !current);
            setEnabled('disconnect', ready && !current);
            operational ||= setup.operational || Boolean(state.last_success);
            get('wizard').hidden = operational;
            get('operational').hidden = !operational;
            get('setup-link').hidden = operational;
            get('app-settings-link').hidden = !operational;
            for (let index = 0; index < 5; index++) {
                const element = get('step-' + index);
                const working = (current && stepFor(current.operation) === index)
                    || (index === 4 && syncBusy) || (index === 1 && apiPending) || (index === 2 && state.device && !deviceExpired);
                const failed = (index === 4 && sync.state === 'Failed') || (index === 1 && verification?.state === 'Failed')
                    || (stepFor(ownOperation) === index && result?.state === 'Failed')
                    || (state.error && stepFor(state.operation) === index);
                text('step-status-' + index, working ? ui.running : failed ? ui.failed : setup.steps[index] ? ui.complete : ui.todo);
                const destination = operational ? get(index === 4 ? 'settings-sync' : 'settings-connections') : get('wizard');
                if (element.parentElement !== destination) destination.append(element);
                if (operational && previousActive === undefined && root.dataset.requestId
                        && stepFor(root.dataset.operation) === index) element.open = true;
                if (!operational && setup.active !== previousActive && index === setup.active) element.open = true;
                if (!operational && setup.active !== previousActive && index === previousActive && !element.contains(document.activeElement)) element.open = false;
            }
            if (operational) get('step-4').open = true;
            if (operational && get('app-config').parentElement !== get('settings-app')) {
                get('settings-app').append(get('app-config'));
            }
            if (operational && previousActive === undefined && root.dataset.operation === 'set_github_app') {
                const fieldset = get('settings-app').closest('fieldset.collapsible');
                if (fieldset?.classList.contains('collapsed')) fieldset.querySelector('.toggle')?.click();
            }
            previousActive = setup.active;
            // Prevent duplicate submissions of the operation still waiting in the queue.
            if (ownPending || ownRunning) {
                const op = root.dataset.operation;
                if (op) setEnabled(op, false);
            }
        } finally { rendering = false; }
    };
    const markEdited = event => {
        // Native ZSelect emits a CustomEvent even for a user's selection.
        if (rendering) return;
        const element = event.target.closest('z-select') ?? event.target;
        if (element.id) edited.add(element.id);
        if (element === get('interval') || durationInputs.includes(element)) showCustomInterval();
    };
    root.addEventListener('input', markEdited);
    root.addEventListener('change', markEdited);
    root.addEventListener('focusout', () => { if (!rendering) render(lastState); });
    const apiUrls = ['bootstrap-url', 'api-url'].map(get);
    const tls = ['bootstrap-tls', 'api-tls'].map(get);
    apiUrls.forEach(input => input.addEventListener('input', () => {
        apiUrls.forEach(other => { if (other !== input) { other.value = input.value; edited.add(other.id); } });
        text('api-url-warning', input.value.startsWith('http:') ? ui.http : '');
    }));
    tls.forEach(input => input.addEventListener('change', () => tls.forEach(other => { other.checked = input.checked; })));
    text('api-url-warning', apiUrls[0].value.startsWith('http:') ? ui.http : '');
    const registration = () => {
        const link = get('register-app');
        const url = new URL(get('app-config').dataset.registrationUrl);
        const organization = get('app-organization').value.trim();
        if (organization && /^[A-Za-z0-9][A-Za-z0-9-]{0,99}$/.test(organization)) {
            url.pathname = '/organizations/' + organization + '/settings/apps/new';
        }
        get('app-creation-guide').hidden = !get('app-creation').checked;
        link.href = url.href;
    };
    get('app-organization').addEventListener('input', registration);
    get('app-creation').addEventListener('change', registration);
    root.addEventListener('click', async event => {
        const copy = event.target.closest('[data-copy]');
        if (copy) {
            const source = document.getElementById(copy.dataset.copy);
            try {
                await writeTextClipboard(source.value ?? source.textContent);
                text('copy-result', ui.copied);
            } catch (_) {
                source.focus();
                if (source.select) source.select();
                text('copy-result', ui.copy_failed);
            }
        }
        const link = event.target.closest('a[href^="#gitkeeper-"]');
        if (link) {
            event.preventDefault();
            const target = document.querySelector(link.getAttribute('href'));
            const panel = target?.closest('[id^="gitkeeper-tab-"]');
            if (panel) document.getElementById('tab_' + panel.id)?.click();
            if (target?.tagName === 'DETAILS') target.open = true;
            const fieldset = target?.closest('fieldset.collapsible');
            if (fieldset?.classList.contains('collapsed')) fieldset.querySelector('.toggle')?.click();
            target?.scrollIntoView({block: 'nearest'});
        }
    });
    forms.forEach(form => form.addEventListener('submit', event => {
        const button = event.submitter;
        if (form.dataset.submitting === '1' || !button) { event.preventDefault(); return; }
        if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) { event.preventDefault(); return; }
        if (button.value === 'set_github_app' && lastState.github_client_id
                && root.querySelector('[name="client_id"]').value.trim() !== lastState.github_client_id
                && !window.confirm(ui.change_app)) { event.preventDefault(); return; }
        // Disabled submitters are omitted from native POST: preserve the validated operation explicitly.
        const operation = document.createElement('input');
        operation.type = 'hidden'; operation.name = 'operation'; operation.value = button.value;
        form.append(operation);
        form.dataset.submitting = '1';
        form.querySelectorAll('[type="submit"]').forEach(item => { item.disabled = true; });
        text('operation-feedback', ui.sent);
    }));
    const active = () => Boolean(lastState.device || lastState.current_request || lastState.queued_requests
        || busyStates.includes(lastState.synchronization?.state) || (root.dataset.requestId && !requestFinished));
    const schedule = delay => {
        clearTimeout(timer);
        if (!stopped && !document.hidden) timer = setTimeout(poll, delay);
    };
    async function poll() {
        if (stopped || document.hidden || inFlight) return;
        inFlight = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const url = new URL('zabbix.php', window.location.href);
            url.searchParams.set('action', 'gitkeeper.status');
            for (const [key, value] of [['request_id', root.dataset.requestId], ['api_request', root.dataset.apiRequest], ['sync_request', root.dataset.syncRequest]]) {
                if (value) url.searchParams.set(key, value);
            }
            const response = await fetch(url.href, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if ([401, 403].includes(response.status) || response.redirected) throw new Error('session');
            if (!response.ok) throw new Error('unavailable');
            const body = await response.text();
            if (body.trimStart().startsWith('<')) throw new Error('session');
            const state = JSON.parse(body);
            if (!state.synchronization || !state.setup) throw new Error('unavailable');
            render(state);
        } catch (error) {
            text('worker-warning', error.message === 'session' ? ui.session : ui.unavailable);
            if (error.message === 'session') stopped = true;
        } finally {
            clearTimeout(timeout);
            inFlight = false;
            schedule(active() ? 3000 : 20000);
        }
    }
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) { if (inFlight) return; schedule(0); }
    });
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
    window.addEventListener('pageshow', () => { stopped = false; schedule(0); });
    render(lastState);
    schedule(0);
});
