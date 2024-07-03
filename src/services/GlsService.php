<?php

namespace craftsnippets\craftgls\services;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as CommercePlugin;
use craft\elements\Address;
use craft\elements\Asset;
use craft\models\VolumeFolder;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\DefineAttributeHtmlEvent;

use craft\helpers\FileHelper;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use iio\libmergepdf\Merger;

use Webapix\GLS\Requests\DeleteLabels;
use yii\base\Component;
use yii\base\Event;

use craftsnippets\craftgls\GlsPlugin;
use craftsnippets\craftgls\fields\GlsField;
use craftsnippets\craftgls\helpers\Common;
use craftsnippets\craftgls\models\ShippingData;
use craftsnippets\craftgls\models\ShippingParcel;
use craftsnippets\craftgls\jobs\UpdateParcelStatusJob;
use craftsnippets\craftgls\models\ParcelStatus;

use craftsnippets\craftgls\elements\actions\CreateParcelsAction;
use craftsnippets\craftgls\elements\actions\PrintLabelsAction;
use craftsnippets\craftgls\elements\actions\UpdateParcelsStatusAction;

// gls api
use GuzzleHttp\Client as HttpClient;
use Webapix\GLS\Client;
use Webapix\GLS\Requests\PrintLabels;
use Webapix\GLS\Requests\GetParcelStatuses;

class GlsService extends Component
{

    public function getApiObject()
    {
        $username = $this->getSettings()->apiUsername;
        $password = $this->getSettings()->apiPassword;
        $clientId = $this->getSettings()->apiCliendId;
        $country = $this->getSettings()->apiCountry;

        // set url
        if($this->getSettings()->testMode){
            $apiUrl = 'https://api.test.mygls';
        }else{
            $apiUrl = 'https://api.mygls';
        }
        $apiUrl = $apiUrl . '.' . $country . '/ParcelService.svc/json/';

        // account object
        $account = new \Webapix\GLS\Models\Account($apiUrl, $clientId, $username, $password);
        return $account;
    }

    public function getSettings()
    {
        return GlsPlugin::getInstance()->getSettings();
    }

    public function getOrderShippingField(): ?GlsField
    {
        $fields = Craft::$app->getFields()->getLayoutByType(Order::class)->getCustomFields();
        $shippingFields = array_filter($fields, function($single){
            return get_class($single) == GlsField::class;
        });
        $field = reset($shippingFields) ?: null;
        return $field;
    }

    public function orderHasShippingField()
    {
        return !is_null($this->getOrderShippingField());
    }

    public function insertShippingInterface()
    {
        Craft::$app->view->hook('cp.commerce.order.edit.main-pane', function(array &$context) {

            $order = $context['order'];

            // never on unfinished order page
            if($order->isCompleted == false){
                return;
            }

            $errors = [];

            // different error message when shipping method not allowed depending if always show widget or onlys how with not allowed when there are parcels already
            if($order->getGls()->getCanUse() == false && $order->getGls()->getHasParcels() == true && Common::getSettings()->showWidgetWhenNotAllowed == false){
                $errors[] = Common::t('GLS shipping integration is disabled for the current shipping method of this order. You can still access GLS shipping functionality because this order already has parcels assigned.');
            }
            if($order->getGls()->getCanUse() == false && Common::getSettings()->showWidgetWhenNotAllowed == true){
                $errors[] = Common::t('GLS shipping integration is disabled for the current shipping method of this order.');
            }

            // plugin settings errors
            $errors = array_merge($errors, Common::getSettings()->getPluginErrors());
            $context['errors'] = $errors;

            // show nothing, but only for orders that do no have parcels yet, so when for some reason order later should not be allowed to use easyship anymore, parcels still can be removed
            if($order->getGls()->getCanUse() == false && $order->getGls()->getHasParcels() == false && Common::getSettings()->showWidgetWhenNotAllowed == false){
                return;
            }

            // pdf url
            $context['pdfUrl'] = $this->getOrdersPdfUrl([$order]);

            // location options
            $context['locationOptions'] = $this->getLocationOptions();
            $context['defaultLocationId'] = $this->getSettings()->defaultLocationId;
            $context['pluginHandle'] = 'gls';

            $templatePath = 'craft-mygls/shipping-interface.twig';
            $renderedHtml = Craft::$app->view->renderTemplate(
                $templatePath, $context, Craft::$app->view::TEMPLATE_MODE_CP
            );
            return $renderedHtml;
        });
    }

