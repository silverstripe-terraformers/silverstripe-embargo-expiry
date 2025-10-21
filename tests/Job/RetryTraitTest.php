<?php

namespace Terraformers\EmbargoExpiry\Tests\Job;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Terraformers\EmbargoExpiry\Job\PublishTargetJob;
use Terraformers\EmbargoExpiry\Job\Traits\RetryTrait;
use Terraformers\EmbargoExpiry\Job\UnPublishTargetJob;
use Exception;

class RetryTraitTest extends SapphireTest
{

    public function testConfiguration(): void
    {
        $this->assertEquals(3, PublishTargetJob::config()->get('retries'));
        $this->assertEquals(60, PublishTargetJob::config()->get('next_job_time'));
        $this->assertEquals(3, UnPublishTargetJob::config()->get('retries'));
        $this->assertEquals(60, UnPublishTargetJob::config()->get('next_job_time'));


        PublishTargetJob::config()->set('next_job_time', 120);
        PublishTargetJob::config()->set('retries', 4);
        UnPublishTargetJob::config()->set('next_job_time', 121);
        UnPublishTargetJob::config()->set('retries', 5);

        $this->assertEquals(120, PublishTargetJob::config()->get('next_job_time'));
        $this->assertEquals(121, UnPublishTargetJob::config()->get('next_job_time'));
        $this->assertEquals(5, UnPublishTargetJob::config()->get('retries'));
    }

    public function testRetry(): void
    {
        // Mock DataObject and job to test RetryTrait
        $page = SiteTree::create();
        $job = new class extends AbstractQueuedJob {
            use RetryTrait;

            public function testMethod($target, $callback, $message)
            {
                $this->executeRetry($target, $callback, $message);
            }

            public function getTitle()
            {
                return '';
            }

            public function process()
            {
            }
        };

        // Test no retries because retries is set to 0
        $job->config()->set('retries', 0);
        $job->config()->set('retryable_errors', [
            'Deadlock found'
        ]);

        // Check that with 0 retries, the write function executes without retrying
        $job->testMethod($page, function ($target) {
            $target->setField('Counter', $target->getField('Counter') + 1);
        }, 'Test message');
        $this->assertEquals(1, $page->getField('Counter'));

        // Test with 2 retries on 'Deadlock found' error
        $job->config()->set('retries', 2);
        $attempts = 0;
        $job->testMethod($page, function ($target) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \Exception('Deadlock found when trying to get lock; try restarting transaction');
            }
        }, 'Test  message');

        // Check that the method was retried twice before succeeding
        $this->assertEquals(2, $attempts);
    }
}
