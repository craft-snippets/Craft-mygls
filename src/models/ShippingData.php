<?php

namespace craftsnippets\craftgls\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;

use craftsnippets\craftgls\GlsPlugin;

use craftsnippets\craftgls\helpers\Common;
use craftsnippets\craftgls\models\ShippingParcel;
use craftsnippets\craftgls\models\ParcelStatus;
/**
 * Gls Data model
 */
class ShippingData extends Model
{

    public Order $order;

    public $jsonData;
    public $parcels = [];

    // address

    public $ClientNumber;
    public $ClientReference;
    public $CODAmount;
    public $CODCurrency;
    public $CODReference;
    public $Content;
    public $Count;
    public $DeliveryAddress;
    public $PickupAddress;
    public $PickupDate;
    public $ServiceList;


    public static function getJsonProperties()
    {
        return [
            [
                'value' => 'ClientNumber',
                'label' => \Craft::t('craft-mygls', 'Client number'),
            ],
            [
                'value' => 'ClientReference',
                'label' => \Craft::t('craft-mygls', 'Parcel reference'),
            ],
            [
                'value' => 'CODAmount',
                'label' => \Craft::t('craft-mygls', 'Cash on delivery amount'),
            ],
            [
                'value' => 'CODCurrency',
                'label' => \Craft::t('craft-mygls', 'Cash on delivery currency'),
            ],
            [
                'value' => 'CODReference',
                'label' => \Craft::t('craft-mygls', 'Cash on delivery reference'),
            ],
            [
                'value' => 'Content',
                'label' => \Craft::t('craft-mygls', 'Parcel info printed on label'),
            ],
            [
                'value' => 'Count',
                'label' => \Craft::t('craft-mygls', 'Count of parcels'),
            ],
            [
                'value' => 'DeliveryAddress',
                'label' => \Craft::t('craft-mygls', 'Delivery address'),
            ],
            [
                'value' => 'PickupAddress',
                'label' => \Craft::t('craft-mygls', 'Pickup address'),
            ],
            [
                'value' => 'PickupDate',
                'label' => \Craft::t('craft-mygls', 'Pickup date'),
            ],
            [
                'value' => 'ServiceList',
                'label' => \Craft::t('craft-mygls', 'Services and their special parameters.'),
            ],
        ];

    }

    public function getAddressJsonProperties()
    {
        return [
            [
                'value' => 'Name',
                'label' => \Craft::t('craft-mygls', 'Name of the person or organization.'),
            ],
            [
                'value' => 'Street',
                'label' => \Craft::t('craft-mygls', 'Street'),
            ],
            [
                'value' => 'HouseNumber',
                'label' => \Craft::t('craft-mygls', 'Number of the house'),
            ],
            [
                'value' => 'HouseNumberInfo',
                'label' => \Craft::t('craft-mygls', 'Additional house information.'),
            ],
            [
                'value' => 'City',
                'label' => \Craft::t('craft-mygls', 'Name of the town or village'),
            ],
            [
                'value' => 'ZipCode',
                'label' => \Craft::t('craft-mygls', 'Area Zip code'),
            ],
            [
                'value' => 'CountryIsoCode',
                'label' => \Craft::t('craft-mygls', 'Country code'),
            ],
            [
                'value' => 'ContactName',
                'label' => \Craft::t('craft-mygls', 'Contact person'),
            ],
            [
                'value' => 'ContactPhone',
                'label' => \Craft::t('craft-mygls', 'Contact phone number'),
            ],
            [
                'value' => 'ContactEmail',
                'label' => \Craft::t('craft-mygls', 'Contact email'),
            ],
        ];
    }

    public function getSavedProperty($property)
    {
        $value = $this->{$property} ?? null;
        if(is_null($value)){
            return null;
        }
        return $value;
    }