    public function validateAddress(Address $address)
    {
        if(is_null($address->organization) && is_null($address->fullName)){
            throw new \Exception(Craft::t('craft-mygls', 'organisation and full name are both empty'));
        }
        if(is_null($address->countryCode)){
            throw new \Exception(Craft::t('craft-mygls', 'country is not set'));
        }
        if(is_null($address->postalCode)){
            throw new \Exception(Craft::t('craft-mygls', 'postal code is not set'));
        }
        if(is_null($address->locality)){
            throw new \Exception(Craft::t('craft-mygls', 'city is not set'));
        }
        if(is_null($address->addressLine1)){
            throw new \Exception(Craft::t('craft-mygls', 'street is not set'));
        }
    }

    public function createParcels(?Order $order, array $requestSettings = [])
    {
        // datepicker creates hidden input with separate id
        if(isset($requestSettings['pickupDate-date'])){
            $requestSettings['pickupDate'] = $requestSettings['pickupDate-date'];
        }

        // request settings
        $defaultSettings = [
            'parcelCount' => 1,
            'senderLocationId' => null,
            'parcelDescription' => null,
            'pickupDate' => null,
        ];
        $requestSettings = array_merge($defaultSettings, $requestSettings);
        // empty string into null
        $requestSettings = array_map(function($value) {
            return $value === "" ? null : $value;
        }, $requestSettings);

        // if order exists
        if(is_null($order)){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Order not found.'),
            ];
        }

        // can't create parcels if shipping method not allowed
        if($order->getGls()->getCanUse() == false){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'GLS shipping is not enabled for this orders shipping method.'),
            ];
        }

        // check plugin settings
        if(!$this->getSettings()->hasCorrectSettings()){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Plugin settings are incorrectly configured.'),
                'errorType' => 'settings',
            ];
        }

        // check if parcels exist
        if($order->getGls()->getHasParcels() == true){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Parcels already exist for this order.'),
                'errorType' => 'exists',
            ];
        }

        $shippingData = new ShippingData([
            'order' => $order,
        ]);

        ////////////////////////////////////////////////////////////////////////
        // SET DELIVERY ADDRESS

        // set name
        $deliveryAddressCraft = $order->shippingAddress;

        try {
            $this->validateAddress($deliveryAddressCraft);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Shipping address:') . ' ' . $e->getMessage(),
            ];
        }

        if(!is_null($deliveryAddressCraft->organization)){
            $deliveryName = $deliveryAddressCraft->organization;
        }else if(!is_null($deliveryAddressCraft->fullName)){
            $deliveryName = $deliveryAddressCraft->fullName;
        }else{
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'No organization or full name defined for the shipping address.'),
                'errorType' => 'Address validation',
            ];
        }

        // delivery address api obj
        $deliveryAddress = new \Webapix\GLS\Models\Address(
            $deliveryName,
            $deliveryAddressCraft->countryCode,
            $deliveryAddressCraft->postalCode,
            $deliveryAddressCraft->locality,
            $deliveryAddressCraft->addressLine1,
            $deliveryAddressCraft->addressLine2,
        );
        // house number info
        if($deliveryAddressCraft->addressLine3){
            $deliveryAddress->setHouseNumberInfo($deliveryAddressCraft->addressLine3);
        }
        // email
        $deliveryAddress->setContactEmail($order->email);
        // phone
        if(!is_null($this->getPhoneField())){
            $deliveryAddress->setContactPhone($deliveryAddressCraft->getFieldValue($this->getPhoneField()->handle));
        }
        // contact name
        if($deliveryAddressCraft->fullName){
            $deliveryAddress->setContactName($deliveryAddressCraft->fullName);
        }

        ////////////////////////////////////////////////////////////////////////
        // SET PICKUP ADDRESS


