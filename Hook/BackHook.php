<?php

namespace EasyProductManager\Hook;

use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
class BackHook extends BaseHook
{
    public function onMainInTopMenuItems(HookRenderEvent $event)
    {
        $event->add(
            $this->render('EasyProductManager/hook/main.in.top.menu.items.html', [])
        );
    }
}
