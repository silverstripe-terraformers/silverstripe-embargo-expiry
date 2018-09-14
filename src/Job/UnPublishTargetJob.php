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
 * @property array $options
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
            if ($this->options && array_key_exists('onBeforeGetObject', $this->options)) {
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
        /** @var SiteTree $target */
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
        /** @var DataObject|Versioned|EmbargoExpiryExtension $target */
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

        $target->setIsUnPublishJobRunning(false);
        $this->completeJob();
    }

    protected function completeJob()
    {
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
