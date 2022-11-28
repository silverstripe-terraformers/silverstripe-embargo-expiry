<?php

namespace Terraformers\EmbargoExpiry\Tests\Extension;

use Exception;
use SilverStripe\CMS\Controllers\CMSMain;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryCMSMainExtension;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

class EmbargoExpiryCMSMainExtensionTest extends FunctionalTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'EmbargoExpiryCMSMainExtensionTest.yml'; // phpcs:ignore

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     * @var array
     */
    protected static $required_extensions = [
        SiteTree::class => [
            EmbargoExpiryExtension::class,
        ],
        CMSMain::class => [
            EmbargoExpiryCMSMainExtension::class,
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
        $page = $this->objFromFixture(SiteTree::class, 'home');
        $id = $page->ID;

        // Check that we're set up correctly.
        $this->assertTrue($page->getIsPublishScheduled());
        $this->assertTrue($page->getIsUnPublishScheduled());

        // Post a request to remove the embargo date.
        $this->post(
            sprintf('admin/pages/edit/EditForm/%s', $id),
            [
                'ClassName' => SiteTree::class,
                'ID' => $id,
                'action_removeEmbargoAction' => 1,
                'ajax' => 1,
            ]
        );

        // Refetch object from DB.
        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = SiteTree::get()->byID($id);

        $this->assertFalse($page->getIsPublishScheduled());
        $this->assertTrue($page->getIsUnPublishScheduled());
    }

    public function testRemoveExpiryAction(): void
    {
        $this->logInWithPermission('ADMIN');

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'contact');
        $id = $page->ID;

        // Check that we're set up correctly.
        $this->assertTrue($page->getIsPublishScheduled());
        $this->assertTrue($page->getIsUnPublishScheduled());

        // Post a request to remove the embargo date.
        $this->post(
            sprintf('admin/pages/edit/EditForm/%s', $id),
            [
                'ClassName' => SiteTree::class,
                'ID' => $id,
                'action_removeExpiryAction' => 1,
                'ajax' => 1,
            ]
        );

        // Refetch object from DB.
        $page = SiteTree::get()->byID($id);

        $this->assertTrue($page->getIsPublishScheduled());
        $this->assertFalse($page->getIsUnPublishScheduled());
    }

    public function testRemoveActionFailsRecordDoesNotExist(): void
    {
        $this->logInWithPermission('ADMIN');

        // Post a request to remove the embargo date.
        $response = $this->post(
            'admin/pages/edit/EditForm/99',
            [
                'ClassName' => SiteTree::class,
                'ID' => 99,
                'action_removeEmbargoAction' => 1,
                'ajax' => 1,
            ]
        );

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertEquals('Bad record ID #99', $response->getBody());
    }

    public function testRemoveActionFailsPermissionDenied(): void
    {
        $this->expectException(AccessDeniedException::class);

        /** @var Member $member */
        $member = $this->objFromFixture(Member::class, 'user1');
        $this->logInAs($member);

        /** @var SiteTree|EmbargoExpiryExtension $page */
        $page = $this->objFromFixture(SiteTree::class, 'home');
        $id = $page->ID;

        // Post a request to remove the embargo date.
        $this->post(
            sprintf('admin/pages/edit/EditForm/%s', $id),
            [
                'ClassName' => SiteTree::class,
                'ID' => $id,
                'action_removeExpiryAction' => 1,
                'ajax' => 1,
            ]
        );
    }
}
