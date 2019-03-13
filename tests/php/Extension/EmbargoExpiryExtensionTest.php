<?php

namespace Terraformers\EmbargoExpiry\Tests\Extension;

use Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TabSet;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationException;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;
use Terraformers\EmbargoExpiry\Tests\Fake\TestQueuedJobService;

/**
 * Class EmbargoExpiryExtensionTest
 *
 * @package Terraformers\EmbargoExpiry\Tests\Extension
 */
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

    /**
     * @return TestQueuedJobService
     */
    protected function getService(): TestQueuedJobService
    {
        return singleton(TestQueuedJobService::class);
    }

    public function testGetIsPublishScheduled(): void
    {
        /** @var SiteTree|EmbargoExpiryExtension $page1 */
        $page1 = $this->objFromFixture(SiteTree::class, 'scheduledPublish1');
        /** @var SiteTree|EmbargoExpiryExtension $page2 */
        $page2 = $this->objFromFixture(SiteTree::class, 'scheduledPublish2');

        $this->assertTrue($page1->getIsPublishScheduled());
        $this->assertTrue($page2->getIsPublishScheduled());
    }

    public function testGetIsUnPublishScheduled(): void
    {
        /** @var SiteTree|EmbargoExpiryExtension $page1 */
        $page1 = $this->objFromFixture(SiteTree::class, 'scheduledUnPublish1');
        /** @var SiteTree|EmbargoExpiryExtension $page2 */
        $page2 = $this->objFromFixture(SiteTree::class, 'scheduledUnPublish2');

        $this->assertTrue($page1->getIsUnPublishScheduled());
        $this->assertTrue($page2->getIsUnPublishScheduled());
    }

    /**
     * @throws ValidationException
     */
    public function testJobCreation(): void
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
     * @throws ValidationException
     */
    public function testIsEditableNoEmbargo(): void
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');

        $page->write();

        $this->assertNull($page->isEditable());
    }

    /**
     * @throws ValidationException
     */
    public function testIsEditableWithEmbargo(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', true);

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');

        $page->DesiredPublishDate = '2014-02-05 12:00:00';
        $page->write();

        $this->assertNull($page->isEditable());
    }

    /**
     * @throws ValidationException
     */
    public function testIsNotEditableWithEmbargo(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');

        $page->DesiredPublishDate = '2014-02-05 12:00:00';
        $page->write();

        $this->assertFalse($page->isEditable());
    }

    /**
     * @throws ValidationException
     * @throws Exception
     */
    public function testPublishJobProcesses(): void
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
     * @throws ValidationException
     * @throws Exception
     */
    public function testUnPublishJobProcesses(): void
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

    public function testUpdateCMSFields(): void
    {
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'idfields');
        $fields = $page->getCMSFields();

        $this->assertNull($fields->dataFieldByName('PublishJobID'));
        $this->assertNull($fields->dataFieldByName('UnPublishJobID'));
    }

    public function testMessageConditionsCanEdit(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', true);

        $this->logOut();

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'messages1');
        $fields = new FieldList();

        $page->addNoticeOrWarningFields($fields);

        $fieldsArray = $fields->toArray();

        $this->assertCount(1, $fieldsArray);

        /** @var LiteralField $literalField */
        $literalField = $fieldsArray[0];
        $content = $literalField->getContent();

        $this->assertNotContains('cannot currently be edited', $content);
        $this->assertContains('Embargo</strong>: 2014-01-07 12:00:00', $content);
        $this->assertContains('Expiry</strong>: 2014-01-08 12:00:00', $content);
    }

    public function testMessageConditionsCannotEditGuest(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        $this->logOut();

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'messages1');
        $fields = new FieldList();

        $page->addNoticeOrWarningFields($fields);

        $fieldsArray = $fields->toArray();

        $this->assertCount(1, $fieldsArray);

        /** @var LiteralField $literalField */
        $literalField = $fieldsArray[0];
        $content = $literalField->getContent();

        $this->assertContains('cannot currently be edited', $content);
        $this->assertContains('An administrator will need', $content);
        $this->assertContains('Embargo</strong>: 2014-01-07 12:00:00', $content);
        $this->assertContains('Expiry</strong>: 2014-01-08 12:00:00', $content);
    }

    public function testMessageConditionsCannotEditAdmin(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'messages1');
        $fields = new FieldList();

        $page->addNoticeOrWarningFields($fields);

        $fieldsArray = $fields->toArray();

        $this->assertCount(1, $fieldsArray);

        /** @var LiteralField $literalField */
        $literalField = $fieldsArray[0];
        $content = $literalField->getContent();

        $this->assertContains('cannot currently be edited', $content);
        $this->assertContains('You will need to remove', $content);
        $this->assertContains('Embargo</strong>: 2014-01-07 12:00:00', $content);
        $this->assertContains('Expiry</strong>: 2014-01-08 12:00:00', $content);
    }

    public function testMessageConditionsWarning(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'messages1');
        $fields = new FieldList();

        $page->addNoticeOrWarningFields($fields);

        $fieldsArray = $fields->toArray();

        $this->assertCount(1, $fieldsArray);

        /** @var LiteralField $literalField */
        $literalField = $fieldsArray[0];
        $content = $literalField->getContent();

        // Test that the two warning messages were added.
        $this->assertContains('Embargo</strong>: 2014-01-07 12:00:00<strong> (this date is in the', $content);
        $this->assertContains('Expiry</strong>: 2014-01-08 12:00:00<strong> (this date is in the', $content);
    }

    public function testEmbargoExpiryFieldNoticeMessageNotEditable(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'scheduledPublish1');

        $this->assertNull($page->getEmbargoExpiryFieldNoticeMessage());
    }

    public function testEmbargoExpiryFieldNoticeMessageWithPermission(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', true);

        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'scheduledPublish1');

        $message = $page->getEmbargoExpiryFieldNoticeMessage();

        $this->assertNotNull($message);
        $this->assertContains('Enter a date and/or time', $message);
    }

    public function testEmbargoExpiryFieldNoticeMessageWithoutPermission(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', true);

        $this->logOut();

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'scheduledPublish1');

        $message = $page->getEmbargoExpiryFieldNoticeMessage();

        $this->assertNotNull($message);
        $this->assertContains('Please contact an administrator', $message);
    }

    public function testAddDesiredDateFieldsWithoutPermission(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        $this->logOut();

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'fields1');
        $fields = new FieldList([TabSet::create('Root')]);

        $page->addDesiredDateFields($fields);

        $publishField = $fields->dataFieldByName('DesiredPublishDate');
        $unPublishField = $fields->dataFieldByName('DesiredUnPublishDate');

        $this->assertNotNull($publishField);
        $this->assertTrue($publishField->isReadonly());
        $this->assertNotNull($unPublishField);
        $this->assertTrue($unPublishField->isReadonly());;
    }

    public function testAddScheduledDateFieldsWithoutPermission(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        $this->logOut();

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'fields1');
        $fields = new FieldList([TabSet::create('Root')]);

        $page->addScheduledDateFields($fields);

        $this->assertNotNull($fields->dataFieldByName('PublishOnDate'));
        $this->assertNotNull($fields->dataFieldByName('UnPublishOnDate'));
    }

    public function testAddDesiredDateFieldsWithPermission(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'fields1');
        $fields = new FieldList([TabSet::create('Root')]);

        $page->addDesiredDateFields($fields);

        $publishField = $fields->dataFieldByName('DesiredPublishDate');
        $unPublishField = $fields->dataFieldByName('DesiredUnPublishDate');

        $this->assertNotNull($publishField);
        $this->assertFalse($publishField->isReadonly());
        $this->assertNotNull($unPublishField);
        $this->assertFalse($unPublishField->isReadonly());
    }

    public function testAddScheduledDateFieldsWithPermission(): void
    {
        Config::modify()->set(SiteTree::class, 'allow_embargoed_editing', false);

        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'fields1');
        $fields = new FieldList([TabSet::create('Root')]);

        $page->addScheduledDateFields($fields);

        $this->assertNotNull($fields->dataFieldByName('PublishOnDate'));
        $this->assertNotNull($fields->dataFieldByName('UnPublishOnDate'));
    }

    public function testUpdateCMSActionsWithoutPermission(): void
    {
        $this->logOut();

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'fields1');
        $actions = $page->getCMSActions();

        $this->assertNull($actions->fieldByName('action_removeEmbargoAction'));
        $this->assertNull($actions->fieldByName('action_removeExpiryAction'));
    }

    public function testUpdateCMSActionsWithPermission(): void
    {
        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'fields1');
        $actions = $page->getCMSActions();

        $this->assertNotNull($actions->fieldByName('action_removeEmbargoAction'));
        $this->assertNotNull($actions->fieldByName('action_removeExpiryAction'));
    }
}
