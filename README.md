# MyGls Shipping integration for Craft Commerce

This is NOT integration for GLS shipping, but MyGls, which is a separate service.

## Requirements

This plugin requires Craft CMS 5.0.0 or later, and PHP 8.2 or later.

## Permissions

In order to be able to use Plugin, control panel users must have "Manage Gls parcels" permission enabled.

# Shipping interface

To use MyGls Shipping, add ONE field of "MyGls parcels data" type to order field layout. This field is used only as container for data and will not display any kind of input on order field layout.

Then you need to enable Gls shipping for specific shipping methods in plugin settings in "Shipping methods with MyGls integration enabled" setting. This will make shipping interface appear in order page and element index actions available fpr specific orders on orders list.

If order has parcels but then admin removes its shipping method from plugin setting, interface will still show up to make it possible to remove parcels.

## Orders list

Functionalities Added to the order list in the control panel:

- searching by parcel number.
- additional element index column with parcel statuses.
- element index actions available after selecting one or more orders - create parcels, update parcels, get pdf of parcel labels

## Phone number field and delivery instruction field

Addresses in Craft do not have phone number field built-in. That's why we need to create plain text field, assign it to address field layout and select it in "Phone number field" plugin setting.

Then you need to add this field to the frontend address form. To automatically get this field object you can use this `getAddressPhoneField` function.

```
{% set phoneField = craft.myGls.getAddressPhoneField() %}
{% if phoneField %}
<input name="fields[{{phoneField.handle}}]">
{% endif %}
```

## Pickup and delivery addresses

Delivery address is taken from shipping address set in the order.

Pickup/sender address is required for parcels. You need to create at least one inventory location in commerce/inventory/locations and select it during parcel creation. In plugin settings you can also set it as default one.

Remember that address field layout **needs to have either "Full name" or "Organisation" native field assigned**. 

Note that you cannot enter any postal code into addresses - API accepts only postal codes with the specific formats.

When creating parcels, plugin assigns these values of Craft address object to its API address object:

* Address line 1 - street
* Address line 2 - street number (only number can be used)
* Address line 3 - additional number such as building, stairway, etc.

Each address requires country. If your shop operates only in one country and there is no need to set country field in your frontend order fields, you can just add hidden country field which is preselected to your country code value.

## Parcel reference number

For "Client reference" and "COD reference" (if cash on delivery is used) parameters of parcelss, order number is used. Not to be confused with order id.

## Parcel labels

MyGls API allows requesting parcel labels only once. That's why upon parcel generation, label pdf file needs to be saved into website. To make it possible, asset field needs to be assigned to order field layout and selected in plugin setting "Parcel label asset field". Setting "Parcel label asset volume" is also required. Make sure that selected field allows uploading files info volume selected in "Parcel label asset volume" setting.

## Cash on delivery

Cash on delivery can be set by selecting proper option for specific shipping method in "Shipping methods with MyGls integration enabled" setting. Second setting "Currency code for cash on delivery parcels" is optional but suggested to enter - without it unspecified currency will be used.

## Saved parcel data

After the parcels are created, it is still possible to edit order. However, this will not edit parcel data, as it was already sent to API.

In theory, admin user could be under impression that editing order address will change address in already created parcels. To avoid misunderstandings, parcels info is saved at the moment of creation in the database and can be checked by clicking "Show details" in the shipping interface.

## Update parcels queue job

Parcels status can be updated by clicking "Update parcels status" button in shipping interface on order page or by selecting multiple parcels on orders list and selecting "update parcels status" option. This will update parcels status immediately.

You can also update ALL parcels status using queue job. Thanks to queue job, system will not be blocked by large amount of API requests running all at once.

This can be triggered in Utilities/MyGls Shipping or by using console command. When running queue job, plugin will ignore orders missing MyGls data or that have order statuses defined in "Order status that will be set when parcels status will be updated to 'delivered' status." plugin setting.

Console command for triggering upating of parcel status:

```
php craft craft-mygls/shipping/update-parcels-statuses
```

## Updating order status

Order status can be updated automatically when parcel status is updated and parcels "DELIVERED" or "DELIVERED_TO_NEIGHBOUR" status is set. This can be set in plugin "Order status that will be set when parcels status will be updated to delivered status" setting. Orders with that status will be ignored when running parcels update queue job.

## Services

Additional parcel services are not handled by the plugin in the current version.