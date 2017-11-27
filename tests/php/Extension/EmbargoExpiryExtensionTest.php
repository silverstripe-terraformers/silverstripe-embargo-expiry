<?php

namespace Terraformers\EmbargoExpiry\Tests\Extension;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

class EmbargoExpiryExtensionTest extends SapphireTest
{
    protected static $fixture_file = 'EmbargoExpiryExtensionTest.yml';

    protected static $required_extensions = [
        SiteTree::class => [
            EmbargoExpiryExtension::class,
        ],
    ];

    protected function setUp()
    {
        parent::setUp();

        DBDatetime::set_mock_now('2014-01-05 12:00:00');

        // This doesn't play nicely with PHPUnit
        Config::modify()->set(QueuedJobService::class, 'use_shutdown_function', false);
    }

    protected function tearDown()
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    public function testJobCreationAndRemoval()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture('SiteTree', 'home');

        $page->PublishOnDate = '2014-02-05 12:00:00';
        $page->UnPublishOnDate = '2014-03-05 12:00:00';
        $page->write();

        $this->assertNotEquals(0, $page->PublishJobID);
        $this->assertNotEquals(0, $page->UnPublishJobID);

        $page->PublishOnDate = null;
        $page->UnPublishOnDate = null;
        $page->write();

        $this->assertEquals(0, $page->PublishJobID);
        $this->assertEquals(0, $page->UnPublishJobID);
    }

    public function testDuplicateAction()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture('SiteTree', 'home');

        $page->PublishOnDate = '2014-02-05 12:00:00';
        $page->UnPublishOnDate = '2014-03-05 12:00:00';
        $page->write();

        /** @var SiteTree|EmbargoExpiryExtension $clone */
        $clone = $page->duplicate();

        $this->assertFalse($clone->getIsPublishScheduled());
        $this->assertFalse($clone->getIsUnPublishScheduled());
    }

    public function testIsEditableNoEmbargo()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture('SiteTree', 'home');

        $page->write();

        $this->assertTrue($page->isEditable());
    }

    public function testIsEditableWithEmbargo()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture('SiteTree', 'home');
        $page->config()->set('allow_embargoed_editing', true);

        $page->PublishOnDate = '2014-02-05 12:00:00';
        $page->write();

        $this->assertTrue($page->isEditable());
    }
}
