<?php
namespace Modules\GitKeeper\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use CSettingsHelper;
use API;
use Modules\GitKeeper\Installation;
use Modules\GitKeeper\Queue;

require_once __DIR__.'/../includes/Queue.php';
require_once __DIR__.'/../includes/Installation.php';

class View extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput(['api_request' => 'string', 'sync_request' => 'string', 'request_id' => 'string',
            'request_operation' => 'string']);
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $status = (new Queue())->status($this->getInput('api_request', ''), $this->getInput('sync_request', ''), $this->getInput('request_id', ''));
        }
        catch (\Throwable $e) {
            $status = ['worker_availability' => 'unavailable', 'state' => 'Unavailable',
                'error' => _('Stato del worker temporaneamente non disponibile. Verifica servizio e permessi.')];
        }
        $api_url = Installation::apiUrl($status['zabbix_url'] ?? '');
        $api_url_source = $api_url !== '' ? _('URL verificato dal worker') : '';
        if ($api_url === '') {
            $api_url = Installation::localApiUrl($_SERVER, ZABBIX_VERSION);
            $api_url_source = $api_url !== '' ? _('API Zabbix locale rilevata e verificata su questo server') : '';
        }
        if ($api_url === '') {
            $api_url = Installation::apiUrl(CSettingsHelper::get(CSettingsHelper::URL));
            $api_url_source = $api_url !== '' ? _('URL frontend dalle impostazioni Zabbix (da verificare)') : '';
        }
        $manual_templates = [];
        try {
            $scope = Queue::templateScope($status['settings']['template_scope'] ?? ['mode' => 'all', 'include_templateids' => []]);
            $ids = $scope['include_templateids'];
            // Never send an empty API filter: it would enumerate all templates.
            $templates = $ids ? API::Template()->get(['output' => ['templateid', 'name'], 'templateids' => $ids]) : [];
            $names = array_column($templates, 'name', 'templateid');
            foreach ($ids as $id) {
                $manual_templates[] = ['id' => $id, 'name' => $names[$id] ?? _('Template non disponibile').' ('.$id.')'];
            }
        }
        catch (\Throwable $e) {
            // A local label lookup must not expose API responses or discard saved selection.
            foreach ($status['settings']['template_scope']['include_templateids'] ?? [] as $id) {
                $manual_templates[] = ['id' => $id, 'name' => $id];
            }
        }
        $response = new CControllerResponseData(['status' => $status, 'manual_templates' => $manual_templates,
            'api_url' => $api_url, 'api_url_source' => $api_url_source,
            'api_request' => $this->getInput('api_request', ''),
            'sync_request' => $this->getInput('sync_request', ''),
            'request_id' => $this->getInput('request_id', ''),
            'request_operation' => $this->getInput('request_operation', '')]);
        $response->setTitle(_('GitKeeper'));
        $this->setResponse($response);
    }
}
