<?php

namespace craftsnippets\craftgls\helpers;
use Craft;
use craftsnippets\craftgls\GlsPlugin;

class Common
{
    public static function addLog($txt, $fileName = 'craft-mygls'){
        $file = Craft::getAlias('@storage/logs/'.$fileName.'.log');
        if(is_array($txt) || is_object($txt)){
            $txt = json_encode($txt);
        }
        $log = date('Y-m-d H:i:s').' '.$txt."\n";
        \craft\helpers\FileHelper::writeToFile($file, $log, ['append' => true]);
    }

    public static function getShippingService(){
        return GlsPlugin::getInstance()->gls;
    }

    public static function getSettings(){
        return GlsPlugin::getInstance()->getSettings();
    }

    public static function t(string $txt): string{
        return Craft::t('craft-mygls', $txt);
    }

    public static function permissionString(){
        return 'manageGls';
    }
    
    public static function getHandle()
    {
        return GlsPlugin::getInstance()->handle;
    }
}