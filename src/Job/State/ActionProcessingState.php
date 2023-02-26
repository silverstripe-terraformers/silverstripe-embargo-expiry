<?php

namespace Terraformers\EmbargoExpiry\Job\State;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

class ActionProcessingState
{

    use Injectable;

    protected bool $actionIsProcessing = false;

    public function getActionIsProcessing(): bool
    {
        return $this->actionIsProcessing;
    }

    public function setActionIsProcessing(bool $actionIsProcessing): ActionProcessingState
    {
        $this->actionIsProcessing = $actionIsProcessing;

        return $this;
    }

    /**
     * Perform the given operation in an isolated state.
     * On return, the state will be restored, so any modifications are temporary.
     *
     * @param callable $callback Callback to run. Will be passed the nested state as a parameter
     * @return mixed Result of callback
     */
    public function withState(callable $callback): mixed
    {
        $newState = clone $this;

        try {
            Injector::inst()->registerService($newState);

            return $callback($newState);
        } finally {
            Injector::inst()->registerService($this);
        }
    }
}
