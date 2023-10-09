<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\View\Requirements;

class EmbargoExpiryLeftAndMainExtension extends Extension
{
    public function init()
    {
        Requirements::css('silverstripe-terraformers/embargo-expiry:client/dist/styles/main.css');
    }

}
