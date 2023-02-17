<?php

namespace Terraformers\EmbargoExpiry\GraphQL;

use DateTime;
use GraphQL\Type\Definition\ResolveInfo;
use SilverStripe\ORM\DataObject;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;
use Terraformers\EmbargoExpiry\Model\Action;

class EmbargoExpiryFormMutation
{
    public static function submitEmbargoExpiryForm(mixed $object, array $args, mixed $context, ResolveInfo $info): array
    {
        $recordClass = $args['recordClass'] ?? null;
        $recordId = (int) $args['recordId'] ?? null;
        $desiredPublishDate = $args['desiredPublishDate'] ?? null;
        $desiredUnPublishDate = $args['desiredUnPublishDate'] ?? null;
        $publishOnDate = null;
        $unPublishOnDate = null;

        $dataObject = DataObject::get($recordClass)->byID($recordId);

        if (!$dataObject?->exists()) {
            return [
                'publishOnDate' => null,
                'unPublishOnDate' => null,
            ];
        }

        if ($desiredPublishDate) {
            $publishOnDate = new DateTime($desiredPublishDate);
            $publishOnDate = $publishOnDate->format('Y-m-d H:i:s');

            $embargoAction = self::findOrCreateEmbargoAction($dataObject);
            $embargoAction->Datetime = $publishOnDate;
            $embargoAction->write();
        }

        if ($desiredUnPublishDate) {
            $unPublishOnDate = new DateTime($desiredUnPublishDate);
            $unPublishOnDate = $unPublishOnDate->format('Y-m-d H:i:s');

            $expiryAction = self::findOrCreateExpiryAction($dataObject);
            $expiryAction->Datetime = $unPublishOnDate;
            $expiryAction->write();
        }

        return [
            'publishOnDate' =>$publishOnDate,
            'unPublishOnDate' => $unPublishOnDate,
        ];
    }

    /**
     * The intention for the future might be to allow multiple Embargoes in sequence for any particular record. For now,
     * we're going to keep it simple with just one (keeping the previous state)
     *
     * @param DataObject|EmbargoExpiryExtension $dataObject
     */
    private static function findOrCreateEmbargoAction(DataObject $dataObject): ?Action
    {
        $embargoAction = $dataObject->EmbargoExpiryActions()
            ->filter('Type', Action::ACTION_EMBARGO)
            ->first();

        if ($embargoAction?->exists()) {
            return $embargoAction;
        }

        $embargoAction = Action::create();
        $embargoAction->Type = Action::ACTION_EMBARGO;
        $embargoAction->RecordClass = $dataObject->ClassName;
        $embargoAction->RecordID = $dataObject->ID;
        $embargoAction->write();

        return $embargoAction;
    }

    /**
     * The intention for the future might be to allow multiple Expiry dates in sequence for any particular record. For
     * now, we're going to keep it simple with just one (keeping the previous state)
     *
     * @param DataObject|EmbargoExpiryExtension $dataObject
     */
    private static function findOrCreateExpiryAction(DataObject $dataObject): ?Action
    {
        $embargoAction = $dataObject->EmbargoExpiryActions()
            ->filter('Type', Action::ACTION_EXPIRY)
            ->first();

        if ($embargoAction?->exists()) {
            return $embargoAction;
        }

        $embargoAction = Action::create();
        $embargoAction->Type = Action::ACTION_EXPIRY;
        $embargoAction->RecordClass = $dataObject->ClassName;
        $embargoAction->RecordID = $dataObject->ID;
        $embargoAction->write();

        return $embargoAction;
    }
}
