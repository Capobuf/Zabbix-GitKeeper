<?php
namespace Modules\GitKeeper\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CControllerResponseData;
use CUrl;
use CWebUser;
use CMessageHelper;
use Modules\GitKeeper\Queue;

require_once __DIR__.'/../includes/Queue.php';

class Save extends CController {
    // Default native CController CSRF validation remains enabled for every mutation.
    protected function checkInput(): bool {
        $valid = $this->validateInput(['operation' => 'required|string', 'repository_id' => 'int32',
            'interval' => 'int32', 'interval_days' => 'int32', 'interval_hours' => 'int32',
            'interval_minutes' => 'int32', 'categories' => 'array', 'api_url' => 'string', 'api_token' => 'string',
            'repository_name' => 'string', 'repository_owner' => 'string', 'client_id' => 'string',
            'consent' => 'in 1', 'tls_skip_verify' => 'in 1', 'template_mode' => 'string',
            'include_templateids' => 'array', 'confirm_template_scope' => 'in 1']);
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
        $interval_error = null;
        $request_id = null;
        $preview_error = null;
        try {
            $op = $this->getInput('operation');
            $params = [];
            if (in_array($op, ['save', 'preview_scope'], true)) {
                $scope = Queue::templateScope(['mode' => $this->getInput('template_mode', 'all'),
                    'include_templateids' => $this->getInput('include_templateids', [])]);
            }
            if ($op === 'preview_scope') {
                $params = ['template_scope' => $scope];
            }
            elseif ($op === 'save') {
                $interval = (int) $this->getInput('interval', Queue::DEFAULT_INTERVAL);
                if ($interval === 0) {
                    $interval_error = _('Intervallo non valido: inserisci giorni non negativi, ore da 0 a 23 e minuti da 0 a 59, per un totale di almeno un minuto entro il limite supportato.');
                    $interval = Queue::customInterval((int) $this->getInput('interval_days', -1),
                        (int) $this->getInput('interval_hours', -1), (int) $this->getInput('interval_minutes', -1));
                    $interval_error = null;
                }
                $params = ['settings' => ['repository_id' => (int) $this->getInput('repository_id', 0),
                    'interval' => $interval, 'categories' => $this->getInput('categories', []), 'template_scope' => $scope]];
                $saved = (new Queue())->status()['settings'] ?? [];
                if (!empty($saved['categories']) && ($saved['template_scope']['mode'] ?? 'all') === 'all'
                        && $scope['mode'] === 'used' && in_array('templates', $params['settings']['categories'], true)
                        && $this->getInput('confirm_template_scope', 0) != 1) {
                    $interval_error = _('Conferma il passaggio a Solo utilizzati: i file esclusi saranno rimossi dalla revisione Git corrente dopo una sincronizzazione riuscita; la cronologia rimane disponibile.');
                    throw new \RuntimeException('Template scope transition requires confirmation.');
                }
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
            $redirect = new CControllerResponseRedirect((new CUrl('zabbix.php'))
                ->setArgument('action', 'gitkeeper.view')->setArgument('request_id', $request_id)
                ->setArgument('request_operation', $op)
                ->setArgument('api_request', $op === 'set_zabbix' ? $request_id : '')
                ->setArgument('sync_request', in_array($op, ['save', 'sync'], true) ? $request_id : ''));
            if ($op !== 'preview_scope') {
                CMessageHelper::setSuccessTitle(_('Richiesta inviata. Attendi l’esito del worker nella sezione dell’operazione.'));
            }
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
            $preview_error = _('Anteprima non inviata. Verifica la selezione dei template e la disponibilità della coda worker.');
            if ($this->getInput('operation') !== 'preview_scope') {
                CMessageHelper::setErrorTitle($token_error ?? $interval_error ?? $errors[$this->getInput('operation')] ??
                    _('Request was not submitted. Check that the worker is installed and its queue is writable.'));
            }
        }
        if ($this->getInput('operation') === 'preview_scope') {
            $response = new CControllerResponseData(['main_block' => json_encode([
                'request_id' => $request_id, 'error' => $preview_error])]);
            $response->disableView();
            $this->setResponse($response);
            return;
        }
        $this->setResponse($redirect);
    }
}
