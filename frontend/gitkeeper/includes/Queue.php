<?php
namespace Modules\GitKeeper;

use RuntimeException;

final class Queue {
    public const CATEGORIES = ['hosts', 'templates', 'actions', 'media_types', 'users'];
    public const DEFAULT_INTERVAL = 4320;
    public const INTERVALS = [5, 15, 30, 60, 1440, 4320];
    public const MAX_INTERVAL = 2147483647; // Native int32 input, in minutes.
    private string $root;

    public function __construct(?string $root = null) {
        $config_file = __DIR__.'/../local.php';
        $config = is_file($config_file) ? require $config_file : [];
        $this->root = $root ?? ($config['state_dir'] ?? '/var/lib/zabbix-gitkeeper');
    }

    public static function apiUrl(string $url): void {
        $parts = parse_url($url);
        if (strlen($url) > 2048 || !$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])
                || !str_ends_with($parts['path'] ?? '', '/api_jsonrpc.php')) {
            throw new RuntimeException('Use an HTTP or HTTPS URL ending in /api_jsonrpc.php without credentials.');
        }
    }

    public static function customInterval(int $days, int $hours, int $minutes): int {
        if ($days < 0 || $days > intdiv(self::MAX_INTERVAL, 1440)
                || $hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            throw new RuntimeException('Invalid custom interval components.');
        }
        $interval = $days * 1440 + $hours * 60 + $minutes;
        if ($interval < 1 || $interval > self::MAX_INTERVAL) {
            throw new RuntimeException('Invalid custom interval total.');
        }
        return $interval;
    }

    public static function validate(string $operation, array $params): void {
        $allowed = ['connect' => [], 'disconnect' => [], 'repositories' => [], 'test_github' => [],
            'sync' => [], 'clear_zabbix' => [], 'save' => ['settings'],
            'set_zabbix' => ['url', 'token'], 'create_repository' => ['name'],
            'set_github_app' => ['client_id'], 'select_repository' => ['repository_id'],
            'preview_scope' => ['template_scope']];
        $keys = array_keys($params);
        if ($operation === 'create_repository' && array_key_exists('owner', $params)) {
            $keys = array_diff($keys, ['owner']);
        }
        if ($operation === 'set_zabbix' && array_key_exists('verify_tls', $params)) {
            $keys = array_diff($keys, ['verify_tls']);
            if (!is_bool($params['verify_tls'])) {
                throw new RuntimeException('Invalid TLS verification setting.');
            }
        }
        sort($keys);
        if (!isset($allowed[$operation])) {
            throw new RuntimeException('Unsupported operation.');
        }
        $expected = $allowed[$operation];
        sort($expected);
        if ($keys !== $expected) {
            throw new RuntimeException('Unsupported parameters.');
        }
        if ($operation === 'save') {
            $s = $params['settings'];
            if (!is_array($s)) {
                throw new RuntimeException('Invalid settings.');
            }
            $keys = array_keys($s);
            $keys = array_diff($keys, ['template_scope']);
            $keys = array_values($keys);
            sort($keys);
            if ($keys !== ['categories', 'interval', 'repository_id'] || !is_int($s['repository_id'])
                    || $s['repository_id'] <= 0 || !is_int($s['interval'])
                    || $s['interval'] < 1 || $s['interval'] > self::MAX_INTERVAL
                    || !is_array($s['categories']) || !$s['categories']
                    || array_diff($s['categories'], self::CATEGORIES)
                    || count(array_unique($s['categories'])) !== count($s['categories'])) {
                throw new RuntimeException('Select a repository, implemented categories and a valid interval.');
            }
            if (array_key_exists('template_scope', $s)) {
                self::templateScope($s['template_scope']);
            }
        }
        elseif ($operation === 'preview_scope') {
            self::templateScope($params['template_scope']);
        }
        elseif ($operation === 'set_zabbix') {
            self::apiUrl($params['url']);
            if (!is_string($params['token']) || !preg_match('/^[a-fA-F0-9]{64}$/D', $params['token'])) {
                throw new RuntimeException('Invalid API token format.');
            }
        }
        elseif ($operation === 'set_github_app'
                && (!is_string($params['client_id']) || !preg_match('/^[A-Za-z0-9_.-]{4,128}$/D', $params['client_id']))) {
            throw new RuntimeException('Enter the GitHub App Client ID from its settings page.');
        }
        elseif ($operation === 'create_repository'
                && (!is_string($params['name']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $params['name']))) {
            throw new RuntimeException('Invalid repository name.');
        }
        if ($operation === 'create_repository' && array_key_exists('owner', $params)
                && (!is_string($params['owner']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,99}$/D', $params['owner']))) {
            throw new RuntimeException('Enter a GitHub account or organization name.');
        }
        if ($operation === 'select_repository' && (!is_int($params['repository_id']) || $params['repository_id'] <= 0)) {
            throw new RuntimeException('Select an authorized private repository.');
        }
    }

    public static function templateScope($scope): array {
        if (!is_array($scope)) {
            throw new RuntimeException('Invalid template scope.');
        }
        $keys = array_keys($scope);
        sort($keys);
        if ($keys !== ['include_templateids', 'mode'] || !in_array($scope['mode'], ['all', 'used'], true)
                || !is_array($scope['include_templateids'])
                || $scope['include_templateids'] !== array_values($scope['include_templateids'])
                || count($scope['include_templateids']) > 1000) {
            throw new RuntimeException('Invalid template scope fields, mode or size.');
        }
        foreach ($scope['include_templateids'] as $id) {
            if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
                throw new RuntimeException('Template IDs must be positive numeric strings.');
            }
        }
        $ids = array_values(array_unique($scope['include_templateids']));
        usort($ids, static fn($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
        return ['mode' => $scope['mode'], 'include_templateids' => $ids];
    }

    public function submit(string $operation, array $params = []): string {
        self::validate($operation, $params);
        if ($operation === 'save' && isset($params['settings']['template_scope'])) {
            $params['settings']['template_scope'] = self::templateScope($params['settings']['template_scope']);
        }
        elseif ($operation === 'preview_scope') {
            $params['template_scope'] = self::templateScope($params['template_scope']);
        }
        $directory = $this->root.'/queue';
        if (!is_dir($directory) || !is_writable($directory) || count(glob($directory.'/*.json')) >= 100) {
            throw new RuntimeException('Worker queue unavailable or full. Check installation and cron.');
        }
        $id = bin2hex(random_bytes(16));
        $temp = $directory.'/.'.$id.'.tmp';
        $stream = fopen($temp, 'x');
        if (!$stream) {
            throw new RuntimeException('Cannot open worker queue.');
        }
        try {
            // Owner (PHP) may write but cannot read; the worker reads via its group.
            if (!chmod($temp, 0240)) {
                throw new RuntimeException('Cannot protect queue request.');
            }
            $data = json_encode(['id' => $id, 'operation' => $operation, 'params' => (object) $params],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($data) > 65536) {
                throw new RuntimeException('Queue request too large.');
            }
            if (fwrite($stream, $data) !== strlen($data) || !fflush($stream)) {
                throw new RuntimeException('Cannot write worker queue.');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temp, $directory.'/'.$id.'.json')) {
                throw new RuntimeException('Cannot publish worker request.');
            }
        }
        finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temp)) {
                unlink($temp);
            }
        }
        return $id;
    }

    public static function setup(array $status): array {
        $settings = $status['settings'] ?? [];
        $repository = false;
        foreach ($status['repositories'] ?? [] as $repo) {
            if (($repo['private'] ?? false) === true && $repo['id'] == ($settings['repository_id'] ?? 0)) {
                $repository = true;
            }
        }
        $steps = [!empty($status['available_categories']), !empty($status['zabbix_configured']),
            !empty($status['github_client_id']) && ($status['github'] ?? '') === 'Ready', $repository,
            !empty($settings['categories']) && !empty($settings['interval']) && !empty($status['last_success'])];
        $active = array_search(false, $steps, true);
        // Existing installations go straight to the operational view, even after a failed attempt.
        $operational = !empty($status['last_success']) || (!empty($settings['repository_id'])
            && !empty($settings['categories']) && !empty($settings['interval'])
            && empty($settings['sync_pending']) && ($status['state'] ?? '') !== 'Running');
        return ['steps' => $steps, 'active' => $active === false ? 4 : $active, 'operational' => $operational];
    }

    public static function synchronization(array $status): array {
        $setup = self::setup($status);
        $missing = [];
        $reasons = [
            [_('Stato del worker non disponibile.'), _('Verifica installazione')],
            [_('Token API Zabbix non verificato.'), _('Configura Zabbix')],
            [_('GitHub App non configurata o account non autorizzato.'), _('Collega GitHub')],
            [_('Nessun repository privato accessibile selezionato.'), _('Scegli repository')],
            [_('Categorie e frequenza non salvate.'), _('Salva e sincronizza')]
        ];
        foreach ($setup['steps'] as $step => $complete) {
            if ($step === 4) {
                $complete = !empty($status['settings']['categories']) && !empty($status['settings']['interval']);
            }
            if (!$complete) {
                $missing[] = [...$reasons[$step], 'gitkeeper-step-'.$step];
            }
        }
        $state = $status['state'] ?? '';
        $op = $status['current_request']['operation'] ?? $status['operation'] ?? '';
        $last_sync = $status['last_sync'] ?? [];
        if ($state === 'Running' && in_array($op, ['save', 'sync'], true)) {
            $phase = $op === 'save' ? 'Saving' : (($status['sync_phase'] ?? '') === 'Publishing' ? 'Publishing' : 'Running');
        }
        elseif (!empty($status['sync_request_pending']) || !empty($status['settings']['sync_pending'])) {
            $phase = 'Queued';
        }
        elseif (($last_sync['state'] ?? '') === 'Failed'
                || (in_array($state, ['Failed', 'Partial / Incomplete'], true) && in_array($op, ['save', 'sync'], true))) {
            $phase = 'Failed';
        }
        elseif ($missing) {
            $phase = 'Incomplete';
        }
        else {
            $phase = !empty($status['last_success']) ? 'Completed' : 'Ready';
        }
        $labels = ['Incomplete' => _('Configurazione da completare'), 'Queued' => _('Richiesta in coda'),
            'Saving' => _('Verifica prerequisiti e impostazioni'), 'Running' => _('Esportazione in corso'),
            'Publishing' => _('Pubblicazione su GitHub'), 'Failed' => _('Sincronizzazione fallita'),
            'Completed' => _('Snapshot pubblicato'), 'Ready' => _('Pronto per sincronizzare')];
        $messages = ['Incomplete' => _('Completa i passaggi indicati per avviare uno snapshot.'),
            'Queued' => _('Richiesta inviata. Se il worker è occupato, attende il termine dell’operazione corrente. Non inviarla di nuovo.'),
            'Saving' => _('Il worker verifica repository, token e categorie prima di salvare le impostazioni.'),
            'Running' => _('Le categorie vengono elaborate. L’esportazione può richiedere minuti; lo snapshot non è ancora pubblicato.'),
            'Publishing' => _('Esportazione terminata. Preparazione del commit e pubblicazione su GitHub in corso.'),
            'Failed' => _('Controlla l’errore e i risultati delle categorie. Puoi correggere le connessioni nelle Impostazioni o riprovare.'),
            'Completed' => _('Snapshot pubblicato correttamente. Il collegamento al commit è disponibile nel riepilogo.'),
            'Ready' => _('Avvia la sincronizzazione con le impostazioni salvate.')];
        if ($phase === 'Completed' && ($last_sync['changed'] ?? $status['changed'] ?? null) === false) {
            $labels['Completed'] = _('Nessuna modifica rilevata');
            $messages['Completed'] = _('Sincronizzazione riuscita senza modifiche. Il commit esistente resta disponibile.');
        }
        return ['ready' => !$missing, 'can_save' => !array_filter($missing,
                static fn($item) => $item[2] !== 'gitkeeper-step-4'),
            'state' => $phase, 'label' => $labels[$phase], 'message' => $messages[$phase], 'missing' => $missing,
            'error' => $last_sync['error'] ?? (in_array($op, ['save', 'sync'], true) ? ($status['error'] ?? null) : null)];
    }

    public function status(string $api_request = '', string $sync_request = '', string $request_id = ''): array {
        $file = $this->root.'/public/status.json';
        $status = is_readable($file) ? json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR)
            : ['state' => 'Not configured', 'github' => 'Disconnected',
                'error' => 'Worker status unavailable. Check installation and cron.'];
        $worker_state = $status['state'] ?? 'Not configured';
        $pending = glob($this->root.'/queue/*.json');
        if ($pending && ($status['state'] ?? '') !== 'Running') {
            $status['state'] = 'Queued';
        }
        $status['worker_availability'] = is_readable($file) ? 'available'
            : (is_dir($this->root.'/queue') ? 'unavailable' : 'not_installed');
        $status['worker_stale'] = isset($status['updated_at']) && time() - $status['updated_at'] > 300;
        // Migrate only the public summary for a pre-refactor failed snapshot, before another operation hides it.
        if (empty($status['last_sync']) && in_array($worker_state, ['Failed', 'Partial / Incomplete'], true)
                && in_array($status['operation'] ?? '', ['save', 'sync'], true)) {
            $status['last_sync'] = ['state' => 'Failed', 'error' => $status['error'] ?? null];
        }
        $status['queued_requests'] = count($pending);
        // Match the redirected request by filename, without reading token-bearing queue contents.
        if (preg_match('/^[a-f0-9]{32}$/D', $api_request)
                && is_file($this->root.'/queue/'.$api_request.'.json')) {
            $status['zabbix_verification'] = ['id' => $api_request, 'state' => 'Queued', 'error' => null];
        }
        $status['sync_request_pending'] = preg_match('/^[a-f0-9]{32}$/D', $sync_request)
            && is_file($this->root.'/queue/'.$sync_request.'.json');
        // Unrelated queued operations must not hide an export already in progress.
        $status['synchronization'] = self::synchronization(array_replace($status, ['state' => $worker_state]));
        $status['setup'] = self::setup($status);
        $status['request_pending'] = preg_match('/^[a-f0-9]{32}$/D', $request_id)
            && is_file($this->root.'/queue/'.$request_id.'.json');
        return $status;
    }
}
