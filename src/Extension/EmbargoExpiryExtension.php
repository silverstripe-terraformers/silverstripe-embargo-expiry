<?php

namespace Terraformers\EmbargoExpiry\Extension;

use DateTimeImmutable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Job\PublishTargetJob;
use Terraformers\EmbargoExpiry\Job\UnPublishTargetJob;

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
 */
class EmbargoExpiryExtension extends DataExtension implements PermissionProvider
{

    public const PERMISSION_ADD = 'AddEmbargoExpiry';
    public const PERMISSION_REMOVE = 'RemoveEmbargoExpiry';

    public const JOB_TYPE_PUBLISH = 'publish';
    public const JOB_TYPE_UNPUBLISH = 'unpublish';

    private static array $db = [
        'DesiredPublishDate' => 'Datetime',
        'DesiredUnPublishDate' => 'Datetime',
        'PublishOnDate' => 'Datetime',
        'UnPublishOnDate' => 'Datetime',
    ];

    private static array $has_one = [
        'PublishJob' => QueuedJobDescriptor::class,
        'UnPublishJob' => QueuedJobDescriptor::class,
    ];

    /**
     * Property used to track when a DataObject is being accessed during a PublishTargetJob.
     *
     * @var bool
     */
    public $isPublishJobRunning = false; // phpcs:ignore

    /**
     * Property used to track when a DataObject is being accessed during a UnPublishTargetJob.
     *
     * @var bool
     */
    public $isUnPublishJobRunning = false; // phpcs:ignore

    public function updateCMSFields(FieldList $fields): void
    {
        Requirements::javascript('silverstripe-terraformers/embargo-expiry:client/dist/js/embargo-expiry.js');

        $fields->removeByName([
            'PublishJobID',
            'UnPublishJobID',
        ]);

        $this->addNoticeOrWarningFields($fields);
        $this->addDesiredDateFields($fields);
        $this->addScheduledDateFields($fields);
    }

    /**
     * If this Object requires sequential embargo/expiry dates, then let's make sure it has that.
     */
    public function validate(ValidationResult $validationResult): ValidationResult
    {
        // We don't require sequential dates.
        if (!$this->owner->config()->get('enforce_sequential_dates')) {
            return $validationResult;
        }

        // We only have 1 or 0 dates set, so we don't need to check for sequential.
        if (!$this->owner->DesiredPublishDate) {
            return $validationResult;
        }

        // If a DesiredUnPublish date is set, then use that, otherwise use UnPublishOnDate.
        $unPublishDate = $this->owner->DesiredUnPublishDate ?? $this->owner->UnPublishOnDate;

        // There is no DesiredUnPublish or UnPublishOnDate, so we don't need to check for sequential.
        if (!$unPublishDate) {
            return $validationResult;
        }

        $publishTime = new DateTimeImmutable($this->owner->DesiredPublishDate);
        $unpublishTime = new DateTimeImmutable($unPublishDate);

        if ($publishTime > $unpublishTime) {
            $validationResult->addFieldError(
                'DesiredPublishDate',
                _t(
                    self::class . 'FAILED_SEQUENTIAL_DATES',
                    'Your publish date cannot be set for after your un-publish date.'
                )
            );
        }

        return $validationResult;
    }

