<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
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
 * Class WorkflowEmbargoExpiryExtension
 *
 * @package Terraformers\EmbargoExpiry\Extension
 * @property $this|DataObject $owner
 * @property DBDatetime $DesiredPublishDate
 * @property DBDatetime $DesiredUnPublishDate
 * @property DBDatetime $PublishOnDate
 * @property DBDatetime $UnPublishOnDate
 * @property bool $AllowEmbargoedEditing
 * @property int $PublishJobID
 * @property int $UnPublishJobID
 * @method QueuedJobDescriptor PublishJob()
 * @method QueuedJobDescriptor UnPublishJob()
 */
class EmbargoExpiryExtension extends DataExtension implements PermissionProvider
{
    const PERMISSION_ADD = 'CMS_ACCESS_AddEmbargoExpiry';
    const PERMISSION_REMOVE = 'CMS_ACCESS_RemoveEmbargoExpiry';

    const JOB_TYPE_PUBLISH = 'publish';
    const JOB_TYPE_UNPUBLISH = 'unpublish';

    /**
     * @var array
     */
    private static $db = [
        'DesiredPublishDate' => 'Datetime',
        'DesiredUnPublishDate' => 'Datetime',
        'PublishOnDate' => 'Datetime',
        'UnPublishOnDate' => 'Datetime',
    ];

    /**
     * @var array
     */
    private static $has_one = [
        'PublishJob' => QueuedJobDescriptor::class,
        'UnPublishJob' => QueuedJobDescriptor::class,
    ];

    /**
     * Property used to track when a DataObject is being accessed during a PublishTargetJob.
     *
     * @var bool
     */
    public $isPublishJobRunning = false;

    /**
     * Property used to track when a DataObject is being accessed during a UnPublishTargetJob.
     *
     * @var bool
     */
    public $isUnPublishJobRunning = false;

    /**
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        Requirements::javascript("silverstripe-terraformers/embargo-expiry:client/dist/js/embargo-expiry.js");

        $fields->removeByName([
            'PublishJobID',
            'UnPublishJobID',
        ]);

        $this->addEmbargoExpiryNoticeFields($fields);
        $this->addPublishingScheduleFields($fields);
    }

    /**
     * @param FieldList $actions
     */
    public function updateCMSActions(FieldList $actions)
    {
        if (!$this->owner->checkRemovePermission()) {
            return;
        }

        if ($this->getIsPublishScheduled()) {
            // Add action to remove embargo.
            $action = new FormAction('removeEmbargoAction', _t(__CLASS__ . '.REMOVE_EMBARGO', 'Remove embargo'));
            $actions->insertBefore('ActionMenus', $action);
        }

        if ($this->getIsUnPublishScheduled()) {
            // Add action to remove embargo.
            $action = new FormAction('removeExpiryAction', _t(__CLASS__ . '.REMOVE_EXPIRY', 'Remove expiry'));
            $actions->insertBefore('ActionMenus', $action);
        }
    }

    /**
     * @return array
     */
    public function providePermissions()
    {
        return [
            self::PERMISSION_ADD => [
                'name' => _t(
                    __CLASS__ . '.ADD_EMBARGO_EXPIRY',
                    'Add Embargo & Expiry'
                ),
                'category' => _t('SilverStripe\\Security\\Permission.CONTENT_CATEGORY', 'Content permissions'),
                'help' => _t(
                    __CLASS__ . '.ADD_EMBARGO_EXPIRY_HELP',
                    'Ability to add Embargo & Expiry dates to a record.'
                ),
                'sort' => 101,
            ],
            self::PERMISSION_REMOVE => [
                'name' => _t(
                    __CLASS__ . '.REMOVE_EMBARGO_EXPIRY',
                    'Remove Embargo & Expiry'
                ),
                'category' => _t('SilverStripe\\Security\\Permission.CONTENT_CATEGORY', 'Content permissions'),
                'help' => _t(
                    __CLASS__ . '.REMOVE_EMBARGO_EXPIRY_HELP',
                    'Ability to remove Embargo & Expiry dates from a record.'
                ),
                'sort' => 102,
            ],
        ];
    }

    public function onBeforeWrite()
    {
        // Only operate on staging content for this extension; otherwise, you need to publish the page to be able to set
        // a 'future' publish... While the same could be said for the unpublish, the 'publish' state is the one that
        // must be avoided so we allow setting the 'unpublish' date for as-yet-not-published content.
        if (Versioned::get_stage() === Versioned::LIVE) {
            return;
        }

        // Jobs can only be queued for records that already exist
        if (!$this->owner->ID) {
            return;
        }

        $this->owner->ensurePublishJob();
        $this->owner->ensureUnPublishJob();
    }

