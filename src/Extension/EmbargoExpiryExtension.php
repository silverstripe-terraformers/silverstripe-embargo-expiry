<?php

namespace Terraformers\EmbargoExpiry\Extension;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Job\PublishTargetJob;

/**
 * Class WorkflowEmbargoExpiryExtension
 * @package Terraformers\EmbargoExpiry\Extension
 *
 * @property $this|DataObject $owner
 * @property DBDatetime $PublishOnDate
 * @property DBDatetime $UnPublishOnDate
 * @property bool $AllowEmbargoedEditing
 * @property int $PublishJobID
 * @property int $UnPublishJobID
 *
 * @method QueuedJobDescriptor PublishJob()
 * @method QueuedJobDescriptor UnPublishJob()
 */
class EmbargoExpiryExtension extends DataExtension implements PermissionProvider
{
    const PERMISSION_ADD = 'CMS_ACCESS_AddEmbargoExpiry';
    const PERMISSION_REMOVE = 'CMS_ACCESS_RemoveEmbargoExpiry';

    const JOB_TYPE_PUBLISH = 'publish';
    const JOB_TYPE_UNPUBLISH = 'unpublish';

    private static $db = array(
        'PublishOnDate' => 'Datetime',
        'UnPublishOnDate' => 'Datetime',
    );

    private static $has_one = array(
        'PublishJob' => QueuedJobDescriptor::class,
        'UnPublishJob' => QueuedJobDescriptor::class,
    );

    /**
     * Property used to track when a DataObject is being accessed during a PublishTargetJob.
     *
     * @var bool
     */
    public $isPublishJobRunning = false;

    /**
     * Config variable to decide whether or not pages can be edited while they are embargoed.
     *
     * @var bool
     */
    public static $allow_embargoed_editing = false;

