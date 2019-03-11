<?php

namespace Terraformers\EmbargoExpiry\Tests\Mock;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * Class EmbargoExpiryDataObject
 *
 * @package App\Model
 * @mixin Versioned
 * @mixin EmbargoExpiryExtension
 */
class EmbargoExpiryDataObject extends DataObject implements TestOnly
{
    /**
     * @var array
     */
    private static $extensions = [
        Versioned::class,
        EmbargoExpiryExtension::class,
    ];

    /**
     * @var array
     */
    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    /**
     * @var array
     */
    private static $has_one = [
        'Page' => EmbargoExpirySiteTree::class,
    ];

    /**
     * @var string
     */
    private static $table_name = 'EmbargoExpiryDataObject';

    /**
     * @param null $member
     * @return bool|int
     */
    public function canDelete($member = null)
    {
        return true;
    }

    /**
     * @param null $member
     * @return bool|int
     */
    public function canEdit($member = null)
    {
        return true;
    }

    /**
     * @param null $member
     * @return bool|int
     */
    public function canView($member = null)
    {
        return true;
    }
}
