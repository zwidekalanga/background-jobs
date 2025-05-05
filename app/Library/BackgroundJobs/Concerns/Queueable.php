<?php

namespace App\Library\BackgroundJobs\Concerns;

use App\Library\BackgroundJobs\Contracts\Dispatcher;
use App\Library\BackgroundJobs\Contracts\Job;
use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\App;

trait Queueable
{
    protected array $queueable_args = [];

    /**
     * Default number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Default number of seconds to wait before retrying the job.
     */
    public int $retryAfter = 60; // seconds


    /**
     * Instantiate the job and capture arguments.
     * Use this in your job's __construct.
     */
    protected function captureArguments(array $args): void
    {
        $this->queueable_args = $args;
    }

    /**
     * Get the captured arguments for serialization.
     */
    public function getQueueableArguments(): array
    {
        return $this->queueable_args;
    }

    /**
     * Dispatch the job with the given arguments immediately.
     */
    public static function perform_async(...$args): ?string
    {
        $job = static::createJobInstance($args);
        return App::make(Dispatcher::class)->dispatch($job);
    }

    /**
     * Dispatch the job with the given arguments after a delay.
     */
    public static function perform_in(CarbonInterval|int $delay, ...$args): ?string
    {
         $job = static::createJobInstance($args);
         return App::make(Dispatcher::class)->dispatchIn($delay, $job);
    }

    /**
     * Dispatch the job with the given arguments at a specific time.
     * 
     * @deprecated This method is deprecated and will be removed in a future version.
     *             Use perform_in() instead.
     */
    public static function perform_at(DateTimeInterface|int $timestamp, ...$args): ?string
    {
         // Show deprecation warning
         trigger_error(
            'perform_at() is deprecated and will be removed in a future version. Use perform_in() instead.', 
            E_USER_DEPRECATED
         );
         
         $job = static::createJobInstance($args);
         return App::make(Dispatcher::class)->dispatchAt($timestamp, $job);
    }

    /**
     * Helper to create job instance and capture args.
     */
    protected static function createJobInstance(array $args): Job
    {
        $job = App::make(static::class); // Use container to resolve dependencies
        if (method_exists($job, 'captureArguments')) {
            $job->captureArguments($args);
        }
        // You might need a more sophisticated way to pass args
        // if your constructor doesn't match the perform_* args directly.
        // Consider a dedicated `setData` method or reflection.
        // This basic example assumes __construct can be called without args,
        // and args are handled via captureArguments/getQueueableArguments.
        // Or that App::make resolves __construct dependencies.

        // Ensure it's a Job
        if (!($job instanceof Job)) {
            throw new \InvalidArgumentException(static::class . ' must implement ' . Job::class);
        }
        return $job;
    }

    // Default implementations for the Job contract
    public function tries(): int
    {
        return $this->tries;
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    // Default empty failed method
    public function failed(\Throwable $exception): void
    {
        // No default action
    }

    /**
     * The job handler method must still be implemented by the concrete class.
     * abstract public function handle();
     */
} 