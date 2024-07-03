<?php

namespace craftsnippets\craftgls\behaviors;

use craftsnippets\craftgls\GlsPlugin;
use craftsnippets\craftgls\models\ShippingData;
use yii\base\Behavior;


class GlsOrderBehavior extends Behavior
{
    private $_shippingData;

    public function getGls()
    {
        if($this->_shippingData !== null){
            return $this->_shippingData;
        }

        $field = GlsPlugin::getInstance()->gls->getOrderShippingField();

        if(is_null($field)){
            $jsonData = null;
        }else{
            $jsonData = $this->owner->getFieldValue($field->handle);
        }
        $obj = new ShippingData([
            'order' => $this->owner,
            'jsonData' => $jsonData,
        ]);
        $this->_shippingData = $obj;
        return $obj;
    }

    // used after we create parcels but before page reloads
    public function reapplyShippingData()
    {
        $this->_shippingData = null;
        return $this->getGls();
    }
}
