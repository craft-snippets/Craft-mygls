<?php
namespace craftsnippets\craftgls\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;

use craftsnippets\craftgls\helpers\Common;

class ShippingController extends Controller
{
    public function actionUpdateParcelsStatuses()
    {
        Common::addLog('Console command - update parcels statuses', 'craft-mygls');
        Common::getShippingService()->updateAllOrdersParcels();
        $this->stdout("Updating parcel statuses..". PHP_EOL);
        return ExitCode::OK;
    }
}