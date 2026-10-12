<?php
namespace Modules\GitKeeper;

use RuntimeException;

final class Queue {
    public const CATEGORIES = ['hosts', 'templates', 'actions', 'media_types', 'users'];
    public const INTERVALS = [5, 15, 30, 60, 1440];
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

    public static function validate(string $operation, array $params): void {
        $allowed = ['connect' => [], 'disconnect' => [], 'repositories' => [], 'test_github' => [],
            'sync' => [], 'clear_zabbix' => [], 'save' => ['settings'],
            'set_zabbix' => ['url', 'token'], 'create_repository' => ['name'],
            'set_github_app' => ['client_id'], 'select_repository' => ['repository_id']];
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
            sort($keys);
            if ($keys !== ['categories', 'interval', 'repository_id'] || !is_int($s['repository_id'])
                    || $s['repository_id'] <= 0 || !in_array($s['interval'], self::INTERVALS, true)
                    || !is_array($s['categories']) || !$s['categories']
                    || array_diff($s['categories'], self::CATEGORIES)
                    || count(array_unique($s['categories'])) !== count($s['categories'])) {
                throw new RuntimeException('Select a repository, implemented categories and a valid interval.');
            }
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

    public function submit(string $operation, array $params = []): string {
        self::validate($operation, $params);
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

    public static function synchronization(array $status): array {
        $settings = $status['settings'] ?? [];
        $missing = [];
        if (empty($status['available_categories'])) {
            $missing[] = [_('Worker status is unavailable. Install the worker or check cron.'),
                _('Open Overview'), 'gitkeeper-tab-overview'];
        }
        if (empty($status['github_client_id'])) {
            $missing[] = [_('The GitHub App Client ID has not been saved.'),
                _('Configure GitHub App'), 'gitkeeper-tab-github'];
        }
        if (($status['github'] ?? '') !== 'Ready') {
            $missing[] = [_('GitHub authorization is not complete.'), _('Connect GitHub'), 'gitkeeper-tab-github'];
        }
        $repositories = array_column($status['repositories'] ?? [], 'id');
        if (empty($settings['repository_id']) || !in_array($settings['repository_id'], $repositories)) {
            $missing[] = [_('No accessible private repository has been selected.'),
                _('Select a repository'), 'gitkeeper-tab-github'];
        }
        if (empty($status['zabbix_configured'])) {
            $missing[] = [_('The Zabbix API token has not been configured and verified.'),
                _('Configure Zabbix API'), 'gitkeeper-tab-api'];
        }
        $can_save = !$missing;
        if (empty($settings['categories']) || empty($settings['interval'])) {
            $missing[] = [_('Backup categories and the export interval have not been saved. Click Save & Sync to save them and start the first snapshot.'),
                _('Open Configuration'), 'gitkeeper-tab-configuration'];
        }
        $state = $status['state'] ?? '';
        if (!empty($status['sync_request_pending'])) {
            $phase = 'Queued';
        }
        elseif ($state === 'Running' && in_array($status['operation'] ?? '', ['save', 'sync'], true)) {
            $phase = $missing ? 'Saving' : 'Running';
        }
        elseif (in_array($state, ['Failed', 'Partial / Incomplete'], true)
                && in_array($status['operation'] ?? '', ['save', 'sync'], true)) {
            $phase = 'Failed';
        }
        elseif ($missing) {
            $phase = 'Incomplete';
        }
        elseif (!empty($settings['sync_pending'])) {
            $phase = 'Queued';
        }
        else {
            $phase = $state === 'Completed' ? 'Completed' : 'Ready';
        }
        $labels = ['Incomplete' => _('Setup incomplete'), 'Queued' => _('Request queued'),
            'Saving' => _('Validating and saving settings'), 'Running' => _('Snapshot in progress'),
            'Failed' => _('Synchronization failed'), 'Completed' => _('Snapshot completed'), 'Ready' => _('Configured')];
        $messages = ['Incomplete' => _('Complete the missing setup steps below before starting a snapshot.'),
            'Queued' => _('Your request was received. Processing starts automatically; if another operation is running, this request waits for it to finish. Do not submit it again.'),
            'Saving' => _('The worker is checking the selected repository and API permissions before saving your settings.'),
            'Running' => _('Settings are saved and the snapshot is in progress. Large categories can take several minutes. Follow the category results below.'),
            'Failed' => _('The last save or snapshot failed. Check Last error below; the missing steps list shows whether setup still needs attention.'),
            'Completed' => _('The snapshot completed successfully. See Last successful snapshot and Last commit below.'),
            'Ready' => _('Setup is complete. Sync now creates a snapshot using the saved settings.')];
        return ['ready' => !$missing, 'can_save' => $can_save, 'state' => $phase, 'label' => $labels[$phase],
            'message' => $messages[$phase], 'missing' => $missing];
    }

    public function status(string $api_request = '', string $sync_request = ''): array {
        $file = $this->root.'/public/status.json';
        $status = is_readable($file) ? json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR)
            : ['state' => 'Not configured', 'github' => 'Disconnected',
                'error' => 'Worker status unavailable. Check installation and cron.'];
        $worker_state = $status['state'] ?? 'Not configured';
        $pending = glob($this->root.'/queue/*.json');
        if ($pending) {
            $status['state'] = 'Queued';
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
        return $status;
    }
}
