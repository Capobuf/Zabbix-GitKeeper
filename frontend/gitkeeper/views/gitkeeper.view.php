<?php
use Modules\GitKeeper\Queue;
use Modules\GitKeeper\Installation;

require_once __DIR__.'/../includes/Installation.php';

$status = $data['status'];
$settings = $status['settings'] ?? [];
$available = $status['available_categories'] ?? [];
$worker_ready = (bool) $available;
$github_ready = ($status['github'] ?? '') === 'Ready';
$app_ready = !empty($status['github_client_id']);
$api_ready = !empty($status['zabbix_configured']);
$selected_repository = null;
foreach ($status['repositories'] ?? [] as $repo) {
    if ($repo['id'] == ($settings['repository_id'] ?? 0)) {
        $selected_repository = $repo;
    }
}
$api_url = $data['api_url'];
$labels = ['hosts' => _('Hosts and host groups'), 'templates' => _('Templates and template groups'),
    'actions' => _('Actions'), 'media_types' => _('Media types'), 'users' => _('Users, groups and roles')];

$form = static function(string $id): CForm {
    return (new CForm('post', (new CUrl('zabbix.php'))->setArgument('action', 'gitkeeper.save')->getUrl()))
        ->setId('gitkeeper-form-'.$id)
        ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('gitkeeper.save')))->removeId());
};
$submit = static function(string $operation, string $caption, bool $enabled, bool $secondary = false): CSubmit {
    $button = (new CSubmit('operation', $caption))->setId('gitkeeper-submit-'.$operation)
        ->setAttribute('value', $operation)->setEnabled($enabled);
    return $secondary ? $button->addClass(ZBX_STYLE_BTN_ALT) : $button;
};
$row = static function(CFormGrid $grid, string $label, $field, ?string $id = null): CFormGrid {
    return $grid->addItem([new CLabel($label, $id), new CFormField($field)]);
};
$note = static function(string $text): CFormField {
    return (new CFormField((new CTag('p', true, $text))->addClass(ZBX_STYLE_GREY)))
        ->addClass(CFormField::ZBX_STYLE_FORM_FIELD_OFFSET_1);
};
$time = static function($value): string {
    return is_numeric($value) ? date('Y-m-d H:i:s T', (int) $value) : '-';
};
if (!$worker_ready) {
    $next_step = _('Install the worker using the command below.');
}
elseif (!$app_ready) {
    $next_step = _('Open GitHub and save your App Client ID.');
}
elseif (!$github_ready) {
    $next_step = !empty($status['device'])
        ? _('Open GitHub, enter the authorization code and return here. This page checks the result automatically.')
        : _('Open GitHub and click Connect GitHub to authorize your account.');
}
elseif (!$selected_repository) {
    $next_step = _('Open GitHub and select an existing private repository or create a new one.');
}
elseif (!$api_ready) {
    $next_step = _('Open Zabbix API and verify a token to allow configuration exports.');
}
elseif (empty($settings['categories']) || empty($settings['interval'])) {
    $next_step = _('Open Configuration, choose what to export and click Save & Sync.');
}
else {
    $next_step = _('Setup is complete. Use Sync now for a snapshot or Configuration to change the schedule.');
}

$sync_requirements = [];
if (!$worker_ready) {
    $sync_requirements[] = [_('Worker status is unavailable. Install the worker or check cron.'),
        _('Open Overview'), 'gitkeeper-tab-overview'];
}
if (!$app_ready) {
    $sync_requirements[] = [_('The GitHub App Client ID has not been saved.'),
        _('Configure GitHub App'), 'gitkeeper-tab-github'];
}
if (!$github_ready) {
    $sync_requirements[] = [_('GitHub authorization is not complete.'),
        _('Connect GitHub'), 'gitkeeper-tab-github'];
}
if (!$selected_repository) {
    $sync_requirements[] = [_('No accessible private repository has been selected.'),
        _('Select a repository'), 'gitkeeper-tab-github'];
}
if (!$api_ready) {
    $sync_requirements[] = [_('The Zabbix API token has not been configured and verified.'),
        _('Configure Zabbix API'), 'gitkeeper-tab-api'];
}
if (empty($settings['categories']) || empty($settings['interval'])) {
    $sync_requirements[] = [_('Backup categories and the export interval have not been saved. Click Save & Sync to save them and start the first snapshot.'),
        _('Open Configuration'), 'gitkeeper-tab-configuration'];
}
$sync_ready = !$sync_requirements;

