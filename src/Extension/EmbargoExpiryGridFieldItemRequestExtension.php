<?php

namespace Terraformers\EmbargoExpiry\Extension;

use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use SilverStripe\View\ViewableData_Customised;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Terraformers\EmbargoExpiry\Form\EmbargoExpiryFormAction;
use Terraformers\EmbargoExpiry\Model\ScheduledAction;

/**
 * Experimental: This does not yet have test coverage. I suggest you write your own for now.
 *
 * @property VersionedGridFieldItemRequest $owner
 */
class EmbargoExpiryGridFieldItemRequestExtension extends Extension
{
    public function updateFormActions(FieldList $actions): FieldList
    {
        /** @var DataObject|EmbargoExpiryExtension $record */
        $record = $this->owner->getRecord();

        // Break out if record does not have EmbargoExpiry extension
        if (!$record->hasExtension(EmbargoExpiryExtension::class)) {
            return $actions;
        }

        // Check that the user has permission to remove Embargo/Expiry for this Object. Exit early if they don't.
        if (!ScheduledAction::checkRemovePermission()) {
            return $actions;
        }

        if ($record->getIsPublishScheduled()) {
            $actions->push(EmbargoExpiryFormAction::create(
                'removeEmbargoAction',
                _t(self::class . '.REMOVE_EMBARGO', 'Remove embargo')
            ));
        }

        if ($record->getIsUnPublishScheduled()) {
            $actions->push(EmbargoExpiryFormAction::create(
                'removeExpiryAction',
                _t(self::class . '.REMOVE_EXPIRY', 'Remove expiry')
            ));
        }

        return $actions;
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their related queued jobs for
     * publishing and/or unpublishing.
     *
     * @return HTTPResponse|ViewableData_Customised|DBHTMLText
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeEmbargoAction(array $data, Form $form)
    {
        $this->removeEmbargoOrExpiry('PublishOnDate');

        $message = _t(self::class . '.RemovedEmbargoAction', 'Successfully removed scheduled expiry date');
        $form->sessionMessage($message, 'notice');

        $controller = Controller::curr();

        return $this->owner->edit($controller->getRequest());
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as theirelated queued jobs for
     * publishing and/or unpublishing.
     *
     * @return HTTPResponse|ViewableData_Customised|DBHTMLText
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeExpiryAction(array $data, Form $form)
    {
        $this->removeEmbargoOrExpiry('UnPublishOnDate');

        $message = _t(self::class . '.RemovedExpiryAction', 'Successfully removed scheduled expiry date');
        $form->sessionMessage($message, 'notice');

        $controller = Controller::curr();

        return $this->owner->edit($controller->getRequest());
    }

    /**
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeEmbargoOrExpiry(string $dateField): void
    {
        /** @var DataObject|EmbargoExpiryExtension|null $record */
        $record = $this->owner->getRecord();

        if ($record === null || !$record->exists()) {
            throw new HTTPResponse_Exception('Bad record', 404);
        }

        if (!ScheduledAction::checkRemovePermission()) {
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
