<?php
use Modules\GitKeeper\Queue;
use Modules\GitKeeper\Installation;
require_once __DIR__.'/../includes/Installation.php';

$status = $data['status'];
$settings = $status['settings'] ?? [];
$available = $status['available_categories'] ?? [];
$setup = $status['setup'] ?? Queue::setup($status);
$sync = $status['synchronization'] ?? Queue::synchronization($status);
$status['setup'] = $setup;
$status['synchronization'] = $sync;
$worker_ready = (bool) $available;
$github_ready = ($status['github'] ?? '') === 'Ready';
$api_ready = !empty($status['zabbix_configured']);
$labels = ['hosts' => _('Host e gruppi'), 'templates' => _('Template e gruppi'),
    'actions' => _('Azioni'), 'media_types' => _('Tipi di supporto'), 'users' => _('Utenti, gruppi e ruoli')];
$ui = [
    'complete' => _('Completato'), 'todo' => _('Da completare'), 'running' => _('In corso'),
    'failed' => _('Errore'), 'processed' => _('Elaborata'), 'waiting' => _('In attesa'),
    'sent' => _('Richiesta inviata. Attendi l’esito del worker.'),
    'queued' => _('Richiesta in coda. Se il worker è occupato, attende il termine dell’operazione corrente.'),
    'done' => _('Operazione completata.'),
    'unavailable' => _('Stato temporaneamente non disponibile. Il worker continua autonomamente; nuovo tentativo automatico.'),
    'session' => _('Sessione scaduta o accesso non consentito. Accedi nuovamente a Zabbix e riapri GitKeeper.'),
    'stale' => _('Lo stato non viene aggiornato da oltre 5 minuti. Verifica il servizio se l’attesa continua; un’esportazione lunga può essere ancora in corso.'),
    'installed' => _('GitKeeper installato.'), 'not_installed' => _('Worker non installato.'),
    'worker_unavailable' => _('Installazione presente, ma stato del worker non disponibile. Verifica servizio e permessi.'),
    'authorized' => _('Account autorizzato'), 'disconnected' => _('Account non autorizzato'),
    'device_pending' => _('In attesa di autorizzazione su GitHub'), 'expired' => _('Codice scaduto. Ricollega GitHub.'),
    'api_verified' => _('Token verificato e salvato'), 'api_absent' => _('Token non configurato'),
    'api_running' => _('Verifica del token in corso'), 'api_failed' => _('Verifica del token fallita'),
    'app_saved' => _('Client ID salvato'), 'app_absent' => _('Client ID da salvare'),
    'access' => _('App installata con accesso in lettura e scrittura ai repository elencati.'),
    'no_access' => _('Nessun repository privato accessibile. Verifica l’installazione sull’account proprietario, Contents read/write e l’accesso dell’utente; poi aggiorna l’elenco.'),
    'select' => _('Scegli un repository privato'), 'none' => _('Nessun repository selezionato'),
    'paused' => _('Pianificazione sospesa'), 'minutes' => _('minuti'), 'hours' => _('ore'),
    'days' => _('giorni'), 'day' => _('giorno'), 'hour' => _('ora'), 'minute' => _('minuto'),
    'three_days' => _('3 giorni'),
    'intervals' => Queue::INTERVALS, 'max_interval' => Queue::MAX_INTERVAL,
    'invalid_interval' => _('Inserisci un intervallo totale di almeno un minuto entro il limite supportato.'),
    'default_interval' => Queue::DEFAULT_INTERVAL, 'copied' => _('Copiato'),
    'copy_failed' => _('Seleziona e copia manualmente.'), 'unknown_result' => _('Esito della richiesta non più disponibile; consulta gli stati persistenti delle connessioni e della sincronizzazione.'),
    'change_app' => _('Cambiare App? L’autorizzazione GitHub locale e la scelta del repository saranno rimosse e la pianificazione sospesa. App e repository su GitHub rimangono invariati.'),
    'change_scope' => _('Passare a Solo utilizzati? Dopo una sincronizzazione riuscita, i file dei template esclusi scompariranno dalla revisione Git corrente. La cronologia precedente rimane invariata.'),
    'preview_counts' => _('Template trovati: {total}; inclusi: {selected}; esclusi: {excluded}; inclusioni manuali effettive: {manual_included}; manuali non più presenti: {missing_manual}.'),
    'preview_note' => _('Anteprima in sola lettura delle scelte correnti. Il perimetro viene ricalcolato durante la sincronizzazione.'),
    'scope_missing' => _('Avviso: le inclusioni manuali non più presenti nell’inventario verificato sono state ignorate.'),
    'http' => _('HTTP trasmette il token senza cifratura. Per un endpoint locale usa, quando verificato, l’indirizzo loopback.'),
    'category_states' => ['Running' => _('In elaborazione'), 'Completed' => _('Elaborata'),
        'exported' => _('Elaborata'), 'Exported' => _('Elaborata'),
        'Failed' => _('Errore'), 'Partial / Incomplete' => _('Incompleta')]
];
$form = static function(string $id): CForm {
    return (new CForm('post', (new CUrl('zabbix.php'))->setArgument('action', 'gitkeeper.save')->getUrl()))
        ->setId('gitkeeper-form-'.$id)->setAttribute('data-gitkeeper-form', '1')
        ->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('gitkeeper.save')))->removeId());
};
$submit = static function(string $operation, string $caption, bool $enabled = true, bool $secondary = false,
        string $confirmation = ''): CSubmit {
    $button = (new CSubmit('operation', $caption))->setId('gitkeeper-submit-'.$operation)
        ->setAttribute('value', $operation)->setEnabled($enabled);
    if ($confirmation !== '') { $button->setAttribute('data-confirm', $confirmation); }
    return $secondary ? $button->addClass(ZBX_STYLE_BTN_ALT) : $button;
};
$row = static function(CFormGrid $grid, string $label, $field, ?string $id = null): void {
    $grid->addItem([new CLabel($label, $id), new CFormField($field)]);
};
$paragraph = static fn(string $text) => (new CTag('p', true, $text))->addClass(ZBX_STYLE_GREY);
$span = static fn(string $id, $value = '') => (new CSpan($value))->setId('gitkeeper-'.$id);
$time = static fn($value) => is_numeric($value) ? date('Y-m-d H:i:s T', (int) $value) : '—';
$frequency = static function(int $minutes) use ($ui): string {
    $parts = [intdiv($minutes, 1440), intdiv($minutes % 1440, 60), $minutes % 60];
    $labels = [['day', 'days'], ['hour', 'hours'], ['minute', 'minutes']];
    $result = [];
    foreach ($parts as $index => $value) {
        if ($value) { $result[] = $value.' '.$ui[$labels[$index][$value === 1 ? 0 : 1]]; }
    }
    return implode(' ', $result);
};
$step = static function(int $index, string $title, string $explanation, $body) use ($setup, $ui): CTag {
    $details = (new CTag('details', true, [new CTag('summary', true, [
        $index.'. '.$title.' — ', (new CSpan($setup['steps'][$index] ? $ui['complete'] : $ui['todo']))
            ->setId('gitkeeper-step-status-'.$index)]), new CTag('p', true, $explanation), $body]))
        ->setId('gitkeeper-step-'.$index)->addClass('gitkeeper-step');
    if ($setup['active'] === $index && !$setup['operational']) { $details->setAttribute('open', 'open'); }
    return $details;
};