$overview = (new CFormGrid())->setId('gitkeeper-overview');
$overview->setAttribute('data-fingerprint', json_encode([$status['last_request'] ?? null, $status['state'] ?? null,
    $status['device'] ?? null, $status['last_commit'] ?? null, $status['categories'] ?? null, $status['account'] ?? null]));
$row($overview, _('Next step'), $next_step);
$row($overview, _('Synchronization'), (new CSpan($sync_ready ? _('Configured') : _('Setup incomplete')))
    ->addClass($sync_ready ? ZBX_STYLE_GREEN : ZBX_STYLE_RED));
foreach (['state' => _('Worker status'), 'github' => _('GitHub'), 'account' => _('Account')] as $key => $label) {
    $row($overview, $label, (new CSpan($status[$key] ?? '-'))->setId('gitkeeper-'.$key));
}
$row($overview, _('Zabbix API'), $api_ready ? _('Configured') : _('Not configured'));
$row($overview, _('Zabbix version'), $status['zabbix_version'] ?? ZABBIX_VERSION);
$repository = $status['repository']['full_name'] ?? null;
$row($overview, _('Repository'), $repository ? new CLink($repository, 'https://github.com/'.$repository) : '-');
foreach (['last_attempt' => _('Last attempt'), 'last_success' => _('Last successful snapshot')] as $key => $label) {
    $row($overview, $label, $time($status[$key] ?? null));
}
$row($overview, _('Next synchronization'), $time($settings['next_sync'] ?? null));
$commit = $status['last_commit'] ?? null;
$row($overview, _('Last commit'), $commit && $repository
    ? new CLink(substr($commit, 0, 12), 'https://github.com/'.$repository.'/commit/'.$commit) : '-');
$row($overview, _('Last error'), (new CSpan($status['error'] ?? '-'))->setId('gitkeeper-error'));
$overview->addItem($note(_('Snapshots track the selected readable configuration. Confidential payloads are omitted; restore and disaster recovery are not available.')));
$overview_body = [$overview];
if (!$worker_ready) {
    $installation = new CFormGrid();
    $frontend_root = realpath(__DIR__.'/../../..');
    $web_user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ? posix_getpwuid(posix_geteuid()) : false;
    if ($frontend_root && is_file($frontend_root.'/zabbix.php')
            && is_file($frontend_root.'/include/defines.inc.php') && $web_user && $web_user['name'] !== 'root') {
        $command = Installation::command($frontend_root, $web_user['name']);
        $command_id = 'gitkeeper-install-command';
        $installation->addItem($note(_('Copy this command into a Bash terminal on the Zabbix server. The frontend path and PHP process user are detected automatically. The command downloads main and installs the module, worker and cron. After installation, configure your App Client ID in the GitHub tab.')));
        $row($installation, _('Installation command'),
            (new CTextArea($command_id, $command, ['rows' => 9, 'readonly' => true]))
                ->disableSpellcheck()->setAdaptiveWidth(900), $command_id);
        $installation->addItem((new CFormField([
            (new CButton('gitkeeper-copy-install', _('Copy')))->addClass(ZBX_STYLE_BTN_ALT),
            ' ', (new CSpan())->setId('gitkeeper-copy-result')->setAttribute('role', 'status')
                ->setAttribute('data-copied', _('Copied'))
                ->setAttribute('data-fallback', _('Select and copy the command manually.'))
        ]))->addClass(CFormField::ZBX_STYLE_FORM_FIELD_OFFSET_1));
        $installation->addItem($note(_('Prerequisites: Python 3.10+ with venv/pip, Git, PHP and cron. After installation, restart your PHP-FPM/Apache service to apply group permissions, then reload this page.')));
    }
    else {
        $installation->addItem($note(_('Cannot detect the frontend path and PHP process user. Follow the installation guide and pass the verified --frontend-root and --web-user values.')));
    }
    $overview_body[] = (new CTag('h4', true, _('Worker installation')))->addClass('input-section-header');
    $overview_body[] = $installation;
}
if (!empty($status['categories'])) {
    $table = (new CTable())->setHeader([_('Category'), _('Result'), _('Objects'), _('Excluded fields')]);
    foreach ($status['categories'] as $category => $result) {
        $table->addRow([$labels[$category] ?? $category, $result['state'] ?? '-', $result['objects'] ?? '-',
            count($result['excluded_fields'] ?? [])]);
    }
    $overview_body[] = $table;
}
$sync_form = $form('sync');
$sync_feedback = (new CSpan())->setId('gitkeeper-sync-feedback')->setAttribute('role', 'status')
    ->setAttribute('data-submitting', _('Submitting snapshot request...'));