    public function getShippingDetails()
    {
        $properties = [];
        foreach ($this->getJsonProperties() as $single){
            $property = $single['value'];
            $propertyLabel = $single['label'];
            if($property == 'DeliveryAddress' || $property == 'PickupAddress'){
                $addressArray = $this->getSavedProperty($property);
                if(!is_array($addressArray)){
                    continue;
                }
                foreach($this->getAddressJsonProperties() as $addressSingle){
                    $addressProperty = $addressSingle['value'];
                    $addressLabel = $addressSingle['label'];
                    $addressValue = $addressArray[$addressProperty] ?? null;
                    $properties[] = [
                        'label' => $propertyLabel . ' - ' . $addressLabel,
                        'value' => $addressValue,
                    ];
                }
                continue;
            }
            $value = $this->getSavedProperty($property);
            // services
            if(is_array($value)){
                continue;
            }
            // date
            if($property == 'PickupDate'){
                $value = $this::decodeDateString($value);
            }
            $properties[] = [
                'label' => $propertyLabel,
                'value' => $value,
            ];
        }
        return $properties;
    }

    private static function decodeDateString($dateString)
    {
        if (preg_match('/\/Date\((\d+)([+-]\d{4})\)\//', $dateString, $matches)) {
            $timestamp = $matches[1]; // The timestamp in milliseconds
            $timezoneOffset = $matches[2]; // The timezone offset in the format ±HHMM

            $timestampInSeconds = $timestamp / 1000;
            $dateTime = new \DateTime("@$timestampInSeconds");

            // Convert the timezone offset to a format understood by DateTime
            $timezoneOffsetFormatted = substr($timezoneOffset, 0, 3) . ':' . substr($timezoneOffset, 3);
            $timezone = new \DateTimeZone($timezoneOffsetFormatted);
            $dateTime->setTimezone($timezone);
            $format = Craft::$app->getFormattingLocale()->getDateFormat('short', 'php');
            $string = $dateTime->format($format);
            return $string;
        }
        return null;
    }

    public function init(): void
    {
        // decode from field value only if json was provided
        if(is_null($this->jsonData)){
            return;
        }
        $data = json_decode($this->jsonData, true);

        // todo
        // assign parcels
        if(isset($data['parcels']) && is_array($data['parcels'])){
            $parcels = [];
            foreach ($data['parcels'] as $parcelInArray) {
                if(!isset($parcelInArray['number'])){
                    continue;
                }
                $statusObjects = array_map(function($status){
                    return new ParcelStatus(
                        [
                            'depotCity' => $status['depotCity'] ?? null,
                            'depotNumber' => $status['depotNumber'] ?? null,
                            'statusCode' => $status['statusCode'] ?? null,
                            'statusDate' => $status['statusDate'] ?? null,
                            'statusDescription' => $status['statusDescription'] ?? null,
                            'statusInfo' => $status['statusInfo'] ?? null,
                        ]
                    );
                }, $parcelInArray['status'] ?? []);
                $parcel = new ShippingParcel(
                    [
                        'number' => $parcelInArray['number'] ?? null,
                        'id' => $parcelInArray['id'] ?? null,
                        'status' => $parcelInArray['status'] ?? null,
                        'order' => $this->order,
                        'statusObjects' => $statusObjects,
                    ]
                );
                $parcels[] = $parcel;
            }
            $this->parcels = $parcels;
        }

        // assign json properties
        foreach ($this->getJsonProperties() as $single) {
            $property = $single['value'];
            if(isset($data[$property])){
                $this->{$property} = $data[$property];
            }
        }
    }

    private function getSettings()
    {
        return $this->getPluginService()->getSettings();
    }

    private function getPluginService()
    {
        return GlsPlugin::getInstance()->gls;
    }


    public function encodeData()
    {
        // todo
        $parcels = array_map(function($single){
            $statuses = array_map(function($status){
                return [
                    'depotCity' => $status->depotCity,
                    'depotNumber' => $status->depotNumber,
                    'statusCode' => $status->statusCode,
                    'statusDate' => $status->statusDate,
                    'statusDescription' => $status->statusDescription,
                    'statusInfo' => $status->statusInfo,
                ];
            }, $single->statusObjects);
            return [
                'number' => $single->number,
                'id' => $single->id,
                'status' => $statuses,
            ];
        }, $this->parcels);
        $array = [
            'parcels' => $parcels,
        ];
        foreach ($this->getJsonProperties() as $single) {
            $property = $single['value'];
            $array[$property] = $this->{$property};
        }
        return json_encode($array);
    }

