<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataExtension;
use SilverStripe\View\Requirements;

class EmbargoExpiryLeftAndMainExtension extends DataExtension
{
    public function init()
    {
        Requirements::css('silverstripe-terraformers/embargo-expiry:client/dist/css/styles/main.css');
    }
}
