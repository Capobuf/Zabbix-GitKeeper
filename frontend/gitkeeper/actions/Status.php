<?php
namespace Modules\GitKeeper\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\GitKeeper\Queue;

require_once __DIR__.'/../includes/Queue.php';

class Status extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput(['api_request' => 'string', 'sync_request' => 'string', 'request_id' => 'string']);
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
            $status['setup'] = Queue::setup($status);
            $status['synchronization'] = Queue::synchronization($status);
        }
        $response = new CControllerResponseData(['main_block' => json_encode($status)]);
        $response->disableView();
        $this->setResponse($response);
    }
}
