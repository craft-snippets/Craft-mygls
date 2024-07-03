<?php

namespace craftsnippets\craftgls\elements\actions;

use Craft;
use craft\base\ElementAction;
use craftsnippets\craftgls\helpers\Common;

class PrintLabelsAction extends ElementAction
{
    public static function displayName(): string
    {
        return Common::t('GLS shipping - get parcel labels');
    }

    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
            (() => {
                new Craft.ElementActionTrigger({
                    type: $type,

                    // Whether this action should be available when multiple elements are selected
                    bulk: true,

                    // Return whether the action should be available depending on which elements are selected
                    validateSelection: function(selectedItems) {
                        var allowed = true;
                        // selectedItems is object instead of regular array
                        for (let key in selectedItems) {
                                if (!isNaN(parseInt(key))) {
                                    let single = selectedItems[key];
                                    if(single.querySelector('[data-craft-mygls-update-allowed]') == null){
                                        allowed = false;
                                    }    
                                }
                        }                  
                        return allowed;
                    },

                    activate: function() {
                      Craft.elementIndex.setIndexBusy();
                      const ids = Craft.elementIndex.getSelectedElementIds();
                      let url = Craft.getActionUrl('craft-mygls/api/print-labels', {orderIds: ids});
                      window.open(url, "_blank");
                      Craft.elementIndex.setIndexAvailable();
                    },
                });
            })();
        JS, [static::class]);
        return null;
    }

}