//        if(is_null($senderLocationId) || ($pickupAddressCraft = CommercePlugin::getInstance()->getInventoryLocations()->getInventoryLocationById((int)$senderLocationId) === null)){
//            return [
//                'success' => false,
//                'error' => Craft::t('craft-mygls', 'No pickup address selected or selected address does not exists.'),
//                'errorType' => 'Pickup address validation',
//            ];
//        }

        $senderLocationId = $requestSettings['senderLocationId'] ?? $this->getSettings()->defaultLocationId;
        if(is_null($senderLocationId)){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'No pickup location selected.'),
                'errorType' => 'Pickup address validation',
            ];
        }
        $pickupLocation = CommercePlugin::getInstance()->getInventoryLocations()->getInventoryLocationById((int)$senderLocationId);
        if(is_null($pickupLocation)){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Pickup location does not exist,'),
                'errorType' => 'Pickup address validation',
            ];
        }

        $pickupAddressCraft = $pickupLocation->getAddress();

        try {
            $this->validateAddress($pickupAddressCraft);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Pickup address:') . ' ' . $e->getMessage(),
            ];
        }

        if(!is_null($pickupAddressCraft->organization)){
            $pickupName = $pickupAddressCraft->organization;
        }else if(!is_null($pickupAddressCraft->fullName)){
            $pickupName = $pickupAddressCraft->fullName;
        }else{
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'No organization or full name defined for the pickup address.'),
                'errorType' => 'Address validation',
            ];
        }

        $pickupAddress = new \Webapix\GLS\Models\Address(
            $pickupName,
            $pickupAddressCraft->countryCode,
            $pickupAddressCraft->postalCode,
            $pickupAddressCraft->locality,
            $pickupAddressCraft->addressLine1,
            $pickupAddressCraft->addressLine2,
        );
        // house number info
        if($pickupAddressCraft->addressLine3){
            $pickupAddress->setHouseNumberInfo($pickupAddressCraft->addressLine3);
        }
        // email
        if(!is_null($this->getEmailField())){
            $pickupAddress->setContactEmail($pickupAddressCraft->getFieldValue($this->getEmailField()->handle));
        }

        // phone
        if(!is_null($this->getPhoneField())){
            $pickupAddress->setContactPhone($pickupAddressCraft->getFieldValue($this->getPhoneField()->handle));
        }
        // contact name
        if($pickupAddressCraft->fullName){
            $pickupAddress->setContactName($pickupAddressCraft->fullName);
        }

        ////////////////////////////////////////////////////////////////////////
        // PARCEL OBJECT

        $account = $this->getApiObject();

        $parcel = (new \Webapix\GLS\Models\Parcel())
            ->setClientNumber($account->clientNumber())
            ->setDeliveryInfo($deliveryAddress)
            ->setPickupAddress($pickupAddress)
            ->setCount($requestSettings['parcelCount'])
            ->setClientReference($order->number);

        if(!is_null($requestSettings['parcelDescription'])){
            $parcel->setContent($requestSettings['parcelDescription']);
        }

        if(!is_null($requestSettings['parcelDescription'])){
            $parcel->setContent($requestSettings['parcelDescription']);
        }

        if(!is_null($requestSettings['pickupDate'])){
            $dateFormat = Craft::$app->getFormattingLocale()->getDateFormat('short', 'php');
            $dateObject = \DateTime::createFromFormat($dateFormat, $requestSettings['pickupDate']);
            $parcel->setPickupDate($dateObject);
        }

        // cod
        if($order->getGls()->canUseCod()){
            $parcel->setCodAmount($order->getGls()->getCodBeforeRequest());
            $parcel->setCodReference($order->number);
            if(Common::getSettings()->currencyCode){
                $parcel->setCodCurrency(Common::getSettings()->currencyCode);
            }

        }


////////////////////////////////////////////////////////////////////////
        // SEND REQUEST

        $client = new Client(new HttpClient);
        $request = new PrintLabels();
        $request->addParcel($parcel);

        $response = $client->on($account)->request($request);

        // api error
        if(!$response->successfull()){
            $errors = array_map(function($single){
                return $single->message();
            }, $response->errors()->all());
            $errors = join(', ', $errors);
            return [
                'success' => false,
                'error' => 'API Error: ' . $errors,
                'errorType' => 'api',
            ];
        }