    /**
     * Add badges to the site tree view to show that a page has been scheduled for publishing or unpublishing
     *
     * @param $flags
     */
    public function updateStatusFlags(&$flags)
    {
        $embargo = $this->owner->getIsPublishScheduled();
        $expiry = $this->owner->getIsUnPublishScheduled();

        if ($embargo || $expiry) {
            unset($flags['addedtodraft'], $flags['modified']);
        }

        if ($embargo && $expiry) {
            $flags['embargo_expiry'] = [
                'text' => _t(__CLASS__ . '.BADGE_PUBLISH_UNPUBLISH', 'Embargo+Expiry'),
                'title' => sprintf(
                    '%s: %s, %s: %s',
                    _t(__CLASS__ . '.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate,
                    _t(__CLASS__ . '.UNPUBLISH_ON', 'Scheduled un-publish date'),
                    $this->owner->UnPublishOnDate
                ),
            ];
        } elseif ($embargo) {
            $flags['embargo'] = [
                'text' => _t(__CLASS__ . '.BADGE_PUBLISH', 'Embargo'),
                'title' => sprintf(
                    '%s: %s',
                    _t(__CLASS__ . '.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate
                ),
            ];
        } elseif ($expiry) {
            $flags['expiry'] = [
                'text' => _t(__CLASS__ . '.BADGE_UNPUBLISH', 'Expiry'),
                'title' => sprintf(
                    '%s: %s',
                    _t(__CLASS__ . '.UNPUBLISH_ON', 'Scheduled un-publish date'),
                    $this->owner->UnPublishOnDate
                ),
            ];
        }
    }

    /**
     * Add edit check for when publishing has been scheduled and if any workflow definitions want the item to be
     * disabled.
     *
     * @param Member $member
     * @return bool|null
     */
    public function canEdit($member = null)
    {
        return $this->owner->isEditable();
    }

    /**
     * Add edit check for when publishing has been scheduled and if any workflow definitions want the item to be
     * disabled.
     *
     * @param Member $member
     * @return bool|null
     */
    public function canPublish($member = null)
    {
        return $this->owner->isEditable();
    }

    /**
     * @param null $member
     * @return bool
     */
    public function checkAddPermission($member = null)
    {
        return Permission::checkMember($member, [self::PERMISSION_ADD]);
    }

    /**
     * @param null $member
     * @return bool
     */
    public function checkRemovePermission($member = null)
    {
        return Permission::checkMember($member, [self::PERMISSION_REMOVE]);
    }

    /**
     * When a Job is in the process of running, we want to unlink it from the DataObject before we save, but we don't
     * want to delete the Job itself (otherwise it won't be able to mark itself as complete).
     */
    public function unlinkPublishJobAndDate()
    {
        $this->owner->PublishOnDate = null;
        $this->owner->PublishJobID = 0;
    }

    /**
     * When a Job is in the process of running, we want to unlink it from the DataObject before we save, but we don't
     * want to delete the Job itself (otherwise it won't be able to mark itself as complete).
     */
    public function unlinkUnPublishJobAndDate()
    {
        $this->owner->UnPublishOnDate = null;
        $this->owner->UnPublishJobID = 0;
    }

    /**
     * Clears any existing publish job against this DataObject (unless they are in the process of being completed).
     */
    public function clearPublishJob()
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsPublishJobRunning()) {
            return;
        }

        $job = $this->owner->PublishJob();

        if ($job && $job->exists()) {
            $job->delete();
        }

        $this->owner->PublishJobID = 0;
        $this->owner->PublishOnDate = null;
    }

