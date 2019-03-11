<?php

namespace Terraformers\EmbargoExpiry\Tests\Mock;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\Debug;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\ORM\HasManyList;

/**
 * Class Page
 *
 * @property int ElementalAreaID
 * @method HasManyList|EmbargoExpiryDataObject[] EmbargoExpiryDataObjects()
 */
class EmbargoExpirySiteTree extends SiteTree implements TestOnly
{
    /**
     * @var array
     */
    private static $db = [
        'MetaTitle' => 'Varchar(255)',
    ];

    /**
     * @var array
     */
    private static $has_many = [
        'EmbargoExpiryDataObjects' => EmbargoExpiryDataObject::class,
    ];

    /**
     * @var string
     */
    private static $table_name = 'EmbargoExpirySiteTree';

    /**
     * @return FieldList
     */
    public function getCMSFields() // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $fields = parent::getCMSFields();

        $fields->addFieldToTab(
            'Root.Main',
            $gridField = GridField::create(
                'EmbargoExpiryDataObjects',
                'Embargo Expiry Data Objects',
                $this->EmbargoExpiryDataObjects()
            )
        );

        $config = $gridField->getConfig();
        $config->addComponent(new GridFieldAddNewButton());
        $config->addComponent(new GridFieldEditButton());
        $config->addComponent(new GridFieldDeleteAction());
        $config->addComponent(new GridFieldDetailForm());

        return $fields;
    }
}