////////////////////////////////////////////////////////////////////////
        // SAVE TO ORDER
        $requestData = $request->toArray()['ParcelList'][0];

        $shippingData->assignRequestData($requestData);

        // assign parcels
        foreach ($response->printLabelsInfo() as $single){
            $parcelObj = new ShippingParcel([
                'id' => $single->parcelId(),
                'number' => $single->parcelNumber(),
                'status' => null,
                'order' => $order,
            ]);
            $shippingData->parcels[] = $parcelObj;
        }
        $fieldContent = $shippingData->encodeData();
        $order->setFieldValue($this->getOrderShippingField()->handle, $fieldContent);

        // folder
        $folderName = 'orders';
        $volumeId = $this->getLabelAssetVolume()->id;

        $folder = Craft::$app->assets->findFolder([
            'volumeId' => $volumeId,
            'path' => $folderName . '/',
        ]);

        // create folder if it is missing
        if ($folder === null) {
            $rootFolder = Craft::$app->assets->getRootFolderByVolumeId($volumeId);
            $folder = new VolumeFolder([
                'parentId' => $rootFolder->id,
                'volumeId' => $volumeId,
                'name' => $folderName,
                'path' => $folderName . '/',
            ]);
            Craft::$app->assets->createFolder($folder);
        }

        // create pdf asset
        $pdfContent = $response->getPdf();
        $tempPath = Craft::$app->getPath()->getTempPath() . '/' . $order->id . '-temp.pdf';
        FileHelper::writeToFile($tempPath, $pdfContent);

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->filename = 'MyGls-label-order-' . $order->id . '.pdf';
        $asset->newFolderId = $folder->id;
        $asset->volumeId = $volumeId;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        $saveAsset = Craft::$app->getElements()->saveElement($asset);
//        unlink($tempPath);
        if(!$saveAsset){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Could not save label PDF Asset.'),
                'errorType' => 'asset',
            ];
        }
        $order->setFieldValue($this->getLabelAssetField()->handle, [$asset->id]);

        // save element
        $save = Craft::$app->elements->saveElement($order);
        $order->reapplyShippingData();

        // todo update status?

        if(!$save){
            return [
                'success' => false,
                'error' => implode(' ', $order->getErrorSummary(true)),
                'errorType' => 'Order validation',
            ];
        }

        Common::addLog('Create parcel for order ID ' . $order->id, 'craft-mygls');

