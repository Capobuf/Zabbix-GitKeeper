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
    public static function setSuccessTitle($message) {}
    public static function setErrorTitle($message) { self::$error = $message; }
}
class CUrl {
    public function __construct($url) {}
    public function setArgument($key, $value) { return $this; }
}
class API {
    public static int $calls = 0;
    public static function Token() { self::$calls++; throw new RuntimeException('Unexpected token creation'); }
}
require __DIR__.'/../frontend/gitkeeper/includes/Queue.php';
require __DIR__.'/../frontend/gitkeeper/actions/Save.php';
require __DIR__.'/../frontend/gitkeeper/actions/View.php';
require __DIR__.'/../frontend/gitkeeper/actions/Status.php';
use Modules\GitKeeper\Queue;
use Modules\GitKeeper\Installation;

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
foreach ([['shell', []], ['sync', ['command' => 'bad']], ['save', ['settings' => ['repository_id' => 42,
        'interval' => 1, 'categories' => ['hosts']]]], ['save', ['settings' => ['repository_id' => 42,
        'interval' => 15, 'categories' => ['restore']]]], ['set_zabbix', ['url' => 'http://example/api_jsonrpc.php',
        'token' => str_repeat('a', 64)]], ['set_github_app', ['client_id' => '$(bad)']],
        ['set_github_app', ['client_id' => 'Iv1.fixture', 'client_secret' => 'bad']],
        ['select_repository', ['repository_id' => 0]], ['select_repository', ['repository_id' => '42']],
        ['create_repository', ['name' => 'backup', 'owner' => '../org']],
        ['create_repository', ['name' => 'backup', 'owner' => 'org', 'token' => 'bad']]] as [$op, $params]) {
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
        ensure(CMessageHelper::$error !== '', 'Rejection did not use the native message helper');
    }
}
$root = sys_get_temp_dir().'/gitkeeper-php-'.bin2hex(random_bytes(8));
mkdir($root);
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
}
finally {
    rmdir($root.'/queue');
    rmdir($root.'/public');
    rmdir($root);
}
echo "PHP validation, controller authorization/consent and queue contracts passed.\n";
