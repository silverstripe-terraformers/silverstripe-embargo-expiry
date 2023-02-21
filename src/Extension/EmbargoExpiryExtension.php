<?php

namespace Terraformers\EmbargoExpiry\Extension;

use DateTimeImmutable;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\PolymorphicHasManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\PermissionProvider;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Terraformers\EmbargoExpiry\Form\EmbargoExpiryField;
use Terraformers\EmbargoExpiry\Job\State\ActionProcessingState;
use Terraformers\EmbargoExpiry\Model\ScheduledAction;

/**
 * @property DataObject|$this $owner
 * @property DBDatetime $DesiredPublishDate
 * @property DBDatetime $DesiredUnPublishDate
 * @property DBDatetime $PublishOnDate
 * @property DBDatetime $UnPublishOnDate
 * @property int $PublishJobID
 * @property int $UnPublishJobID
 * @method QueuedJobDescriptor PublishJob()
 * @method QueuedJobDescriptor UnPublishJob()
 * @method PolymorphicHasManyList ScheduledActions()
 */
class EmbargoExpiryExtension extends DataExtension implements PermissionProvider
{
    public const PERMISSION_ADD = 'AddEmbargoExpiry';
    public const PERMISSION_REMOVE = 'RemoveEmbargoExpiry';

    public const JOB_TYPE_PUBLISH = 'publish';
    public const JOB_TYPE_UNPUBLISH = 'unpublish';

    private static array $has_one = [
        'PublishJob' => QueuedJobDescriptor::class,
        'UnPublishJob' => QueuedJobDescriptor::class,
    ];

    private static array $has_many = [
        'ScheduledActions' => ScheduledAction::class,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $this->addEmbargoExpiryNotice($fields);

        $fields->findOrMakeTab(
            'Root.PublishingSchedule',
            _t(self::class . '.TAB_TITLE', 'Publishing Schedule')
        );

        $fields->addFieldToTab(
            'Root.PublishingSchedule',
            HeaderField::create(
                'PublishDateHeader',
                _t(self::class . '.PUBLISH_DATE_HEADER', 'Embargo & Expiry'),
                2
            )
        );

        $this->addPublishScheduleSummaryFields($fields);

        $fields->addFieldToTab(
            'Root.PublishingSchedule',
            EmbargoExpiryField::create('PublishingScheduleFields')
        );
    }

    /**
     * @codeCoverageIgnore
     */
    public function providePermissions(): array
    {
        return [
            self::PERMISSION_ADD => [
                'name' => _t(self::class . '.ADD_EMBARGO_EXPIRY', 'Add Embargo & Expiry'),
                'category' => _t('SilverStripe\\Security\\Permission.CONTENT_CATEGORY', 'Content permissions'),
                'help' => _t(
                    self::class . '.ADD_EMBARGO_EXPIRY_HELP',
                    'Ability to add Embargo & Expiry dates to a record.'
                ),
                'sort' => 101,
            ],
            self::PERMISSION_REMOVE => [
                'name' => _t(self::class . '.REMOVE_EMBARGO_EXPIRY', 'Remove Embargo & Expiry'),
                'category' => _t('SilverStripe\\Security\\Permission.CONTENT_CATEGORY', 'Content permissions'),
                'help' => _t(
                    self::class . '.REMOVE_EMBARGO_EXPIRY_HELP',
                    'Ability to remove Embargo & Expiry dates from a record.'
                ),
                'sort' => 102,
            ],
        ];
    }

    /**
     * Add edit check for when publishing has been scheduled and if any workflow definitions want the item to be
     * disabled.
     *
     * @param Member|int|null $member
     */
    public function canEdit($member = null): ?bool
    {
        return $this->owner->isEditable();
    }

    /**
     * Add edit check for when publishing has been scheduled and if any workflow definitions want the item to be
     * disabled.
     *
     * @param Member|int|null $member
     */
    public function canPublish($member = null): ?bool
    {
        return $this->owner->isEditable();
    }

