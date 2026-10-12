// Exercise the actual polling asset with a pending Device Flow URL serialized by PHP.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

async function main() {
    const state = {last_request: 'fixture', state: 'Disconnected', device: {
        user_code: 'ABCD-EFGH', verification_uri: 'https://github.com/login/device', expires_at: 1000
    }, last_commit: null, categories: null, account: null, github: 'Disconnected', error: null};
    const elements = new Map();
    const fieldListeners = new Map();
    const input = {value: 'http://172.16.100.7:8080/zabbix/api_jsonrpc.php', addEventListener() {}};
    elements.set('gitkeeper-bootstrap-url', {...input});
    elements.set('gitkeeper-existing-url', {...input});
    elements.set('gitkeeper-api-url-source', {dataset: {}});
    elements.set('gitkeeper-api-url-warning', {dataset: {}});
    for (const id of ['gitkeeper-bootstrap-tls', 'gitkeeper-existing-tls', 'gitkeeper-use-loopback']) {
        elements.set(id, {checked: false, addEventListener: (type, callback) => fieldListeners.set(id, callback)});
    }
    elements.set('gitkeeper-overview', {dataset: {fingerprint: JSON.stringify([
        state.last_request, state.state, state.device, state.last_commit, state.categories, state.account
    ]).replaceAll('/', '\\/')}});
    elements.set('gitkeeper-api-configured', {textContent: '', dataset: {
        configured: 'Configured', unconfigured: 'Not configured'
    }});
    for (const prefix of ['gitkeeper-', 'gitkeeper-connection-', 'gitkeeper-api-']) {
        for (const key of ['state', 'github', 'account', 'error']) {
            elements.set(prefix + key, {textContent: '', dataset: {
                connected: 'Connected', disconnected: 'Disconnected', pending: 'Awaiting authorization'
            }});
        }
    }
    let start;
    let reloads = 0;
    let focused = false;
    const listeners = new Map();
    const timers = [];
    const context = vm.createContext({
        document: {
            getElementById: id => elements.get(id) ?? null,
            addEventListener: (type, callback) => {
                if (type === 'DOMContentLoaded') start = callback;
                else listeners.set(type, callback);
            },
            querySelector: () => focused ? {} : null
        },
        window: {addEventListener() {}, location: {reload: () => { reloads++; }}},
        URL,
        setTimeout: callback => timers.push(callback),
        fetch: async () => ({ok: true, json: async () => state})
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../frontend/gitkeeper/assets/js/gitkeeper.js'), 'utf8'), context);
    start();
    const poll = timers.shift();
    await poll();
    assert.equal(reloads, 0, 'Unchanged pending authorization must not reload because PHP escapes URL slashes');
    assert.equal(elements.get('gitkeeper-connection-github').textContent, 'Awaiting authorization');
    state.error = 'Fixture authorization failure';
    await timers.shift()();
    assert.equal(reloads, 0);
    assert.equal(elements.get('gitkeeper-connection-error').textContent, state.error,
        'Connection errors must appear in the GitHub panel while polling');
    state.device.user_code = 'IJKL-MNOP';
    focused = true;
    await timers.shift()();
    assert.equal(reloads, 0, 'Refresh must wait until the focused input is released');
    state.device = null;
    state.github = 'Ready';
    state.account = 'Capobuf';
    state.state = 'Ready';
    await timers.shift()();
    assert.equal(reloads, 0);
    assert.equal(elements.get('gitkeeper-connection-github').textContent, 'Connected');
    assert.equal(elements.get('gitkeeper-connection-account').textContent, 'Capobuf');
    focused = false;
    await timers.shift()();
    assert.equal(reloads, 1, 'Deferred authorization changes must refresh the form after focus leaves');
    listeners.get('input')();
    state.state = 'Failed';
    state.error = 'Zabbix denied a required read method';
    state.zabbix_configured = false;
    await poll();
    assert.equal(elements.get('gitkeeper-api-error').textContent, state.error);
    assert.equal(elements.get('gitkeeper-api-state').textContent, 'Failed');
    assert.equal(elements.get('gitkeeper-api-configured').textContent, 'Not configured');
    state.state = 'Ready';
    state.error = null;
    state.zabbix_configured = true;
    await timers.shift()();
    assert.equal(reloads, 1, 'Typed input must be preserved after API verification');
    assert.equal(elements.get('gitkeeper-api-error').textContent, '-');
    assert.equal(elements.get('gitkeeper-api-configured').textContent, 'Configured');
    fieldListeners.get('gitkeeper-use-loopback')();
    assert.equal(elements.get('gitkeeper-bootstrap-url').value, 'http://127.0.0.1:8080/zabbix/api_jsonrpc.php');
    assert.equal(elements.get('gitkeeper-existing-url').value, 'http://127.0.0.1:8080/zabbix/api_jsonrpc.php');
    elements.get('gitkeeper-bootstrap-tls').checked = true;
    fieldListeners.get('gitkeeper-bootstrap-tls')();
    assert.equal(elements.get('gitkeeper-existing-tls').checked, true, 'Both forms must share certificate verification choice');
    console.log('Frontend authorization, deferred refresh and connection error regressions passed.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