////////////////////////////////////////////////////////////////////////
        /// RETURN TO CONTROLLER

        return [
            'success' => true,
            'error' => null,
        ];
    }

    public function removeParcels(?Order $order)
    {
        if(is_null($order)){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Order not found.'),
            ];
        }

        if(!$order->getGls()->canRemoveParcels()){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Cannot remove parcels for this order.'),
            ];
        }

        $account = $this->getApiObject();
        $client = new Client(new HttpClient);
        $request = new DeleteLabels();

        foreach ($order->getGls()->parcels as $parcel){
            $parcelId = $parcel->id;
            $request->addParcelId($parcelId);
        }
        $response = $client->on($account)->request($request);

        if(!$response->successfull()){
            $errors = array_map(function($single){
                return $single->message();
            }, $response->errors()->all());
            $errors = join(', ', $errors);
            return [
                'success' => false,
                'error' => 'API Error: ' . $errors,
                'errorType' => 'api',
            ];
        }

        $order->setFieldValue($this->getOrderShippingField()->handle, null);
        $save = Craft::$app->elements->saveElement($order);
        if(!$save){
            return [
                'success' => false,
                'status' => implode(' ', $order->getErrorSummary(true)),
                'errorType' => 'Order validation',
            ];
        }

        Common::addLog('Remove parcels for order ID ' . $order->id);

        return [
            'success' => true,
//            'status' => $statusString,
            'status' => 'ok',
        ];
    }

    public function updateParcelsStatus(?Order $order)
    {
        if(is_null($order)){
            return [
                'success' => false,
                'error' => Craft::t('craft-mygls', 'Order not found.'),
            ];
        }

        if(!$order->getGls()->updateParcelsActionAllowed()){
            return [
                'success' => false,
                'error' => 'Not allowed',
            ];
        }

        $parcels = [];
//        $errors = [];
        foreach ($order->getGls()->parcels as $parcel){
            $result = $this->getParcelStatus($parcel->number);
            if($result['success']){
                $parcel->statusObjects = $result['result'];
                $parcels[] = $parcel;
            }else{
                return [
                    'success' => false,
                    'error' => $result['error'],
                    'errorType' => $result['errorType'],
                ];
            }
        }

        $order->getGls()->parcels = $parcels;
        $fieldContent = $order->getGls()->encodeData();
        $order->setFieldValue($this->getOrderShippingField()->handle, $fieldContent);
        $save = Craft::$app->elements->saveElement($order);
        $order->reapplyShippingData();

        // check if delivered, if yes set proper order status
        $allParcelsDelivered = true;
        foreach ($parcels as $parcel){
            if(!$parcel->getIsDelivered()){
                $allParcelsDelivered = false;
            }
        }

        $deliveredStatus = $this->getSettings()->getDeliveredOrderStatus();
        if($allParcelsDelivered && !is_null($deliveredStatus)){
            $order->orderStatusId = $deliveredStatus->id;
        }

        $save = Craft::$app->elements->saveElement($order);


        if(!$save){
            return [
                'success' => false,
                'error' => implode(' ', $order->getErrorSummary(true)),
                'errorType' => 'Order validation',
            ];
        }

        Common::addLog('update order ID ' . $order->id);

        return [
            'success' => true,
//            'status' => $statusString,
            'status' => 'ok',
        ];
    }

    public function printLabels($orderIds)
    {
        $orders = Order::find()->id($orderIds)->all();
        if(empty($orders)){
            echo Craft::t('craft-mygls', 'Print labels error - no orders found.');
        }
        $assets = [];
        foreach ($orders as $order){
            $asset = $order->getGls()->getParcelLabelAsset();
            if(!is_null($asset)){
                $assets[] = $asset;
            }
        }
        if(empty($assets)){
            echo Craft::t('craft-mygls', 'Print labels error - no files found.');
        }
        if(count($assets) == 1){
            $first = reset($assets);
            $pdfContent = $first->getContents();
        }else{
            $merger = new Merger;
            foreach ($assets as $asset){
                $merger->addRaw($asset->getContents());
            }
            $pdfContent = $merger->merge();
        }
        $title = array_column($orders, 'id');
        $title = join('-', $title);
        $title = 'MyGls-label-orders-'.$title.'.pdf';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: inline; filename="'.$title.'"');
        echo $pdfContent;
    }

    public function hasSettings(): bool
    {
        // todo
        return true;
    }

    public function getParcelStatus($parcelNumber)
    {
        $account = $this->getApiObject();
        $client = new Client(new HttpClient);
        $request = new GetParcelStatuses($parcelNumber);
        $response = $client->on($account)->request($request);

        // api error
        if(!$response->successfull()){
            $errors = array_map(function($single){
                return $single->message();
            }, $response->errors()->all());
            $errors = join(', ', $errors);
            return [
                'success' => false,
                'error' => $parcelNumber . ' - API Error: ' . $errors,
                'errorType' => 'api',
            ];
        }
        $parcelStatusList = $response->ParcelStatusList();

        $statusObjects = array_map(function($single){
            return new ParcelStatus([
                'depotCity' => $single->depotCity(),
                'depotNumber' => $single->depotNumber(),
                'statusCode' => $single->statusCode(),
                'statusDate' => $single->statusDate(),
                'statusDescription' => $single->statusDescription(),
                'statusInfo' => $single->statusInfo(),
            ]);
        }, $parcelStatusList);
        return [
            'success' => true,
            'result' => $statusObjects,
        ];
    }

    public function getOrdersPdfUrl(array $orders)
    {
        // todo
        $orderIds = array_column($orders, 'id');
        return UrlHelper::actionUrl('craft-mygls/api/print-labels', [
            'orderIds' => $orderIds,
        ]);
    }

    public function getPhoneField()
    {
        $fieldId = GlsPlugin::getInstance()->getSettings()->phoneFieldId;
        if(!$fieldId){
            return null;
        }

        // if field is assigned to address field layout
        // if text field (use one function there and in settings)
        // todo

        $field = Craft::$app->getFields()->getFieldById($fieldId);
        if(!$field){
            return null;
        }
        return $field;
    }

    public function getEmailField()
    {
        $fieldId = GlsPlugin::getInstance()->getSettings()->pickupAddressEmailFieldId;
        if(!$fieldId){
            return null;
        }

        // if field is assigned to address field layout
        // if text field (use one function there and in settings)
        // todo

        $field = Craft::$app->getFields()->getFieldById($fieldId);
        if(!$field){
            return null;
        }
        return $field;
    }

    public function getLabelAssetField()
    {
        $fieldId = GlsPlugin::getInstance()->getSettings()->labelAssetFieldId;
        if(!$fieldId){
            return null;
        }
        // if field is assigned to order field layout
        // if asset field
        // todo
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        if(!$field){
            return null;
        }
        return $field;
    }

    public function getLabelAssetVolume()
    {
        $volume = Craft::$app->getVolumes()->getVolumeById($this->getSettings()->labelAssetVolumeId);
        return $volume;
    }

    public function getOrderQueryForUpdate()
    {
        $settings = Common::getSettings();
        $field = $this->getOrderShippingField();
        if(is_null($field)){
            return null;
        }
        $query = Order::find();

        // has parcel
        $query = $query->{$field->handle}(':notempty:');

        // without status delivered
        if(!is_null($settings->deliveredOrderStatusId)){
            $query = $query->orderStatusId(['not', $settings->deliveredOrderStatusId]);
        }

        // todo not older tha month

        return $query;
    }

    public function updateAllOrdersParcels()
    {
        if(is_null($this->getOrderQueryForUpdate())){
            Common::addLog('Update all statuses - Gls field missing from order field layout.', 'craft-mygls');
            return;
        }
        $orderIds = $this->getOrderQueryForUpdate()->ids();

        $this->pushUpdateStatusJob($orderIds);
    }

    public function pushUpdateStatusJob($orderIds)
    {
        Common::addLog('Gls - pushed update parcels job', 'craft-mygls');
        Queue::push(new UpdateParcelStatusJob([
            'orderIds' => $orderIds,
        ]));
    }

    public function getLocationOptions()
    {
        $options = [];
        // add empty default option if default location is not selected in plugin settings
        if(is_null($this->getSettings()->defaultLocationId)){
            $options[] = [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ];
        }
        $inventoryLocations = CommercePlugin::getInstance()->getInventoryLocations()->getAllInventoryLocations();
        $options = array_merge($options, array_map(function($location){
            return [
                'label' => $location->name,
                'value' => $location->id,
            ];
        }, $inventoryLocations->toArray()));
        return $options;
    }


    public function registerElementActions()
    {
        Event::on(
            Order::class,
            Element::EVENT_REGISTER_ACTIONS,
            function(RegisterElementActionsEvent $event) {
                $event->actions[] = PrintLabelsAction::class;
                $event->actions[] = CreateParcelsAction::class;
                $event->actions[] = UpdateParcelsStatusAction::class;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_HTML_ATTRIBUTES,
            function(\craft\events\RegisterElementHtmlAttributesEvent $event) {
                $order = $event->sender;

                // for actions
                if($order->getGls()->createParcelsActionAllowed()){
                    $event->htmlAttributes['data-craft-mygls-create-allowed'] = true;
                }
                if($order->getGls()->updateParcelsActionAllowed()){
                    $event->htmlAttributes['data-craft-mygls-update-allowed'] = true;
                }
                if($order->getGls()->getLabelActionAllowed()){
                    $event->htmlAttributes['data-craft-mygls-label-allowed'] = true;
                }
            }
        );

    }

    public function registerTableAttributes()
    {
        $attributeKey = 'craftGlsStatus';

        Event::on(
            Order::class,
            Order::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $e) use ($attributeKey){
                $e->tableAttributes[$attributeKey] = [
                    'label' => Craft::t('craft-mygls', 'GLS shipping parcels status'),
                ];
            });

        Event::on(
            Order::class,
            Order::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $e) use ($attributeKey){
                if($e->attribute === $attributeKey){
                    $order = $e->sender;
                    $e->html = $order->getGls()->getIndexColumnStatusesSummary();
                }
            }
        );
    }

}
