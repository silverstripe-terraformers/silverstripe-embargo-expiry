<?php

namespace Terraformers\EmbargoExpiry\Tests\Extension;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;
use Terraformers\EmbargoExpiry\Tests\Mock\TestQueuedJobService;

class EmbargoExpiryExtensionTest extends SapphireTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'EmbargoExpiryExtensionTest.yml';

    /**
     * @var array
     */
    protected static $required_extensions = [
        SiteTree::class => [
            EmbargoExpiryExtension::class,
        ],
    ];

    /**
     * @throws \Exception
     */
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

    /**
     * @return TestQueuedJobService
     */
    protected function getService()
    {
        return singleton(TestQueuedJobService::class);
    }

    /**
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function testJobCreation()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');

        $page->DesiredPublishDate = '2014-02-05 12:00:00';
        $page->DesiredUnPublishDate = '2014-03-05 12:00:00';
        $page->write();

        $this->assertNotEquals(0, $page->PublishJobID);
        $this->assertNotEquals(0, $page->UnPublishJobID);
    }

    /**
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function testIsEditableNoEmbargo()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');

        $page->write();

        $this->assertTrue($page->isEditable());
    }

    /**
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function testIsEditableWithEmbargo()
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');
        $page->config()->set('allow_embargoed_editing', true);

        $page->DesiredPublishDate = '2014-02-05 12:00:00';
        $page->write();

        $this->assertTrue($page->isEditable());
    }

    /**
     * @throws \SilverStripe\ORM\ValidationException
     * @throws \Exception
     */
    public function testPublishJobProcesses()
    {
        $service = $this->getService();
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'embargo1');

        // PublishDate is in the past, so it should be run immediately when we initialise it.
        $page->DesiredPublishDate = '2014-01-01 12:00:00';
        $page->write();

        // Make sure we're not published before the Job runs.
        $this->assertFalse((bool) $page->isPublished());
        $this->assertNotEquals(0, $page->PublishJobID);

        $job = $service->testInit($page->PublishJob());
        $id = $service->queueJob($job);

        $service->runJob($id);

        // We should now be published.
        $this->assertTrue((bool) $page->isPublished());
    }

    /**
     * @throws \SilverStripe\ORM\ValidationException
     * @throws \Exception
     */
    public function testUnPublishJobProcesses()
    {
        $service = $this->getService();
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'expiry1');

        // UnPublishDate is in the past, so it should be run immediately when we initialise it.
        $page->DesiredUnPublishDate = '2014-01-01 12:00:00';
        $page->write();
        $page->publishSingle();

        // Make sure we're published before the Job runs.
        $this->assertTrue((bool) $page->isPublished());
        $this->assertNotEquals(0, $page->UnPublishJobID);

        $job = $service->testInit($page->UnPublishJob());
        $id = $service->queueJob($job);

        $service->runJob($id);

        // We should now be un-published.
        $this->assertFalse((bool) $page->isPublished());
    }
}
