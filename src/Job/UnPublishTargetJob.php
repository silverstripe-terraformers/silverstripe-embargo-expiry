<?php

namespace Terraformers\EmbargoExpiry\Job;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SuperClosure\SerializableClosure;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * Class WorkflowPublishTargetJob
 *
 * @package Terraformers\EmbargoExpiry\Jobs
 * @property array|null $options
 */
class UnPublishTargetJob extends AbstractQueuedJob
{
    /**
     * @var DataObject
     */
    private $target;

    /**
     * WorkflowPublishTargetJob constructor.
     *
     * @param DataObject|Versioned|EmbargoExpiryExtension|null $obj
     * @param array $options
     */
    public function __construct(?DataObject $obj = null, ?array $options = null)
    {
        $this->totalSteps = 1;

        if ($obj !== null) {
            $this->setObject($obj);
        }

        if ($options !== null) {
            $this->options = $options;
        }
    }

    /**
     * @return DataObject|Versioned|EmbargoExpiryExtension|null $obj
     */
    public function getTarget()
    {
        if ($this->target === null) {
            if (is_array($this->options) && array_key_exists('onBeforeGetObject', $this->options)) {
                $superClosure = $this->options['onBeforeGetObject'];

                if ($superClosure instanceof SerializableClosure) {
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
        $target = $this->getTarget();

        return _t(
            __CLASS__ . '.SCHEDULEUNPUBLISHJOBTITLE',
            "Scheduled un-publishing of {object}",
            "",
            [
                'object' => $target->Title,
            ]
        );
    }

    public function process()
    {
        $target = $this->getTarget();

        if ($target === null) {
            $this->completeJob();

            return;
        }

        $target->setIsUnPublishJobRunning(true);

        $target->invokeWithExtensions('preUnPublishTargetJob', $this->options);
        $target->unlinkUnPublishJobAndDate();
        $target->writeWithoutVersion();
        $target->doUnpublish();

        // This allows actions to occur after the unpublish job has run such as creating snapshots
        $target->invokeWithExtensions('afterUnPublishTargetJob', $this->options);

        $target->setIsUnPublishJobRunning(false);
        $this->completeJob();
    }

    protected function completeJob()
    {
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
