<?php

namespace Terraformers\EmbargoExpiry\Job;

use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SuperClosure\SerializableClosure;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;

/**
 * Class WorkflowPublishTargetJob
 *
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
     * @return DataObject|Versioned|EmbargoExpiryExtension|null
     */
    public function getTarget(): ?DataObject
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
    public function getTitle(): string
    {
        $target = $this->getTarget();

        return _t(
            __CLASS__ . '.SCHEDULEPUBLISHJOBTITLE',
            "Scheduled publishing of {object}",
            "",
            [
                'object' => $target->Title,
            ]
        );
    }

    public function process(): void
    {
        $target = $this->getTarget();

        if ($target === null) {
            $this->completeJob();

            return;
        }

        $target->setIsPublishJobRunning(true);

        $target->invokeWithExtensions('prePublishTargetJob', $this->options);
        $target->unlinkPublishJobAndDate();
        $target->writeWithoutVersion();
        $target->publishRecursive();

        // This allows actions to occur after the publish job has run such as creating snapshots
        $target->invokeWithExtensions('afterPublishTargetJob', $this->options);

        $target->setIsPublishJobRunning(false);
        $this->completeJob();
    }

    protected function completeJob(): void
    {
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
