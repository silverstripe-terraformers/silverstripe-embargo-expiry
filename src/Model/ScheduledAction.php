<?php

namespace Terraformers\EmbargoExpiry\Model;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;
use Terraformers\EmbargoExpiry\Job\PublishTargetJob;
use Terraformers\EmbargoExpiry\Job\State\ActionProcessingState;

/**
 * @property string $Datetime
 * @property int $QueuedJobID
 * @property string $RecordClass
 * @property int $RecordID
 * @property string $Type
 * @method QueuedJobDescriptor QueuedJob()
 * @method DataObject|EmbargoExpiryExtension Record()
 */
class ScheduledAction extends DataObject
{
    public const TYPE_EMBARGO = 'embargo';
    public const TYPE_EXPIRY = 'expiry';

    private static string $table_name = 'ScheduledAction';

    private static array $db = [
        'Datetime' => 'Datetime',
        'Type' => 'Varchar(10)',
    ];

    private static array $has_one = [
        'QueuedJob' => QueuedJobDescriptor::class,
        'Record' => DataObject::class,
    ];

    public function onBeforeWrite(): void
    {
        $this->ensureActionJob();
    }

    /**
     * @param Member|int|null $member
     */
    public static function checkAddPermission($member = null): bool
    {
        return Permission::checkMember($member, [EmbargoExpiryExtension::PERMISSION_ADD]);
    }

    /**
     * @param Member|int|null $member
     */
    public static function checkRemovePermission($member = null): bool
    {
        return Permission::checkMember($member, [EmbargoExpiryExtension::PERMISSION_REMOVE]);
    }

    /**
     * Ensure the existence (or removal) of a publishing/unpublishing job at the specified time.
     */
    private function ensureActionJob(): void
    {
        // Can't clear a job while it's in the process of being completed.
        if (ActionProcessingState::singleton()->getActionIsProcessing()) {
            return;
        }

        // You don't have permission to do this.
        if (!ScheduledAction::checkAddPermission()) {
            return;
        }

        // Existing publish and un-publish date (if set).
        $publishTime = (bool) $this->Datetime;

        // If there is no PublishOnDate set, make sure we remove any existing Jobs.
        if (!$publishTime) {
            $this->clearActionJob();

            return;
        }

        $this->createOrUpdatePublishJob();
    }

    /**
     * Clears any existing publish job against this DataObject (unless they are in the process of being completed).
     */
    private function clearActionJob(): void
    {
        // Can't clear a job while it's in the process of being completed.
        if (ActionProcessingState::singleton()->getActionIsProcessing()) {
            return;
        }

        $job = $this->QueuedJob();

        if ($job?->exists()) {
            $job->delete();
        }

        $this->ActionJobID = null;
        $this->Datetime = null;
    }

    private function objectRequiresPublishJob(): bool
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

    private function createOrUpdatePublishJob(int $desiredPublishTime): void
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
}
