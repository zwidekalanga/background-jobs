<?php

namespace App\Library\BackgroundJobs\Services;

use App\Library\BackgroundJobs\Contracts\Dispatcher as DispatcherContract;
use App\Library\BackgroundJobs\Contracts\Job as JobContract;
use App\Library\BackgroundJobs\Services\RedisQueue;
use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use JsonException;

class RedisDispatcher implements DispatcherContract
{
    protected RedisQueue $redisQueue;
    protected ConfigRepository $config;
    protected Container $container;

    public function __construct(RedisQueue $redisQueue, ConfigRepository $config, Container $container)
    {
        $this->redisQueue = $redisQueue;
        $this->config = $config;
        $this->container = $container; // Needed to serialize job correctly potentially
    }

    public function dispatch(JobContract $job, ?string $queue = null): ?string
    {
        $payload = $this->createPayload($job);
        if ($this->redisQueue->push($payload, $queue)) {
            return $this->getJobId($payload);
        }
        return null;
    }

    public function dispatchIn(CarbonInterval|int $delay, JobContract $job, ?string $queue = null): ?string
    {
        $delaySeconds = $this->parseDelay($delay);
        $timestamp = time() + $delaySeconds;
        $payload = $this->createPayload($job, ['delay_until' => $timestamp]);

        if ($this->redisQueue->push($payload, $queue)) {
             return $this->getJobId($payload);
        }
        return null;
    }

    /**
     * @deprecated This method is deprecated and will be removed in a future version.
     *             Use dispatchIn() instead.
     */
    public function dispatchAt(DateTimeInterface|int $timestamp, JobContract $job, ?string $queue = null): ?string
    {
        $unixTimestamp = ($timestamp instanceof DateTimeInterface)
            ? $timestamp->getTimestamp()
            : $timestamp;
        
        // Use delay_until for consistency with our new approach
        $delay = max(0, $unixTimestamp - time());
        
        if ($delay <= 0) {
            // If the timestamp is in the past or present, dispatch immediately
            return $this->dispatch($job, $queue);
        } else {
            // Otherwise convert to a delay and use dispatchIn
            return $this->dispatchIn($delay, $job, $queue);
        }
    }

    /**
     * Create the JSON payload for the job.
     */
    protected function createPayload(JobContract $job, array $additionalData = []): string
    {
        $jobId = Str::uuid()->toString();
        $jobClass = get_class($job);
        $args = method_exists($job, 'getQueueableArguments') ? $job->getQueueableArguments() : [];

        $payload = [
            'uuid' => $jobId,
            'displayName' => $jobClass,
            'job' => 'App\\Library\\BackgroundJobs\\CallQueuedHandler@call', // Placeholder for potential handler logic later
            'maxTries' => $job->tries(),
            'retryAfter' => $job->retryAfter(),
            'timeout' => null, // Add timeout support if needed
            'pushedAt' => now()->getTimestamp(),
            'data' => [
                'commandName' => $jobClass,
                // Serialize the job itself. Using Laravel's serializer might be complex here.
                // A simpler approach is to store class name + constructor args.
                // Requires the worker to reconstruct the job instance.
                'command' => [ // Store args needed to reconstruct
                    'class' => $jobClass,
                    'args' => $args, // Captured via Queueable trait
                ],
            ],
            'attempts' => 0,
        ];
        
        // Merge any additional data like delay_until
        if (!empty($additionalData)) {
            $payload = array_merge($payload, $additionalData);
        }

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Handle JSON encoding error appropriately
            throw new \RuntimeException("Failed to encode job payload: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract Job ID from payload string.
     */
    protected function getJobId(string $payload): ?string
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            return $decoded['uuid'] ?? null;
        } catch (JsonException $e) {
            return null;
        }
    }

    /**
     * Parse the delay into seconds.
     */
    protected function parseDelay(CarbonInterval|DateTimeInterface|int $delay): int
    {
        if ($delay instanceof CarbonInterval) {
            return (int) $delay->totalSeconds;
        }

        if ($delay instanceof DateTimeInterface) {
            return max(0, $delay->getTimestamp() - now()->getTimestamp());
        }

        return (int) $delay;
    }
} 