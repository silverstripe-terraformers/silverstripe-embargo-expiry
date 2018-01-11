<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use Symfony\Component\Finder\Exception\AccessDeniedException;

class EmbargoExpiryCMSMainExtension extends Extension
{
    private static $allowed_actions = array(
        'removeEmbargoAction',
        'removeExpiryAction',
    );

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
     */
    public function removeEmbargoAction($data, $form)
    {
        // Find the record.
        $id = $data['ID'];

        $this->removeEmbargoOrExpiry($id, 'PublishOnDate');

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
     */
    public function removeExpiryAction($data, $form)
    {
        // Find the record.
        $id = $data['ID'];

        $this->removeEmbargoOrExpiry($id, 'UnPublishOnDate');

        $this->owner->getResponse()->addHeader(
            'X-Status',
            'Successfully removed scheduled expiry date'
        );

        return $this->owner->getResponseNegotiator()->respond($this->owner->getRequest());
    }

    /**
     * @param $id
     * @param $field
     * @throws HTTPResponse_Exception
     */
    protected function removeEmbargoOrExpiry($id, $field)
    {
        /** @var SiteTree|EmbargoExpiryExtension $record */
        $record = SiteTree::get()->byID($id);
        if (!$record || !$record->exists()) {
            throw new HTTPResponse_Exception("Bad record ID #$id", 404);
        }

        if (!$record->checkRemovePermission()) {
            throw new AccessDeniedException('You do not have permission to remove embargo and expiry dates.');
        }

        // Writing the record with no embargo set will automatically remove the queued jobs.
        $record->$field = null;

        $record->write();
    }
}
