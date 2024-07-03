<?php

namespace craftsnippets\craftgls\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;

/**
 * Gls Parcel model
 */
class ShippingParcel extends Model
{


    public int $number;
    public int $id;
    public $status;
    public array $statusObjects = [];
    public Order $order;

    public function getTrackingUrl()
    {
        return null;
    }

    public function getTitle()
    {
        return $this->number;
    }

    public function getStatusText()
    {
        if(empty($this->statusObjects)){
            return null;
        }
        $last = end($this->statusObjects);
        return $last->statusDescription;
    }

    public function getIsDelivered()
    {
        if(empty($this->statusObjects)){
            return false;
        }
        $lastStatus = end($this->statusObjects);
        return $lastStatus->getIsDelivered();
    }

}
