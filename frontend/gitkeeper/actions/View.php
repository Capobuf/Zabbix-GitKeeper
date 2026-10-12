<?php
namespace Modules\GitKeeper\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use CSettingsHelper;
use Modules\GitKeeper\Installation;
use Modules\GitKeeper\Queue;

require_once __DIR__.'/../includes/Queue.php';
require_once __DIR__.'/../includes/Installation.php';

class View extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput(['api_request' => 'string', 'sync_request' => 'string']);
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $status = (new Queue())->status($this->getInput('api_request', ''), $this->getInput('sync_request', ''));
        }
        catch (\Throwable $e) {
            $status = ['state' => 'Failed', 'error' => 'Cannot read worker status. Check installation.'];
        }
        $api_url = Installation::apiUrl($status['zabbix_url'] ?? '');
        $api_url_source = $api_url !== '' ? _('Verified worker URL') : '';
        if ($api_url === '') {
            $api_url = Installation::localApiUrl($_SERVER, ZABBIX_VERSION);
            $api_url_source = $api_url !== '' ? _('Local Zabbix API detected on this server') : '';
        }
        if ($api_url === '') {
            $api_url = Installation::apiUrl(CSettingsHelper::get(CSettingsHelper::URL));
            $api_url_source = $api_url !== '' ? _('Zabbix frontend URL setting') : '';
        }
        $response = new CControllerResponseData(['status' => $status,
            'api_url' => $api_url, 'api_url_source' => $api_url_source,
            'api_request' => $this->getInput('api_request', ''),
            'sync_request' => $this->getInput('sync_request', '')]);
        $response->setTitle(_('GitKeeper'));
        $this->setResponse($response);
    }
}
