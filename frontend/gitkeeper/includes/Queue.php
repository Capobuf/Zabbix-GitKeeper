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
        if (strlen($url) > 2048 || !$parts || ($parts['scheme'] ?? '') !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])
                || !str_ends_with($parts['path'] ?? '', '/api_jsonrpc.php')) {
            throw new RuntimeException('Use an HTTPS URL ending in /api_jsonrpc.php without credentials.');
        }
    }

    public static function validate(string $operation, array $params): void {
        $allowed = ['connect' => [], 'disconnect' => [], 'repositories' => [], 'test_github' => [],
            'sync' => [], 'clear_zabbix' => [], 'save' => ['settings'],
            'set_zabbix' => ['url', 'token'], 'create_repository' => ['name']];
        $keys = array_keys($params);
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
        elseif ($operation === 'create_repository'
                && (!is_string($params['name']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/D', $params['name']))) {
            throw new RuntimeException('Invalid repository name.');
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

    public function status(): array {
        $file = $this->root.'/public/status.json';
        $status = is_readable($file) ? json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR)
            : ['state' => 'Not configured', 'github' => 'Disconnected',
                'error' => 'Worker status unavailable. Check installation and cron.'];
        $pending = glob($this->root.'/queue/*.json');
        if ($pending) {
            $status['state'] = 'Queued';
        }
        $status['queued_requests'] = count($pending);
        return $status;
    }
}
