<?php

namespace EasyProductManager;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Module\BaseModule;

/**
 * @author Gilles Bourgeat >gilles.bourgeat@gmail.com>
 */
class EasyProductManager extends BaseModule
{
    /** @var string */
    const DOMAIN_NAME = 'easyproductmanager';
    const MODULE_NAME = 'EasyProductManager';

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()). "/I18n/*"])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
