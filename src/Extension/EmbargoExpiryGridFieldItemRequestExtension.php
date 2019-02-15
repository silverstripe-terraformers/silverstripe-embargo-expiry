<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use SilverStripe\View\ViewableData_Customised;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Terraformers\EmbargoExpiry\Form\EmbargoExpiryFormAction;

/**
 * Experimental: This does not yet have test coverage. I suggest you write your own for now.
 *
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
    public function updateFormActions(FieldList $actions): FieldList
    {
        /** @var DataObject|EmbargoExpiryExtension $record */
        $record = $this->owner->getRecord();

        // Break out if record does not have EmbargoExpiry extension
        if (!$record->hasExtension(EmbargoExpiryExtension::class)) {
            return $actions;
        }

        // Check that the user has permission to remove Embargo/Expiry for this Object. Exit early if they don't.
        if (!$record->checkRemovePermission()) {
            return $actions;
        }

        if ($record->getIsPublishScheduled()) {
            $actions->push(EmbargoExpiryFormAction::create(
                'removeEmbargoAction',
                _t(__CLASS__ . '.REMOVE_EMBARGO', 'Remove embargo')
            ));
        }

        if ($record->getIsUnPublishScheduled()) {
            $actions->push(EmbargoExpiryFormAction::create(
                'removeExpiryAction',
                _t(__CLASS__ . '.REMOVE_EXPIRY', 'Remove expiry')
            ));
        }

        return $actions;
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their related queued jobs for
     * publishing and/or unpublishing.
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse|ViewableData_Customised
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeEmbargoAction(array $data, Form $form)
    {
        $this->removeEmbargoOrExpiry('PublishOnDate');

        $message = _t(__CLASS__ . '.RemovedEmbargoAction', 'Successfully removed scheduled expiry date');
        $form->sessionMessage($message, 'notice');

        $controller = Controller::curr();

        return $this->owner->edit($controller->getRequest());
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as theirelated queued jobs for
     * publishing and/or unpublishing.
     *
     * @param array $data
     * @param Form $form
     * @return HTTPResponse|ViewableData_Customised
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeExpiryAction(array $data, Form $form)
    {
        $this->removeEmbargoOrExpiry('UnPublishOnDate');

        $message = _t(__CLASS__ . '.RemovedExpiryAction', 'Successfully removed scheduled expiry date');
        $form->sessionMessage($message, 'notice');

        $controller = Controller::curr();

        return $this->owner->edit($controller->getRequest());
    }

    /**
     * @param string $dateField
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeEmbargoOrExpiry(string $dateField): void
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

        $record->write();
    }
}
