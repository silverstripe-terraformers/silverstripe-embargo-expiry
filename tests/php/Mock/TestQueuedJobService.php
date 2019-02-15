<?php

namespace Terraformers\EmbargoExpiry\Tests\Mock;

use SilverStripe\Dev\TestOnly;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Stub class to be able to call init from an external context
 *
 * Class TestQueuedJobService
 *
 * @package Terraformers\EmbargoExpiry\Tests\Mock
 */
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
     * @return bool|QueuedJob
     * @throws \Exception
     */
    public function testInit(QueuedJobDescriptor $descriptor)
    {
        return $this->initialiseJob($descriptor);
    }
}