$installation = [$span('installation-state', $worker_ready ? $ui['installed']
    : (($status['worker_availability'] ?? '') === 'not_installed' ? $ui['not_installed'] : $ui['worker_unavailable']))];
if (!$worker_ready) {
    $frontend_root = realpath(__DIR__.'/../../..');
    $web_user = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ? posix_getpwuid(posix_geteuid()) : false;
    if ($frontend_root && is_file($frontend_root.'/zabbix.php')
            && is_file($frontend_root.'/include/defines.inc.php') && $web_user && $web_user['name'] !== 'root') {
        $installation[] = $paragraph(_('Esegui il comando in Bash sul server Zabbix. Dopo l’installazione riavvia il servizio PHP/Apache effettivo per applicare i permessi e riapri questa pagina.'));
        $installation[] = (new CTextArea('gitkeeper-install-command', Installation::command($frontend_root, $web_user['name']),
            ['rows' => 9, 'readonly' => true]))->disableSpellcheck()->setAdaptiveWidth(900);
        $installation[] = (new CButton('gitkeeper-copy-install', _('Copia')))
            ->setAttribute('data-copy', 'gitkeeper-install-command')->addClass(ZBX_STYLE_BTN_ALT);
    }
    $installation[] = new CLink(_('Guida di installazione e diagnostica'),
        'https://github.com/Capobuf/Zabbix-GitKeeper/blob/main/docs/installation.md');
}
$steps = [$step(0, _('Verifica installazione'), _('Il worker elabora le richieste e gli snapshot sul server.'), $installation)];

