<?php

namespace Terraformers\EmbargoExpiry\Model;

use SilverStripe\ORM\DataObject;

/**
 * @property string $Type
 * @property string $Datetime
 * @property int $RecordID
 * @property string $RecordClass
 * @method DataObject Record()
 */
class Action extends DataObject
{
    public const ACTION_EMBARGO = 'embargo';
    public const ACTION_EXPIRY = 'expiry';

    private static string $table_name = 'EmbargoExpiryAction';

    private static array $db = [
        'Type' => 'Varchar(10)',
        'Datetime' => 'Datetime',
    ];

    private static array $has_one = [
        'Record' => DataObject::class,
    ];
}
