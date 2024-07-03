<?php

namespace craftsnippets\craftgls\elements\actions;

use Craft;
use craft\base\ElementAction;
use Craft\elements\db\ElementQueryInterface;
use craftsnippets\craftgls\helpers\Common;

class CreateParcelsAction extends ElementAction
{
    public static function displayName(): string
    {
        return Common::t('GLS shipping - create parcels');
    }

    public function getTriggerHtml(): ?string
    {
//        $handle = GlsPlugin::getInstance()->handle;
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
                                    if(single.querySelector('[data-craft-mygls-create-allowed]') == null){
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

    public function performAction(ElementQueryInterface $query): bool
    {
        $orders = $query->all();
        $successAll = true;
        $errors = [];
        foreach ($orders as $order){
            $result = Common::getShippingService()->createParcels($order);
            if($result['success'] == false){
                $successAll = false;
                $errors[] = $result['error'];
            }
        }

        if($successAll == true){
            $message = Common::t('GLS parcels created for the selected orders.');
        }else{
            $message = Common::t('Could not create GLS parcels for the all selected orders. Errors:');
            $errors = join(', ', $errors);
            $message = $message . ' ' . $errors;
        }

        $this->setMessage($message);
        return $successAll;
    }

    public function  getConfirmationMessage(): string
    {
        return Common::t('Are you sure you want to create GLS parcels for the selected orders? Default settings will be used for the each parcel.');
    }
}
