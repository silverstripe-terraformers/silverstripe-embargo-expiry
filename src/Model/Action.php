<?php

namespace Terraformers\EmbargoExpiry\Model;

use SilverStripe\ORM\DataObject;

/**
 * @property string $Type
 * @property string $Datetime
 * @property int $RecordID
 * @method DataObject Record()
 */
class Action extends DataObject
{
    private const ACTION_EMBARGO = 'embargo';
    private const ACTION_EXPIRY = 'expiry';

    private static string $table_name = 'EmbargoExpiryAction';

    private static array $db = [
        'Type' => 'Varchar(10)',
        'Date' => 'Datetime',
    ];

    private static array $has_one = [
        'Record' => DataObject::class,
    ];
}