if (!$sync_ready) {
    $missing = new CList();
    foreach ($sync_requirements as [$reason, $action, $tab]) {
        $missing->addItem([$reason, ' ', (new CLink($action, '#'.$tab))->setAttribute('data-gitkeeper-tab', $tab)]);
    }
    $sync_form->addItem((new CTag('h4', true, _('Sync now is unavailable: complete these steps first.')))
        ->addClass(ZBX_STYLE_RED));
    $sync_form->addItem((new CDiv($missing))->setId('gitkeeper-sync-requirements'));
}
else {
    $sync_feedback->addItem(_('Sync now requests a snapshot using the saved settings. It starts on the next worker tick, normally within one minute.'));
}
$sync_button = $submit('sync', _('Sync now'), $sync_ready);
if (!$sync_ready) {
    $sync_button->setAttribute('aria-describedby', 'gitkeeper-sync-requirements');
    $sync_button->setAttribute('title', implode(' ', array_column($sync_requirements, 0)));
}
$overview_body[] = $sync_form->addItem(makeFormFooter($sync_button))->addItem($sync_feedback);

$github = new CFormGrid();
$row($github, _('Connection'), (new CSpan($github_ready ? _('Connected')
    : (!empty($status['device']) ? _('Awaiting authorization') : _('Disconnected'))))
    ->setId('gitkeeper-connection-github')->setAttribute('data-connected', _('Connected'))
    ->setAttribute('data-disconnected', _('Disconnected'))
    ->setAttribute('data-pending', _('Awaiting authorization')));
$row($github, _('Account'), (new CSpan($status['account'] ?? '-'))->setId('gitkeeper-connection-account'));
$row($github, _('Last error'), (new CSpan($status['error'] ?? '-'))
    ->setId('gitkeeper-connection-error')->setAttribute('role', 'status'));
if (!empty($status['device'])) {
    $row($github, _('Authorization code'), new CTag('strong', true, $status['device']['user_code']));
    $row($github, _('Authorization'), new CLink(_('Authorize on GitHub'), 'https://github.com/login/device'));
    $github->addItem($note(_('Click Authorize on GitHub and enter this code. After authorizing your account, return here: the connection updates automatically. Repository access is configured separately in step 3.')));
}
elseif ($github_ready) {
    $github->addItem($note($selected_repository ? $next_step
        : _('Your account is connected. Continue with step 3 to choose the repository that will receive your snapshots.')));
}
elseif (!$worker_ready || !$app_ready) {
    $github->addItem($note(!$worker_ready ? _('Install the worker from Overview before connecting GitHub.')
        : _('Save the Client ID in step 1 to enable Connect GitHub.')));
}
else {
    $github->addItem($note(_('Click Connect GitHub. An authorization code and an Authorize on GitHub link will appear here when the worker processes the request. This page updates automatically.')));
}
$connection_actions = [];
if ($github_ready) {
    $connection_actions[] = $submit('test_github', _('Check GitHub connection'), $worker_ready, true);
}
if ($github_ready || !empty($status['device'])) {
    $connection_actions[] = $submit('disconnect', $github_ready ? _('Disconnect GitHub') : _('Cancel authorization'),
        $worker_ready, true);
}
$github_connection = $form('github')->addItem($github)->addItem(makeFormFooter(
    !$github_ready && empty($status['device'])
        ? $submit('connect', !empty($status['reconnect']) ? _('Reconnect GitHub') : _('Connect GitHub'),
            $worker_ready && $app_ready) : null,
    $connection_actions
));
$create = new CFormGrid();
$owner = $selected_repository ? explode('/', $selected_repository['full_name'])[0] : ($status['account'] ?? '');
$row($create, _('Owner (account or organization)'),
    (new CTextBox('repository_owner', $owner))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
        ->setAttribute('maxlength', 100), 'repository_owner');
$row($create, _('New private repository name'),
    (new CTextBox('repository_name'))->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
        ->setAttribute('maxlength', 100), 'repository_name');
$create->addItem($note(_('Creates a private repository for this backup and selects it after verifying App access. For an organization, replace the owner with its GitHub name. No snapshot is sent until Save & Sync.')));
$create->addItem($note(_('Requires Repository creation permission on the App. If GitHub creates the repository but the App cannot access it, grant access and click Refresh repositories above; do not create it again.')));
$repository_list = (new CTable())->addClass(ZBX_STYLE_LIST_TABLE)
    ->setHeader([_('Repository'), _('Visibility'), _('Access'), _('Branch')]);