    public function updateCMSActions(FieldList $actions): void
    {
        if (!$this->owner->checkRemovePermission()) {
            return;
        }

        if ($this->getIsPublishScheduled()) {
            // Add action to remove embargo.
            $action = FormAction::create('removeEmbargoAction', _t(self::class . '.REMOVE_EMBARGO', 'Remove embargo'));
            $actions->insertBefore('ActionMenus', $action);
        }

        if ($this->getIsUnPublishScheduled()) {
            // Add action to remove embargo.
            $action = FormAction::create('removeExpiryAction', _t(self::class . '.REMOVE_EXPIRY', 'Remove expiry'));
            $actions->insertBefore('ActionMenus', $action);
        }
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

    public function onBeforeWrite(): void
    {
        // Only operate on staging content for this extension; otherwise, you need to publish the page to be able to set
        // a 'future' publish... While the same could be said for the unpublish, the 'publish' state is the one that
        // must be avoided so we allow setting the 'unpublish' date for as-yet-not-published content.
        if (Versioned::get_stage() === Versioned::LIVE) {
            return;
        }

        // Jobs can only be queued for records that already exist
        if (!$this->owner->isInDB()) {
            return;
        }

        // We allow other extensions/modules to prevent Jobs from being queued (only temporarily though, we hope). EG:
        // The Advanced Workflow module will prevent Jobs being queued during write() operations if a Workflow is set,
        // and will later allow them during an approval step
        $extensionResults = $this->owner->invokeWithExtensions('preventEmbargoExpiryQueueJobs');

        if (in_array(true, $extensionResults, true)) {
            return;
        }

        $this->owner->ensurePublishJob();
        $this->owner->ensureUnPublishJob();
    }

    /**
     * Add badges to the site tree view to show that a page has been scheduled for publishing or unpublishing
     */
    public function updateStatusFlags(array &$flags): void
    {
        $embargo = $this->owner->getIsPublishScheduled();
        $expiry = $this->owner->getIsUnPublishScheduled();

        if (!$embargo && !$expiry) {
            return;
        }

        // @todo need to move these into badges so that we don't have to remove these messages.
        unset($flags['addedtodraft'], $flags['modified']);

        if ($embargo && $expiry) {
            $flags['embargo_expiry'] = [
                'text' => _t(self::class . '.BADGE_PUBLISH_UNPUBLISH', 'Embargo+Expiry'),
                'title' => sprintf(
                    '%s: %s, %s: %s',
                    _t(self::class . '.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate,
                    _t(self::class . '.UNPUBLISH_ON', 'Scheduled un-publish date'),
                    $this->owner->UnPublishOnDate
                ),
            ];

            return;
        }

        if ($embargo) {
            $flags['embargo'] = [
                'text' => _t(self::class . '.BADGE_PUBLISH', 'Embargo'),
                'title' => sprintf(
                    '%s: %s',
                    _t(self::class . '.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate
                ),
            ];

            return;
        }

        $flags['expiry'] = [
            'text' => _t(self::class . '.BADGE_UNPUBLISH', 'Expiry'),
            'title' => sprintf(
                '%s: %s',
                _t(self::class . '.UNPUBLISH_ON', 'Scheduled un-publish date'),
                $this->owner->UnPublishOnDate
            ),
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
     * @param Member|int|null $member
     */
    public function checkAddPermission($member = null): bool
    {
        return Permission::checkMember($member, [self::PERMISSION_ADD]);
    }

    /**
     * @param Member|int|null $member
     */
    public function checkRemovePermission($member = null): bool
    {
        return Permission::checkMember($member, [self::PERMISSION_REMOVE]);
    }

    /**
     * When a Job is in the process of running, we want to unlink it from the DataObject before we save, but we don't
     * want to delete the Job itself (otherwise it won't be able to mark itself as complete).
     */
    public function unlinkPublishJobAndDate(): void
    {
        $this->owner->PublishOnDate = null;
        $this->owner->PublishJobID = 0;
    }

    /**
     * When a Job is in the process of running, we want to unlink it from the DataObject before we save, but we don't
     * want to delete the Job itself (otherwise it won't be able to mark itself as complete).
     */
    public function unlinkUnPublishJobAndDate(): void
    {
        $this->owner->UnPublishOnDate = null;
        $this->owner->UnPublishJobID = 0;
    }

    /**
     * Clears any existing publish job against this DataObject (unless they are in the process of being completed).
     */
    public function clearPublishJob(): void
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsPublishJobRunning()) {
            return;
        }

        $job = $this->owner->PublishJob();

        if ($job !== null && $job->exists()) {
            $job->delete();
        }

        $this->owner->PublishJobID = 0;
        $this->owner->PublishOnDate = null;
    }

    /**
     * Clears any existing unpublish job against this DataObject (unless they are in the process of being completed).
     */
    public function clearUnPublishJob(): void
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsUnPublishJobRunning()) {
            return;
        }

        $job = $this->owner->UnPublishJob();

        if ($job !== null && $job->exists()) {
            $job->delete();
        }

        $this->owner->UnPublishJobID = 0;
        $this->owner->UnPublishOnDate = null;
    }

    public function getDesiredPublishDateAsTimestamp(): int
    {
        /** @var DBDatetime $desiredPublishTimeField */
        $desiredPublishTimeField = $this->owner->dbObject('DesiredPublishDate');

        return $desiredPublishTimeField->getTimestamp();
    }

    public function getPublishOnDateAsTimestamp(): int
    {
        /** @var DBDatetime $desiredPublishTimeField */
        $desiredPublishTimeField = $this->owner->dbObject('PublishOnDate');

        return $desiredPublishTimeField->getTimestamp();
    }

    public function getDesiredUnPublishDateAsTimestamp(): int
    {
        /** @var DBDatetime $desiredPublishTimeField */
        $desiredPublishTimeField = $this->owner->dbObject('DesiredUnPublishDate');

        return $desiredPublishTimeField->getTimestamp();
    }

    public function getUnPublishOnDateAsTimestamp(): int
    {
        /** @var DBDatetime $desiredPublishTimeField */
        $desiredPublishTimeField = $this->owner->dbObject('UnPublishOnDate');

        return $desiredPublishTimeField->getTimestamp();
    }

    /**
     * Ensure the existence (or removal) of a publish job at the specified time.
     */
    public function ensurePublishJob(): void
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsPublishJobRunning()) {
            return;
        }

