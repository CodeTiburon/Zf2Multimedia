<?php
namespace Zf2Multimedia;

use Zend\Mvc\ModuleRouteListener;

class Module
{
    public function onBootstrap($event)
    {
        $app = $event->getApplication();
        $events = $app->getEventManager();
        $services = $app->getServiceManager();
    }

    public function getConfig()
    {
        $result = array();

        $configs = glob(__DIR__ . '/config/*.config.php');

        foreach ($configs as $config) {
            if (file_exists($config)) {
                $result = array_merge($result, include_once($config));
            }
        }

        return $result;
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\ClassMapAutoloader' => array(
                __DIR__ . '/autoload_classmap.php',
            ),
        );
    }
}