    /**
     * Clears any existing unpublish job against this DataObject (unless they are in the process of being completed).
     */
    public function clearUnPublishJob()
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsUnPublishJobRunning()) {
            return;
        }

        $job = $this->owner->UnPublishJob();

        if ($job && $job->exists()) {
            $job->delete();
        }

        $this->owner->UnPublishJobID = 0;
        $this->owner->UnPublishOnDate = null;
    }

    /**
     * Ensure the existence of a publish job at the specified time.
     */
    public function ensurePublishJob()
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsPublishJobRunning()) {
            return;
        }

        if (!$this->owner->checkAddPermission()) {
            return;
        }

        $now = DBDatetime::now()->getTimestamp();
        // New desired date (if set).
        $desiredPublishTime = $this->owner->dbObject('DesiredPublishDate')->getTimestamp();
        $desiredUnPublishTime = $this->owner->dbObject('DesiredUnPublishDate')->getTimestamp();
        // Existing publish and un-publish date (if set).
        $publishTime = $this->owner->dbObject('PublishOnDate')->getTimestamp();
        $unPublishTime = $this->owner->dbObject('UnPublishOnDate')->getTimestamp();

        // If there is no PublishOnDate set, make sure we remove any existing Jobs.
        if (!$publishTime) {
            $this->clearPublishJob();
        }

        // If there is no desired publish time set, then there is nothing for us to change.
        if (!$desiredPublishTime) {
            return;
        }

        // You might have some additional requirements for allowing a PublishJob to be created.
        /** @var array|bool[] $canHavePublishJob */
        $canHavePublishJob = $this->owner->invokeWithExtensions('publishJobCanBeQueued');
        // One or more extensions said that this Object cannot have a PublishJob.
        if (in_array(false, $canHavePublishJob)) {
            return;
        }

        // The desired publish date is set after the desired un-publish date, and you require sequential dates.
        if ($this->owner->config()->get('enforce_sequential_dates')
            && $desiredUnPublishTime
            && $desiredPublishTime > $desiredUnPublishTime
        ) {
            return;
        }

        // The desired publish date is set after the active un-publish date, and you require sequential dates.
        if ($this->owner->config()->get('enforce_sequential_dates')
            && $unPublishTime
            && $desiredPublishTime > $unPublishTime
        ) {
            return;
        }

        // Check if there is a prior Publish Job.
        if ((int) $this->owner->PublishJobID !== 0) {
            $job = $this->owner->PublishJob();

            // If it's the same Publish Job, leave it be.
            if ($job
                && $job->exists()
                && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() === $desiredPublishTime
            ) {
                // Make sure our PublishOnDate is up to date.
                $this->updatePublishOnDate();

                return;
            }

            // Remove the old Publish Job.
            $this->owner->clearPublishJob();
        }

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

        // Create a new job with the specified schedule. If publish time is in the past, run the Job immediately.
        // @todo There is a PR on QueuedJobs to use injectable. Should update this once that goes through.
        $jobTime = $desiredPublishTime > $now ? date('Y-m-d H:i:s', $desiredPublishTime) : null;
        $job = new PublishTargetJob($this->owner, $options);
        $this->owner->PublishJobID = Injector::inst()->get(QueuedJobService::class)
            ->queueJob($job, $jobTime, null, $queueID);

        // Make sure our PublishOnDate is up to date.
        $this->updatePublishOnDate();
    }

    /**
     * Ensure the existence of an unpublish job at the specified time.
     */
    public function ensureUnPublishJob()
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsUnPublishJobRunning()) {
            return;
        }

        if (!$this->owner->checkAddPermission()) {
            return;
        }

        $now = DBDatetime::now()->getTimestamp();
        // New desired date (if set).
        $desiredUnPublishTime = $this->owner->dbObject('DesiredUnPublishDate')->getTimestamp();
        // Existing publish and un-publish date (if set).
        $unPublishTime = $this->owner->dbObject('UnPublishOnDate')->getTimestamp();

        // If there is no UnPublishOnDate set, make sure we remove any existing Jobs.
        if (!$unPublishTime) {
            $this->clearUnPublishJob();
        }

        // If there is no desired un-publish time set, then there is nothing for us to change.
        if (!$desiredUnPublishTime) {
            return;
        }

        // You might have some additional requirements for allowing a UnPublishJob to be created.
        /** @var array|bool[] $canHaveUnPublishJob */
        $canHaveUnPublishJob = $this->owner->invokeWithExtensions('unPublishJobCanBeQueued');
        // One or more extensions said that this Object cannot have an UnPublishJob.
        if (in_array(false, $canHaveUnPublishJob)) {
            return;
        }

        // Check if there is a prior job.
        if ((int) $this->owner->UnPublishJobID !== 0) {
            $job = $this->owner->UnPublishJob();

            // If it's the same UnPublish Job, leave it bet.
            if ($job
                && $job->exists()
                && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() === $desiredUnPublishTime
            ) {
                // Make sure our UnPublishOnDate is up to date.
                $this->updateUnPublishOnDate();

                return;
            }

            $this->owner->clearUnPublishJob();
        }

        $options = [];

        $this->owner->invokeWithExtensions('updateUnPublishTargetJobOptions', $options);

        // Do you want to use a different queue? You can define it at a DataObject level using this config. Your options
        // are: 1 (immediate), 2 (queued), 3 (large). See QueuedJob constants. Default is 2 (queued).
        $queueID = (int) $this->owner->config()->get('un_publish_target_job_queue_id');

        // Make sure the value set is valid, if it isn't, set back to default.
        if ($queueID === 0) {
            $queueID = null;
        }

        // Create a new job with the specified schedule. If unpublish time is in the past, run the Job immediately.
        // @todo There is a PR on QueuedJobs to use injectable. Should update this once that goes through.
        $jobTime = $desiredUnPublishTime > $now ? date('Y-m-d H:i:s', $desiredUnPublishTime) : null;
        $job = new UnPublishTargetJob($this->owner, $options);
        $this->owner->UnPublishJobID = Injector::inst()->get(QueuedJobService::class)
            ->queueJob($job, $jobTime, null, $queueID);

        // Make sure our UnPublishOnDate is up to date.
        $this->updateUnPublishOnDate();
    }

    /**
     * Returns whether a publishing date has been set and is after the current date
     *
     * @return bool
     */
    public function getIsPublishScheduled()
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
    public function getIsUnPublishScheduled()
    {
        /** @var DBDatetime $unpublish */
        $unpublish = $this->owner->dbObject('UnPublishOnDate');

        if ($unpublish->InFuture()) {
            return true;
        }

        if ((int) $this->owner->UnPublishJobID !== 0) {
            return true;
        }

        return false;
    }

    /**
     * Default logic for whether or not the DataObject is editable. Feel free to override this method on your DataObject
     * if you need to change the logic.
     *
     * @return bool
     */
    public function isEditable()
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
    public function addPublishingScheduleFields(FieldList $fields)
    {
        $message = $this->getEmbargoExpiryFieldNoticeMessage();

        $fields->findOrMakeTab(
            'Root.PublishingSchedule',
            _t(__CLASS__ . '.TAB_TITLE', 'Publishing Schedule')
        );

        $fields->addFieldsToTab(
            'Root.PublishingSchedule',
            [
                HeaderField::create(
                    'PublishDateHeader',
                    _t(__CLASS__ . '.PUBLISH_DATE_HEADER', 'Expiry and Embargo'),
                    3
                ),
                $publishDateField = DatetimeField::create(
                    'DesiredPublishDate',
                    _t(__CLASS__ . '.DESIRED_PUBLISH_ON', 'Desired publish date')
                ),
                $unPublishDateField = DatetimeField::create(
                    'DesiredUnPublishDate',
                    _t(__CLASS__ . '.DESIRED_UNPUBLISH_ON', 'Desired un-publish date')
                ),
            ]
        );

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

        if ($this->getIsPublishScheduled() || $this->getIsUnPublishScheduled()) {
            $newFields = [];

            $message =  _t(__CLASS__ . '.EXISTING_PUBLISH_MESSAGE', 'Existing embargo schedule.');

            $newFields[] = LiteralField::create(
                'ExistingPublishScheduleInfo',
                sprintf('<h4 class="notice">%s</h4>', $message)
            );

            if ($this->getIsPublishScheduled()) {
                $newFields[] = ReadonlyField::create(
                    'PublishOnDate',
                    _t(__CLASS__ . '.PUBLISH_ON', 'Scheduled publish date')
                );
            }

            if ($this->getIsUnPublishScheduled()) {
                $newFields[] = ReadonlyField::create(
                    'UnPublishOnDate',
                    _t(__CLASS__ . '.UNPUBLISH_ON', 'Scheduled un-publish date')
                );
            }

            $fields->addFieldsToTab(
                'Root.PublishingSchedule',
                $newFields
            );
        }

        if (!$this->owner->checkAddPermission()) {
            $publishDateField->setReadonly(true);
            $unPublishDateField->setReadonly(true);
        }
    }

    /**
     * @param $conditions
     * @return string
     */
    public function getEmbargoExpiryNoticeMessage($conditions)
    {
        if ($this->isEditable()) {
            return sprintf(
                _t(
                    __CLASS__ . '.EMBARGO_EDITING_NOTICE',
                    'You are currently editing a record that has an %s date set.'
                ),
                implode(' and ', array_column($conditions, 'name'))
            );
        }

        if (!$this->checkRemovePermission()) {
            return sprintf(
                _t(
                    __CLASS__ . '.EMBARGO_NONREMOVABLE_NOTICE',
                    'This record has an %s date set, and cannot currently be edited. An administrator will need
                    to remove the scheduled embargo date before you are able to edit this record.'
                ),
                implode(' and ', array_column($conditions, 'name'))
            );
        }

        if (array_key_exists('embargo', $conditions)) {
            return sprintf(
                _t(
                    __CLASS__ . '.EMBARGO_NONREMOVABLE_NOTICE',
                    'This record has an %s date set, and cannot currently be edited. You will need to remove the
                    scheduled embargo date in order to edit this record.'
                ),
                implode(' and ', array_column($conditions, 'name'))
            );
        }

        return sprintf(
            _t(
                __CLASS__ . '.EMBARGO_SET_NOTICE',
                'This record has an %s date set.'
            ),
            implode(' and ', array_column($conditions, 'name'))
        );
    }

    /**
     * @param FieldList $fields
     */
    public function addEmbargoExpiryNoticeFields(FieldList $fields)
    {
        $conditions = [];

        if ($this->getIsPublishScheduled()) {
            $time = strtotime($this->owner->PublishOnDate);

            $conditions['embargo'] = [
                'date' => $this->owner->PublishOnDate,
                'warning' => ($time > 0 && $time < time()),
                'name' => _t(__CLASS__ . '.EMBARGO_NAME', 'embargo')
            ];
        }

        if ($this->getIsUnPublishScheduled()) {
            $time = strtotime($this->owner->UnPublishOnDate);

            $conditions['expiry'] = [
                'date' => $this->owner->UnPublishOnDate,
                'warning' => ($time > 0 && $time < time()),
                'name' => _t(__CLASS__ . '.EXPIRY_NAME', 'expiry')
            ];
        }

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
                    _t(__CLASS__ . '.PAST_DATE_WARNING', ' (this date is in the past, is it still valid?)')
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
     * @return null|string
     */
    public function getEmbargoExpiryFieldNoticeMessage()
    {
        if (!$this->isEditable()) {
            return null;
        }

        if ($this->checkAddPermission()) {
            return $message = _t(
                __CLASS__ . '.EDITABLE_NOTICE',
                'Enter a date and/or time to specify embargo and expiry dates.<br />
                If an embargo is already set, adding a new one prior to that date\'s passing will overwrite it.'
            );
        }

        return $message = _t(
            __CLASS__ . '.NOTEDITABLE_NOTICE',
            'Please contact an administrator if you wish to add an embargo or expiry date to this record.'
        );
    }

    /**
     * Method to decide whether or not this Object is being accessed while a PublishTargetJob is running.
     *
     * @return bool
     */
    public function getIsPublishJobRunning()
    {
        return $this->isPublishJobRunning;
    }

    /**
     * @param $bool
     */
    public function setIsPublishJobRunning($bool)
    {
        $this->isPublishJobRunning = $bool;
    }

    /**
     * Method to decide whether or not this Object is being accessed while a PublishTargetJob is running.
     *
     * @return bool
     */
    public function getIsUnPublishJobRunning()
    {
        return $this->isUnPublishJobRunning;
    }

    /**
     * @param $bool
     */
    public function setIsUnPublishJobRunning($bool)
    {
        $this->isUnPublishJobRunning = $bool;
    }

    private function updatePublishOnDate()
    {
        // Make sure our PublishOnDate field is set correctly.
        $this->owner->PublishOnDate = $this->owner->DesiredPublishDate;
        // Remove the DesiredPublishDate.
        $this->owner->DesiredPublishDate = null;
    }

    private function updateUnPublishOnDate()
    {
        // Make sure our UnPublishOnDate field is set correctly.
        $this->owner->UnPublishOnDate = $this->owner->DesiredUnPublishDate;
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
     * @param array $options
     */
    public function prePublishTargetJob($options)
    {
    }

    /**
     * A method that can be implemented on your DataObject. This method is run with invokeWithExtensions prior to
     * calling doUnpublish() in the PublishTargetJob.
     *
     * The purpose of this method is to allow you a chance to modify your DataObject in any way you may need to prior
     * to it being unpublished. You have access to any $options that you set as part of the PublishTargetJob.
     *
     * @param array $options
     */
    public function preUnPublishTargetJob($options)
    {
    }

    /**
     * A method that can be implemented on your DataObject. This method is run with invokeWithExtensions prior to
     * creation of the PublishTargetJob.
     *
     * @param array $options
     */
    public function updatePublishTargetJobOptions(&$options)
    {
    }

    /**
     * A method that can be implemented on your DataObject. This method is run with invokeWithExtensions prior to
     * creation of the PublishTargetJob.
     *
     * @param array $options
     */
    public function updateUnPublishTargetJobOptions(&$options)
    {
    }
}
