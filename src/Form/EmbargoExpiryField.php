<?php

namespace Terraformers\EmbargoExpiry\Form;

use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FormField;

class EmbargoExpiryField extends FieldGroup
{
    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;

    protected $schemaComponent = 'EmbargoExpiryField';

    private DatetimeField $desiredPublishDate;

    private DatetimeField $desiredUnPublishDate;

    private static array $default_classes = [
        'EmbargoExpiryField',
    ];

    public function __construct(
        string $name,
        ?string $title = null,
    ) {
        $fields = [
            $this->desiredPublishDate = DatetimeField::create('DesiredPublishDate'),
            $this->desiredUnPublishDate = DatetimeField::create('DesiredUnPublishDate'),
        ];

        $this->addExtraClass('EmbargoExpiryField');

        parent::__construct($title, $fields);
    }

    public function getState(): string
    {
        return json_encode([
            'RecordID' => $this->getForm()->getRecord()->ID,
            'RecordClass' => $this->getForm()->getRecord()->ClassName,
        ]);
    }

    public function getSchemaStateDefaults()
    {
        $state = parent::getSchemaStateDefaults();
        $state['desiredPublishDate'] = $this->desiredPublishDate->getSchemaState();
        $state['desiredUnPublishDate'] = $this->desiredUnPublishDate->getSchemaState();

        return $state;
    }
}
