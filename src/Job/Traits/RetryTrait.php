<?php

namespace Terraformers\EmbargoExpiry\Job\Traits;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\QueuedJobService;

trait RetryTrait
{
    use Configurable;

    /**
     * List of transient DB error messages to retry on
     *
     * @config
     */
    private static array $retryable_errors = [
        'Duplicate entry',
        'Deadlock found',
        'Lock wait timeout exceeded',
        'Serialization failure',
        'try restarting transaction',
    ];

    /**
     * Number of retries for transient DB errors before failing and re-queuing the job
     *
     * @config
     */
    private static int $retries = 3;

    /**
     * When re-queuing a job after exhausting retries, how many seconds to wait before next attempt.
     *
     * @config
     */
    private static int $next_job_time = 60;

    /**
     * Attempt to execute a callback with retry behaviour for transient DB errors.
     *
     * @param \SilverStripe\ORM\DataObject $target a DataObject context that will be provided to the callback.
     * @param callable $callback the callable to execute. The callable will be invoked as `callback($target)`.
     * @param string $message a fallback text to use when logging; note this value will be overwritten with
     *                        the exception message when an exception occurs.
     * @throws \SilverStripe\Core\Validation\ValidationException
     * @throws \Throwable
     */
    private function executeRetry(DataObject $target, callable $callback, string $message): void
    {
        // Read configured values from the consuming class.
        $callerClass = get_class($this);
        $retryableErrors = $callerClass::config()->get('retryable_errors');
        $retries = $callerClass::config()->get('retries');
        $nextJobTime = $callerClass::config()->get('retries');

        // No retry configuration, just execute the callback directly.
        if (empty($retryableErrors) || $retries <= 0) {
            $callback($target);
            return;
        }

        $attempt = 0;
        while ($attempt < $retries) {
            try {
                // Execute the provided callback, if successful we are done.
                $callback($target);
                return;
            } catch (\Throwable $exception) {
                // Capture the error message for retry detection and (later) logging.
                $exceptionMessage = $exception->getMessage();

                // Check if message matches any retryable pattern
                $isRetryable = (bool)array_filter(
                    $retryableErrors,
                    fn($pattern) => stripos($exceptionMessage, $pattern) !== false
                );

                // Increment attempt counter and wait a short time before retrying.
                if ($isRetryable) {
                    $attempt++;
                    usleep(300000 * $attempt);
                    continue;
                }

                // If the error isn't considered retryable, rethrow immediately so callers can handle it.
                throw $exception;
            }
        }

        // If we exhausted our retries, re-queue the job for later processing, and log the failure.
        $cloneJob = clone $this;
        QueuedJobService::singleton()->queueJob(
            $cloneJob,
            DBDatetime::create()->setValue(DBDatetime::now()->getTimestamp() + $nextJobTime)->Rfc2822()
        );
        DB::alteration_message(sprintf($message, $retries));
    }
}
