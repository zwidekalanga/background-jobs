<?php

namespace App\Library\BackgroundJobs\Contracts;

interface Job
{
    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle();

    /**
     * Get the number of times the job may be attempted.
     *
     * @return int
     */
    public function tries(): int;

    /**
     * Get the number of seconds to delay before retrying the job.
     * Exponential backoff based on attempt number is recommended in the worker.
     *
     * @return int
     */
    public function retryAfter(): int;

    /**
     * Optional method to handle a job failure after all retries.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception): void;

} 