foreach ($status['repositories'] ?? [] as $repo) {
    $repository_list->addRow([new CLink($repo['full_name'], 'https://github.com/'.$repo['full_name']),
        _('Private'), _('Read and write'), $repo['default_branch']]);
}
if (empty($status['repositories'])) {
    $repository_list->addRow([(new CCol(_('No accessible private repositories')))->setColSpan(4)]);
}
$destination = new CFormGrid();
$repository_id = $settings['repository_id'] ?? 0;
if (!$repository_id && count($status['repositories'] ?? []) === 1) {
    $repository_id = $status['repositories'][0]['id'];
    $destination->addItem($note(_('One repository is available. Click Use repository to confirm it as the destination.')));
}
$repositories = (new CSelect('repository_id'))->setId('gitkeeper-repository')->setFocusableElementId('gitkeeper-repository-focus')
    ->setValue($repository_id)->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH);
$repositories->addOption(new CSelectOption(0, _('Select a private repository')));
foreach ($status['repositories'] ?? [] as $repo) {
    $repositories->addOption(new CSelectOption($repo['id'], $repo['full_name'].' ('.$repo['default_branch'].')'));
}
if (!empty($status['repositories'])) {
    $row($destination, _('Choose destination'), $repositories, 'gitkeeper-repository-focus');
}
$row($destination, _('Selected destination'), $selected_repository
    ? new CLink($selected_repository['full_name'], 'https://github.com/'.$selected_repository['full_name'])
    : _('No repository selected'));
if ($github_ready && empty($status['repositories'])) {
    $destination->addItem($note(_('Your account is connected, but no private repository is available to this App. On GitHub, install the App for the repository owner (your account or organization), allow access to the destination repository, then click Refresh repositories.')));
    $destination->addItem($note(_('The App needs Contents read and write permission, and your account must have push access. To use a new repository, create one below or on GitHub, then grant App access.')));
}
elseif (!$github_ready) {
    $destination->addItem($note(_('Connect your account in step 2 before loading or creating repositories.')));
}
else {
    $destination->addItem($note(_('Choose a repository and click Use repository. Changing the destination pauses synchronization until you click Save & Sync in Configuration.')));
}
$row($destination, _('App access'), new CLink(_('Manage repository access on GitHub'), 'https://github.com/settings/installations'));
$app = new CFormGrid();
$row($app, _('GitHub App Client ID'),
    (new CTextBox('client_id', $status['github_client_id'] ?? ''))->setAttribute('maxlength', 128)
        ->setAttribute('autocomplete', 'off')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH), 'client_id');
$app->addItem($note(_('Open GitHub App settings, copy Client ID and enable Device Flow. Paste the Client ID here and click Save Client ID. The numeric App ID is a different value.')));
if (ctype_digit($status['github_client_id'] ?? '')) {
    $app->addItem($note(_('The saved value is numeric. Verify that you copied the Client ID, not the App ID, from your GitHub App settings.')));
}
$app_details = (new CTag('details', true, [new CTag('summary', true, _('What happens if I change the App?')),
    new CTag('p', true, _('Changing the Client ID disconnects the current App and pauses synchronization. Connect again and select a repository. Saving the same Client ID keeps the connection. No client secret or private key is required.'))]));
$app->addItem((new CFormField($app_details))->addClass(CFormField::ZBX_STYLE_FORM_FIELD_OFFSET_1));
if (!$worker_ready) {
    $app->addItem($note(_('Install the worker from Overview first; then save your App Client ID here.')));
}
$github_body = [
    (new CTag('h4', true, _('1. Configure the GitHub App')))->addClass('input-section-header'),
    $form('github-app')->addItem($app)->addItem(makeFormFooter(
        $submit('set_github_app', _('Save Client ID'), $worker_ready),
        [new CLink(_('GitHub App settings'), 'https://github.com/settings/apps')])),
    (new CTag('h4', true, _('2. Connect your GitHub account')))->addClass('input-section-header'),
    $github_connection,
    (new CTag('h4', true, _('3. Choose the backup repository')))->addClass('input-section-header'),
    $repository_list, $form('repository')->addItem($destination)->addItem(makeFormFooter(
        !empty($status['repositories']) ? $submit('select_repository', _('Use repository'), $worker_ready && $github_ready) : null,
        [$submit('repositories', _('Refresh repositories'), $worker_ready && $github_ready, true)])),
    (new CTag('h4', true, _('Or create a new private repository')))->addClass('input-section-header'),
    $form('create-repository')->addItem($create)->addItem(makeFormFooter(
    $submit('create_repository', _('Create and select private repository'), $worker_ready && $github_ready),
    [new CLink(_('Create on GitHub'), 'https://github.com/new')]
))];

