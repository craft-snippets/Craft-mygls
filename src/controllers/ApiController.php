<?php

namespace craftsnippets\craftgls\controllers;

use Craft;
use craft\web\Controller;
use craft\commerce\elements\Order;

use craftsnippets\craftgls\GlsPlugin;
use craftsnippets\craftgls\helpers\Common;

class ApiController extends Controller
{
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;



    public function actionCreateParcel()
    {
        $this->requirePermission(Common::permissionString());

        $orderId = Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $requestSettings = Craft::$app->getRequest()->getRequiredBodyParam('requestSettings');
        $requestSettings = json_decode($requestSettings, true);
        $order = Order::find()->id($orderId)->one();

        $result = Common::getShippingService()->createParcels($order, $requestSettings);

        return $this->asJson([
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
            'errorType' => $result['errorType'] ?? null,
        ]);
    }

    public function actionPrintLabels()
    {
        $this->requirePermission(Common::permissionString());

        $orderIds = Craft::$app->getRequest()->getRequiredQueryParam('orderIds');
        Common::getShippingService()->printLabels($orderIds);
    }

    public function actionRemoveParcels()
    {
        $this->requirePermission(Common::permissionString());

        $orderId = Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->one();
        if(is_null($order)){
            return $this->asJson([
                'success' => false,
            ]);
        }
        $result = Common::getShippingService()->removeParcels($order);
        return $this->asJson([
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
            'errorType' => $result['errorType'] ?? null,
            'status' => $result['status'] ?? null,
        ]);
    }

    public function actionUpdateParcelsStatus()
    {
        $this->requirePermission(Common::permissionString());

        $orderId = Craft::$app->getRequest()->getRequiredBodyParam('orderId');
        $order = Order::find()->id($orderId)->one();
        if(is_null($order)){
            return $this->asJson([
                'success' => false,
            ]);
        }
        $result = Common::getShippingService()->updateParcelsStatus($order);
        return $this->asJson([
            'success' => $result['success'],
            'error' => $result['error'] ?? null,
            'errorType' => $result['errorType'] ?? null,
            'status' => $result['status'] ?? null,
        ]);
    }

    public function actionPushParcelsStatusesUpdateJob()
    {
        $this->requirePermission(Common::permissionString());

        Common::getShippingService()->updateAllOrdersParcels();
        Craft::$app->getSession()->setNotice(Craft::t('craft-mygls', 'GLS shipping update parcels queue job started.'));
        return $this->redirect('utilities/gls-shipping-utility');
    }


}
