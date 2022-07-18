<?php

namespace Terraformers\EmbargoExpiry\Tests\Extension;

use Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryFluentExtension;
use Terraformers\EmbargoExpiry\Tests\Fake\TestQueuedJobService;
use TractorCow\Fluent\Extension\FluentSiteTreeExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

class EmbargoExpiryFluentExtensionTest extends SapphireTest
{

    private const LOCALE_INT = 'en_NZ';
    private const LOCALE_JP = 'ja_JP';

    /**
     * @var string
     */
    protected static $fixture_file = 'EmbargoExpiryFluentExtensionTest.yml'; // phpcs:ignore

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     * @var array
     */
    protected static $required_extensions = [
        SiteTree::class => [
            EmbargoExpiryExtension::class,
            EmbargoExpiryFluentExtension::class,
            FluentSiteTreeExtension::class,
        ],
    ];

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     * @var array
     */
    protected static $extra_dataobjects = [
        Locale::class,
    ];

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        DBDatetime::set_mock_now('2014-01-05 12:00:00');

        // This doesn't play nicely with PHPUnit
        Config::modify()->set(QueuedJobService::class, 'use_shutdown_function', false);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();

        parent::tearDown();
    }

    /**
     * @return TestQueuedJobService
     */
    protected function getService(): TestQueuedJobService
    {
        return singleton(TestQueuedJobService::class);
    }

    public function testCorrectConfigSet(): void
    {
        /** @var SiteTree|EmbargoExpiryFluentExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');

        $expected = [
            'DesiredPublishDate',
            'DesiredUnPublishDate',
            'PublishOnDate',
            'UnPublishOnDate',
            'PublishJobID',
            'UnPublishJobID',
        ];

        $actual = $page->config()->get('field_include');

        // Set canonicalize to true so that the order of the items in each array are standardised.
        $this->assertEquals($expected, $actual, '', 0.0, 10, true);
    }

    public function testPublishScheduled(): void
    {
        // Fetch the Page ID for this object from the fixture, so that we can use the ID later and fetch more naturally.
        /** @var SiteTree $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');
        $pageID = $page->ID;

        // Check that an Embargo date is correctly set on the Int localisation.
        FluentState::singleton()->withState(function (FluentState $state) use ($pageID): void {
            $state->setLocale(static::LOCALE_INT);

            /** @var SiteTree|EmbargoExpiryExtension $page */
            $page = SiteTree::get()->byID($pageID);

            $this->assertNotNull($page);

            $this->assertTrue($page->getIsPublishScheduled(), 'Embargo was not set on Int localisation');
        });

        // Check that an Embargo date is correctly NOT set on the Int localisation.
        FluentState::singleton()->withState(function (FluentState $state) use ($pageID): void {
            $state->setLocale(static::LOCALE_JP);

            /** @var SiteTree|EmbargoExpiryExtension $page */
            $page = SiteTree::get()->byID($pageID);

            $this->assertFalse($page->getIsPublishScheduled(), 'Embargo was not set on Int localisation');
        });
    }

    public function testUnPublishScheduled(): void
    {
        // Check that an Expiry date is correctly set on the Int localisation.
        FluentState::singleton()->withState(function (FluentState $state): void {
            $state->setLocale(static::LOCALE_INT);

            /** @var SiteTree|EmbargoExpiryExtension $page */
            $page = $this->objFromFixture(SiteTree::class, 'home');

            $this->assertTrue($page->getIsUnPublishScheduled(), 'Expiry was not set on Int localisation');
        });

        // Check that an Expiry date is correctly NOT set on the Int localisation.
        FluentState::singleton()->withState(function (FluentState $state): void {
            $state->setLocale(static::LOCALE_JP);

            /** @var SiteTree|EmbargoExpiryExtension $page */
            $page = $this->objFromFixture(SiteTree::class, 'home');

            $this->assertFalse($page->getIsUnPublishScheduled(), 'Expiry was incorrectly set on JP localisation');
        });
    }

    /**
     * @throws Exception
     */
    public function testPublishJobProcesses(): void
    {
        // Fetch the Page ID for this object from the fixture, so that we can use the ID later and fetch more naturally.
        /** @var SiteTree $page */
        $page = $this->objFromFixture(SiteTree::class, 'embargo1');
        $pageID = $page->ID;

        FluentState::singleton()->withState(function (FluentState $state) use ($pageID): void {
            $state->setLocale(static::LOCALE_INT);

            $service = $this->getService();

            /** @var SiteTree|EmbargoExpiryExtension|FluentSiteTreeExtension $page */
            $page = SiteTree::get()->byID($pageID);

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
            $this->assertTrue($page->isPublishedInLocale());
        });

        // Check that the JP localisation is still in draft.
        FluentState::singleton()->withState(function (FluentState $state) use ($pageID): void {
            $state->setLocale(static::LOCALE_JP);

            /** @var SiteTree|EmbargoExpiryExtension|FluentSiteTreeExtension $page */
            $page = SiteTree::get()->byID($pageID);

            $this->assertFalse($page->isPublishedInLocale());
        });
    }

    /**
     * @throws Exception
     */
    public function testUnPublishJobProcesses(): void
    {
        // Fetch the Page ID for this object from the fixture, so that we can use the ID later and fetch more naturally.
        /** @var SiteTree $page */
        $page = $this->objFromFixture(SiteTree::class, 'expiry1');
        $pageID = $page->ID;

        FluentState::singleton()->withState(function (FluentState $state) use ($pageID): void {
            $state->setLocale(static::LOCALE_INT);

            $service = $this->getService();

            /** @var SiteTree|EmbargoExpiryExtension|FluentSiteTreeExtension $page */
            $page = SiteTree::get()->byID($pageID);

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
            $this->assertFalse($page->isPublishedInLocale());
        });

        // Check that the JP localisation is still published.
        FluentState::singleton()->withState(function (FluentState $state) use ($pageID): void {
            $state->setLocale(static::LOCALE_JP);

            /** @var SiteTree|EmbargoExpiryExtension|FluentSiteTreeExtension $page */
            $page = SiteTree::get()->byID($pageID);

            $this->assertTrue($page->isPublishedInLocale());
        });
    }

}