$bootstrap = new CFormGrid();
$bootstrap_url_id = 'gitkeeper-bootstrap-url';
$row($bootstrap, _('API URL'), (new CTextBox('api_url', $api_url))->setId($bootstrap_url_id)
    ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)->setAttribute('required', true)
    ->setAttribute('pattern', 'https?://[^\\s]+/api_jsonrpc\\.php')
    ->setAttribute('title', _('Use an HTTP or HTTPS URL ending in /api_jsonrpc.php.'))
    ->setAttribute('placeholder', 'https://zabbix.example/zabbix/api_jsonrpc.php'),
    $bootstrap_url_id);
$row($bootstrap, _('HTTPS certificate'), (new CCheckBox('tls_skip_verify', 1))
    ->setId('gitkeeper-bootstrap-tls')->setChecked(($status['zabbix_verify_tls'] ?? true) === false)
    ->setLabel(_('Allow an invalid or self-signed certificate (skip verification).')), 'gitkeeper-bootstrap-tls');
$row($bootstrap, _('Consent'), (new CCheckBox('consent', 1))
    ->setLabel(_('Create a non-expiring token that inherits my current account permissions.'))
    ->setAttribute('required', true), 'consent');
$bootstrap->addItem($note(_('A Super admin API token is required. The worker uses it independently of your browser session.')));
$api = new CFormGrid();
$existing_url_id = 'gitkeeper-existing-url';
$row($api, _('API URL'), (new CTextBox('api_url', $api_url))->setId($existing_url_id)
    ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)->setAttribute('required', true)
    ->setAttribute('pattern', 'https?://[^\\s]+/api_jsonrpc\\.php')
    ->setAttribute('title', _('Use an HTTP or HTTPS URL ending in /api_jsonrpc.php.'))
    ->setAttribute('placeholder', 'https://zabbix.example/zabbix/api_jsonrpc.php'),
    $existing_url_id);
$row($api, _('HTTPS certificate'), (new CCheckBox('tls_skip_verify', 1))
    ->setId('gitkeeper-existing-tls')->setChecked(($status['zabbix_verify_tls'] ?? true) === false)
    ->setLabel(_('Allow an invalid or self-signed certificate (skip verification).')), 'gitkeeper-existing-tls');
$row($api, _('Existing API token'), (new CTextBox('api_token'))->setAttribute('type', 'password')
    ->setAttribute('autocomplete', 'new-password')->setAttribute('required', true)
    ->setAttribute('pattern', '[a-fA-F0-9]{64}')
    ->setAttribute('title', _('Enter the complete 64-character API token.'))
    ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH), 'api_token');