$api_forms = [];
foreach (['bootstrap', 'api'] as $mode) {
    $grid = new CFormGrid();
    if ($mode === 'bootstrap') {
        $row($grid, _('Consenso'), (new CCheckBox('consent', 1))->setLabel(
            _('Autorizzo la creazione di un token senza scadenza che eredita i permessi del mio account.'))
                ->setAttribute('required', true), 'consent');
    }
    else {
        $row($grid, _('Token esistente'), (new CTextBox('api_token'))->setAttribute('type', 'password')
            ->setAttribute('autocomplete', 'new-password')->setAttribute('maxlength', 64)
            ->setAttribute('required', true), 'api_token');
    }
    $advanced = new CFormGrid();
    $url_id = 'gitkeeper-'.$mode.'-url';
    $row($advanced, _('URL API'), (new CTextBox('api_url', $data['api_url']))->setId($url_id)
        ->setAttribute('required', true)->setAttribute('placeholder', 'https://zabbix.example/api_jsonrpc.php')
        ->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH), $url_id);
    $row($advanced, _('Certificati HTTPS'), (new CCheckBox('tls_skip_verify', 1))->setId('gitkeeper-'.$mode.'-tls')
        ->setChecked(($status['zabbix_verify_tls'] ?? true) === false)
        ->setLabel(_('Consenti certificati non validi o autofirmati (disabilita la verifica).')));
    $advanced->addItem(new CFormField($paragraph(_('La verifica TLS è abilitata per impostazione predefinita. Il token viene salvato soltanto dopo la verifica del worker.'))));
    $api_forms[$mode] = $form($mode)->addItem($grid)->addItem(
        (new CFormGrid())->addClass('gitkeeper-sections')->addItem(
            (new CFormFieldsetCollapsible(_('Opzioni avanzate API'), new CFormField($advanced)))
                ->setExpanded($data['api_url'] === '')))
        ->addItem(makeFormFooter($submit($mode === 'bootstrap' ? 'create_zabbix_token' : 'set_zabbix',
            $mode === 'bootstrap' ? _('Crea e verifica token') : _('Verifica e usa token'), $worker_ready)));
}
$steps[] = $step(1, _('Accesso alla configurazione Zabbix'),
    _('Crea un token attraverso le API native Zabbix. Il worker lo usa anche quando il browser è chiuso.'), [
        $span('api-verification', $api_ready ? $ui['api_verified'] : $ui['api_absent'])->setAttribute('role', 'status'),
        $span('api-verification-error', $status['zabbix_verification']['error'] ?? '')->addClass(ZBX_STYLE_RED),
        $paragraph($data['api_url_source']), $span('api-url-warning'), $api_forms['bootstrap'],
        new CTag('details', true, [new CTag('summary', true, _('Usa un token esistente')), $api_forms['api']])
    ]);

