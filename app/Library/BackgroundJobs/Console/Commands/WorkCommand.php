<?php

namespace App\Library\BackgroundJobs\Console\Commands;

use App\Library\BackgroundJobs\Services\RedisQueue;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Throwable;
use JsonException;

class WorkCommand extends Command
{
    protected $signature = 'bgj:work
                            {queue? : The name of the queue to work}
                            {--tries=1 : Number of times to attempt a job before logging it failed}
                            {--sleep=3 : Sleep time in seconds when no jobs are available}
                            {--max-memory=128 : Memory limit in MB before worker restarts}
                            {--max-jobs=0 : Maximum number of jobs to process (0 = unlimited)}';

    protected $description = 'Process jobs from the BackgroundJobs queue';

    protected RedisQueue $redisQueue;
    protected Container $container;
    protected ExceptionHandler $exceptions;
    protected ?string $logChannel;
    protected bool $shouldQuit = false;
    protected int $jobsProcessed = 0;
    
    // Worker state tracking
    protected string $workerState = 'idle';
    protected string $workerId;
    
    // Lock key used to track worker state in Redis
    protected string $workerStateKey;

    public function __construct(RedisQueue $redisQueue, Container $container, ExceptionHandler $exceptions)
    {
        parent::__construct();
        $this->redisQueue = $redisQueue;
        $this->container = $container;
        $this->exceptions = $exceptions;
        $this->logChannel = config('background-jobs.log_channel');
        $this->workerId = uniqid('worker_', true);
        
        // Set up signal handlers to gracefully exit
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
        }
    }

    public function handle(): int
    {
        $queueName = $this->argument('queue') ?? config('background-jobs.default_queue');
        $sleep = (int) $this->option('sleep');
        $maxMemory = (int) $this->option('max-memory') * 1024 * 1024; // Convert to bytes
        $maxJobs = (int) $this->option('max-jobs');

        // Set worker state key for this specific worker/queue
        $this->workerStateKey = "bgj:worker:{$this->workerId}:state";
        
        $this->info("Starting worker {$this->workerId} for queue: [{$queueName}]");
        $this->log("Worker started for queue: {$queueName}", 'info', ['worker_id' => $this->workerId]);
        
        // Main processing loop - event-driven approach
        while (!$this->shouldQuit) {
            try {
                // Check if we should quit due to memory usage
                if ($maxMemory > 0 && memory_get_usage(true) > $maxMemory) {
                    $this->warn("Memory usage exceeded limit. Restarting worker.");
                    $this->log("Memory usage exceeded {$maxMemory} bytes. Graceful restart initiated.", 'warning');
                    break;
                }
                
                // Check if there are jobs in the queue
                $pendingCount = $this->redisQueue->getPendingCount($queueName);
                
                if ($pendingCount > 0) {
                    // Jobs available, process them
                    $this->processJobs($queueName, $maxJobs);
                } else {
                    // No jobs available, check for delayed jobs that need to be moved
                    $migratedCount = $this->redisQueue->migrateExpiredJobs($queueName);
                    if ($migratedCount > 0) {
                        $this->log("Migrated {$migratedCount} delayed jobs to pending queue: {$queueName}", 'info');
                        // Process newly migrated jobs
                        $this->processJobs($queueName, $maxJobs);
                    } else {
                        // Still no jobs, sleep before checking again
                        $this->log("No jobs in queue: {$queueName}, sleeping for {$sleep} seconds", 'debug');
                        sleep($sleep);
                    }
                }
                
            } catch (\Throwable $e) {
                $this->error("Worker error: " . $e->getMessage());
                $this->log("Worker error: " . $e->getMessage(), 'error');
                
                // Sleep before retrying to prevent hammering Redis if there's a connection issue
                sleep(5);
            }
        }

        $this->log("Worker shutting down. Processed {$this->jobsProcessed} jobs.", 'info');
        $this->info("Worker shutting down. Processed {$this->jobsProcessed} jobs.");
        
        return Command::SUCCESS;
    }

    /**
     * Process all available jobs in the queue
     */
    protected function processJobs(string $queueName, int $maxJobs): void
    {
        $this->setWorkerState('running');
        $this->log("Starting job processing for queue: {$queueName}", 'debug');
        
        $jobLimit = $maxJobs > 0 ? $maxJobs - $this->jobsProcessed : PHP_INT_MAX;
        $keepProcessing = true;
        $noJobsCounter = 0;
        
        // Process jobs in a loop until queue is empty or we hit limits
        while ($keepProcessing && !$this->shouldQuit) {
            try {
                // Get a job from the queue (non-blocking)
                $jobData = $this->redisQueue->pop($queueName);
                
                if ($jobData) {
                    $payload = $jobData; // The payload is the job data
                    $this->processJob($payload, $queueName);
                    $this->jobsProcessed++;
                    $noJobsCounter = 0; // Reset counter when we successfully process a job
                    
                    // Check if we've hit our job limit
                    if ($maxJobs > 0 && $this->jobsProcessed >= $maxJobs) {
                        $this->log("Reached maximum job limit ({$maxJobs}). Stopping job processing.", 'info');
                        $keepProcessing = false;
                    }
                } else {
                    // No job available, check delayed jobs and then potentially exit
                    $migratedCount = $this->redisQueue->migrateExpiredJobs($queueName);
                    
                    if ($migratedCount > 0) {
                        $this->log("Migrated {$migratedCount} delayed jobs to pending queue: {$queueName}", 'info');
                        // Continue processing for newly migrated jobs
                        continue;
                    }
                    
                    // No job found and no delayed jobs migrated
                    $noJobsCounter++;
                    
                    // After 3 consecutive empty checks, we consider the queue empty
                    if ($noJobsCounter >= 3) {
                        $this->log("Queue {$queueName} is empty. Stopping job processing.", 'debug');
                        $keepProcessing = false;
                    } else {
                        // Brief pause to prevent tight loop
                        usleep(250000); // 250ms
                    }
                }
                
            } catch (\Throwable $e) {
                $this->error("Error processing jobs: " . $e->getMessage());
                $this->log("Error processing jobs: " . $e->getMessage(), 'error');
                $keepProcessing = false; // Exit the processing loop on error
            }
        }
        
        $this->setWorkerState('idle');
    }

    /**
     * Set the worker state
     */
    protected function setWorkerState(string $state): void
    {
        $this->workerState = $state;
        $redis = $this->redisQueue->getRedis();
        
        try {
            // Store worker state in Redis with expiration
            $redis->set($this->workerStateKey, $state);
            $redis->expire($this->workerStateKey, 300); // 5 minute expiry
            
            $this->log("Worker state changed to: {$state}", 'debug');
        } catch (\Throwable $e) {
            // Don't fail the worker if Redis state tracking fails
            $this->log("Failed to set worker state: " . $e->getMessage(), 'warning');
        }
    }

    public function shutdown()
    {
        $this->shouldQuit = true;
        $this->info("Received shutdown signal. Finishing current job and exiting...");
        $this->log("Received shutdown signal. Worker is shutting down gracefully.", 'info');
    }

    protected function processJob(string $payload, string $queueName): void
    {
        $jobDetails = null;
        try {
            $jobDetails = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $jobId = $jobDetails['uuid'] ?? 'unknown';
            $jobDisplayName = $jobDetails['displayName'] ?? 'unknown';
            $jobClassName = $jobDetails['data']['command']['class'] ?? null;
            $jobArgs = $jobDetails['data']['command']['args'] ?? [];
            $currentAttempt = ($jobDetails['attempts'] ?? 0) + 1;
            $maxTries = $jobDetails['maxTries'] ?? (int) $this->option('tries');

            if (!$jobClassName || !class_exists($jobClassName)) {
                throw new \RuntimeException("Job class {$jobClassName} not found.");
            }

            $this->log("Processing job: {$jobDisplayName} (ID: {$jobId}) - Attempt {$currentAttempt}/{$maxTries}", 'info', $jobDetails);

            // Check for delayed job
            if (isset($jobDetails['delay_until']) && $jobDetails['delay_until'] > time()) {
                $delaySeconds = $jobDetails['delay_until'] - time();
                $this->log("Job {$jobId} is delayed for {$delaySeconds} seconds. Sleeping...", 'info');
                
                // Simple sleep for the delay period
                sleep($delaySeconds);
            }

            // Resolve and run the job
            $instance = $this->resolveJob($jobClassName, $jobArgs);
            $instance->handle();

            // Job Success - No need to acknowledge since we're using pop not blockingPop
            $this->log("Processed job: {$jobDisplayName} (ID: {$jobId})", 'info', ['status' => 'success', ...$jobDetails]);

        } catch (Throwable $e) {
            $jobId = $jobDetails['uuid'] ?? 'unknown';
            $jobDisplayName = $jobDetails['displayName'] ?? 'unknown';
            $this->log("Job failed: {$jobDisplayName} (ID: {$jobId}) - Attempt {$currentAttempt}/{$maxTries} - Error: {$e->getMessage()}", 'error', ['exception' => $e, 'status' => 'failure', ...($jobDetails ?? [])]);

            // Increment attempt count in payload before potentially rescheduling
            if ($jobDetails) {
                $jobDetails['attempts'] = $currentAttempt;
                $jobDetails['last_error'] = $e->getMessage();
                $jobDetails['failed_at'] = now()->toIso8601String();
            }

            if ($currentAttempt < $maxTries) {
                // Retry logic
                $retryAfterSeconds = $jobDetails['retryAfter'] ?? 60;
                // Simple exponential backoff example
                $backoffSeconds = $retryAfterSeconds * pow(2, $currentAttempt -1);
                $retryTimestamp = time() + $backoffSeconds;
                
                // For event-driven model, we'll use delay_until instead of separate delayed queue
                $jobDetails['delay_until'] = $retryTimestamp;

                $this->log("Retrying job: {$jobDisplayName} (ID: {$jobId}) in {$backoffSeconds}s", 'warning', $jobDetails);

                try {
                    $this->redisQueue->push(json_encode($jobDetails, JSON_THROW_ON_ERROR), $queueName);
                } catch (Throwable $pushError) {
                    $this->log("Failed to push job for retry: {$jobDisplayName} (ID: {$jobId}) - Error: {$pushError->getMessage()}", 'critical', ['original_exception' => $e, ...$jobDetails]);
                    $this->exceptions->report($pushError);
                    // Push to failed queue here
                    $this->pushToFailedQueue($payload, $queueName, $e);
                }
            } else {
                // Max tries exceeded - Permanent failure
                $this->log("Permanent failure: {$jobDisplayName} (ID: {$jobId}) after {$maxTries} attempts. Error: {$e->getMessage()}", 'critical', ['exception' => $e, ...$jobDetails]);
                $this->pushToFailedQueue($payload, $queueName, $e);

                // Call failed method on the job instance if it exists
                try {
                    if (isset($instance) && method_exists($instance, 'failed')) {
                        $instance->failed($e);
                    }
                } catch (Throwable $failedMethodError) {
                    $this->log("Error executing failed() method for job: {$jobDisplayName} (ID: {$jobId}) - Error: {$failedMethodError->getMessage()}", 'error', ['original_exception' => $e, ...$jobDetails]);
                    $this->exceptions->report($failedMethodError);
                }
            }
            $this->exceptions->report($e); // Report original exception
        }
    }

    protected function resolveJob(string $className, array $args): \App\Library\BackgroundJobs\Contracts\Job
    {
        // Basic resolution. Assumes constructor takes args directly.
        $instance = new $className(...$args);

        if (!($instance instanceof \App\Library\BackgroundJobs\Contracts\Job)) {
            throw new \RuntimeException("Resolved class {$className} does not implement the required Job contract.");
        }
        return $instance;
    }

     protected function pushToFailedQueue(string $originalPayload, string $queueName, Throwable $exception): void
    {
        try {
             $failedPayload = json_decode($originalPayload, true);
             $failedPayload['failed_at'] = now()->toIso8601String();
             $failedPayload['exception_message'] = $exception->getMessage();
             // Consider adding stack trace (can be large)
             // $failedPayload['exception_trace'] = $exception->getTraceAsString();
             $this->redisQueue->pushFailed(json_encode($failedPayload, JSON_THROW_ON_ERROR), $queueName);
        } catch (Throwable $e) {
             $this->log("Could not push failed job payload to failed queue for queue: {$queueName}. Error: " . $e->getMessage(), 'critical');
             $this->exceptions->report($e);
             // Log the original payload somewhere safe if possible
             Log::channel($this->logChannel)->critical("Original Payload unable to be stored in failed queue: " . $originalPayload);
        }
    }

    protected function log(string $message, string $level = 'info', array $context = []): void
    {
         // Add common context
        $context['timestamp'] = Carbon::now()->toIso8601String();
        $context['worker_pid'] = getmypid();
        $context['worker_id'] = $this->workerId;
        $context['worker_state'] = $this->workerState;
        $context['memory_usage'] = round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB';

         // Extract job specific info if available
        if (isset($context['displayName'])) $context['class'] = $context['displayName'];
        if (isset($context['uuid'])) $context['job_id'] = $context['uuid'];

        // Remove redundant/large objects from context before logging if needed
        unset($context['exception']); // Avoid logging full exception object by default maybe?

        Log::channel($this->logChannel)->{$level}($message, $context);
    }
} 