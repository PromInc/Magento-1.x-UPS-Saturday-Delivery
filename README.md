# Magento 1.x UPS Saturday Delivery
In Magento 1.x, enabling Saturday delivery via UPS is not a default function.  For that reason, this simple extension will allow for adding Saturday UPS shipping methods.

## Configuration
System -> Configuration -> Shipping Settings -> Saturday Delivery - UPS
* Enable/Disable
* Select which days to offer Saturday UPS delivery
* Select which UPS shipping rates Saturday delivery should be available to

System -> Configuration -> Shipping Methods -> UPS
* Ensure that **UPS Type** is set to **United Parcel Service XML** and you have proper credentials set.

## How it works
The UPS API considers *Saturday Delivery* to be a **shipping option**, not a different rate.  For that reason this module will make two requests to the UPS API - once for non-Satuday rates and a second request for Saturday rates.  Magento will then merge these two requests into one rate block for UPS.

## Tested On
Developed and tested on Magento Enterprise Edition 1.14.2.0