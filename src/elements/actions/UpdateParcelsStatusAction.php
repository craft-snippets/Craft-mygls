<?php

namespace craftsnippets\craftgls\elements\actions;

use Craft;
use craft\base\ElementAction;
use craftsnippets\craftgls\helpers\Common;


class UpdateParcelsStatusAction extends ElementAction
{
    public static function displayName(): string
    {
        return Common::t('GLS shipping - update parcels status');
    }

    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
            (() => {
                new Craft.ElementActionTrigger({
                    type: $type,
                    bulk: true,
                    validateSelection: (selectedItems) => {
                        var allowed = true;
                        // selectedItems is object instead of regular array
                        for (let key in selectedItems) {
                                if (!isNaN(parseInt(key))) {
                                    let single = selectedItems[key];
                                    if(single.querySelector('[data-craft-mygls-label-allowed]') == null){
                                        allowed = false;
                                    }    
                                }
                        }                  
                        return allowed;
                    },
                });
            })();
        JS, [static::class]);
        return null;
    }

    public function performAction(Craft\elements\db\ElementQueryInterface $query): bool
    {
        $orders = $query->all();
        foreach ($orders as $order){
            // cant use queue, need to refresh orders list only after all api calls end during one request
            Common::getShippingService()->updateParcelsStatus($order);
        }
        return true;
    }

    public function  getMessage(): string
    {
        return Common::t('MyGls parcels status updated for the selected orders.');
    }
}
