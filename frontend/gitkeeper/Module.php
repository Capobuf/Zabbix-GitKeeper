<?php
namespace Modules\GitKeeper;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;
use CWebUser;

class Module extends CModule {
    public function init(): void {
        if (CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
            APP::Component()->get('menu.main')->findOrAdd(_('Administration'))->getSubMenu()
                ->add((new CMenuItem(_('GitKeeper')))->setAction('gitkeeper.view'));
        }
    }
}
