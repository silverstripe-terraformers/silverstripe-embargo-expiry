<?php

namespace Terraformers\EmbargoExpiry\Extension;

use Exception;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use Symfony\Component\Finder\Exception\AccessDeniedException;

/**
 * @extends Extension<CMSMain>
 */
class EmbargoExpiryCMSMainExtension extends Extension
{
    private static array $allowed_actions = [
        'removeEmbargoAction',
        'removeExpiryAction',
    ];

    /**
     * Extension point in @see CMSMain::getEditForm()
     */
    protected function updateEditForm(Form $form): void
    {
        // Add archive to CMS exemption
        $exempt = $form->getValidationExemptActions();
        $exempt = array_merge(
            $exempt,
            [
                'removeEmbargoAction',
                'removeExpiryAction',
            ]
        );

        $form->setValidationExemptActions($exempt);
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their related queued jobs for
     * publishing and/or unpublishing.
     *
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeEmbargoAction(array $data, Form $form): mixed
    {
        $owner = $this->getOwner();

        $this->removeEmbargoOrExpiry($data['ClassName'], $data['ID'], 'PublishOnDate');
        $owner->getResponse()->addHeader('X-Status', 'Successfully removed scheduled embargo date');

        return $owner->getResponseNegotiator()->respond($owner->getRequest());
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their related queued jobs for
     * publishing and/or unpublishing.
     *
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    public function removeExpiryAction(array $data, Form $form): mixed
    {
        $owner = $this->getOwner();

        $this->removeEmbargoOrExpiry($data['ClassName'], $data['ID'], 'UnPublishOnDate');
        $owner->getResponse()->addHeader('X-Status', 'Successfully removed scheduled expiry date');

        return $owner->getResponseNegotiator()->respond($owner->getRequest());
    }

    /**
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     * @throws Exception
     */
    protected function removeEmbargoOrExpiry(string $className, int $id, string $dateField): void
    {
        /** @var DataObject|EmbargoExpiryExtension|null $record */
        $record = DataObject::get($className)->byID($id);

        if ($record === null || !$record->exists()) {
            throw new HTTPResponse_Exception(sprintf('Bad record ID #%s', $id), 404);
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
