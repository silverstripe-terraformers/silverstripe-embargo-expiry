<?php

namespace Terraformers\EmbargoExpiry\Tests\Extension;

use Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\VersionedGridFieldItemRequest;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryGridFieldItemRequestExtension;
use Terraformers\EmbargoExpiry\Tests\Mock\EmbargoExpiryDataObject;
use Terraformers\EmbargoExpiry\Tests\Mock\EmbargoExpirySiteTree;

/**
 * Class EmbargoExpiryGridFieldItemRequestExtension
 *
 * @package Terraformers\EmbargoExpiry\Tests\Extension
 * @see EmbargoExpiryGridFieldItemRequestExtension
 */
class EmbargoExpiryGridFieldItemRequestExtensionTest extends FunctionalTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'EmbargoExpiryGridFieldItemRequestExtensionTest.yml';

    /**
     * @var array
     */
    protected static $extra_dataobjects = [
        EmbargoExpirySiteTree::class,
        EmbargoExpiryDataObject::class,
    ];

    /**
     * @var array
     */
    protected static $required_extensions = [
        VersionedGridFieldItemRequest::class => [
            EmbargoExpiryGridFieldItemRequestExtension::class,
        ],
    ];

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        DBDatetime::set_mock_now('2014-01-05 12:00:00');

        // This doesn't play nicely with PHPUnit
        Config::modify()->set(QueuedJobService::class, 'use_shutdown_function', false);
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    public function testRemoveEmbargoAction(): void
    {
        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(EmbargoExpirySiteTree::class, 'home');
        $id = $page->ID;

        /** @var EmbargoExpiryDataObject $object */
        $object = $this->objFromFixture(EmbargoExpiryDataObject::class, 'object1');
        $objectID = $object->ID;

        $this->assertTrue($object->getIsPublishScheduled());
        $this->assertTrue($object->getIsUnPublishScheduled());

        // Post a request to remove the embargo date.
        $this->post(
            sprintf('/admin/pages/edit/EditForm/%s/field/EmbargoExpiryDataObjects/item/%s/ItemEditForm/', $id, $objectID),
            [
                'PageID' => $id,
                'DesiredPublishDate' => '',
                'DesiredUnPublishDate' => '',
                'Title' => 'Test1',
                'action_removeEmbargoAction' => 1,
            ]
        );

        // Refetch object from DB.
        $object = EmbargoExpiryDataObject::get()->byID($objectID);

        $this->assertFalse($object->getIsPublishScheduled());
        $this->assertTrue($object->getIsUnPublishScheduled());
    }

    public function testRemoveExpiryAction(): void
    {
        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(EmbargoExpirySiteTree::class, 'contact');
        $id = $page->ID;

        /** @var EmbargoExpiryDataObject $object */
        $object = $this->objFromFixture(EmbargoExpiryDataObject::class, 'object2');
        $objectID = $object->ID;

        // Check that we're set up correctly.
        $this->assertTrue($object->getIsPublishScheduled());
        $this->assertTrue($object->getIsUnPublishScheduled());

        // Post a request to remove the embargo date.
        $this->post(
            sprintf('/admin/pages/edit/EditForm/%s/field/EmbargoExpiryDataObjects/item/%s/ItemEditForm/', $id, $objectID),
            [
                'PageID' => $id,
                'DesiredPublishDate' => '',
                'DesiredUnPublishDate' => '',
                'Title' => 'Test1',
                'action_removeExpiryAction' => 1,
            ]
        );

        // Refetch object from DB.
        $object = EmbargoExpiryDataObject::get()->byID($objectID);

        $this->assertTrue($object->getIsPublishScheduled());
        $this->assertFalse($object->getIsUnPublishScheduled());
    }
}