// Only documented registration parameters; Device Flow is enabled manually on GitHub.
$registration_params = ['description' => 'Zabbix GitKeeper configuration snapshots',
    'url' => 'https://github.com/Capobuf/Zabbix-GitKeeper', 'contents' => 'write',
    'webhook_active' => 'false', 'request_oauth_on_install' => 'false', 'public' => 'false'];
$registration_url = 'https://github.com/settings/apps/new?'.http_build_query($registration_params);
$app_grid = new CFormGrid();
$row($app_grid, _('Client ID della tua GitHub App'),
    (new CTextBox('client_id', $status['github_client_id'] ?? ''))->setAttribute('maxlength', 128)
        ->setAttribute('autocomplete', 'off')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH), 'client_id');
$app_config = (new CDiv([
    (new CLink(_('Crea la tua GitHub App'), $registration_url))->setId('gitkeeper-register-app'),
    $paragraph(_('Scegli un nome univoco. Abilita manualmente Device Flow e lascia abilitata la scadenza dei token utente. Nessun webhook, callback URL, Client Secret o chiave privata richiesto. L’autorizzazione OAuth durante l’installazione non serve.')),
    new CTag('details', true, [new CTag('summary', true, _('Repository di un’organizzazione o creazione da GitKeeper')),
        $paragraph(_('Per un’organizzazione registra preferibilmente l’App nelle sue Developer settings. Deve essere installabile sull’account proprietario: un’App privata personale non è automaticamente installabile in un’altra organizzazione.')),
        (new CTextBox('gitkeeper-app-organization'))->setAttribute('placeholder', _('Nome organizzazione')),
        ' ', (new CCheckBox('gitkeeper-app-creation', 1))->setLabel(_('Creerò il repository da GitKeeper (Repository creation: write).')),
        $paragraph(_('La creazione da GitKeeper richiede un permesso aggiuntivo; lascialo disabilitato se userai un repository esistente.')),
        (new CTag('p', true, _('Nelle impostazioni GitHub abilita manualmente Repository creation: write. Il collegamento usa solo parametri URL verificati.')))
            ->setId('gitkeeper-app-creation-guide')->setAttribute('hidden', 'hidden')]),
    $paragraph(_('Copia il Client ID dalle impostazioni dell’App: è diverso dall’App ID numerico. Cambiare Client ID disconnette GitHub e sospende la pianificazione; salvare lo stesso ID conserva il collegamento.')),
    $form('app')->addItem($app_grid)->addItem(makeFormFooter($submit('set_github_app', _('Salva Client ID'), $worker_ready))),
    $span('app-state', !empty($status['github_client_id']) ? $ui['app_saved'] : $ui['app_absent'])
]))->setId('gitkeeper-app-config')->setAttribute('data-registration-url', $registration_url);
$github_grid = new CFormGrid();
$row($github_grid, _('Autorizzazione account'), $span('connection', $github_ready ? $ui['authorized'] : $ui['disconnected']));
$row($github_grid, _('Account'), $span('account', $status['account'] ?? '—'));
$row($github_grid, _('Esito del collegamento'), $span('connection-error')->setAttribute('role', 'status'));
$device = (new CDiv([
    (new CTag('strong', true, $status['device']['user_code'] ?? ''))->setId('gitkeeper-device-code'), ' ',
    (new CButton('gitkeeper-copy-device', _('Copia codice')))->setAttribute('data-copy', 'gitkeeper-device-code')->addClass(ZBX_STYLE_BTN_ALT),
    ' ', new CLink(_('Autorizza su GitHub'), 'https://github.com/login/device'),
    $paragraph(_('Inserisci il codice su GitHub e torna qui per l’esito. Questo autorizza l’account; l’installazione dell’App è un passaggio distinto.'))
]))->setId('gitkeeper-device');
if (empty($status['device'])) { $device->setAttribute('hidden', 'hidden'); }
$steps[] = $step(2, _('Crea e collega la tua GitHub App'),
    _('Ogni installazione usa la propria App. Registra l’App, salva il Client ID, poi autorizza il tuo account.'), [
        $app_config,
        (new CLink(_('Modifica il Client ID nelle opzioni avanzate'), '#gitkeeper-settings-app'))->setId('gitkeeper-app-settings-link'),
        $github_grid, $device,
        $form('github')->addItem(makeFormFooter($submit('connect', _('Collega / ricollega GitHub'),
            $worker_ready && !empty($status['github_client_id']) && !$github_ready && empty($status['device'])), [
                $submit('test_github', _('Verifica connessione'), $worker_ready && $github_ready, true)])),
        $span('installation-access', !empty($status['repositories']) ? $ui['access'] : $ui['no_access']),
        new CTag('p', true, new CLink(_('Gestisci le GitHub App installate'), 'https://github.com/settings/installations'))
    ]);

