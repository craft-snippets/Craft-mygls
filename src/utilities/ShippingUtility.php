<?php

namespace craftsnippets\craftgls\utilities;
use Craft;
use craft\base\Utility;

class ShippingUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('craft-mygls', 'MyGls Shipping');
    }

    static function id(): string
    {
        return 'gls-shipping-utility';
    }

    public static function iconPath(): ?string
    {
        return null;
    }

    static function contentHtml(): string
    {
        $txt = Craft::t('craft-mygls', 'Update parcels statuses');
        $url = \craft\helpers\UrlHelper::actionUrl('craft-mygls/api/push-parcels-statuses-update-job');
        $html = '<a href="'.$url.'" type="submit" class="btn submit">'.$txt.'</a>';
        return $html;
    }
}