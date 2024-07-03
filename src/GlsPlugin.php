<?php

namespace craftsnippets\craftgls;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\commerce\elements\Order;
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use yii\base\Event;

use craftsnippets\craftgls\fields\GlsField;
use craftsnippets\craftgls\models\Settings;
use craftsnippets\craftgls\services\GlsService;
use craftsnippets\craftgls\behaviors\GlsOrderBehavior;
use craftsnippets\craftgls\variables\GlsVariable;
use craftsnippets\craftgls\helpers\Common;
use craft\services\Utilities;

use craftsnippets\craftgls\utilities\ShippingUtility;


class GlsPlugin extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => ['gls' => GlsService::class],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
        });

        // insert interface into order page
        $this->gls->insertShippingInterface();
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('craft-mygls/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // field
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = GlsField::class;
        });

        // behavior
        Event::on(
            Order::class,
            Order::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $e) {
                $e->behaviors['GlsOrderBehavior'] = GlsOrderBehavior::class;
            }
        );

        // element actions
        $this->gls->registerElementActions();

        // table attributes
        $this->gls->registerTableAttributes();

        // variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('myGls', GlsVariable::class);
            }
        );

        // permission
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Gls',
                    'permissions' => [
                        Common::permissionString() => [
                            'label' => Craft::t('craft-mygls', 'Manage Gls parcels'),
                        ],
                    ],
                ];
            }
        );

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ShippingUtility::class;
        });

    }

}
