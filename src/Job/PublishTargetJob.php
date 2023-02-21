<?php

namespace Terraformers\EmbargoExpiry\Job;

use Opis\Closure\SerializableClosure;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Terraformers\EmbargoExpiry\Extension\EmbargoExpiryExtension;
use Terraformers\EmbargoExpiry\Job\State\ActionProcessingState;
use Terraformers\EmbargoExpiry\Model\ScheduledAction;

/**
 * @property array $options
 */
class PublishTargetJob extends AbstractQueuedJob
{

    /**
     * @var DataObject
     */
    private $target; // phpcs:ignore SlevomatCodingStandard.TypeHints

    public function __construct(?DataObject $obj = null, ?array $options = null)
    {
        parent::__construct();

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
        if ($this->target !== null) {
            return $this->target;
        }

        if (is_array($this->options) && array_key_exists('onBeforeGetObject', $this->options)) {
            $superClosure = $this->options['onBeforeGetObject'];

            if ($superClosure instanceof SerializableClosure) {
                $superClosure->__invoke();
            }
        }

        $this->target = parent::getObject();

        return $this->target;
    }

    public function getTitle(): string
    {
        $target = $this->getTarget();

        return _t(
            self::class . '.SCHEDULEPUBLISHJOBTITLE',
            'Scheduled publishing of {object}',
            '',
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

        ActionProcessingState::singleton()->setActionIsProcessing(true);

        // Make sure to use local variables for passing by reference as these are job properties
        // which are manipulated via magic methods and these do not work with passing by reference directly
        $options = $this->options;
        $target->invokeWithExtensions('prePublishTargetJob', $options);
        $this->options = $options;

        $target->publishRecursive();

        /** @var DataList|ScheduledAction[] $scheduleActions */
        $scheduleActions = $target->ScheduledActions()->filter('Type', ScheduledAction::TYPE_EMBARGO);

        foreach ($scheduleActions as $scheduleAction) {
            $scheduleAction->delete();
        }

        // Make sure to use local variables for passing by reference as these are job properties
        // which are manipulated via magic methods and these do not work with passing by reference directly
        $options = $this->options;
        // This allows actions to occur after the publishing job has run such as creating snapshots
        $target->invokeWithExtensions('afterPublishTargetJob', $options);
        $this->options = $options;

        ActionProcessingState::singleton()->setActionIsProcessing(false);

        $this->completeJob();
    }

    protected function completeJob(): void
    {
        $this->currentStep = 1;
        $this->isComplete = true;
    }
}
