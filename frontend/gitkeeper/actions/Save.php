<?php
namespace Modules\GitKeeper\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CUrl;
use CWebUser;
use CMessageHelper;
use Modules\GitKeeper\Queue;

require_once __DIR__.'/../includes/Queue.php';

class Save extends CController {
    // Default native CController CSRF validation remains enabled for every mutation.
    protected function checkInput(): bool {
        $valid = $this->validateInput(['operation' => 'required|string', 'repository_id' => 'int32',
            'interval' => 'int32', 'categories' => 'array', 'api_url' => 'string', 'api_token' => 'string',
            'repository_name' => 'string', 'repository_owner' => 'string', 'client_id' => 'string',
            'consent' => 'in 1', 'tls_skip_verify' => 'in 1']);
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
        $token_error = null;
        try {
            $op = $this->getInput('operation');
            $messages = [
                'set_github_app' => _('Client ID update requested. The stored value will update when the worker finishes.'),
                'connect' => _('GitHub connection requested. Wait here for the authorization code and link.'),
                'disconnect' => _('GitHub disconnection requested. Wait for the connection status to update.'),
                'repositories' => _('Repository refresh requested. The list will update when the worker finishes.'),
                'test_github' => _('Connection check requested. The result will appear in the connection status.'),
                'select_repository' => _('Repository selection requested. The selected destination will update when confirmed.'),
                'create_repository' => _('Private repository creation requested. Wait for the selected destination or an error before trying again.'),
                'create_zabbix_token' => _('Token created. Verification is queued; setup is not complete yet. Follow the verification result in Zabbix API.'),
                'set_zabbix' => _('Verification queued. The submitted token is not active yet. Follow the verification result in Zabbix API.'),
                'clear_zabbix' => _('Stored token removal requested. This does not revoke the token in Zabbix.'),
                'save' => _('Settings save and snapshot requested. Follow progress in Overview.'),
                'sync' => _('Snapshot queued. Processing starts automatically; wait if another operation is running. Follow Worker status and Last error in Overview.')
            ];
            $message = $messages[$op] ?? _('Request submitted. Wait for the status to update.');
            $params = [];
            if ($op === 'save') {
                $params = ['settings' => ['repository_id' => (int) $this->getInput('repository_id', 0),
                    'interval' => (int) $this->getInput('interval', 15), 'categories' => $this->getInput('categories', [])]];
            }
            elseif ($op === 'set_zabbix' || $op === 'create_zabbix_token') {
                $token_error = _('Enter an HTTP or HTTPS API URL ending in /api_jsonrpc.php, without credentials, query parameters or a fragment. No token was created or submitted.');
                $url = trim($this->getInput('api_url', ''));
                Queue::apiUrl($url);
                if ($op === 'create_zabbix_token') {
                    $token_error = _('Check the consent box to create a token inheriting your account permissions. No token was created.');
                    if ($this->getInput('consent', 0) != 1) {
                        throw new \RuntimeException('Explicit consent is required to create a token inheriting your account permissions.');
                    }
                    // Same native API used by Zabbix token administration; never return the token to the browser.
                    $token_error = _('Zabbix could not create the token. Check your role permission to manage API tokens, or use an existing Super admin token.');
                    $result = API::Token()->create(['name' => 'GitKeeper '.bin2hex(random_bytes(4)),
                        'userid' => CWebUser::$data['userid'], 'expires_at' => 0, 'status' => ZBX_AUTH_TOKEN_ENABLED]);
                    if (!$result) {
                        throw new \RuntimeException('Native token creation failed. Use an existing API token.');
                    }
                    $created = $result['tokenids'][0];
                    $token_error = _('Zabbix could not generate the token. Check your role permission to manage API tokens.');
                    $generated = API::Token()->generate([$created]);
                    if (!$generated) {
                        throw new \RuntimeException('Native token generation failed.');
                    }
                    $token = $generated[0]['token'];
                }
                else {
                    $token = trim($this->getInput('api_token', ''));
                }
                $op = 'set_zabbix';
                $params = ['url' => $url, 'token' => $token,
                    'verify_tls' => $this->getInput('tls_skip_verify', 0) != 1];
                $token_error = _('Enter the complete 64-character API token. Verification was not submitted.');
                Queue::validate($op, $params);
                $token_error = _('Token verification could not be queued. Check worker installation, queue permissions and pending requests.');
            }
            elseif ($op === 'set_github_app') {
                $params = ['client_id' => trim($this->getInput('client_id', ''))];
            }
            elseif ($op === 'create_repository') {
                $params = ['name' => trim($this->getInput('repository_name', '')),
                    'owner' => trim($this->getInput('repository_owner', ''))];
            }
            elseif ($op === 'select_repository') {
                $params = ['repository_id' => (int) $this->getInput('repository_id', 0)];
            }
            $request_id = (new Queue())->submit($op, $params);
            if ($op === 'set_zabbix') {
                $redirect = new CControllerResponseRedirect((new CUrl('zabbix.php'))
                    ->setArgument('action', 'gitkeeper.view')->setArgument('api_request', $request_id));
            }
            elseif ($op === 'save' || $op === 'sync') {
                $redirect = new CControllerResponseRedirect((new CUrl('zabbix.php'))
                    ->setArgument('action', 'gitkeeper.view')->setArgument('sync_request', $request_id));
            }
            CMessageHelper::setSuccessTitle($message);
        }
        catch (\Throwable $e) {
            if ($created !== null) {
                try {
                    if (!API::Token()->delete([$created])) {
                        throw new \RuntimeException('Token cleanup failed.');
                    }
                }
                catch (\Throwable $cleanup_error) {
                    $token_error = _('Token setup failed and the newly created token could not be removed. Revoke the unused GitKeeper token in Manage / revoke API tokens before retrying.');
                }
            }
            // Never reflect API responses, request bodies or token-bearing errors.
            $errors = [
                'set_github_app' => _('Client ID was not submitted. Check its value and the worker queue permissions.'),
                'create_repository' => _('Repository creation was not submitted. Check the owner, repository name and worker queue permissions.'),
                'select_repository' => _('Repository selection was not submitted. Select a repository and check the worker queue permissions.'),
                'save' => _('Settings were not submitted. Select a repository, at least one category and an interval; check the worker queue permissions.'),
                'sync' => _('Snapshot could not be queued. Check worker installation, queue permissions and pending requests. Follow the missing setup steps beside Sync now in Overview.'),
                'set_zabbix' => _('Token verification was not submitted. Check the API URL, token format and worker queue permissions.'),
                'create_zabbix_token' => _('Token setup was not submitted. Check the API URL, consent and worker queue permissions.')
            ];
            CMessageHelper::setErrorTitle($token_error ?? $errors[$this->getInput('operation')] ??
                _('Request was not submitted. Check that the worker is installed and its queue is writable.'));
        }
        $this->setResponse($redirect);
    }
}
