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
            $status = ['state' => 'Failed', 'error' => 'Cannot read worker status.'];
        }
        $response = new CControllerResponseData(['main_block' => json_encode($status)]);
        $response->disableView();
        $this->setResponse($response);
    }
}
