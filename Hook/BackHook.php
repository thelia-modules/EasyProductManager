<?php

namespace EasyProductManager\Hook;

use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
class BackHook extends BaseHook
{
    public static function getSubscribedHooks(): array
    {
        return [
            'main.in-top-menu-items' => [
                ['type' => 'back', 'method' => 'onMainInTopMenuItems'],
            ],
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onModuleConfiguration'],
            ],
        ];
    }

    public function onMainInTopMenuItems(HookRenderEvent $event): void
    {
        $event->add(
            $this->render('EasyProductManager/hook/main.in.top.menu.items.html.twig', [])
        );
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $event->add(
            $this->render('EasyProductManager/module-configuration.html.twig', [])
        );
    }
}
