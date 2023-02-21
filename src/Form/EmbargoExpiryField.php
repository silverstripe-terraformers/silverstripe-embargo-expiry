<?php

namespace Terraformers\EmbargoExpiry\Form;

use DateTimeImmutable;
use Exception;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
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

        // TODO need to forward the authors edit permissions, and appropriate enable disable editing of embargo/expiry
    }

    private function datesAreSequential(int $desiredPublishTime, int $desiredUnPublishTime, int $unPublishTime): bool
    {
        // The desired publish date is set after the desired un-publish date, and you require sequential dates.
        if ($desiredUnPublishTime && $desiredPublishTime > $desiredUnPublishTime) {
            return false;
        }

        // The desired publish date is set after the active un-publish date, and you require sequential dates.
        if ($unPublishTime && $desiredPublishTime > $unPublishTime) {
            return false;
        }

        return true;
    }

}
