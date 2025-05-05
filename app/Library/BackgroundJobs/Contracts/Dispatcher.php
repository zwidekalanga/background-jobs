<?php

namespace App\Library\BackgroundJobs\Contracts;

use Carbon\CarbonInterval;
use DateTimeInterface;

interface Dispatcher
{
    /**
     * Dispatch a job to the queue.
     *
     * @param Job $job
     * @param string|null $queue
     * @return string|null Job ID or null on failure
     */
    public function dispatch(Job $job, ?string $queue = null): ?string;

    /**
     * Dispatch a job to be processed after a delay.
     *
     * @param CarbonInterval|int $delay (seconds)
     * @param Job $job
     * @param string|null $queue
     * @return string|null Job ID or null on failure
     */
    public function dispatchIn(CarbonInterval|int $delay, Job $job, ?string $queue = null): ?string;

    /**
     * Dispatch a job to be processed at a specific time.
     * 
     * @deprecated This method is deprecated and will be removed in a future version.
     *             Use dispatchIn() instead.
     *
     * @param DateTimeInterface|int $timestamp (Unix timestamp)
     * @param Job $job
     * @param string|null $queue
     * @return string|null Job ID or null on failure
     */
    public function dispatchAt(DateTimeInterface|int $timestamp, Job $job, ?string $queue = null): ?string;
} 