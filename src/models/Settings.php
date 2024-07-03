<?php

namespace craftsnippets\craftgls\models;

use Craft;
use craft\base\Model;
use craft\commerce\Plugin as CommercePlugin;
use craft\elements\Address;
use craft\fields\PlainText;
use craft\fields\Email;
use craft\fields\Assets;
use craft\commerce\elements\Order;
use craftsnippets\craftgls\GlsPlugin;
use craftsnippets\craftgls\helpers\Common;

/**
 * Gls integration settings
 */
class Settings extends Model
{

    const COD_ENABLED = 'enabled';
    const COD_DISABLED = 'disabled';

    public $apiUsername;
    public $apiPassword;
    public $apiCliendId;
    public $apiCountry;
    public bool $testMode = false;
    public $phoneFieldId;
    public ?int $labelAssetFieldId = null;
    public ?int $labelAssetVolumeId = null;
    public array $enabledShippingMethods = [];
    public ?int $deliveredOrderStatusId = null;
    public ?int $defaultLocationId = null;
    public bool $showWidgetWhenNotAllowed = true;
    public $currencyCode;
    public $pickupAddressEmailFieldId;

    // debug
    public $reloadOnRequest = true;
    public $hideField = true;


    public function getPhoneFieldOptions()
    {
        $fields = Craft::$app->getFields()->getLayoutByType(Address::class)->getCustomFields();
        $properFields = array_filter($fields, function($single){
            return get_class($single) == PlainText::class;
        });
        $options = [
            [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ]
        ];
        foreach($properFields as $single){
            $options[] = [
                'label' => $single->name,
                'value' => $single->id,
            ];
        }
        return $options;
    }

    public function getPickupAddressEmailFieldOptions()
    {
        $fields = Craft::$app->getFields()->getLayoutByType(Address::class)->getCustomFields();
        $properFields = array_filter($fields, function($single){
            return get_class($single) == Email::class;
        });
        $options = [
            [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ]
        ];
        foreach($properFields as $single){
            $options[] = [
                'label' => $single->name,
                'value' => $single->id,
            ];
        }
        return $options;
    }

    public function getLabelAssetFieldOptions()
    {
        $fields = Craft::$app->getFields()->getLayoutByType(Order::class)->getCustomFields();
        $fields = array_filter($fields, function($single){
            return get_class($single) == Assets::class;
        });
        // todo
        // filter out fields belonging to asset source which uses filessystem where files are uploaded to he web root
        $options = [
            [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ]
        ];
        foreach($fields as $single){
            $options[] = [
                'label' => $single->name,
                'value' => $single->id,
            ];
        }
        return $options;
    }

    public function getLabelAssetVolumeOptions()
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $options = [
            [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ]
        ];
        foreach($volumes as $single){
            $options[] = [
                'label' => $single->name,
                'value' => $single->id,
            ];
        }
        return $options;
    }

    public function getShippingMethodsColumns()
    {
        $shippingMethods = CommercePlugin::getInstance()->getShippingMethods()->getAllShippingMethods();
        $shippingMethodsOptions = $shippingMethods->map(function ($shippingMethod) {
            return [
                'label' => $shippingMethod->name,
                'value' => $shippingMethod->id,
            ];
        });
        $codOptions = [
            [
                'label' => Craft::t('craft-mygls', 'Disabled'),
                'value' => self::COD_DISABLED,
            ],
            [
                'label' => Craft::t('craft-mygls', 'Enabled'),
                'value' => self::COD_ENABLED,
            ],
        ];
        $columns = [
            'shippingMethodId' => [
                'heading' => Craft::t('craft-mygls', 'Shipping method'),
                'type' => 'select',
                'options' => $shippingMethodsOptions,

            ],
            'cod' => [
                'heading' => Craft::t('craft-mygls', 'Cash on delivery'),
                'type' => 'select',
                'options' => $codOptions,
            ],
        ];
        return $columns;
    }

    public function getCountryOptions()
    {
        return [
            [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ],
            [
                'label' => Craft::t('craft-mygls', 'Croatia'),
                'value' => 'hr',
            ],
            [
                'label' => Craft::t('craft-mygls', 'Czechia'),
                'value' => 'cz',
            ],
            [
                'label' => Craft::t('craft-mygls', 'Hungary'),
                'value' => 'hu',
            ],
            [
                'label' => Craft::t('craft-mygls', 'Romania'),
                'value' => 'ro',
            ],
            [
                'label' => Craft::t('craft-mygls', 'Slovenia'),
                'value' => 'si',
            ],
            [
                'label' => Craft::t('craft-mygls', 'Slovakia'),
                'value' => 'sk',
            ],
            [
                'label' => Craft::t('craft-mygls', 'Serbia'),
                'value' => 'rs',
            ],

        ];
    }

    public function getDeliveredOrderStatusIdOptions()
    {
        $options = [
            [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ]
        ];
        $statuses = CommercePlugin::getInstance()->getOrderStatuses()->getAllOrderStatuses();
        $options = array_merge($options, array_map(function($status){
            return [
                'label' => $status->name,
                'value' => $status->id,
            ];
        }, $statuses->toArray()));
        return $options;
    }

    public function getDeliveredOrderStatus()
    {
        if(is_null($this->deliveredOrderStatusId)){
            return null;
        }
        $status = CommercePlugin::getInstance()->getOrderStatuses()->getOrderStatusById($this->deliveredOrderStatusId);
        return $status;
    }

    public function getLocationOptions()
    {
        $options = [
            [
                'label' => Craft::t('craft-mygls', 'Select'),
                'value' => null,
            ]
        ];
        $inventoryLocations = CommercePlugin::getInstance()->getInventoryLocations()->getAllInventoryLocations();
        $options = array_merge($options, array_map(function($location){
            return [
                'label' => $location->name,
                'value' => $location->id,
            ];
        }, $inventoryLocations->toArray()));
        return $options;
    }

    public function hasProperLabelAssetField()
    {
        // todo check if asigned to model, if field with id exist, if proper asset field, if volume is set if volume exists
        return !is_null($this->labelAssetFieldId);
    }

    public function getPluginErrors()
    {
        $errors = [];

        if(!$this->hasProperLabelAssetField()){
            $errors[] = Craft::t('craft-mygls', 'Label asset field is not set');
        }
        if(is_null($this->labelAssetVolumeId)){
            $errors[] = Craft::t('craft-mygls', 'Label asset volume is not set');
        }
        if(empty($this->apiUsername) || empty($this->apiPassword) ||empty($this->apiCliendId) ||empty($this->apiCountry)){
            $errors[] = Craft::t('craft-mygls', 'Api credentials are not fully set - country, client ID, username and password are required.');
        }
        if(Common::getShippingService()->orderhasShippingField() === false){
            $errors[] = Craft::t('craft-mygls', 'GLS shipping field is not assigned to the order field layout.');
        }
        return $errors;
    }

    public function getPluginWarnings()
    {
        $warnings = [];
        if($this->testMode){
            $warnings[] = Craft::t('craft-mygls', 'Test mode is enabled');
        }
        return $warnings;
    }

    public function hasCorrectSettings()
    {
        return empty($this->getPluginErrors());
    }

}
