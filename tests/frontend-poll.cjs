// Exercise the actual asset with lightweight DOM/worker doubles; no live integration claims.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

async function fixture(initial = {}) {
    const elements = new Map();
    const ui = Object.fromEntries(['complete', 'todo', 'running', 'failed', 'processed', 'waiting', 'sent',
        'queued', 'done', 'unavailable', 'session', 'stale', 'installed', 'not_installed', 'worker_unavailable',
        'authorized', 'disconnected', 'device_pending', 'expired', 'api_verified', 'api_absent', 'api_running',
        'api_failed', 'app_saved', 'app_absent', 'access', 'no_access', 'select', 'none', 'paused', 'minutes',
        'days', 'hours', 'day', 'hour', 'minute', 'invalid_interval', 'copied', 'copy_failed', 'unknown_result', 'http', 'change_app'].map(key => [key, key]));
    ui.category_states = {Running: 'Running', Completed: 'Elaborata', exported: 'Elaborata', Failed: 'Failed'};
    ui.default_interval = 4320;
    ui.intervals = [5, 15, 30, 60, 1440, 4320];
    ui.max_interval = 2147483647;
    ui.change_scope = 'Excluded template files leave the current revision; Git history remains.';
    ui.preview_counts = 'Found {total}; selected {selected}; excluded {excluded}; manual {manual_included}; missing {missing_manual}';
    ui.scope_missing = 'Missing manual templates were ignored after inventory verification.';
    class Element {
        constructor(id = '', tag = 'SPAN') {
            this.id = id; this.tagName = tag; this.dataset = {}; this.children = []; this.listeners = new Map();
            this.textContent = ''; this.value = ''; this.checked = false; this.hidden = false; this.attributes = {};
            this.classList = {toggle() {}};
            if (id) elements.set(id, this);
        }
        get innerHTML() { return this.textContent.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'); }
        set innerHTML(_) { throw new Error('Unsafe HTML assignment'); }
        addEventListener(type, callback) { this.listeners.set(type, callback); }
        append(...items) {
            for (const item of items) {
                if (item.parentElement) item.parentElement.children = item.parentElement.children.filter(other => other !== item);
                item.parentElement = this; this.children.push(item);
            }
        }
        replaceChildren(...items) { this.children = []; this.append(...items); }
        contains(item) { return this === item || this.children.some(child => child.contains?.(item)); }
        closest(selector) {
            if (selector === 'form') return this.tagName === 'FORM' ? this : this.parentElement?.closest(selector);
            if (selector === 'z-select') return this.tagName === 'Z-SELECT' ? this : this.parentElement?.closest(selector);
            return null;
        }
        querySelectorAll(selector) { return selector === '[type="submit"]' ? this.children.filter(item => item.type === 'submit') : []; }
        cloneNode() { const copy = new Element('', this.tagName); copy.id = this.id; copy.attributes = {...this.attributes}; return copy; }
        setAttribute(key, value) { this.attributes[key] = value; if (key === 'value') this.value = value; }
        replaceWith(replacement) { elements.set(this.id, replacement); replacement.parentElement = this.parentElement; }
        focus() { document.activeElement = this; }
        setCustomValidity(message) { this.validationMessage = message; }
        select() {}
    }
    const get = key => elements.get('gitkeeper-' + key);
    const root = new Element('gitkeeper', 'DIV');
    root.dataset = {ui: JSON.stringify(ui), requestId: '', apiRequest: '', syncRequest: '', operation: ''};
    const forms = new Map();
    for (const op of ['sync', 'save', 'preview_scope', 'set_github_app', 'connect', 'repositories', 'test_github',
        'create_repository', 'select_repository', 'create_zabbix_token', 'set_zabbix', 'clear_zabbix', 'disconnect']) {
        const form = new Element('form-' + op, 'FORM');
        forms.set(op, form);
        const button = new Element('gitkeeper-submit-' + op, 'BUTTON');
        button.type = 'submit'; button.value = op; form.append(button);
    }
    for (const id of ['operation-feedback', 'sync-feedback', 'synchronization', 'error', 'worker-warning',
        'installation-state', 'connection', 'connection-error', 'account', 'app-state', 'installation-access', 'device',
        'device-code', 'api-verification', 'api-verification-error', 'last-success', 'next-sync', 'frequency',
        'destination', 'summary-repository', 'commit', 'saved-repository', 'category-results', 'excluded',
        'sync-requirements', 'wizard', 'operational', 'setup-link', 'settings-sync', 'settings-connections',
        'settings-app', 'app-settings-link', 'app-config', 'app-organization', 'app-creation', 'app-creation-guide', 'register-app', 'api-url-warning',
        'copy-result', 'bootstrap-url', 'api-url', 'bootstrap-tls', 'api-tls', 'scope-preview', 'scope-summary', 'template-manual',
        'template-manual-label']) new Element('gitkeeper-' + id);
    const templateMode = new Element('gitkeeper-template-mode', 'Z-SELECT');
    templateMode.value = initial.settings?.template_scope?.mode ?? (initial.settings?.categories ? 'all' : 'used');
    const manual = new Element('include_templateids_', 'DIV');
    manual.value = initial.settings?.template_scope?.include_templateids ?? [];
    get('app-config').dataset.registrationUrl = 'https://github.com/settings/apps/new?contents=write&public=false';
    for (let index = 0; index < 5; index++) {
        const step = new Element('gitkeeper-step-' + index, 'DETAILS');
        get('wizard').append(step); new Element('gitkeeper-step-status-' + index);
    }
    get('step-2').append(get('app-config'));
    for (const category of ['hosts', 'templates', 'actions', 'media_types', 'users']) {
        for (const prefix of ['category-', 'category-row-', 'category-state-', 'category-objects-', 'category-progress-', 'category-excluded-']) {
            new Element('gitkeeper-' + prefix + category);
        }
    }
    const client = new Element('client_id', 'INPUT');
    const owner = new Element('repository_owner', 'INPUT');
    const repository = new Element('gitkeeper-repository', 'Z-SELECT');
    root.append(repository);
    new Element('gitkeeper-interval', 'Z-SELECT');
    const custom = new Element('gitkeeper-custom-interval', 'DIV');
    for (const unit of ['days', 'hours', 'minutes']) custom.append(new Element('gitkeeper-interval-' + unit, 'INPUT'));
    root.querySelector = selector => selector.includes('client_id') ? client : owner;
    root.querySelectorAll = selector => selector === 'fieldset.collapsible' ? [] : [...forms.values()];
    const document = {hidden: false, activeElement: null, listeners: new Map(),
        getElementById: id => elements.get(id) ?? null, createElement: tag => new Element('', tag.toUpperCase()),
        createTextNode: value => ({textContent: value}), addEventListener(type, callback) { this.listeners.set(type, callback); }};
    let state = {state: 'Not configured', github: 'Disconnected', settings: {}, categories: {},
        available_categories: [], setup: {steps: [false, false, false, false, false], active: 0, operational: false},
        synchronization: {state: 'Incomplete', label: 'Incomplete', message: 'Finish setup', ready: false,
            can_save: false, missing: [['Worker absent', 'Install', 'gitkeeper-step-0']]}, ...initial};
    root.dataset.status = JSON.stringify(state);
    let responseType = 'json'; let calls = 0; let pendingFetch; let posts = [];
    const timers = new Map(); let nextTimer = 0;
    const window = {location: {href: 'https://zabbix.example/zabbix/zabbix.php', reload() { throw new Error('Automatic page reload'); }},
        confirm: () => true, listeners: new Map(), addEventListener(type, callback) { this.listeners.set(type, callback); }};
    class FormData {
        constructor(form) {
            this.entries = form ? [['csrf_token', 'fixture-csrf'], ['template_mode', templateMode.value],
                ...manual.value.map(id => ['include_templateids[]', id]), ['interval', 'invalid'], ['categories[]', 'hosts']] : [];
        }
        append(key, value) { this.entries.push([key, value]); }
        set(key, value) { this.entries = this.entries.filter(([name]) => name !== key); this.append(key, value); }
        [Symbol.iterator]() { return this.entries[Symbol.iterator](); }
    }
    const context = vm.createContext({document, window, URL, Date, AbortController, FormData,
        setTimeout: (callback, delay) => { timers.set(++nextTimer, {callback, delay}); return nextTimer; },
        clearTimeout: id => timers.delete(id), writeTextClipboard: async () => {},
        fetch: async (url, options) => {
            if (options.method === 'POST') {
                posts.push([...options.body]);
                return {ok: true, status: 200, redirected: false,
                    text: async () => JSON.stringify({request_id: 'e'.repeat(32), error: null})};
            }
            calls++; assert.match(url, /action=gitkeeper.status/);
            if (pendingFetch) await pendingFetch;
            if (responseType === 'offline') throw new Error('Network');
            return {ok: true, status: 200, redirected: false,
                text: async () => responseType === 'html' ? '<html>Sign in</html>' : JSON.stringify(state)};
        }});
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../frontend/gitkeeper/assets/js/gitkeeper.js'), 'utf8'), context);
    document.listeners.get('DOMContentLoaded')();
    const poll = async () => {
        const [id, item] = [...timers].find(([, item]) => item.delay !== 15000) ?? [];
        assert.ok(item, 'One next poll must be scheduled'); timers.delete(id); await item.callback();
    };
    return {get, root, document, window, forms, ui, poll, timers, client, owner, manual, posts,
        get calls() { return calls; }, get state() { return state; }, set state(value) { state = value; },
        set responseType(value) { responseType = value; }, set pendingFetch(value) { pendingFetch = value; }};
}
async function main() {
    const f = await fixture();
    assert.equal(f.get('interval').value, 4320);
    assert.equal(f.get('custom-interval').hidden, true);
    assert.equal(f.get('interval-days').disabled, true);
    const custom = await fixture({settings: {interval: 1507}});
    assert.equal(custom.get('interval').value, 0, 'Saved custom duration must select Personalizzato');
    assert.equal(custom.get('custom-interval').hidden, false);
    assert.deepEqual(['days', 'hours', 'minutes'].map(unit => custom.get('interval-' + unit).value), [1, 1, 7]);
    assert.equal(custom.get('frequency').textContent, '1 day 1 hour 7 minutes');
    custom.get('interval-hours').value = '5';
    custom.root.listeners.get('input')({target: custom.get('interval-hours')});
    custom.state.settings.interval = 4320;
    await custom.poll();
    assert.equal(custom.get('interval').value, 0, 'Polling must preserve the custom selection');
    assert.equal(custom.get('interval-hours').value, '5', 'Polling must preserve duration edits');
    for (const unit of ['days', 'hours', 'minutes']) {
        custom.get('interval-' + unit).value = '0';
        custom.root.listeners.get('input')({target: custom.get('interval-' + unit)});
    }
    assert.equal(custom.get('interval-minutes').validationMessage, custom.ui.invalid_interval);
    custom.get('interval-minutes').value = '1';
    custom.root.listeners.get('input')({target: custom.get('interval-minutes')});
    assert.equal(custom.get('interval-minutes').validationMessage, '');
    custom.get('interval').value = 30;
    custom.root.listeners.get('change')({target: custom.get('interval')});
    assert.equal(custom.get('custom-interval').hidden, true);
    assert.equal(custom.get('interval-minutes').disabled, true);
    custom.get('interval').value = 0;
    custom.root.listeners.get('change')({target: custom.get('interval')});
    assert.equal(custom.get('interval-minutes').value, '1', 'Switching to presets must retain the custom draft');
    custom.get('interval-days').value = '1491308'; custom.get('interval-hours').value = '2';
    custom.get('interval-minutes').value = '8';
    custom.root.listeners.get('input')({target: custom.get('interval-minutes')});
    assert.equal(custom.get('interval-minutes').validationMessage, custom.ui.invalid_interval);
    assert.equal(f.get('step-0').open, true);
    assert.equal(f.get('submit-sync').disabled, true);
    await f.poll();
    assert.equal(f.get('installation-state').textContent, f.ui.worker_unavailable);
    f.state.worker_availability = 'not_installed'; await f.poll();
    assert.equal(f.get('installation-state').textContent, f.ui.not_installed);
    f.state.available_categories = ['hosts', 'templates'];
    f.state.setup = {steps: [true, false, false, false, false], active: 1, operational: false};
    await f.poll(); assert.equal(f.get('step-1').open, true); assert.equal(f.get('category-hosts').checked, true);
    f.state.zabbix_verification = {state: 'Running'}; await f.poll();
    assert.equal(f.get('api-verification').textContent, f.ui.api_running);
    f.state.zabbix_verification = {state: 'Failed', error: 'Permission denied'}; await f.poll();
    assert.equal(f.get('api-verification-error').textContent, 'Permission denied');
    f.state.zabbix_configured = true; f.state.zabbix_verification = {state: 'Verified'};
    f.state.github_client_id = 'Iv1.fixture';
    f.state.device = {user_code: 'ABCD-EFGH', expires_at: Date.now() / 1000 + 1000}; await f.poll();
    assert.equal(f.get('device-code').textContent, 'ABCD-EFGH'); assert.equal(f.get('device').hidden, false);
    f.state.device.expires_at = 1; await f.poll(); assert.equal(f.get('connection').textContent, f.ui.expired);
    assert.equal(f.get('submit-connect').disabled, false);
    f.state.device = null; f.state.error = 'Authorization denied'; f.state.operation = 'connect'; await f.poll();
    assert.equal(f.get('connection-error').textContent, 'Authorization denied');
    f.state.error = null; f.state.github = 'Ready'; f.state.account = 'fixture'; await f.poll();
    assert.equal(f.get('connection').textContent, f.ui.authorized);
    assert.equal(f.get('installation-access').textContent, f.ui.no_access, 'Authorization must not imply installation/repo access');
    f.state.repositories = [{id: 42, full_name: 'fixture/private', default_branch: 'main<test>', private: true},
        {id: 43, full_name: 'fixture/public', default_branch: 'main', private: false}]; await f.poll();
    const options = JSON.parse(f.get('repository').attributes['data-options']);
    assert.equal(options.length, 2, 'Public repositories must be excluded');
    assert.match(options[1].label, /&lt;test&gt;/, 'Native option templates must escape remote branch labels');
    assert.equal(f.get('repository').value, '42');
    f.get('repository').value = '0';
    f.root.listeners.get('change')({target: f.get('repository'), isTrusted: false});
    f.state.repositories.push({id: 44, full_name: 'fixture/new', default_branch: 'main', private: true}); await f.poll();
    assert.equal(f.get('repository').value, '0', 'Preserve unconfirmed repository choice');
    f.client.value = 'Iv1.unsaved'; f.root.listeners.get('input')({target: f.client, isTrusted: true});
    f.get('interval').value = 30; f.root.listeners.get('change')({target: f.get('interval'), isTrusted: false});
    f.state.current_request = {id: 'other', operation: 'sync'};
    f.state.synchronization = {state: 'Running', label: 'Exporting', message: 'Not published', ready: true, can_save: true, missing: []};
    f.state.categories = {templates: {state: 'Running', processed: 1, total: 300}}; await f.poll();
    assert.equal(f.get('category-progress-templates').textContent, '1 / 300');
    assert.equal(f.get('interval').value, 30); assert.equal(f.client.value, 'Iv1.unsaved');
    f.state.categories.templates = {state: 'Completed', objects: 376, excluded_fields: ['secret']};
    f.state.synchronization.state = 'Publishing'; await f.poll();
    assert.equal(f.get('category-state-templates').textContent, 'Elaborata'); assert.equal(f.get('submit-sync').disabled, true);
    f.state.current_request = null; f.state.last_success = 123; f.state.last_commit = 'a'.repeat(40);
    f.state.repository = f.state.repositories[0]; f.state.setup.operational = true;
    f.state.synchronization = {...f.state.synchronization, state: 'Completed', label: 'No changes', message: 'Commit retained'};
    await f.poll(); assert.equal(f.get('wizard').hidden, true); assert.match(f.get('commit').href, /commit\/a{40}$/);
    assert.equal(f.get('step-4').parentElement, f.get('settings-sync'));
    f.state.synchronization = {...f.state.synchronization, state: 'Failed', error: 'Snapshot failed'};
    f.state.operation = 'test_github'; f.state.error = null; await f.poll();
    assert.equal(f.get('error').textContent, 'Snapshot failed'); assert.equal(f.get('wizard').hidden, true);
    assert.notEqual(f.get('last-success').textContent, '—');
    f.state.worker_stale = true; await f.poll(); assert.equal(f.get('worker-warning').textContent, f.ui.stale);
    f.responseType = 'offline'; await f.poll(); assert.equal(f.get('worker-warning').textContent, f.ui.unavailable);
    f.responseType = 'json'; f.document.hidden = true; f.document.listeners.get('visibilitychange')();
    assert.equal(f.timers.size, 0, 'Hidden page must not poll');
    f.document.hidden = false; f.document.listeners.get('visibilitychange')(); await f.poll();
    let release; f.pendingFetch = new Promise(resolve => { release = resolve; });
    const firstPoll = f.poll(); const before = f.calls;
    f.document.listeners.get('visibilitychange')(); assert.equal(f.calls, before, 'Do not overlap status requests');
    release(); await firstPoll; f.pendingFetch = null;
    assert.equal(f.timers.size, 1, 'Only one polling timer after a response');
    f.responseType = 'html'; await f.poll(); assert.equal(f.get('worker-warning').textContent, f.ui.session);
    assert.equal(f.timers.size, 0, 'Expired session stops polling');
    const reopened = await fixture({...f.state, worker_stale: false});
    assert.equal(reopened.get('wizard').hidden, true, 'Reopening must recover persistent configuration/snapshot');
    reopened.root.dataset.requestId = 'requested'; reopened.root.dataset.operation = 'repositories';
    reopened.state.request_pending = true;
    reopened.state.current_request = {id: 'other', operation: 'test_github'}; await reopened.poll();
    assert.equal(reopened.get('operation-feedback').textContent, reopened.ui.queued);
    assert.equal(reopened.get('submit-repositories').disabled, true);
    reopened.state.request_pending = false;
    reopened.state.current_request = {id: 'requested', operation: 'repositories'}; await reopened.poll();
    assert.equal(reopened.get('operation-feedback').textContent, reopened.ui.running);
    reopened.state.current_request = null;
    reopened.state.last_result = {id: 'requested', operation: 'repositories', state: 'Completed'}; await reopened.poll();
    assert.equal(reopened.get('operation-feedback').textContent, reopened.ui.done);
    assert.equal([...reopened.timers.values()][0].delay, 20000, 'Finished requests return to idle polling');
    reopened.state.last_result = {id: 'requested', operation: 'sync', state: 'Queued'};
    reopened.state.last_sync = {id: null, state: 'Completed'}; await reopened.poll();
    assert.equal(reopened.get('operation-feedback').textContent, reopened.ui.unknown_result,
        'An old queued outcome must not borrow the result of a later scheduled snapshot');
    const form = reopened.forms.get('sync'); const button = reopened.get('submit-sync');
    let prevented = false; form.listeners.get('submit')({submitter: button, preventDefault() { prevented = true; }});
    assert.equal(prevented, false); assert.equal(button.disabled, true);
    assert.equal(form.children.at(-1).name, 'operation'); assert.equal(form.children.at(-1).value, 'sync');
    form.listeners.get('submit')({submitter: button, preventDefault() { prevented = true; }});
    assert.equal(prevented, true, 'Duplicate submission must be prevented');
    const preview = await fixture({zabbix_configured: true,
        settings: {categories: ['templates'], interval: 4320, template_scope: {mode: 'all', include_templateids: ['10']}}});
    assert.equal(preview.get('template-mode').value, 'all', 'Saved all mode must be retained');
    preview.get('template-mode').value = 'used'; preview.manual.value = ['11', '12'];
    preview.root.listeners.get('change')({target: preview.get('template-mode')});
    preview.get('interval').value = 30;
    preview.root.listeners.get('change')({target: preview.get('interval')});
    assert.equal(preview.get('template-manual').hidden, false);
    const previewForm = preview.forms.get('preview_scope');
    const previewButton = preview.get('submit-preview_scope');
    let releaseOldPoll;
    preview.pendingFetch = new Promise(resolve => { releaseOldPoll = resolve; });
    const oldPreviewPoll = preview.poll();
    let previewPrevented = false;
    await previewForm.listeners.get('submit')({submitter: previewButton, preventDefault() { previewPrevented = true; }});
    assert.equal(previewPrevented, true, 'Preview must remain on the current page');
    assert.equal(previewForm.dataset.submitting, undefined, 'Preview must not freeze the settings form');
    assert.equal(preview.posts.length, 1);
    const params = Object.fromEntries(preview.posts[0]);
    assert.equal(params.csrf_token, 'fixture-csrf');
    assert.equal(params.operation, 'preview_scope');
    assert.equal(params.template_mode, 'used');
    assert.equal(params.interval, undefined, 'Unrelated invalid form drafts must not block preview');
    assert.deepEqual(preview.posts[0].filter(([key]) => key === 'include_templateids[]').map(([, value]) => value), ['11', '12']);
    assert.equal(preview.get('scope-preview').textContent, preview.ui.queued);
    releaseOldPoll(); await oldPreviewPoll; preview.pendingFetch = null;
    assert.equal(preview.get('scope-preview').textContent, preview.ui.queued, 'An older poll must not replace preview correlation');
    assert.equal(previewButton.disabled, true, 'An older poll must not enable duplicate preview submissions');
    await previewForm.listeners.get('submit')({submitter: previewButton, preventDefault() {}});
    assert.equal(preview.posts.length, 1, 'Do not duplicate a queued preview');
    preview.state.request_pending = true; await preview.poll();
    assert.equal(preview.get('scope-preview').textContent, preview.ui.queued);
    preview.state.request_pending = false;
    preview.state.current_request = {id: 'e'.repeat(32), operation: 'preview_scope'};
    preview.state.scope_preview = {id: 'e'.repeat(32), state: 'Running'}; await preview.poll();
    assert.equal(preview.get('scope-preview').textContent, preview.ui.running);
    preview.state.current_request = null;
    preview.state.scope_preview = {id: 'e'.repeat(32), state: 'Completed', summary: {
        total: 9, selected: 3, excluded: 6, manual_included: 2, missing_manual: 0, warning: null}};
    preview.state.last_result = {id: 'e'.repeat(32), operation: 'preview_scope', state: 'Completed'};
    await preview.poll();
    assert.equal(preview.get('scope-preview').textContent, 'Found 9; selected 3; excluded 6; manual 2; missing 0');
    assert.equal(preview.get('template-mode').value, 'used');
    assert.deepEqual(preview.manual.value, ['11', '12']);
    assert.equal(preview.get('interval').value, 30);
    preview.state.scope_preview = {id: 'other', state: 'Completed', summary: {total: 999}}; await preview.poll();
    assert.equal(preview.get('scope-preview').textContent, preview.ui.unknown_result, 'Do not display another request result');
    preview.state.scope_preview = {id: 'e'.repeat(32), state: 'Failed', error: 'Scope unavailable'}; await preview.poll();
    assert.equal(preview.get('scope-preview').textContent, 'Scope unavailable');
    preview.window.confirm = () => false;
    preview.get('category-templates').checked = true;
    let canceled = false;
    const saveForm = preview.forms.get('save');
    await saveForm.listeners.get('submit')({submitter: preview.get('submit-save'), preventDefault() { canceled = true; }});
    assert.equal(canceled, true, 'all to used must ask for confirmation');
    assert.equal(saveForm.dataset.submitting, undefined);
    preview.window.confirm = () => true;
    await saveForm.listeners.get('submit')({submitter: preview.get('submit-save'), preventDefault() {}});
    assert.ok(saveForm.children.some(item => item.name === 'confirm_template_scope' && item.value === '1'));
    console.log('Frontend wizard, device states, repositories, live progress, input preservation, visibility, session and submission checks passed.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
