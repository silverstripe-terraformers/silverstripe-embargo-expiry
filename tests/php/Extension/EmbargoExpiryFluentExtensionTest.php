<?php

namespace Terraformers\EmbargoExpiry\Tests\Extension;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryFluentExtension;

/**
 * Class EmbargoExpiryFluentExtensionTest
 *
 * @package Terraformers\EmbargoExpiry\Tests\Extension
 */
class EmbargoExpiryFluentExtensionTest extends SapphireTest
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
            EmbargoExpiryFluentExtension::class,
        ],
    ];

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

        // Find and remove duplicate values. This should result in all $expected values being removed.
        $result = array_diff($expected, $actual);

        $this->assertCount(0, $result);
    }
}
