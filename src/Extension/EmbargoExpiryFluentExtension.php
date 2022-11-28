<?php

namespace Terraformers\EmbargoExpiry\Extension;

use Exception;
use Opis\Closure\SerializableClosure;
use SilverStripe\ORM\DataExtension;
use TractorCow\Fluent\State\FluentState;

class EmbargoExpiryFluentExtension extends DataExtension
{
    /**
     * Fluent specific configuration
     */
    private static array $field_include = [
        'DesiredPublishDate',
        'DesiredUnPublishDate',
        'PublishOnDate',
        'UnPublishOnDate',
        'PublishJobID',
        'UnPublishJobID',
    ];

    /**
     * @throws Exception
     */
    public function setLocaleOptions(array &$options): void
    {
        if (!class_exists(FluentState::class)) {
            throw new Exception('Fluent extension not available. Please add it to your composer requirements');
        }

        $locale = FluentState::singleton()->getLocale();

        // There's nothing to be done here if there is no active Locale.
        if (!$locale) {
            return;
        }

        // Locale isn't currently used in our Job, but if you subclass, you might find it useful for something.
        $options['locale'] = $locale;

        // Before we fetch our DataObject in the Job, we must have the request Locale set to our FluentState. Otherwise
        // you'll end up pulling the *base* record (EG: from SiteTree instead of SiteTree_Localised), and you'll also
        // publish/un-publish the *base* record.
        $options['onBeforeGetObject'] = new SerializableClosure(static function () use ($locale): void {
            FluentState::singleton()->setLocale($locale);
        });
    }

    /**
     * @throws Exception
     */
    public function updatePublishTargetJobOptions(array &$options): void
    {
        $this->setLocaleOptions($options);
    }

    /**
     * @throws Exception
     */
    public function updateUnPublishTargetJobOptions(array &$options): void
    {
        $this->setLocaleOptions($options);
    }
}
