<?php

namespace craftsnippets\craftgls\jobs;

use Craft;
use craft\queue\BaseJob;
use craftsnippets\craftgls\helpers\Common;

/**
 * Update Parcel Status Job queue job
 */
class UpdateParcelStatusJob extends BaseJob
{
    public array $orderIds;
    function execute($queue): void
    {
        $orderIds = $this->orderIds;
        $query = \craft\commerce\elements\Order::find()->id($orderIds);
        $totalElements = $query->count();
        $currentElement = 0;

        try {
            $i = 0;
            foreach ($query->each() as $order) {
                $i ++;
                $this->setProgress($queue, $currentElement++ / $totalElements);
                try{
                    Common::getShippingService()->updateParcelsStatus($order);
                } catch(\Exception $e){
                    Common::addLog($e, 'craft-mygls');
                }
            }
        } catch (\Exception $e) {
            // Fail silently
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('craft-mygls', 'Updating Craft GLS parcels statuses');
    }
}