    public function getIndexColumnStatusesSummary()
    {
        if(empty($this->parcels)){
            return '';  // element index cannot use null
        }
        $statuses = array_map(function($parcel) {
            return $parcel->getStatusText();
        }, $this->parcels);
        $statuses = array_filter($statuses);
        if(empty($statuses)){
            $statuses = '[' . Craft::t('craft-mygls', 'No status') . ']';
        }else{
            $statuses = implode(', ', $statuses);
        }
        return $statuses;;
    }

    public function getShippingMethodAllowed()
    {
        $methodsIds = array_column($this->getSettings()->enabledShippingMethods, 'shippingMethodId');
        $order = $this->order;
        if(is_null($order->getShippingMethod()) || !in_array($order->getShippingMethod()->id, $methodsIds)){
            return false;
        }
        return true;
    }


    public function getCanUse()
    {
        $order = $this->order;
        // don't add to cart page in the control panel
        if($order->isCompleted == false){
            return false;
        }
        if($this->getShippingMethodAllowed() == false){
            return false;
        }
        return true;
    }

    public function getHasParcels()
    {
        return !empty($this->parcels);
    }

    public function assignRequestData($request)
    {
        foreach($this->getJsonProperties() as $single){
            $property = $single['value'];
            if($property == 'DeliveryAddress' || $property == 'PickupAddress'){
                $addressArray = [];
                foreach($this->getAddressJsonProperties() as $addressSingle){
                    $addressProperty = $addressSingle['value'];
                    $addressArray[$addressProperty] = $request[$property][$addressProperty];
                }
                $this->{$property} = $addressArray;
                continue;
            }
            $this->{$property} = $request[$property];
        }
    }

    public function canRemoveParcels()
    {
        return true;
    }

    public function createParcelsActionAllowed()
    {
        if($this->getHasParcels() == true){
            return false;
        }
        if($this->getPluginService()->orderHasShippingField() == false){
            return false;
        }
        if($this->getCanUse() == false){
            return false;
        }
        return true;
    }

    public function updateParcelsActionAllowed()
    {
        if($this->getHasParcels() == false){
            return false;
        }
        if($this->getPluginService()->orderHasShippingField() == false){
            return false;
        }
        if($this->getCanUse() == false){
            return false;
        }
        return true;
    }

    public function getLabelActionAllowed()
    {
        if($this->getHasParcels() == false){
            return false;
        }
        if($this->getPluginService()->orderHasShippingField() == false){
            return false;
        }
        if($this->getCanUse() == false){
            return false;
        }
        return true;
    }

    // cod

    public function canReloadOnRequest()
    {
        return $this->getSettings()->reloadOnRequest == true;
    }

    public function isCod()
    {
        return $this->CODAmount > 0;
    }
    public function canUseCod()
    {
        $methodsIds = array_column($this->getSettings()->enabledShippingMethods, 'shippingMethodId');
        $order = $this->order;
        if(is_null($order->getShippingMethod()) || !in_array($order->getShippingMethod()->id, $methodsIds)){
            return false;
        }
        $shippingMetgodOption = array_filter($this->getSettings()->enabledShippingMethods, function($single) use ($order){
            return $single['shippingMethodId'] == $order->getShippingMethod()->id;
        });
        $shippingMetgodOption = reset($shippingMetgodOption);
        if(($shippingMetgodOption['cod'] ?? false) == $this->getSettings()::COD_ENABLED){
            return true;
        }
        return false;
    }

    public function getCodAmountNumber()
    {
        return $this->CODAmount;
    }

    public function getCodAmountCurrency(){
        return $this->CODCurrency;
    }

    public function getCodBeforeRequest()
    {
        return $this->order->getTotalPrice();
    }

    public function getCodCurrencyBeforeRequest()
    {
        return Common::getSettings()->currencyCode;
    }

    public function getParcelLabelAsset()
    {
        $parcelLabelField = $this->getPluginService()->getLabelAssetField();
        if(is_null($parcelLabelField)){
            return null;
        }
        return $this->order->getFieldValue($parcelLabelField->handle)->one();
    }


}