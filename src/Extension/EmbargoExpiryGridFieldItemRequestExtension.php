<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use Symfony\Component\Finder\Exception\AccessDeniedException;

/**
 * Class EmbargoExpiryGridFieldItemRequestExtension
 *
 * @package Terraformers\EmbargoExpiry\Extension
 * @property VersionedGridFieldItemRequest $owner
 */
class EmbargoExpiryGridFieldItemRequestExtension extends Extension
{
    /**
     * @param FieldList $actions
     * @return FieldList
     */
    public function updateFormActions(FieldList $actions)
    {
        /** @var DataObject|EmbargoExpiryExtension $record */
        $record = $this->owner->getRecord();

        // Break out if record does not have EmbargoExpiry extension
        if (!$record->hasExtension(EmbargoExpiryExtension::class)) {
            return $actions;
        }

        if ($record->getIsPublishScheduled()) {
            $actions->push(FormAction::create(
                'removeEmbargoAction',
                _t(__CLASS__ . '.REMOVE_EMBARGO', 'Remove embargo')
            ));
        }

        if ($record->getIsUnPublishScheduled()) {
            $actions->push(FormAction::create(
                'removeExpiryAction',
                _t(__CLASS__ . '.REMOVE_EXPIRY', 'Remove expiry')
            ));
        }

        return $actions;
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their
     * related queued jobs for publishing and/or unpublishing.
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeEmbargoAction($data, $form)
    {
        $this->removeEmbargoOrExpiry('PublishOnDate', 'PublishJobID');

        $message = _t(__CLASS__ . '.RemovedEmbargoAction', 'Successfully removed scheduled expiry date');
        $form->sessionMessage($message, 'notice');

        $controller = Controller::curr();
        return $this->owner->edit($controller->getRequest());
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their
     * related queued jobs for publishing and/or unpublishing.
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeExpiryAction($data, $form)
    {
        $this->removeEmbargoOrExpiry('UnPublishOnDate', 'UnPublishJobID');

        $message = _t(__CLASS__ . '.RemovedExpiryAction', 'Successfully removed scheduled expiry date');
        $form->sessionMessage($message, 'notice');

        $controller = Controller::curr();
        return $this->owner->edit($controller->getRequest());
    }

    /**
     * @param string $dateField
     * @param string $jobField
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeEmbargoOrExpiry($dateField, $jobField)
    {
        /** @var DataObject|EmbargoExpiryExtension $record */
        $record = $this->owner->getRecord();

        if (!$record || !$record->exists()) {
            throw new HTTPResponse_Exception("Bad record", 404);
        }

        if (!$record->checkRemovePermission()) {
            throw new AccessDeniedException('You do not have permission to remove embargo and expiry dates.');
        }

        // Writing the record with no embargo set will automatically remove the queued jobs.
        $record->{$dateField} = null;
        $record->{$jobField} = 0;

        $record->write();
    }
}
