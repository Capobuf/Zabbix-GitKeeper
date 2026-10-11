<?php
namespace Modules\GitKeeper\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CUrl;
use CWebUser;
use Modules\GitKeeper\Queue;

require_once __DIR__.'/../includes/Queue.php';

class Save extends CController {
    // Default native CController CSRF validation remains enabled for every mutation.
    protected function checkInput(): bool {
        $valid = $this->validateInput(['operation' => 'required|string', 'repository_id' => 'int32',
            'interval' => 'int32', 'categories' => 'array', 'api_url' => 'string', 'api_token' => 'string',
            'repository_name' => 'string', 'consent' => 'in 1']);
        if (!$valid) {
            $this->setResponse(new CControllerResponseFatal());
        }
        return $valid;
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $redirect = new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'gitkeeper.view'));
        $created = null;
        try {
            $op = $this->getInput('operation');
            $params = [];
            if ($op === 'save') {
                $params = ['settings' => ['repository_id' => (int) $this->getInput('repository_id', 0),
                    'interval' => (int) $this->getInput('interval', 15), 'categories' => $this->getInput('categories', [])]];
            }
            elseif ($op === 'set_zabbix' || $op === 'create_zabbix_token') {
                $url = $this->getInput('api_url', '');
                Queue::apiUrl($url);
                if ($op === 'create_zabbix_token') {
                    if ($this->getInput('consent', 0) != 1) {
                        throw new \RuntimeException('Explicit consent is required to create a token inheriting your account permissions.');
                    }
                    // Same native API used by Zabbix token administration; never return the token to the browser.
                    $result = API::Token()->create(['name' => 'GitKeeper '.bin2hex(random_bytes(4)),
                        'userid' => CWebUser::$data['userid'], 'expires_at' => 0, 'status' => ZBX_AUTH_TOKEN_ENABLED]);
                    if (!$result) {
                        throw new \RuntimeException('Native token creation failed. Use an existing API token.');
                    }
                    $created = $result['tokenids'][0];
                    $generated = API::Token()->generate([$created]);
                    if (!$generated) {
                        throw new \RuntimeException('Native token generation failed.');
                    }
                    $token = $generated[0]['token'];
                }
                else {
                    $token = $this->getInput('api_token', '');
                }
                $op = 'set_zabbix';
                $params = ['url' => $url, 'token' => $token];
            }
            elseif ($op === 'create_repository') {
                $params = ['name' => $this->getInput('repository_name', '')];
            }
            (new Queue())->submit($op, $params);
            $redirect->setMessageOk(_('Queued. The worker processes requests on the next cron tick.'));
        }
        catch (\Throwable $e) {
            if ($created !== null) {
                API::Token()->delete([$created]);
            }
            // Never reflect API responses, request bodies or token-bearing errors.
            $redirect->setMessageError(_('Request rejected. Check the fields, token consent and worker queue permissions.'));
        }
        $this->setResponse($redirect);
    }
}