$api_body = [
    $row(new CFormGrid(), _('Zabbix API'), (new CSpan($api_ready ? _('Configured') : _('Not configured')))
        ->setId('gitkeeper-api-configured')->setAttribute('data-configured', _('Configured'))
        ->setAttribute('data-unconfigured', _('Not configured')))
        ->addItem([new CLabel(_('Worker status')), new CFormField(
            (new CSpan($status['state'] ?? '-'))->setId('gitkeeper-api-state')->setAttribute('role', 'status'))])
        ->addItem([new CLabel(_('Last error')), new CFormField(
            (new CSpan($status['error'] ?? '-'))->setId('gitkeeper-api-error')->setAttribute('role', 'status'))])
        ->addItem($note(_('After submission, verification starts on the next worker tick, normally within one minute. Wait for Configured or check Last error here.')))
        ->addItem($note($api_ready
            ? _('The API token is configured. To replace it, create or verify another token below.')
            : _('Create a token below, or use an existing Super admin token. Configuration exports become available after verification succeeds.')))
        ->addItem($note(_('The API address is prefilled from this installation. Change it only if the worker needs a different address.')))
        ->addItem((new CFormField((new CSpan($data['api_url_source']))->setId('gitkeeper-api-url-source')
            ->setAttribute('data-current-label', _('Current frontend address'))
            ->setAttribute('data-custom-label', _('Custom API address'))))
            ->addClass(CFormField::ZBX_STYLE_FORM_FIELD_OFFSET_1))
        ->addItem((new CFormField((new CSpan())->setId('gitkeeper-api-url-warning')->setAttribute('role', 'status')
            ->setAttribute('data-http-warning', _('HTTP sends the API token without encryption. For a local frontend, you can use 127.0.0.1 to keep worker traffic on this server.'))))
            ->addClass(CFormField::ZBX_STYLE_FORM_FIELD_OFFSET_1)),
    (new CFormGrid())->addItem((new CFormField([
        (new CButton('gitkeeper-use-loopback', _('Use 127.0.0.1')))->addClass(ZBX_STYLE_BTN_ALT),
        ' ', _('Uses the current scheme, port and path. The worker verifies this address before saving; name-based virtual hosts may require the original hostname.')
    ]))->addClass(CFormField::ZBX_STYLE_FORM_FIELD_OFFSET_1)),
    (new CTag('h4', true, _('Create a Zabbix API token')))->addClass('input-section-header'),
    $form('bootstrap')->addItem($bootstrap)->addItem(makeFormFooter(
        $submit('create_zabbix_token', _('Create and verify token'), $worker_ready))),
    (new CTag('h4', true, _('Use an existing token')))->addClass('input-section-header'),
    $form('api')->addItem($api)->addItem(makeFormFooter(
        $submit('set_zabbix', $api_ready ? _('Verify and replace token') : _('Verify and use token'), $worker_ready))),
    (new CTag('h4', true, _('Token management')))->addClass('input-section-header'),
    $form('clear-api')->addItem(makeFormFooter(
        $submit('clear_zabbix', _('Remove stored API token'), $worker_ready && $api_ready, true),
        [new CLink(_('Manage / revoke API tokens'), (new CUrl('zabbix.php'))->setArgument('action', 'token.list'))])),
    (new CFormGrid())->addItem($note(_('Removing or replacing the stored token does not revoke it in Zabbix. Revoke unused GitKeeper tokens in the native API token page.')))
];

$configuration = new CFormGrid();
$row($configuration, _('Repository'), $selected_repository
    ? new CLink($selected_repository['full_name'], 'https://github.com/'.$selected_repository['full_name'])
    : _('Choose or create a repository in the GitHub tab first.'));
$checks = [];
foreach ($labels as $category => $label) {
    $checks[] = new CDiv((new CCheckBox('categories[]', $category))->setId('gitkeeper-category-'.$category)
        ->setLabel($label)->setChecked(in_array($category, $settings['categories'] ?? $available, true))
        ->setEnabled(in_array($category, $available, true)));
}
$row($configuration, _('Configuration to export'), $checks);
$interval = (new CSelect('interval'))->setId('gitkeeper-interval')->setFocusableElementId('gitkeeper-interval-focus')
    ->setValue($settings['interval'] ?? 15);
foreach (Queue::INTERVALS as $minutes) {
    $interval->addOption(new CSelectOption($minutes, $minutes === 1440 ? _('24 hours') : $minutes.' '._('minutes')));
}
$row($configuration, _('Export interval'), $interval, 'gitkeeper-interval-focus');
$configuration->addItem($note(_('Saved settings are reused. On first setup, all categories supported by the worker are selected and the default interval is 15 minutes.')));
$configuration->addItem($note(_('Save & Sync saves these settings and requests a snapshot. Subsequent snapshots follow the selected interval. Follow progress in Overview.')));
if (!$worker_ready || !$github_ready || !$selected_repository || !$api_ready) {
    $configuration->addItem($note($next_step));
}
$configuration_body = $form('configuration')->addItem((new CVar('repository_id', $settings['repository_id'] ?? 0))->removeId())
    ->addItem($configuration)->addItem(makeFormFooter(
    $submit('save', _('Save & Sync'), $worker_ready && $api_ready && $github_ready && $selected_repository !== null)
));

(new CHtmlPage())->setTitle(_('GitKeeper'))->addItem(
    (new CTabView(['id' => 'gitkeeper-tabs']))
        ->addTab('gitkeeper-tab-overview', _('Overview'), $overview_body)
        ->addTab('gitkeeper-tab-github', _('GitHub'), $github_body)
        ->addTab('gitkeeper-tab-api', _('Zabbix API'), $api_body)
        ->addTab('gitkeeper-tab-configuration', _('Configuration'), $configuration_body)
)->show();
