<?php

namespace craftsnippets\craftgls\variables;

use craftsnippets\craftgls\helpers\Common;

class GlsVariable
{
    public function getAddressPhoneField()
    {
        return Common::getShippingService()->getPhoneField();
    }

}