    /**
     * Default logic for whether or not the DataObject is editable. Feel free to override this method on your DataObject
     * if you need to change the logic.
     *
     * @return bool
     */
    public function isEditable(): ?bool
    {
        // Need to be able to save the DataObject if this is being called during one of our Scheduled Action Jobs
        if (ActionProcessingState::singleton()->getActionIsProcessing()) {
            return true;
        }

        // If the owner object allows embargoed editing, then return null so we can fall back to SiteTree behaviours
        // (SiteTree and inherited permissions)
        if ($this->owner->config()->get('allow_embargoed_editing')) {
            return null;
        }

        // An embargo is set, and we've opted to disable editing when that is the case
        if ($this->owner->getIsPublishScheduled()) {
            return false;
        }

        $embargoRecordIsEditable = $this->owner->invokeWithExtensions('embargoRecordIsEditable');

        if (in_array(false, $embargoRecordIsEditable)) {
            return false;
        }

        // Everything looks ok, so let's fall back to SiteTree behaviours (SiteTree and inherited permissions).
        return null;
    }

    /**
     * Returns whether a publishing date has been set and is after the current date
     *
     * @return bool
     */
    public function getIsPublishScheduled(): bool
    {
        $embargoAction = $this->getNextScheduledAction(ScheduledAction::TYPE_EMBARGO);

        if (!$embargoAction?->exists()) {
            return false;
        }

        /** @var DBDatetime $publishTime */
        $publishTime = $embargoAction->dbObject('Datetime');

        if ($publishTime->InFuture()) {
            return true;
        }

        if ((int) $this->owner->PublishJobID !== 0) {
            return true;
        }

        return false;
    }

    /**
     * Returns whether an unpublishing date has been set and is after the current date
     *
     * @return bool
     */
    public function getIsUnPublishScheduled(): bool
    {
        $expiryAction = $this->getNextScheduledAction(ScheduledAction::TYPE_EXPIRY);

        if (!$expiryAction?->exists()) {
            return false;
        }

        /** @var DBDatetime $publishTime */
        $unPublishTime = $expiryAction->dbObject('Datetime');

        if ($unPublishTime->InFuture()) {
            return true;
        }

        return (int) $this->owner->UnPublishJobID !== 0;
    }

    private function getNextScheduledAction(string $type): ?ScheduledAction
    {
        return $this->owner->ScheduledActions()
            ->filter('Type', $type)
            ->first();
    }

    /**
     * Add a notice to the top of our FieldList to indicate to authors that this record has an active Embargo and/or
     * Expiry date
     */
    private function addEmbargoExpiryNotice(FieldList $fields): void
    {
        if (!$this->ScheduledActions()->count()) {
            return;
        }

        // true and null are both valid canEdit() values for indicating that a user has permission to edit
        if ($this->owner->canEdit() !== false) {
            // This record is editable
            $message = _t(
                self::class . '.EMBARGO_EDITING_NOTICE',
                'You are currently editing a record that has Embargo/Expiry dates set.'
            );
        } else if (!$this->owner->config()->get('allow_embargoed_editing')) {
            // This record is not editable, and the author doesn't have the ability to change Scheduled Actions
            $message = _t(
                self::class . '.EMBARGO_NONREMOVABLE_NOTICE',
                'This record has a Embargo/Expiry dates set, and cannot currently be edited. An administrator will'
                . ' need to remove the scheduled embargo date before you are able to edit this record.'
            );
        } else {
            // This record is not editable, but the author does have the ability to change Scheduled Actions
            $message = _t(
                self::class . '.EMBARGO_SET_NOTICE',
                'This record has a Embargo/Expiry dates set, and cannot currently be edited. You will need to'
                . ' remove the scheduled embargo date before you are able to edit this record.'
            );
        }

        $fields->unshift(
            LiteralField::create(
                'EmbargoExpiryNotice',
                sprintf('<p class="message notice">%s</p>', $message)
            )
        );
    }

