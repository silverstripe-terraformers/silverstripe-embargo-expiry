<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\Control\HTTPResponse;
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
    private static $allowed_actions = array(
        'removeEmbargoAction',
        'removeExpiryAction',
    );

    /**
     * @param Form $form
     */
    public function updateEditForm($form)
    {
        // Add archive to CMS exemption
        $exempt = $form->getValidationExemptActions();
        $exempt = array_merge(
            $exempt,
            array(
                'removeEmbargoAction',
                'removeExpiryAction',
            )
        );

        $form->setValidationExemptActions($exempt);
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
        $this->removeEmbargoOrExpiry($data['ClassName'], $data['ID'], 'PublishOnDate', 'PublishJobID');

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
     * @return HTTPResponse
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    public function removeExpiryAction($data, $form)
    {
        $this->removeEmbargoOrExpiry($data['ClassName'], $data['ID'], 'UnPublishOnDate', 'UnPublishJobID');

        $this->owner->getResponse()->addHeader(
            'X-Status',
            'Successfully removed scheduled expiry date'
        );

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }

    /**
     * @param string $className
     * @param string $id
     * @param string $dateField
     * @param string $jobField
     * @throws HTTPResponse_Exception
     * @throws ValidationException
     */
    protected function removeEmbargoOrExpiry($className, $id, $dateField, $jobField)
    {
        /** @var DataObject|EmbargoExpiryExtension $record */
        $record = DataObject::get($className)->byID($id);
        if (!$record || !$record->exists()) {
            throw new HTTPResponse_Exception("Bad record ID #$id", 404);
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