$repository_grid = new CFormGrid();
$repos = array_values(array_filter($status['repositories'] ?? [], static fn($repo) => ($repo['private'] ?? false) === true));
$repository_id = $settings['repository_id'] ?? (count($repos) === 1 ? $repos[0]['id'] : 0);
$selector = (new CSelect('repository_id'))->setId('gitkeeper-repository')->setFocusableElementId('gitkeeper-repository-focus')
    ->setValue($repository_id)->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)->addOption(new CSelectOption(0, $ui['select']));
foreach ($repos as $repo) {
    // Native ZSelect evaluates its option labels as HTML templates.
    $selector->addOption(new CSelectOption($repo['id'],
        htmlspecialchars($repo['full_name'].' ('.$repo['default_branch'].')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
}
$row($repository_grid, _('Repository privato e branch'), $selector, 'gitkeeper-repository-focus');
$row($repository_grid, _('Destinazione confermata'), $span('destination', $status['repository']['full_name'] ?? $ui['none']));
$create = new CFormGrid();
$row($create, _('Proprietario (account o organizzazione)'), (new CTextBox('repository_owner', $status['account'] ?? ''))
    ->setAttribute('maxlength', 100), 'repository_owner');
$row($create, _('Nome repository'), (new CTextBox('repository_name'))->setAttribute('maxlength', 100), 'repository_name');
$steps[] = $step(3, _('Repository di destinazione'),
    _('Installa l’App sull’account proprietario e concedile accesso al repository privato. Se è disponibile un solo repository, conferma comunque la scelta.'), [
        $form('repository')->addItem($repository_grid)->addItem(makeFormFooter(
            $submit('select_repository', _('Usa repository'), $worker_ready && $github_ready && (bool) $repos),
            [$submit('repositories', _('Aggiorna elenco'), $worker_ready && $github_ready, true)])),
        $paragraph(_('Cambiare destinazione sospende la pianificazione fino a Salva e sincronizza. Il branch usato è quello predefinito del repository.')),
        new CTag('details', true, [new CTag('summary', true, _('Crea nuovo repository')),
            $paragraph(_('La creazione è sempre privata. Richiede Repository creation: write e il permesso di creare repository per il proprietario scelto.')),
            $form('create-repository')->addItem($create)->addItem(makeFormFooter(
                $submit('create_repository', _('Crea e seleziona repository privato'), $worker_ready && $github_ready))),
            $paragraph(_('Se GitHub ha creato il repository ma manca l’accesso dell’App, concedilo e aggiorna l’elenco: non ricreare il repository. Se la creazione diretta non è consentita, crea su GitHub e concedi accesso all’App.')),
            new CLink(_('Crea su GitHub'), 'https://github.com/new')])
    ]);

$configuration = new CFormGrid();
$checks = [];
foreach ($labels as $category => $label) {
    $checks[] = new CDiv((new CCheckBox('categories[]', $category))->setId('gitkeeper-category-'.$category)
        ->setLabel($label)->setChecked(in_array($category, $settings['categories'] ?? $available, true))
        ->setEnabled(in_array($category, $available, true)));
}
$row($configuration, _('Categorie'), $checks);
$scope_mode = $settings['template_scope']['mode'] ?? (!empty($settings['categories']) ? 'all' : 'used');
$scope_grid = new CFormGrid();
$row($scope_grid, _('Modalità'), (new CSelect('template_mode'))->setId('gitkeeper-template-mode')
    ->setFocusableElementId('gitkeeper-template-mode-focus')->setValue($scope_mode)
    ->addOptions([new CSelectOption('used', _('Solo utilizzati')), new CSelectOption('all', _('Tutti'))]),
    'gitkeeper-template-mode-focus');
$manual = (new CMultiSelect([
    'name' => 'include_templateids[]', 'object_name' => 'templates', 'data' => $data['manual_templates'] ?? [],
    'popup' => ['parameters' => ['srctbl' => 'templates', 'srcfld1' => 'hostid', 'srcfld2' => 'host',
        'dstfrm' => 'gitkeeper-configuration', 'dstfld1' => 'include_templateids_']]
]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH);
$scope_grid->addItem([(new CLabel(_('Inclusioni manuali'), 'include_templateids__ms'))->setId('gitkeeper-template-manual-label'),
    (new CFormField($manual))->setId('gitkeeper-template-manual')]);
$scope_grid->addItem(new CFormField([
    $submit('preview_scope', _('Anteprima perimetro'), $worker_ready && $api_ready, true)
        ->setAttribute('formnovalidate', 'formnovalidate'),
    $span('scope-preview')->setAttribute('role', 'status'), $paragraph($ui['preview_note']),
    $paragraph(_('Sono inclusi i template collegati agli host, anche disabilitati o scoperti, alle azioni e ai prototype pertinenti, più le dipendenze e le inclusioni manuali. I gruppi template restano completi. I file esclusi vengono rimossi dalla revisione Git corrente dopo una sincronizzazione riuscita; la cronologia resta invariata.'))
]));
$configuration->addItem(new CFormField(new CFormFieldsetCollapsible(_('Template da versionare'), new CFormField($scope_grid))));
$saved_interval = $settings['interval'] ?? Queue::DEFAULT_INTERVAL;
$custom_interval = !in_array($saved_interval, Queue::INTERVALS, true);
$interval = (new CSelect('interval'))->setId('gitkeeper-interval')->setFocusableElementId('gitkeeper-interval-focus')
    ->setValue($custom_interval ? 0 : $saved_interval);
foreach (Queue::INTERVALS as $minutes) {
    $interval->addOption(new CSelectOption($minutes, $minutes === 4320 ? $ui['three_days']
        : ($minutes === 1440 ? _('24 ore') : $minutes.' '._('minuti'))));
}
$interval->addOption(new CSelectOption(0, _('Personalizzato')));
$row($configuration, _('Frequenza'), $interval, 'gitkeeper-interval-focus');
$duration = new CFormGrid();
foreach (['days' => [_('Giorni'), intdiv($saved_interval, 1440), intdiv(Queue::MAX_INTERVAL, 1440)],
        'hours' => [_('Ore'), intdiv($saved_interval % 1440, 60), 23],
        'minutes' => [_('Minuti'), $saved_interval % 60, 59]] as $unit => [$label, $value, $max]) {
    $id = 'gitkeeper-interval-'.$unit;
    $row($duration, $label, (new CTextBox('interval_'.$unit, $value))->setId($id)
        ->setAttribute('type', 'number')->setAttribute('min', 0)->setAttribute('max', $max)
        ->setAttribute('step', 1)->setAttribute('required', true)->setEnabled($custom_interval)
        ->setWidth(ZBX_TEXTAREA_SMALL_WIDTH), $id);
}
$custom_field = (new CFormField([$duration, $paragraph(_('Intervallo minimo: un minuto.'))]))
    ->setId('gitkeeper-custom-interval')->addClass(CFormField::ZBX_STYLE_FORM_FIELD_OFFSET_1);
if (!$custom_interval) { $custom_field->setAttribute('hidden', 'hidden'); }
$configuration->addItem($custom_field);
$configuration_form = $form('configuration')->setName('gitkeeper-configuration')->addItem(
    new CVar('repository_id', $settings['repository_id'] ?? 0, 'gitkeeper-saved-repository'))
    ->addItem($configuration)->addItem($paragraph(_('Sono versionati i dati leggibili secondo la policy corrente sui dati riservati; consulta i campi esclusi. Il ripristino completo dell’istanza e il disaster recovery non sono disponibili.')))
    ->addItem(makeFormFooter($submit('save', _('Salva e sincronizza'), $sync['can_save'])));
$steps[] = $step(4, _('Cosa sincronizzare'), _('Salva categorie e frequenza e avvia uno snapshot. Le esecuzioni successive seguono la frequenza scelta.'), $configuration_form);

$summary = new CFormGrid();
$row($summary, _('Ultima sincronizzazione'), $span('synchronization', $sync['label'])->setAttribute('role', 'status'));
$row($summary, _('Ultimo snapshot riuscito'), $span('last-success', $time($status['last_success'] ?? null)));
$row($summary, _('Prossima esecuzione'), $span('next-sync', isset($settings['next_sync']) ? $time($settings['next_sync']) : $ui['paused']));
$row($summary, _('Repository e branch'), $span('summary-repository', isset($status['repository'])
    ? $status['repository']['full_name'].' ('.$status['repository']['default_branch'].')' : $ui['none']));
$row($summary, _('Frequenza'), $span('frequency', isset($settings['interval']) ? $frequency($settings['interval']) : '—'));
$repo_name = $status['repository']['full_name'] ?? '';
$row($summary, _('Ultimo commit'), (new CLink(isset($status['last_commit']) ? substr($status['last_commit'], 0, 12) : '—',
    !empty($status['last_commit']) && $repo_name ? 'https://github.com/'.$repo_name.'/commit/'.$status['last_commit'] : '#'))->setId('gitkeeper-commit'));
$table = (new CTableInfo())->setId('gitkeeper-category-results')->setHeader(
    [_('Categoria'), _('Stato'), _('Oggetti'), _('Host/template elaborati'), _('Campi esclusi')]);
foreach ($labels as $category => $label) {
    $result = $status['categories'][$category] ?? [];
    $table->addRow((new CRow([$label, $span('category-state-'.$category, $ui['category_states'][$result['state'] ?? ''] ?? '—'),
        $span('category-objects-'.$category, $result['objects'] ?? '—'),
        $span('category-progress-'.$category, isset($result['total']) ? ($result['processed'] ?? 0).' / '.$result['total'] : '—'),
        $span('category-excluded-'.$category, count($result['excluded_fields'] ?? []))]))->setId('gitkeeper-category-row-'.$category));
}
$excluded = new CTag('details', true, [new CTag('summary', true, _('Dettaglio campi esclusi')),
    (new CTag('pre', true, json_encode(array_map(static fn($r) => $r['excluded_fields'] ?? [], $status['categories'] ?? []),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)))->setId('gitkeeper-excluded')]);
$wizard = (new CDiv($setup['operational'] ? [] : $steps))->setId('gitkeeper-wizard');
if ($setup['operational']) { $wizard->setAttribute('hidden', 'hidden'); }
$operational = (new CDiv([$summary]))->setId('gitkeeper-operational');
if (!$setup['operational']) { $operational->setAttribute('hidden', 'hidden'); }
$overview = [
    $paragraph(_('Versiona la configurazione Zabbix in un repository GitHub privato.')),
    $form('sync')->addItem(makeFormFooter($submit('sync', _('Sincronizza ora'), $sync['ready']
        && !in_array($sync['state'], ['Queued', 'Saving', 'Running', 'Publishing'], true)))),
    $span('sync-feedback', $sync['message'])->setAttribute('role', 'status')->setAttribute('aria-live', 'polite'),
    (new CList())->setId('gitkeeper-sync-requirements'), $span('worker-warning')->setAttribute('role', 'status'),
    $wizard, $operational, $paragraph(_('Le categorie elaborate diventano uno snapshot soltanto dopo la pubblicazione riuscita.')),
    $table, $span('scope-summary')->setAttribute('role', 'status'), $excluded
];
$connections = (new CDiv($setup['operational'] ? array_slice($steps, 0, 4) : []))->setId('gitkeeper-settings-connections');
$sync_settings = (new CDiv($setup['operational'] ? $steps[4] : []))->setId('gitkeeper-settings-sync');
$advanced = [
    new CLink(_('Gestisci / revoca token API Zabbix'), (new CUrl('zabbix.php'))->setArgument('action', 'token.list')),
    new CTag('p', true, new CLink(_('Revoca autorizzazioni GitHub'), 'https://github.com/settings/apps/authorizations')),
    $form('remove')->addItem(makeFormFooter($submit('clear_zabbix', _('Rimuovi token locale Zabbix'), $api_ready, true,
        _('Rimuovere il token locale? La pianificazione sarà sospesa. Il token rimane valido su Zabbix: revocalo separatamente nella gestione token.')),
        [$submit('disconnect', _('Disconnetti GitHub'), $worker_ready, true,
            _('Eliminare i token GitHub locali e sospendere la pianificazione? App, repository e commit rimangono su GitHub. La revoca dell’autorizzazione si effettua separatamente su GitHub.'))])),
    new CLink(_('Diagnostica del worker'), 'https://github.com/Capobuf/Zabbix-GitKeeper/blob/main/docs/installation.md#diagnose-failures-and-uninstall')
];
$settings_body = [
    (new CDiv(new CLink(_('Completa la configurazione in Panoramica'), '#gitkeeper-tab-overview')))->setId('gitkeeper-setup-link'),
    (new CFormGrid())->addClass('gitkeeper-sections')->addItem([
        new CFormFieldset(_('Sincronizzazione'), new CFormField($sync_settings)),
        new CFormFieldset(_('Connessioni'), new CFormField($connections)),
        new CFormFieldsetCollapsible(_('Avanzate e gestione credenziali'), new CFormField([
            (new CDiv())->setId('gitkeeper-settings-app'), $advanced]))])
];
$error = $sync['error'] ?? $status['error'] ?? '';
$logo_variant = in_array(getUserTheme(CWebUser::$data), ['dark-theme', 'hc-dark'], true) ? 'white' : 'black';
$brand = (new CDiv(new CImg($this->getAssetsPath().'/img/logo-'.$logo_variant.'.svg', _('Zabbix GitKeeper'))))
    ->addClass('gitkeeper-brand');
$root = (new CDiv([
    $brand,
    (new CDiv($error))->setId('gitkeeper-error')->addClass(ZBX_STYLE_RED)->setAttribute('role', 'alert'),
    $span('operation-feedback')->setAttribute('role', 'status')->setAttribute('aria-live', 'polite'),
    $span('copy-result')->setAttribute('role', 'status'),
    (new CTabView(['id' => 'gitkeeper-tabs']))->addTab('gitkeeper-tab-overview', _('Panoramica'), $overview)
        ->addTab('gitkeeper-tab-settings', _('Impostazioni'), $settings_body)
]))->setId('gitkeeper')->setAttribute('data-status', json_encode($status))
    ->setAttribute('data-ui', json_encode($ui))->setAttribute('data-request-id', $data['request_id'] ?? '')
    ->setAttribute('data-api-request', $data['api_request'] ?? '')->setAttribute('data-sync-request', $data['sync_request'] ?? '')
    ->setAttribute('data-operation', $data['request_operation'] ?? '');
(new CHtmlPage())->setTitle(_('GitKeeper'))->addItem($root)->show();
