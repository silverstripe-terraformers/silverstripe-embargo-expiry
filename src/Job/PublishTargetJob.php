<?php

namespace Terraformers\EmbargoExpiry\Job;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SuperClosure\SerializableClosure;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * Class WorkflowPublishTargetJob
 * @package Terraformers\EmbargoExpiry\Jobs
 * @property array $options
 */
class PublishTargetJob extends AbstractQueuedJob
{
    /**
     * @var DataObject
     */
    private $target;

    /**
     * WorkflowPublishTargetJob constructor.
     * @param SiteTree|null $obj
     * @param array $options
     */
    public function __construct($obj = null, $options = [])
    {
        $this->totalSteps = 1;

        if ($obj) {
            $this->setObject($obj);
        }

        if ($options) {
            $this->options = $options;
        }
    }

    /**
     * @return DataObject
     */
    public function getTarget()
    {
        if ($this->target === null) {
            if (array_key_exists('onBeforeGetObject', $this->options)) {
                if (($superClosure = $this->options['onBeforeGetObject']) instanceof SerializableClosure) {
                    $superClosure->__invoke();
                }
            }

            $this->target = parent::getObject();
        }

        return $this->target;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        /** @var SiteTree $target */
        $target = $this->getTarget();
        $type = array_key_exists('type', $this->options) ? $this->options['type'] : null;

        return _t(
            __CLASS__ . '.SCHEDULEJOBTITLE',
            "Scheduled {type} of {object}",
            "",
            array(
                'type' => $type,
                'object' => $target->Title
            )
        );
    }

    public function process()
    {
        /** @var SiteTree $target */
        $target = $this->getTarget();
        $type = array_key_exists('type', $this->options) ? $this->options['type'] : null;

        if ($target === null) {
            $this->completeJob();

            return;
        }

        if (!$type === null) {
            $this->completeJob();

            return;
        }

        $target->setIsPublishJobRunning(true);

        if ($type === EmbargoExpiryExtension::JOB_TYPE_PUBLISH) {
            $target->prePublishTargetJob($this->options);
            $target->unlinkPublishJobAndDate();
            $target->writeWithoutVersion();
            $target->updateVersionsTableRecord(EmbargoExpiryExtension::JOB_TYPE_PUBLISH);
            $target->publishRecursive();
        } elseif ($type === EmbargoExpiryExtension::JOB_TYPE_UNPUBLISH) {
            $target->preUnPublishTargetJob($this->options);
            $target->unlinkUnPublishJobAndDate();
            $target->writeWithoutVersion();
            $target->updateVersionsTableRecord(EmbargoExpiryExtension::JOB_TYPE_UNPUBLISH);
            $target->doUnpublish();
        }

        $target->setIsPublishJobRunning(false);
        $this->completeJob();
    }

    protected function completeJob()
    {
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
