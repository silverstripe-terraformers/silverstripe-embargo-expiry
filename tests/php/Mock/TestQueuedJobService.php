<?php

namespace Terraformers\EmbargoExpiry\Tests\Mock;

use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use SilverStripe\Dev\TestOnly;

// stub class to be able to call init from an external context
class TestQueuedJobService extends QueuedJobService implements TestOnly
{
    /**
     * @var array
     */
    private static $dependencies = [
        'queueHandler' => '%$QueueHandler',
    ];

    /**
     * @param QueuedJobDescriptor $descriptor
     * @return bool|\Symbiote\QueuedJobs\Services\QueuedJob
     * @throws \Exception
     */
    public function testInit($descriptor)
    {
        return $this->initialiseJob($descriptor);
    }
}