    /**
     * Outputs messages with any/all active Embargo/Expiry dates, and adds warnings for any dates that might be in
     * the past
     */
    private function addPublishScheduleSummaryFields(FieldList $fields): void
    {
        $now = DBDatetime::now()->getTimestamp();

        $embargo = $this->getNextScheduledAction(ScheduledAction::TYPE_EMBARGO);
        $expiry = $this->getNextScheduledAction(ScheduledAction::TYPE_EXPIRY);

        $message = '';
        $type = 'notice';

        if ($embargo?->exists()) {
            $time = new DateTimeImmutable($embargo->Datetime);
            $warning = null;

            if ($time->getTimestamp() < $now) {
                $type = 'error';
                $warning = sprintf(
                    '<strong>%s</strong>',
                    _t(self::class . '.PAST_DATE_WARNING', ' (this date is in the past, is it still valid?)')
                );
            }

            $message .= sprintf(
                '<strong>%s</strong>: %s%s',
                ucfirst(_t(self::class . '.EMBARGO_NAME', 'embargo')),
                $time->format('Y-m-d H:i T'),
                $warning
            );
        }

        if ($expiry?->exists()) {
            $time = new DateTimeImmutable($expiry->Datetime);
            $warning = null;

            if ($time->getTimestamp() < $now) {
                $type = 'error';
                $warning = sprintf(
                    '<strong>%s</strong>',
                    _t(self::class . '.PAST_DATE_WARNING', ' (this date is in the past, is it still valid?)')
                );
            }

            $message .= sprintf(
                '<br /><strong>%s</strong>: %s%s',
                ucfirst(_t(self::class . '.EXPIRY_NAME', 'expiry')),
                $time->format('Y-m-d H:i T'),
                $warning
            );
        }

        if (!$message) {
            return;
        }

        $fields->addFieldToTab(
            'Root.PublishingSchedule',
            LiteralField::create(
                'EmbargoExpiryNotice',
                sprintf('<p class="message %s">%s</p>', $type, $message)
            )
        );
    }

    /**
     * A method that can be implemented on your DataObject. This method is run with invokeWithExtensions prior to
     * calling publishRecursive() in the PublishTargetJob.
     *
     * The purpose of this method is to allow you a chance to modify your DataObject in any way you may need to prior
     * to it being published. You have access to any $options that you set as part of the PublishTargetJob.
     *
     * @param array|null $options
     */
    public function prePublishTargetJob(?array $options): void
    {
        // You do not need to call parent::() when implementing this method, it is simply here to provide code hinting
    }

    /**
     * A method that can be implemented on your DataObject. This method is run with invokeWithExtensions prior to
     * calling doUnpublish() in the PublishTargetJob.
     *
     * The purpose of this method is to allow you a chance to modify your DataObject in any way you may need to prior
     * to it being unpublished. You have access to any $options that you set as part of the PublishTargetJob.
     *
     * @param array|null $options
     */
    public function preUnPublishTargetJob(?array $options): void
    {
        // You do not need to call parent::() when implementing this method, it is simply here to provide code hinting
    }

    /**
     * A method that can be implemented on your DataObject. This method is run with invokeWithExtensions prior to
     * creation of the PublishTargetJob.
     *
     * @param array|null $options
     */
    public function updatePublishTargetJobOptions(?array &$options): void
    {
        // You do not need to call parent::() when implementing this method, it is simply here to provide code hinting
    }

    /**
     * A method that can be implemented on your DataObject. This method is run with invokeWithExtensions prior to
     * creation of the PublishTargetJob.
     *
     * @param array|null $options
     */
    public function updateUnPublishTargetJobOptions(?array &$options): void
    {
        // You do not need to call parent::() when implementing this method, it is simply here to provide code hinting
    }
}
