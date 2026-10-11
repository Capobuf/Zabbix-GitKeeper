<?php
use Modules\GitKeeper\Queue;

$status = $data['status'];
$settings = $status['settings'] ?? [];
$labels = ['hosts' => _('Hosts and host groups'), 'templates' => _('Templates and template groups'),
    'actions' => _('Actions'), 'media_types' => _('Media types'), 'users' => _('Users, groups and roles')];

$form = static function(string $operation): CForm {
    return (new CForm('post', 'zabbix.php'))->addVar('action', 'gitkeeper.save')
        ->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('gitkeeper.save'))->addVar('operation', $operation);
};
$page = (new CHtmlPage())->setTitle(_('GitKeeper'));
$overview = (new CFormList())->setId('gitkeeper-overview');
$overview->setAttribute('data-fingerprint', json_encode([$status['last_request'] ?? null, $status['state'] ?? null,
    $status['device'] ?? null, $status['last_commit'] ?? null, $status['categories'] ?? null, $status['account'] ?? null]));
foreach (['state' => _('Status'), 'github' => _('GitHub'), 'account' => _('Account'),
    'last_attempt' => _('Last attempt'), 'last_success' => _('Last successful snapshot'),
    'last_commit' => _('Last commit'), 'error' => _('Last error')] as $key => $label) {
    $value = $status[$key] ?? '-';
    if (in_array($key, ['last_attempt', 'last_success']) && is_numeric($value)) {
        $value = date('Y-m-d H:i:s T', (int) $value);
    }
    $overview->addRow($label, (new CSpan((string) $value))->setId('gitkeeper-'.$key));
}
$overview->addRow(_('Repository'), $status['repository']['full_name'] ?? '-');
if (!empty($status['repository']['full_name'])) {
    $repo_url = 'https://github.com/'.$status['repository']['full_name'];
    $overview->addRow(_('GitHub links'), [new CLink(_('Open repository'), $repo_url), ' ',
        !empty($status['last_commit']) ? new CLink(_('View last commit'), $repo_url.'/commit/'.$status['last_commit']) : '']);
}
$next = $settings['next_sync'] ?? null;
$overview->addRow(_('Next synchronization'), $next ? date('Y-m-d H:i:s T', (int) $next) : '-');
$page->addItem(new CTag('h2', true, _('Overview')))->addItem($overview);
$table = (new CTableInfo())->setHeader([_('Category'), _('Result'), _('Objects'), _('Excluded fields')]);
foreach ($status['categories'] ?? [] as $category => $result) {
    $table->addRow([$labels[$category] ?? $category, $result['state'] ?? '-', $result['objects'] ?? '-',
        count($result['excluded_fields'] ?? [])]);
}
$page->addItem($table)->addItem(new CTag('p', true,
    _('Snapshots omit confidential payloads. Completed means the selected readable scope was exported and pushed; restore and disaster recovery are not implemented.')));

$page->addItem(new CTag('h2', true, _('GitHub')));
if (!empty($status['device'])) {
    $page->addItem(new CTag('p', true, [new CLink(_('Authorize on GitHub'), 'https://github.com/login/device'),
        ' — ', new CTag('strong', true, $status['device']['user_code']), ' — ', _('Waiting for authorization.')]));
}
foreach (['connect' => !empty($status['reconnect']) ? _('Reconnect GitHub') : _('Connect GitHub'),
    'disconnect' => _('Disconnect GitHub'), 'repositories' => _('Refresh repositories'), 'test_github' => _('Test connection')]
        as $operation => $title) {
    $page->addItem($form($operation)->addItem(new CSubmit('submit', $title)));
}
$create = $form('create_repository');
$create->addItem((new CFormList())->addRow(_('New private repository name'), new CTextBox('repository_name')))
    ->addItem(new CSubmit('submit', _('Create private repository')));
$page->addItem($create)->addItem(new CTag('p', true, [new CLink(_('Create on GitHub'), 'https://github.com/new'),
    ' — ', _('If creation is denied, create a private repository on GitHub, grant the App access, then refresh. Direct creation targets your personal account.')]));

$page->addItem(new CTag('h2', true, _('Zabbix API access')));
$page->addItem(new CTag('p', true,
    _('This first iteration requires a Super admin API token to verify whole-instance enumeration. The worker uses it independently of your browser session.')));
$api = $form('set_zabbix');
$api->addItem((new CFormList())->addRow(_('HTTPS API URL'), (new CTextBox('api_url'))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH))
    ->addRow(_('Existing API token'), (new CTextBox('api_token'))->setAttribute('type', 'password')
        ->setAttribute('autocomplete', 'new-password')))
    ->addItem(new CSubmit('submit', _('Verify and replace API token')));
$page->addItem($api);
$bootstrap = $form('create_zabbix_token');
$bootstrap->addItem((new CFormList())->addRow(_('HTTPS API URL'), (new CTextBox('api_url'))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH))
    ->addRow(_('Consent'), [(new CCheckBox('consent', 1)), ' ',
        _('Create a non-expiring GitKeeper token that inherits the permissions of my current account.')]))
    ->addItem(new CSubmit('submit', _('Create and verify native API token')));
$page->addItem($bootstrap)->addItem($form('clear_zabbix')->addItem(new CSubmit('submit', _('Remove stored API token'))))
    ->addItem(new CTag('p', true, [new CLink(_('Manage / revoke API tokens'),
        (new CUrl('zabbix.php'))->setArgument('action', 'token.list')), ' — ',
        _('Removing the local token or replacing it does not revoke it in Zabbix. Revoke unused GitKeeper tokens in the native API token page.')]));

$page->addItem(new CTag('h2', true, _('Backup settings')));
$backup = $form('save');
$repositories = (new CSelect('repository_id'))->setValue($settings['repository_id'] ?? 0);
$repositories->addOption(new CSelectOption(0, _('Select a private repository')));
foreach ($status['repositories'] ?? [] as $repo) {
    $repositories->addOption(new CSelectOption($repo['id'], $repo['full_name'].' ('.$repo['default_branch'].')'));
}
$fields = (new CFormList())->addRow(_('Repository'), $repositories);
$checks = [];
$available = $status['available_categories'] ?? [];
foreach ($labels as $category => $label) {
    $checks[] = new CDiv([(new CCheckBox('categories[]', $category))
        ->setChecked(in_array($category, $settings['categories'] ?? Queue::CATEGORIES, true))
        ->setEnabled(in_array($category, $available, true)), ' ', $label]);
}
$fields->addRow(_('Implemented categories'), $checks);
$interval = (new CSelect('interval'))->setValue($settings['interval'] ?? 15);
foreach (Queue::INTERVALS as $minutes) {
    $interval->addOption(new CSelectOption($minutes, $minutes === 1440 ? _('24 hours') : $minutes.' '._('minutes')));
}
$fields->addRow(_('Export interval'), $interval);
$backup->addItem($fields)->addItem(new CSubmit('submit', _('Save & Sync')));
$page->addItem($backup)->addItem($form('sync')->addItem(new CSubmit('submit', _('Sync now'))));
$page->show();
