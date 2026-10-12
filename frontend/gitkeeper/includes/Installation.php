<?php
namespace Modules\GitKeeper;

final class Installation {
    public static function loopbackUrl(array $server): string {
        $port = (string) ($server['SERVER_PORT'] ?? '');
        $script = $server['SCRIPT_NAME'] ?? '';
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
                || !is_string($script) || !str_starts_with($script, '/')
                || basename($script) !== 'zabbix.php') {
            return '';
        }
        $https = isset($server['HTTPS']) && !in_array(strtolower((string) $server['HTTPS']), ['', 'off', '0'], true);
        $scheme = $https ? 'https' : 'http';
        $authority = '127.0.0.1'.((int) $port === ($https ? 443 : 80) ? '' : ':'.$port);
        return self::apiUrl($scheme.'://'.$authority.$script);
    }

    public static function localApiUrl(array $server, string $version): string {
        $url = self::loopbackUrl($server);
        if ($url === '') {
            return '';
        }
        // A bounded, unauthenticated native API probe. Never guess that loopback serves this frontend.
        $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 2,
            'follow_location' => 0, 'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['jsonrpc' => '2.0', 'method' => 'apiinfo.version', 'params' => [], 'id' => 1])]]);
        $response = @file_get_contents($url, false, $context, 0, 4096);
        $data = $response === false ? null : json_decode($response, true);
        return is_array($data) && ($data['result'] ?? null) === $version ? $url : '';
    }

    public static function apiUrl(string $frontend_url): string {
        $url = trim($frontend_url);
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 2000) {
            return '';
        }
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/api_jsonrpc.php')) {
            return $url;
        }
        // Native frontend entry points can also be used as the configured URL.
        if (in_array(basename($parts['path'] ?? ''), ['index.php', 'zabbix.php'], true)) {
            $url = substr($url, 0, strrpos($url, '/'));
        }
        return $url.'/api_jsonrpc.php';
    }

    public static function command(string $frontend_root, string $web_user): string {
        return "(\n"
            ."  set -eu\n"
            .'  gitkeeper_source=$(mktemp -d /tmp/zabbix-gitkeeper.XXXXXX)'."\n"
            .'  git clone --depth 1 --branch main https://github.com/Capobuf/Zabbix-GitKeeper.git "$gitkeeper_source"'."\n"
            .'  sudo bash "$gitkeeper_source/scripts/install.sh" \\'."\n"
            .'    --frontend-root '.escapeshellarg($frontend_root).' \\'."\n"
            .'    --web-user '.escapeshellarg($web_user)."\n"
            .")";
    }
}
