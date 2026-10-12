<?php
// Small controller contract doubles. This does not certify a live Zabbix frontend.
define('USER_TYPE_SUPER_ADMIN', 3);
define('ZBX_AUTH_TOKEN_ENABLED', 0);
if (!function_exists('_')) {
    function _($text) { return $text; }
}
function ensure($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
class CWebUser {
    public static int $type = 3;
    public static array $data = ['userid' => '1'];
    public static function getType() { return self::$type; }
}
class CController {
    public bool $csrf = true;
    public array $input = [];
    public $response;
    public function validateInput($fields) { return true; }
    public function disableCsrfValidation() { $this->csrf = false; }
    public function setResponse($response) { $this->response = $response; }
    public function getInput($key, $default = null) { return $this->input[$key] ?? $default; }
}
class CControllerResponseFatal {}
class CControllerResponseRedirect {
    public function __construct($url) {}
}
class CMessageHelper {
    public static string $error = '';
    public static string $success = '';
    public static function setSuccessTitle($message) { self::$success = $message; }
    public static function setErrorTitle($message) { self::$error = $message; }
}
class CUrl {
    public function __construct($url) {}
    public function setArgument($key, $value) { return $this; }
}
class API {
    public static int $calls = 0;
    public static $tokens;
    public static function Token() { self::$calls++; return self::$tokens; }
}
class TokenAPI {
    public array $created = [];
    public array $deleted = [];
    public bool $generate = true;
    public bool $delete = true;
    public function create($params) { $this->created[] = $params; return ['tokenids' => ['42']]; }
    public function generate($ids) { return $this->generate ? [['tokenid' => '42', 'token' => str_repeat('a', 64)]] : false; }
    public function delete($ids) { $this->deleted[] = $ids; return $this->delete; }
}
// Load the real controllers/queue in an isolated module installation with its own local.php.
$root = sys_get_temp_dir().'/gitkeeper-php-'.bin2hex(random_bytes(8));
mkdir($root);
mkdir($root.'/includes');
mkdir($root.'/actions');
$module_files = ['includes/Queue.php', 'includes/Installation.php', 'actions/Save.php', 'actions/View.php', 'actions/Status.php'];
foreach ($module_files as $file) {
    copy(__DIR__.'/../frontend/gitkeeper/'.$file, $root.'/'.$file);
}
file_put_contents($root.'/local.php', '<?php return '.var_export(['state_dir' => $root], true).';');
register_shutdown_function(static function() use ($root, $module_files): void {
    foreach (array_merge($module_files, ['local.php']) as $file) { unlink($root.'/'.$file); }
    rmdir($root.'/includes');
    rmdir($root.'/actions');
    rmdir($root);
});
require $root.'/includes/Queue.php';
require $root.'/actions/Save.php';
require $root.'/actions/View.php';
require $root.'/actions/Status.php';
use Modules\GitKeeper\Queue;
use Modules\GitKeeper\Installation;

foreach ([
    [['SERVER_PORT' => '80', 'SCRIPT_NAME' => '/zabbix/zabbix.php'], 'http://127.0.0.1/zabbix/api_jsonrpc.php'],
    [['SERVER_PORT' => '8080', 'SCRIPT_NAME' => '/zabbix.php'], 'http://127.0.0.1:8080/api_jsonrpc.php'],
    [['SERVER_PORT' => '443', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/monitor/zabbix.php'], 'https://127.0.0.1/monitor/api_jsonrpc.php'],
    [['SERVER_PORT' => '80', 'HTTPS' => 'off', 'SCRIPT_NAME' => '/zabbix.php'], 'http://127.0.0.1/api_jsonrpc.php'],
    [['SERVER_PORT' => '65536', 'SCRIPT_NAME' => '/zabbix.php'], ''],
    [['SERVER_PORT' => '80', 'SCRIPT_NAME' => 'zabbix.php'], ''],
    [[], '']
] as [$server, $expected]) {
    ensure(Installation::loopbackUrl($server) === $expected, 'Incorrect local endpoint detection');
}
ensure(Installation::localApiUrl([], '7.4.15') === '', 'Unknown local configuration must fall back');
class ProbeStream {
    public $context;
    public static string $response = '{"jsonrpc":"2.0","id":1,"result":"7.4.15"}';
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        $http = stream_context_get_options($this->context)['http'];
        $request = json_decode($http['content'], true);
        ensure($request['method'] === 'apiinfo.version' && !isset($request['auth']), 'Probe must not authenticate');
        ensure($http['timeout'] === 2 && $http['follow_location'] === 0, 'Unbounded local API probe');
        return true;
    }
    public function stream_read($count) {
        $data = substr(self::$response, $this->position, $count);
        $this->position += strlen($data);
        return $data;
    }
    public function stream_eof() { return $this->position >= strlen(self::$response); }
    public function stream_stat() { return []; }
}
stream_wrapper_unregister('http');
stream_wrapper_register('http', ProbeStream::class);
try {
    $server = ['SERVER_PORT' => '80', 'SCRIPT_NAME' => '/zabbix/zabbix.php'];
    ensure(Installation::localApiUrl($server, '7.4.15') === 'http://127.0.0.1/zabbix/api_jsonrpc.php', 'Working loopback not detected');
    ProbeStream::$response = '{"result":"7.0.0"}';
    ensure(Installation::localApiUrl($server, '7.4.15') === '', 'Mismatched local frontend must fall back');
    ProbeStream::$response = '<html>Not an API</html>';
    ensure(Installation::localApiUrl($server, '7.4.15') === '', 'Non-API response must fall back');
}
finally { stream_wrapper_restore('http'); }

foreach ([
    'https://monitor.example/monitor/' => 'https://monitor.example/monitor/api_jsonrpc.php',
    'http://172.16.100.7/zabbix/' => 'http://172.16.100.7/zabbix/api_jsonrpc.php',
    'https://monitor.example:8443/zabbix.php' => 'https://monitor.example:8443/api_jsonrpc.php',
    'https://monitor.example/api_jsonrpc.php' => 'https://monitor.example/api_jsonrpc.php',
    'https://user:password@monitor.example/' => '',
    'https://monitor.example/?token=secret' => '',
    'https://monitor.example/#secret' => '',
    '/zabbix/' => ''
] as $frontend_url => $expected) {
    ensure(Installation::apiUrl($frontend_url) === $expected, 'Incorrect derived endpoint');
}

$valid = ['settings' => ['repository_id' => 42, 'interval' => 15, 'categories' => ['hosts']]];
Queue::validate('save', $valid);
Queue::validate('set_github_app', ['client_id' => 'Iv1.fixture']);
Queue::validate('select_repository', ['repository_id' => 42]);
Queue::validate('create_repository', ['name' => 'backup', 'owner' => 'Test-Organization']);
Queue::validate('set_zabbix', ['url' => 'http://127.0.0.1/zabbix/api_jsonrpc.php', 'token' => str_repeat('a', 64)]);
Queue::validate('set_zabbix', ['url' => 'https://127.0.0.1/zabbix/api_jsonrpc.php',
    'token' => str_repeat('a', 64), 'verify_tls' => false]);
foreach ([['shell', []], ['sync', ['command' => 'bad']], ['save', ['settings' => ['repository_id' => 42,
        'interval' => 1, 'categories' => ['hosts']]]], ['save', ['settings' => ['repository_id' => 42,
        'interval' => 15, 'categories' => ['restore']]]], ['set_zabbix', ['url' => 'ftp://example/api_jsonrpc.php',
        'token' => str_repeat('a', 64)]], ['set_github_app', ['client_id' => '$(bad)']],
        ['set_github_app', ['client_id' => 'Iv1.fixture', 'client_secret' => 'bad']],
        ['select_repository', ['repository_id' => 0]], ['select_repository', ['repository_id' => '42']],
        ['create_repository', ['name' => 'backup', 'owner' => '../org']],
        ['create_repository', ['name' => 'backup', 'owner' => 'org', 'token' => 'bad']],
        ['set_zabbix', ['url' => 'https://example/api_jsonrpc.php', 'token' => str_repeat('a', 64), 'verify_tls' => 'false']]] as [$op, $params]) {
    $rejected = false;
    try { Queue::validate($op, $params); }
    catch (Throwable $e) { $rejected = true; }
    ensure($rejected, 'Invalid input was accepted');
}
foreach (['Save', 'View', 'Status'] as $name) {
    $class = 'Modules\\GitKeeper\\Actions\\'.$name;
    $controller = new $class();
    $permissions = new ReflectionMethod($class, 'checkPermissions');
    CWebUser::$type = 2;
    ensure(!$permissions->invoke($controller), 'Non-super-admin accepted');
    CWebUser::$type = 3;
    ensure($permissions->invoke($controller), 'Super-admin rejected');
    if ($name === 'Save') {
        ensure($controller->csrf, 'Mutation disabled CSRF validation');
        $controller->input = ['operation' => 'create_zabbix_token', 'api_url' => 'https://example/api_jsonrpc.php'];
        (new ReflectionMethod($class, 'doAction'))->invoke($controller);
        ensure(API::$calls === 0, 'Token created without explicit consent');
        ensure(str_contains(CMessageHelper::$error, 'consent'), 'Missing consent was not identified');
    }
}
mkdir($root.'/queue');
mkdir($root.'/public');
try {
    $queue = new Queue($root);
    $id = $queue->submit('sync');
    ensure(file_exists($root.'/queue/'.$id.'.json'), 'Request not atomically published');
    ensure($queue->status()['state'] === 'Queued', 'Queued status not immediate');
    if (PHP_OS_FAMILY !== 'Windows') {
        ensure((fileperms($root.'/queue/'.$id.'.json') & 0777) === 0240, 'Insecure queue mode');
    }
    unlink($root.'/queue/'.$id.'.json');
    API::$tokens = new TokenAPI();
    $controller = new Modules\GitKeeper\Actions\Save();
    $action = new ReflectionMethod($controller, 'doAction');
    $controller->input = ['operation' => 'create_zabbix_token', 'api_url' => ' http://127.0.0.1/zabbix/api_jsonrpc.php ', 'consent' => 1];
    $action->invoke($controller);
    ensure(CMessageHelper::$success !== '', 'Successful token setup had no notification');
    $requests = glob($root.'/queue/*.json');
    ensure(count($requests) === 1, 'Native token was not queued');
    $request = json_decode(file_get_contents($requests[0]), true);
    ensure($request['operation'] === 'set_zabbix' && $request['params']['token'] === str_repeat('a', 64), 'Incorrect generated-token handoff');
    ensure($request['params']['url'] === 'http://127.0.0.1/zabbix/api_jsonrpc.php', 'HTTP loopback URL was not preserved');
    ensure($request['params']['verify_tls'] === true, 'TLS verification must default to enabled');
    ensure(API::$tokens->created[0]['userid'] === '1' && API::$tokens->deleted === [], 'Native creation/ownership failed');
    unlink($requests[0]);
    $controller->input = ['operation' => 'set_zabbix', 'api_url' => 'https://127.0.0.1/zabbix/api_jsonrpc.php',
        'api_token' => ' '.str_repeat('b', 64).' ', 'tls_skip_verify' => 1];
    $action->invoke($controller);
    $requests = glob($root.'/queue/*.json');
    $request = json_decode(file_get_contents($requests[0]), true);
    ensure($request['params']['verify_tls'] === false && $request['params']['token'] === str_repeat('b', 64), 'Existing token/TLS option not submitted');
    unlink($requests[0]);
    $controller->input = ['operation' => 'create_zabbix_token', 'api_url' => 'https://example/api_jsonrpc.php', 'consent' => 1];
    API::$tokens->generate = false;
    $action->invoke($controller);
    ensure(API::$tokens->deleted === [['42']], 'Generation failure did not revoke the new token');
    ensure(str_contains(CMessageHelper::$error, 'generate'), 'Generation failure was not identified');
    API::$tokens->delete = false;
    $action->invoke($controller);
    ensure(str_contains(CMessageHelper::$error, 'could not be removed'), 'Cleanup failure was not visible');
    API::$tokens = new TokenAPI();
    rename($root.'/queue', $root.'/paused-queue');
    try {
        $action->invoke($controller);
        ensure(API::$tokens->deleted === [['42']], 'Queue failure left an unused new token');
        ensure(str_contains(CMessageHelper::$error, 'could not be queued'), 'Queue failure was not identified');
    }
    finally { rename($root.'/paused-queue', $root.'/queue'); }
}
finally {
    rmdir($root.'/queue');
    rmdir($root.'/public');
}
echo "PHP validation, controller authorization/consent and queue contracts passed.\n";
