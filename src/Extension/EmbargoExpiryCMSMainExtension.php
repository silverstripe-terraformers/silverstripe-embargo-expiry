<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;
use Symfony\Component\Finder\Exception\AccessDeniedException;

/**
 * Class EmbargoExpiryCMSMainExtension
 *
 * @package Terraformers\EmbargoExpiry\Extension
 * @property CMSMain $owner
 */
class EmbargoExpiryCMSMainExtension extends Extension
{
    /**
     * @var array
     */
    private static $allowed_actions = [
        'removeEmbargoAction',
        'removeExpiryAction',
    ];

    /**
     * @codeCoverageIgnore
     * @param Form $form
     */
    public function updateEditForm(Form $form): void
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
     * This action will remove any/all embargo related dates from a record as well as their
     * related queued jobs for publishing and/or unpublishing.
     *
     * @param array $data
     * @param Form $form
     * @return mixed
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeEmbargoAction(array $data, Form $form)
    {
        $this->removeEmbargoOrExpiry($data['ClassName'], $data['ID'], 'PublishOnDate');

        $this->owner->getResponse()->addHeader(
            'X-Status',
            'Successfully removed scheduled embargo date'
        );

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }

    /**
     * This action will remove any/all embargo related dates from a record as well as their
     * related queued jobs for publishing and/or unpublishing.
     *
     * @param array $data
     * @param Form $form
     * @return mixed
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeExpiryAction(array $data, Form $form)
    {
        $this->removeEmbargoOrExpiry($data['ClassName'], $data['ID'], 'UnPublishOnDate');

        $this->owner->getResponse()->addHeader(
            'X-Status',
            'Successfully removed scheduled expiry date'
        );

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }

    /**
     * @param string $className
     * @param int $id
     * @param string $dateField
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    protected function removeEmbargoOrExpiry(string $className, int $id, string $dateField): void
    {
        /** @var DataObject|EmbargoExpiryExtension|null $record */
        $record = DataObject::get($className)->byID($id);
        if ($record === null || !$record->exists()) {
            throw new HTTPResponse_Exception("Bad record ID #$id", 404);
        }

        if (!$record->checkRemovePermission()) {
            throw new AccessDeniedException('You do not have permission to remove embargo and expiry dates.');
        }

        // Writing the record with no embargo set will automatically remove the queued jobs.
        $record->{$dateField} = null;

        $record->write();
    }
}