    /**
     * Config variable that you can set to true if you want to always enforce that publish dates are before unpublish
     * dates.
     *
     * @var bool
     */
    public static $enforce_sequential_dates = false;

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
            $action = new FormAction('removeEmbargoAction', _t(__CLASS__ . 'REMOVE_EMBARGO', 'Remove embargo'));
            $actions->insertBefore('ActionMenus', $action);
        }

        if ($this->getIsUnPublishScheduled()) {
            // Add action to remove embargo.
            $action = new FormAction('removeExpiryAction', _t(__CLASS__ . 'REMOVE_EXPIRY', 'Remove expiry'));
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
            ]
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

    public function onBeforeDuplicate($original, $doWrite)
    {
        $this->owner->PublishOnDate = null;
        $this->owner->UnPublishOnDate = null;
        $this->owner->clearPublishJob();
        $this->owner->clearUnPublishJob();
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
            $flags['embargo_expiry'] = array(
                'text' => _t(__CLASS__ . '.BADGE_PUBLISH_UNPUBLISH', 'Embargo+Expiry'),
                'title' => sprintf(
                    '%s: %s, %s: %s',
                    _t(__CLASS__ . '.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate,
                    _t(__CLASS__ . '.UNPUBLISH_ON', 'Scheduled un-publish date'),
                    $this->owner->UnPublishOnDate
                ),
            );
        } elseif ($embargo) {
            $flags['embargo'] = array(
                'text' => _t(__CLASS__ . '.BADGE_PUBLISH', 'Embargo'),
                'title' => sprintf(
                    '%s: %s',
                    _t(__CLASS__ . '.PUBLISH_ON', 'Scheduled publish date'),
                    $this->owner->PublishOnDate
                ),
            );
        } elseif ($expiry) {
            $flags['expiry'] = array(
                'text' => _t(__CLASS__ . '.BADGE_UNPUBLISH', 'Expiry'),
                'title' => sprintf(
                    '%s: %s',
                    _t(__CLASS__ . '.UNPUBLISH_ON', 'Scheduled un-publish date'),
                    $this->owner->UnPublishOnDate
                ),
            );
        }
    }

    /**
     * Add edit check for when publishing has been scheduled and if any workflow definitions want the item to be
     * disabled.
     *
     * @param Member $member
     * @return bool|null
     */
    public function canEdit($member)
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
    public function canPublish($member)
    {
        return $this->owner->isEditable();
    }

    /**
     * @param null $member
     * @return bool
     */
    public function checkAddPermission($member = null)
    {
        return Permission::checkMember($member, array(self::PERMISSION_ADD));
    }

    /**
     * @param null $member
     * @return bool
     */
    public function checkRemovePermission($member = null)
    {
        return Permission::checkMember($member, array(self::PERMISSION_REMOVE));
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
    }

    /**
     * Clears any existing unpublish job against this DataObject (unless they are in the process of being completed).
     */
    public function clearUnPublishJob()
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsPublishJobRunning()) {
            return;
        }

        $job = $this->owner->UnPublishJob();

        if ($job && $job->exists()) {
            $job->delete();
        }

        $this->owner->UnPublishJobID = 0;
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
        $publishTime = $this->owner->dbObject('PublishOnDate')->getTimestamp();
        $unPublishTime = $this->owner->dbObject('UnPublishOnDate')->getTimestamp();

        // If there is no publish time, we must want to remove the old Publish Job.
        if (!$publishTime) {
            $this->owner->clearPublishJob();

            return;
        }

        // There is an unpublish time set, and that time has already passed or it's before the publish time.
        if ($this->owner->config()->get('enforce_sequential_dates')
            && $unPublishTime
            && ($unPublishTime < $now || $unPublishTime < $publishTime)
        ) {
            // We don't want to publish something that's meant to be being unpublished..
            $this->owner->clearPublishJob();

            return;
        }

        // Check if there is a prior Publish Job.
        if ((int) $this->owner->PublishJobID !== 0) {
            $job = $this->owner->PublishJob();

            // If it's the same Publish Job, leave it be.
            if ($job
                && $job->exists()
                && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() === $publishTime
            ) {
                return;
            }

            // Remove the old Publish Job.
            $this->owner->clearPublishJob();
        }

        $options = [
            'type' => 'publish',
        ];

        $this->owner->extend('updatePublishTargetJobOptions', $options);

        // Create a new job with the specified schedule. If publish time is in the past, run the Job immediately.
        $job = new PublishTargetJob($this->owner, $options);
        $this->owner->PublishJobID = Injector::inst()->get(QueuedJobService::class)
            ->queueJob($job, $publishTime > $now ? date('Y-m-d H:i:s', $publishTime) : null);
    }

    /**
     * Ensure the existence of an unpublish job at the specified time.
     */
    public function ensureUnPublishJob()
    {
        // Can't clear a job while it's in the process of being completed.
        if ($this->owner->getIsPublishJobRunning()) {
            return;
        }

        if (!$this->owner->checkAddPermission()) {
            return;
        }

        $now = DBDatetime::now()->getTimestamp();
        $unPublishTime = $this->owner->dbObject('UnPublishOnDate')->getTimestamp();

        if (!$unPublishTime) {
            $this->owner->clearUnPublishJob();

            return;
        }

        // Check if there is a prior job.
        if ((int) $this->owner->UnPublishJobID !== 0) {
            $job = $this->owner->UnPublishJob();

            // If it's the same UnPublish Job, leave it bet.
            if ($job
                && $job->exists()
                && DBDatetime::create()->setValue($job->StartAfter)->getTimestamp() === $unPublishTime
            ) {
                return;
            }

            $this->owner->clearUnPublishJob();
        }

        $options = [
            'type' => 'unpublish',
        ];

        $this->owner->extend('updateUnPublishTargetJobOptions', $options);

        // Create a new job with the specified schedule. If unpublish time is in the past, run the Job immediately.
        $job = new PublishTargetJob($this->owner, $options);
        $this->owner->UnPublishJobID = Injector::inst()->get(QueuedJobService::class)
            ->queueJob($job, $unPublishTime > $now ? date('Y-m-d H:i:s', $unPublishTime) : null);
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

        if ($this->owner->config()->get('allow_embargoed_editing')) {
            return true;
        }

        if (!$this->owner->getIsPublishScheduled()) {
            return true;
        }

        return false;
    }

    /**
     * @param FieldList $fields
     */
    public function addPublishingScheduleFields(FieldList $fields)
    {
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
                    'PublishOnDate',
                    _t(__CLASS__ . '.PUBLISH_ON', 'Scheduled publish date')
                ),
                $unPublishDateField = DatetimeField::create(
                    'UnPublishOnDate',
                    _t(__CLASS__ . '.UNPUBLISH_ON', 'Scheduled un-publish date')
                ),
            ]
        );

        if (($message = $this->getEmbargoExpiryFieldNoticeMessage()) !== null) {
            $fields->addFieldToTab(
                'Root.PublishingSchedule',
                LiteralField::create(
                    'PublishDateIntro',
                    "<h4 class=\"notice\">{$message}</h4>"
                ),
                'PublishOnDate'
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
                implode(' and ', array_keys($conditions))
            );
        }

        if ($this->checkRemovePermission()) {
            return sprintf(
                _t(
                    __CLASS__ . '.EMBARGO_REMOVABLE_NOTICE',
                    'This record has an %s date set, and cannot currently be edited. You will need to remove the
                    scheduled embargo date in order to edit this record.'
                ),
                implode(' and ', array_keys($conditions))
            );
        }

        return sprintf(
            _t(
                __CLASS__ . '.EMBARGO_NONREMOVABLE_NOTICE',
                'This record has an %s date set, and cannot currently be edited. An administrator will need
                to remove the scheduled embargo date before you are able to edit this record.'
            ),
            implode(' and ', array_keys($conditions))
        );
    }

    /**
     * @param FieldList $fields
     */
    public function addEmbargoExpiryNoticeFields(FieldList $fields)
    {
        $conditions = [];

        if ($this->getIsPublishScheduled()) {
            $key = _t(__CLASS__ . '.EMBARGO_NAME', 'embargo');
            $conditions[$key] = $this->owner->PublishOnDate;
        }

        if ($this->getIsUnPublishScheduled()) {
            $key = _t(__CLASS__ . '.EXPIRY_NAME', 'expiry');
            $conditions[$key] = $this->owner->UnPublishOnDate;
        }

        if (count($conditions) === 0) {
            return;
        }

        $message = $this->getEmbargoExpiryNoticeMessage($conditions);

        foreach ($conditions as $name => $date) {
            $message .= sprintf(
                '<br /><strong>%s</strong>: %s',
                ucfirst($name),
                $date
            );
        }

        $fields->unshift(
            LiteralField::create(
                'EmbargoExpiryNotice',
                "<p class=\"message notice\">{$message}</p>"
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
     * This is a stopgap for (what I'm considering, for now at least) a bug in Versioned. I believe that
     * writeWithoutVersion() should update the matching _Versions record, as well as the Stage record, but currently it
     * does not.
     *
     * @param string $jobType
     * @throws \InvalidArgumentException
     */
    public function updateVersionsTableRecord($jobType)
    {
        $table = $this->owner->baseTable() . '_Versions';

        switch ($jobType) {
            case static::JOB_TYPE_PUBLISH:
                $dateField = 'PublishOnDate';
                $jobField = 'PublishJobID';
                break;
            case static::JOB_TYPE_UNPUBLISH:
                $dateField = 'UnPublishOnDate';
                $jobField = 'UnPublishJobID';
                break;
            default:
                throw new \InvalidArgumentException('Invalid Job type supplied.');
        }

        $sql = SQLUpdate::create($table,
            [
                $dateField => $this->$dateField,
                $jobField => $this->$jobField,
            ],
            [
                'RecordID' => $this->owner->ID,
                'Version' => $this->owner->Version,
            ]
        );

        $sql->execute();
    }

    /**
     * A method that can be implemented on your DataObject. This method is run prior to calling publishRecursive() in
     * the PublishTargetJob.
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
     * A method that can be implemented on your DataObject. This method is run prior to calling doUnpublish() in the
     * PublishTargetJob.
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
     * Before a PublishTargetJob is created for publishing a DataObject, you may have some additional options that you
     * want to add to it.
     *
     * @param array $options
     */
    public function updatePublishTargetJobOptions(&$options)
    {
    }

    /**
     * Before a PublishTargetJob is created for unpublishing a DataObject, you may have some additional options that you
     * want to add to it.
     *
     * @param array $options
     */
    public function updateUnPublishTargetJobOptions(&$options)
    {
    }
}
