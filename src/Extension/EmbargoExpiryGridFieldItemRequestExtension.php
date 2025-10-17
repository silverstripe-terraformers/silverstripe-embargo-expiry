<?php

namespace Terraformers\EmbargoExpiry\Extension;

use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Model\ModelDataCustomised;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Terraformers\EmbargoExpiry\Form\EmbargoExpiryFormAction;

/**
 * Experimental: This does not yet have test coverage. I suggest you write your own for now.
 *
 * @extends Extension<VersionedGridFieldItemRequest>
 */
class EmbargoExpiryGridFieldItemRequestExtension extends Extension
{
    /**
     * Extension point in @see VersionedGridFieldItemRequest::getFormActions()
     */
    protected function updateFormActions(FieldList $actions): FieldList
    {
        $owner = $this->getOwner();

        /** @var DataObject|EmbargoExpiryExtension $record */
        $record = $owner->getRecord();

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
                _t(EmbargoExpiryGridFieldItemRequestExtension::class . '.REMOVE_EMBARGO', 'Remove embargo')
            ));
        }

        if ($record->getIsUnPublishScheduled()) {
            $actions->push(EmbargoExpiryFormAction::create(
                'removeExpiryAction',
                _t(EmbargoExpiryGridFieldItemRequestExtension::class . '.REMOVE_EXPIRY', 'Remove expiry')
            ));
        }

        return $actions;
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their related queued jobs for
     * publishing and/or unpublishing.
     *
     * @return HTTPResponse|ModelDataCustomised|DBHTMLText
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeEmbargoAction(array $data, Form $form): mixed
    {
        $owner = $this->getOwner();
        $this->removeEmbargoOrExpiry('PublishOnDate');

        $message = _t(
            EmbargoExpiryGridFieldItemRequestExtension::class . '.RemovedEmbargoAction',
            'Successfully removed scheduled expiry date'
        );
        $form->sessionMessage($message, 'notice');
        $controller = Controller::curr();

        return $owner->edit($controller->getRequest());
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as the related queued jobs for
     * publishing and/or unpublishing.
     *
     * @return HTTPResponse|ModelDataCustomised|DBHTMLText
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeExpiryAction(array $data, Form $form): mixed
    {
        $owner = $this->getOwner();
        $this->removeEmbargoOrExpiry('UnPublishOnDate');

        $message = _t(
            EmbargoExpiryGridFieldItemRequestExtension::class . '.RemovedExpiryAction',
            'Successfully removed scheduled expiry date'
        );
        $form->sessionMessage($message, 'notice');
        $controller = Controller::curr();

        return $owner->edit($controller->getRequest());
    }

    /**
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeEmbargoOrExpiry(string $dateField): void
    {
        $owner = $this->getOwner();

        /** @var DataObject|EmbargoExpiryExtension|null $record */
        $record = $owner->getRecord();

        if ($record === null || !$record->exists()) {
            throw new HTTPResponse_Exception('Bad record', 404);
        }

        if (!$record->checkRemovePermission()) {
            throw new AccessDeniedException('You do not have permission to remove embargo and expiry dates.');
        }

        // Clear the appropriate Job and field
        switch ($dateField) {
            case 'PublishOnDate':
                $record->clearPublishJob();

                break;
            case 'UnPublishOnDate':
                $record->clearUnPublishJob();

                break;
            default:
                throw new Exception('Invalid action submitted');
        }

        $record->write();
    }
}
