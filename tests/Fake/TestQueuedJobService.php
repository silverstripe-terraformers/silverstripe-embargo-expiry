<?php

namespace Terraformers\EmbargoExpiry\Tests\Fake;

use Exception;
use SilverStripe\Dev\TestOnly;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Stub class to be able to call init from an external context
 */
class TestQueuedJobService extends QueuedJobService implements TestOnly
{
    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
     * @var array
     */
    private static $dependencies = [
        'queueHandler' => '%$QueueHandler',
    ];

    /**
     * @return bool|QueuedJob
     * @throws Exception
     */
    public function testInit(QueuedJobDescriptor $descriptor)
    {
        return $this->initialiseJob($descriptor);
    }
}