        // You don't have permission to do this.
        if (!$this->owner->checkAddPermission()) {
            return;
        }

        // New desired date (if set).
        $desiredPublishTime = $this->getDesiredPublishDateAsTimestamp();
        // Existing publish and un-publish date (if set).
        $publishTime = $this->getPublishOnDateAsTimestamp();

        // If there is no PublishOnDate set, make sure we remove any existing Jobs.
        if (!$publishTime) {
            $this->clearPublishJob();
        }

        // Check if this Object needs a Publish Job to be updated or created.
        if (!$this->objectRequiresPublishJob()) {
            return;
        }

        $this->createOrUpdatePublishJob($desiredPublishTime);
    }

    /**
     * Ensure the existence (or removal) of an unpublish job at the specified time.
     */
    public function ensureUnPublishJob(): void
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsUnPublishJobRunning()) {
            return;
        }

        // You don't have permission to do this.
        if (!$this->owner->checkAddPermission()) {
            return;
        }

        // New desired date (if set).
        $desiredUnPublishTime = $this->getDesiredUnPublishDateAsTimestamp();
        // Existing publish and un-publish date (if set).
        $unPublishTime = $this->getUnPublishOnDateAsTimestamp();

        // If there is no UnPublishOnDate set, make sure we remove any existing Jobs.
        if (!$unPublishTime) {
            $this->clearUnPublishJob();
        }

        if (!$this->objectRequiresUnPublishJob()) {
            return;
        }

        $this->createOrUpdateUnPublishJob($desiredUnPublishTime);
    }

    public function objectRequiresPublishJob(): bool
    {
        // New desired dates (if set).
        $desiredPublishTime = $this->getDesiredPublishDateAsTimestamp();
        $desiredUnPublishTime = $this->getDesiredUnPublishDateAsTimestamp();

        // Existing UnPublishOnDate (if set).
        $unPublishTime = $this->getUnPublishOnDateAsTimestamp();

        // If there is no desired publish time set, then there is nothing for us to change.
        if (!$desiredPublishTime) {
            return false;
        }

        // You might have some additional requirements for allowing a PublishJob to be created.
        /** @var array|bool[] $canHavePublishJob */
        $canHavePublishJob = $this->owner->invokeWithExtensions('publishJobCanBeQueued');

        // One or more extensions said that this Object cannot have a PublishJob.
        if (in_array(false, $canHavePublishJob)) {
            return false;
        }

        // You don't currently require sequential dates, so we're good to go!
        if (!$this->owner->config()->get('enforce_sequential_dates')) {
            return true;
        }

        return $this->datesAreSequential($desiredPublishTime, $desiredUnPublishTime, $unPublishTime);
    }

    public function objectRequiresUnPublishJob(): bool
    {
        // New desired date (if set).
        $desiredUnPublishTime = $this->getDesiredUnPublishDateAsTimestamp();

        // If there is no desired un-publish time set, then there is nothing for us to change.
        if (!$desiredUnPublishTime) {
            return false;
        }

        // You might have some additional requirements for allowing a UnPublishJob to be created.
        /** @var array|bool[] $canHaveUnPublishJob */
        $canHaveUnPublishJob = $this->owner->invokeWithExtensions('unPublishJobCanBeQueued');

        // One or more extensions said that this Object cannot have an UnPublishJob.
        if (in_array(false, $canHaveUnPublishJob)) {
            return false;
        }

        // We don't need to check for sequential dates for unPublishing. We do this for Publishing, and if it's
        // determined there that the dates are *not* sequential, then the Embargo date is the one that gets removed.
        return true;
    }

    public function datesAreSequential(int $desiredPublishTime, int $desiredUnPublishTime, int $unPublishTime): bool
    {
        // The desired publish date is set after the desired un-publish date, and you require sequential dates.
        if ($desiredUnPublishTime && $desiredPublishTime > $desiredUnPublishTime) {
            return false;
        }

        // The desired publish date is set after the active un-publish date, and you require sequential dates.
        if ($unPublishTime && $desiredPublishTime > $unPublishTime) {
            return false;
        }

        return true;
    }

    public function createOrUpdatePublishJob(int $desiredPublishTime): void
    {
        $now = DBDatetime::now()->getTimestamp();

        // Grab any existing PublishJob.
        $job = $this->owner->PublishJob();

        // If the existing PublishJob already represents the same date, then leave it be and exit early.
        if ($job !== null
            && $job->exists()
            && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() === $desiredPublishTime
            // This check is (mostly) to support migrations from Workflow to E&E. If we previously had a Workflow job,
            // we would want to clear and update this to an E&E job
            && $job->Implementation === PublishTargetJob::class
        ) {
            // Make sure our PublishOnDate is up to date.
            $this->updatePublishOnDate();

            return;
        }

        // Clear any exiting PublishJob.
        $this->owner->clearPublishJob();

        $options = [];

        // If you have some extra options that you would like to pass to your Job, add them here.
        $this->owner->invokeWithExtensions('updatePublishTargetJobOptions', $options);

        // Do you want to use a different queue? You can define it at a DataObject level using this config. Your options
        // are: 1 (immediate), 2 (queued), 3 (large). See QueuedJob constants. Default is 2 (queued).
        $queueID = (int) $this->owner->config()->get('publish_target_job_queue_id');

        // Make sure the value set is valid, if it isn't, set back to default.
        if ($queueID === 0) {
            $queueID = null;
        }

        // The value that will be used to update our PublishOnDate field.
        $updateTime = date('Y-m-d H:i:s', $desiredPublishTime);

        // Create a new job with the specified schedule. If publish time is in the past, run the Job immediately.
        $jobTime = $desiredPublishTime > $now
            ? date('Y-m-d H:i:s', $desiredPublishTime)
            : null;
        // @todo There is a PR on QueuedJobs to use injectable. Should update this once that goes through.
        $job = Injector::inst()->create(PublishTargetJob::class, $this->owner, $options);
        $this->owner->PublishJobID = QueuedJobService::singleton()
            ->queueJob($job, $jobTime, null, $queueID);

        // Make sure our PublishOnDate is up to date.
        $this->updatePublishOnDate($updateTime);
    }

    /**
     * @param int $desiredUnPublishTime
     */
    public function createOrUpdateUnPublishJob(int $desiredUnPublishTime): void
    {
        $now = DBDatetime::now()->getTimestamp();

        // Grab any existing UnPublishJob.
        $job = $this->owner->UnPublishJob();

        // If the existing UnPublishJob already represents the same date, then leave it be and exit early.
        if ($job !== null
            && $job->exists()
            && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() === $desiredUnPublishTime
            // This check is (mostly) to support migrations from Workflow to E&E. If we previously had a Workflow job,
            // we would want to clear and update this to an E&E job
            && $job->Implementation === UnPublishTargetJob::class
        ) {
            // Make sure our UnPublishOnDate is up to date.
            $this->updateUnPublishOnDate();

            return;
        }

        // Clear any exiting UnPublishJob.
        $this->owner->clearUnPublishJob();

        $options = [];

        $this->owner->invokeWithExtensions('updateUnPublishTargetJobOptions', $options);

        // Do you want to use a different queue? You can define it at a DataObject level using this config. Your options
        // are: 1 (immediate), 2 (queued), 3 (large). See QueuedJob constants. Default is 2 (queued).
        $queueID = (int) $this->owner->config()->get('un_publish_target_job_queue_id');

        // Make sure the value set is valid, if it isn't, set back to default.
        if ($queueID === 0) {
            $queueID = null;
        }

        // The value that will be used to update our UnPublishOnDate field.
        $updateTime = date('Y-m-d H:i:s', $desiredUnPublishTime);

        // Create a new job with the specified schedule. If unpublish time is in the past, run the Job immediately.
        $jobTime = $desiredUnPublishTime > $now
            ? date('Y-m-d H:i:s', $desiredUnPublishTime)
            : null;
        // @todo There is a PR on QueuedJobs to use injectable. Should update this once that goes through.
        $job = Injector::inst()->create(UnPublishTargetJob::class, $this->owner, $options);
        $this->owner->UnPublishJobID = QueuedJobService::singleton()
            ->queueJob($job, $jobTime, null, $queueID);

        // Make sure our UnPublishOnDate is up to date.
        $this->updateUnPublishOnDate($updateTime);
    }

    /**
     * Returns whether a publishing date has been set and is after the current date
     *
     * @return bool
     */
    public function getIsPublishScheduled(): bool
    {
        /** @var DBDatetime $publishTime */
        $publishTime = $this->owner->dbObject('PublishOnDate');

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
        /** @var DBDatetime $unPublishTime */
        $unPublishTime = $this->owner->dbObject('UnPublishOnDate');

        if ($unPublishTime->InFuture()) {
            return true;
        }

        return (int) $this->owner->UnPublishJobID !== 0;
    }

    /**
     * Default logic for whether or not the DataObject is editable. Feel free to override this method on your DataObject
     * if you need to change the logic.
     *
     * @return bool
     */
    public function isEditable(): ?bool
    {
        // Need to be able to save the DataObject if this is being called during PublishTargetJob.
        if ($this->owner->getIsPublishJobRunning()) {
            return true;
        }

        // Need to be able to save the DataObject if this is being called during UnPublishTargetJob.
        if ($this->owner->getIsUnPublishJobRunning()) {
            return true;
        }

        // If the owner object allows embargoed editing, then return null so we can fall back to SiteTree behaviours
        // (SiteTree and inherited permissions)
        if ($this->owner->config()->get('allow_embargoed_editing')) {
            return null;
        }

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
     * @param FieldList $fields
     */
    public function addDesiredDateFields(FieldList $fields): void
    {
        $fields->findOrMakeTab(
            'Root.PublishingSchedule',
            _t(self::class . '.TAB_TITLE', 'Publishing Schedule')
        );

        $fields->addFieldsToTab(
            'Root.PublishingSchedule',
            [
                HeaderField::create(
                    'PublishDateHeader',
                    _t(self::class . '.PUBLISH_DATE_HEADER', 'Expiry and Embargo'),
                    3
                ),
                $publishDateField = DatetimeField::create(
                    'DesiredPublishDate',
                    _t(self::class . '.DESIRED_PUBLISH_ON', 'Desired publish date')
                ),
                $unPublishDateField = DatetimeField::create(
                    'DesiredUnPublishDate',
                    _t(self::class . '.DESIRED_UNPUBLISH_ON', 'Desired un-publish date')
                ),
            ]
        );

        $message = $this->getEmbargoExpiryFieldNoticeMessage();

        if ($message !== null) {
            $fields->addFieldToTab(
                'Root.PublishingSchedule',
                LiteralField::create(
                    'PublishDateIntro',
                    sprintf('<h4 class="notice">%s</h4>', $message)
                ),
                'DesiredPublishDate'
            );
        }

        // You have permission to edit this record. Exit early.
        if ($this->owner->checkAddPermission()) {
            return;
        }

        // You do not have permission to edit.
        $publishDateField->setReadonly(true);
        $unPublishDateField->setReadonly(true);
    }

    /**
     * @param FieldList $fields
     */
    public function addScheduledDateFields(FieldList $fields): void
    {
        if (!$this->getIsPublishScheduled() && !$this->getIsUnPublishScheduled()) {
            return;
        }

        $newFields = [];

        $message = _t(self::class . '.EXISTING_PUBLISH_MESSAGE', 'Existing embargo schedule.');

        $newFields[] = LiteralField::create(
            'ExistingPublishScheduleInfo',
            sprintf('<h4 class="notice">%s</h4>', $message)
        );

        if ($this->getIsPublishScheduled()) {
            $newFields[] = ReadonlyField::create(
                'PublishOnDate',
                _t(self::class . '.PUBLISH_ON', 'Scheduled publish date')
            );
        }

        if ($this->getIsUnPublishScheduled()) {
            $newFields[] = ReadonlyField::create(
                'UnPublishOnDate',
                _t(self::class . '.UNPUBLISH_ON', 'Scheduled un-publish date')
            );
        }

        $fields->addFieldsToTab(
            'Root.PublishingSchedule',
            $newFields
        );
    }

    public function getEmbargoExpiryFieldNoticeMessage(): ?string
    {
        // true and null are both valid isEditable() values for indicating that a user has permission to edit (from the
        // point of view of this extension).
        if ($this->isEditable() === false) {
            return null;
        }

        if ($this->checkAddPermission()) {
            return _t(
                self::class . '.EDITABLE_NOTICE',
                'Enter a date and/or time to specify embargo and expiry dates.<br />
                If an embargo is already set, adding a new one prior to that date\'s passing will overwrite it.'
            );
        }

        return _t(
            self::class . '.NOTEDITABLE_NOTICE',
            'Please contact an administrator if you wish to add an embargo or expiry date to this record.'
        );
    }

    /**
     * @param FieldList $fields
     */
    public function addNoticeOrWarningFields(FieldList $fields): void
    {
        $conditions = $this->getEmbargoExpiryNoticeFieldConditions();

        if (count($conditions) === 0) {
            return;
        }

        $message = $this->getEmbargoExpiryNoticeMessage($conditions);
        $type = 'notice';

        foreach ($conditions as $name => $data) {
            $warning = '';

            if ($data['warning']) {
                $type = 'error';

                $warning = sprintf(
                    '<strong>%s</strong>',
                    _t(self::class . '.PAST_DATE_WARNING', ' (this date is in the past, is it still valid?)')
                );
            }

            $message .= sprintf(
                '<br /><strong>%s</strong>: %s%s',
                ucfirst($name),
                $data['date'],
                $warning
            );
        }

        $fields->unshift(
            LiteralField::create(
                'EmbargoExpiryNotice',
                sprintf('<p class="message %s">%s</p>', $type, $message)
            )
        );
    }

    /**
     * @return array
     */
    public function getEmbargoExpiryNoticeFieldConditions(): array
    {
        $conditions = [];
        $now = DBDatetime::now()->getTimestamp();

        if ($this->getPublishOnDateAsTimestamp()) {
            $time = new DateTimeImmutable($this->owner->PublishOnDate);

            $conditions['embargo'] = [
                'date' => $time->format('Y-m-d H:i T'),
                'warning' => ($time->getTimestamp() < $now),
                'name' => _t(self::class . '.EMBARGO_NAME', 'embargo'),
            ];
        }

        if ($this->getUnPublishOnDateAsTimestamp()) {
            $time = new DateTimeImmutable($this->owner->UnPublishOnDate);

            $conditions['expiry'] = [
                'date' => $time->format('Y-m-d H:i T'),
                'warning' => ($time->getTimestamp() < $now),
                'name' => _t(self::class . '.EXPIRY_NAME', 'expiry'),
            ];
        }

        return $conditions;
    }

    /**
     * @param array $conditions
     * @return string
     */
    public function getEmbargoExpiryNoticeMessage(array $conditions): ?string
    {
        if (count($conditions) === 0) {
            return null;
        }

        // true and null are both valid isEditable() values for indicating that a user has permission to edit (from the
        // point of view of this extension).
        if ($this->isEditable() !== false) {
            return sprintf(
                _t(
                    self::class . '.EMBARGO_EDITING_NOTICE',
                    'You are currently editing a record that has an %s date set.'
                ),
                implode(' and ', array_column($conditions, 'name'))
            );
        }

        if (!$this->checkRemovePermission()) {
            return sprintf(
                _t(
                    self::class . '.EMBARGO_NONREMOVABLE_NOTICE',
                    'This record has an %s date set, and cannot currently be edited. An administrator will need
                    to remove the scheduled embargo date before you are able to edit this record.'
                ),
                implode(' and ', array_column($conditions, 'name'))
            );
        }

        if (array_key_exists('embargo', $conditions)) {
            return sprintf(
                _t(
                    self::class . '.EMBARGO_NONREMOVABLE_NOTICE',
                    'This record has an %s date set, and cannot currently be edited. You will need to remove the
                    scheduled embargo date in order to edit this record.'
                ),
                implode(' and ', array_column($conditions, 'name'))
            );
        }

        return sprintf(
            _t(
                self::class . '.EMBARGO_SET_NOTICE',
                'This record has an %s date set.'
            ),
            implode(' and ', array_column($conditions, 'name'))
        );
    }

    /**
     * Method to decide whether or not this Object is being accessed while a PublishTargetJob is running.
     *
     * @return bool
     */
    public function getIsPublishJobRunning(): bool
    {
        return $this->isPublishJobRunning;
    }

    /**
     * @param bool $bool
     */
    public function setIsPublishJobRunning(bool $bool): void
    {
        $this->isPublishJobRunning = $bool;
    }

    /**
     * Method to decide whether or not this Object is being accessed while a PublishTargetJob is running.
     *
     * @return bool
     */
    public function getIsUnPublishJobRunning(): bool
    {
        return $this->isUnPublishJobRunning;
    }

    /**
     * @param $bool
     */
    public function setIsUnPublishJobRunning(bool $bool): void
    {
        $this->isUnPublishJobRunning = $bool;
    }

    /**
     * @param string|null $desiredPublishTime
     */
    protected function updatePublishOnDate(?string $desiredPublishTime = null): void
    {
        if ($desiredPublishTime === null) {
            $desiredPublishTime = $this->owner->DesiredPublishDate;
        }

        // Make sure our PublishOnDate field is set correctly.
        $this->owner->PublishOnDate = $desiredPublishTime;
        // Remove the DesiredPublishDate.
        $this->owner->DesiredPublishDate = null;
    }

    /**
     * @param string|null $desiredUnPublishTime
     */
    protected function updateUnPublishOnDate(?string $desiredUnPublishTime = null): void
    {
        if ($desiredUnPublishTime === null) {
            $desiredUnPublishTime = $this->owner->DesiredUnPublishDate;
        }

        // Make sure our UnPublishOnDate field is set correctly.
        $this->owner->UnPublishOnDate = $desiredUnPublishTime;
        // Remove the DesiredUnPublishDate.
        $this->owner->DesiredUnPublishDate = null;
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
