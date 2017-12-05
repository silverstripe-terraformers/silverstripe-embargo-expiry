<?php

namespace Terraformers\EmbargoExpiry\Job;

use SilverStripe\CMS\Model\SiteTree;
use SuperClosure\SerializableClosure;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

/**
 * Class WorkflowPublishTargetJob
 * @package Terraformers\EmbargoExpiry\Jobs
 * @property array $options
 */
class PublishTargetJob extends AbstractQueuedJob
{
    /**
     * WorkflowPublishTargetJob constructor.
     * @param SiteTree|null $obj
     * @param array $options
     */
    public function __construct($obj = null, $options = [])
    {
        if ($obj) {
            $this->setObject($obj);
            $this->totalSteps = 1;
            $this->options = $options;
        }
    }

    /**
     * @param string $name
     * @return \SilverStripe\ORM\DataObject
     */
    public function getObject($name = 'SilverStripe\\Core\\Object')
    {
        if (array_key_exists('onBeforeGetObject', $this->options)) {
            if (($superClosure = $this->options['onBeforeGetObject']) instanceof SerializableClosure) {
                $superClosure->__invoke();
            }
        }

        return parent::getObject($name);
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        /** @var SiteTree $target */
        $target = $this->getObject();
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
        $target = $this->getObject();
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

        if ($type === 'publish') {
            $target->prePublishTargetJob($this->options);
            $target->unlinkPublishJobAndDate();
            $target->writeWithoutVersion();
            $target->publishRecursive();
        } elseif ($type === 'unpublish') {
            $target->preUnPublishTargetJob($this->options);
            $target->unlinkUnPublishJobAndDate();
            $target->writeWithoutVersion();
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
