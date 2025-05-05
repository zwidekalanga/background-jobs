<?php

namespace App\Jobs;

use App\Library\BackgroundJobs\Contracts\Job;
use App\Library\BackgroundJobs\Concerns\Queueable;
use Illuminate\Support\Facades\Log;

class TestBackgroundJob implements Job
{
    use Queueable;

    protected $data;
    protected $options;

    /**
     * Create a new job instance.
     * 
     * @param string|array $data Main data to process
     * @param array $options Additional processing options 
     */
    public function __construct($data = 'test data', array $options = [])
    {
        $this->data = $data;
        $this->options = $options;
        
        // Store arguments for serialization - this will be used for retry
        $this->captureArguments([$data, $options]);
        
        // Set job-specific retry and attempt settings
        $this->tries = $options['tries'] ?? 3;
        $this->retryAfter = $options['retryAfter'] ?? 30;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $queue = $this->options['queue'] ?? 'default';
        $isPriority = $this->options['priority'] ?? false;
        
        // Log start of processing with job details
        Log::channel(config('background-jobs.log_channel'))
            ->info("Starting TestBackgroundJob", [
                'data' => $this->data, 
                'queue' => $queue,
                'priority' => $isPriority,
                'options' => $this->options
            ]);

        // Simulate processing work
        if (is_array($this->data) && isset($this->data['sleep'])) {
            sleep($this->data['sleep']);
        } else {
            sleep(2); // Default processing time
        }
        
        // Simulate potential failure
        if (is_array($this->data) && isset($this->data['fail']) && $this->data['fail'] === true) {
            throw new \Exception("Job failed due to test failure condition");
        }

        // Log completion
        Log::channel(config('background-jobs.log_channel'))
            ->info("Completed TestBackgroundJob", [
                'data' => $this->data,
                'queue' => $queue
            ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Log failure details
        Log::channel(config('background-jobs.log_channel'))
            ->error("TestBackgroundJob failed", [
                'data' => $this->data,
                'error' => $exception->getMessage(),
                'exception_class' => get_class($exception)
            ]);
        
        // Perform any cleanup or notification needed
    }

    /**
     * Generate a simple description for this job.
     * Useful for display in monitoring UIs.
     */
    public function displayName(): string
    {
        $dataType = is_array($this->data) ? 'array' : gettype($this->data);
        return "TestBackgroundJob({$dataType})";
    }
}
