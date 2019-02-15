<?php

namespace Terraformers\EmbargoExpiry\Form;

use SilverStripe\Forms\FormAction;

/**
 * Class EmbargoExpiryFormAction
 *
 * @package Terraformers\EmbargoExpiry\Form
 */
class EmbargoExpiryFormAction extends FormAction
{
    /**
     * We don't ever want to perform a readonly transformation on this action. If it has been made available to the use,
     * that means they're allowed to use it.
     */
    public function performReadonlyTransformation()
    {
        return $this;
    }
}
