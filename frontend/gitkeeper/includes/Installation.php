<?php
namespace Modules\GitKeeper;

final class Installation {
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
            .'  git clone --depth 1 --branch feat/gitkeeper-foundation https://github.com/Capobuf/Zabbix-GitKeeper.git "$gitkeeper_source"'."\n"
            .'  sudo bash "$gitkeeper_source/scripts/install.sh" \\'."\n"
            .'    --frontend-root '.escapeshellarg($frontend_root).' \\'."\n"
            .'    --web-user '.escapeshellarg($web_user)."\n"
            .")";
    }
}
