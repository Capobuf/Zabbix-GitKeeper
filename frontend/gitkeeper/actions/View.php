<?php
namespace Modules\GitKeeper\Actions;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\GitKeeper\Queue;

require_once __DIR__.'/../includes/Queue.php';

class View extends CController {
    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $status = (new Queue())->status();
        }
        catch (\Throwable $e) {
            $status = ['state' => 'Failed', 'error' => 'Cannot read worker status. Check installation.'];
        }
        $response = new CControllerResponseData(['status' => $status]);
        $response->setTitle(_('GitKeeper'));
        $this->setResponse($response);
    }
}
