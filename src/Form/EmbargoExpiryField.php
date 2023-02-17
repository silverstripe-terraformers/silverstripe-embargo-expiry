<?php

namespace Terraformers\EmbargoExpiry\Form;

use Exception;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DataObject;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

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
        /** @var DataObject|EmbargoExpiryExtension $record */
        $record = $this->getForm()->getRecord();

        if (!$record->hasExtension(EmbargoExpiryExtension::class)) {
            throw new Exception(sprintf(
                'Class "%s" does not have "%s" applied',
                $record->ClassName,
                EmbargoExpiryExtension::class
            ));
        }

        return json_encode([
            'recordId' => $record->ID,
            'recordClass' => $record->ClassName,
            'desiredPublishDate' => $record->DesiredPublishDate,
            'desiredUnPublishDate' => $record->DesiredUnPublishDate,
            'publishOnDate' => $record->PublishOnDate,
            'unPublishOnDate' => $record->UnPublishOnDate,
        ]);
    }
}
