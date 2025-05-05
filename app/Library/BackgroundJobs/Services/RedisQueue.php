<?php

namespace App\Library\BackgroundJobs\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

class RedisQueue
{
    protected $redis;
    protected string $keyPrefix;
    protected string $defaultQueue;

    public function __construct(ConfigRepository $config)
    {
        // Use PHP's Redis extension directly instead of the Laravel Redis facade
        $this->redis = new \Redis();
        $host = $config->get('database.redis.default.host', '127.0.0.1');
        $port = $config->get('database.redis.default.port', 6379);
        $timeout = 0.0;
        $retry_interval = 0;
        $read_timeout = 60.0;
        
        try {
            // Try to connect with the default settings
            $this->redis->connect($host, $port, $timeout, null, $retry_interval, $read_timeout);
            
            // Set prefix if needed
            $prefix = $config->get('database.redis.options.prefix', '');
            if ($prefix) {
                $this->redis->setOption(\Redis::OPT_PREFIX, $prefix);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Redis connection error: ' . $e->getMessage());
            throw $e;
        }
        
        $this->keyPrefix = $config->get('background-jobs.key_prefix', 'bgj:');
        $this->defaultQueue = $config->get('background-jobs.default_queue', 'default');
    }

    /**
     * Get the Redis client instance.
     */
    public function getRedis(): \Redis
    {
        return $this->redis;
    }

    /**
     * Get the key prefix used for all Redis keys.
     */
    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    protected function getPrefixedKey(string $key, ?string $queue = null): string
    {
        $queue = $queue ?: $this->defaultQueue;
        return $this->keyPrefix . $key . ':' . $queue;
    }

    // --- Key Getters ---

    public function getPendingKey(?string $queue = null): string
    {
        return $this->getPrefixedKey('queues', $queue) . ':pending';
    }

    public function getDelayedKey(?string $queue = null): string
    {
        return $this->getPrefixedKey('queues', $queue) . ':delayed';
    }

    public function getFailedKey(?string $queue = null): string
    {
         return $this->getPrefixedKey('queues', $queue) . ':failed';
    }

    public function getProcessingKey(?string $queue = null): string
    {
        return $this->getPrefixedKey('queues', $queue) . ':processing';
    }

    // --- Queue Operations ---

    /**
     * Push a raw payload onto the pending queue.
     */
    public function push(string $payload, ?string $queue = null): int|false
    {
        return $this->redis->lpush($this->getPendingKey($queue), $payload);
    }

    /**
     * Push a raw payload onto the delayed queue.
     */
    public function later(int $delayTimestamp, string $payload, ?string $queue = null): int|false
    {
        return $this->redis->zadd($this->getDelayedKey($queue), $delayTimestamp, $payload);
    }

    /**
     * Pop the next job from the pending queue (blocking).
     * Returns [queueName, payload] or null if timeout occurs.
     * 
     * Uses BRPOPLPUSH to atomically move the job to the processing list
     * which provides better reliability in case of worker crashes.
     */
    public function popBlocking(int $timeout = 5, ?string $queue = null): ?array
    {
        $pendingKey = $this->getPendingKey($queue);
        $processingKey = $this->getProcessingKey($queue);
        
        try {
            // BRPOPLPUSH atomically pops from pending and pushes to processing
            // This ensures we don't lose jobs if the worker crashes
            $payload = $this->redis->brPoplPush($pendingKey, $processingKey, $timeout);
            
            if ($payload) {
                // If job successfully popped and moved to processing
                return [$pendingKey, $payload];
            }
            
            return null; // Timeout occurred
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Redis BRPOPLPUSH error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Acknowledge a job as completed by removing it from the processing list.
     * Uses a workaround for Redis extension that has strict type checking.
     */
    public function acknowledgeJob(string $payload, ?string $queue = null): int|false
    {
        $processingKey = $this->getProcessingKey($queue);
        
        // Unfortunately there's a type error with Redis::lRem in some PHP Redis versions
        // We'll use a Lua script as a workaround which is more reliable
        $script = <<<'LUA'
            local list_key = KEYS[1]
            local value = ARGV[1]
            local count = 0
            
            -- Get the current length of the list
            local len = redis.call('LLEN', list_key)
            
            -- Iterate through the list and search for our value
            for i=0,len-1 do
                local item = redis.call('LINDEX', list_key, i)
                if item == value then
                    -- Found it! Remove it
                    redis.call('LREM', list_key, 1, value)
                    count = 1
                    break
                end
            end
            
            return count
        LUA;
        
        try {
            return (int) $this->redis->eval($script, [$processingKey, $payload], 1);
        } catch (\Exception $e) {
            // Fallback in case eval is not supported
            \Illuminate\Support\Facades\Log::error('Redis acknowledge error: ' . $e->getMessage());
            
            // Last resort approach: get everything, delete the list, then add back everything except our item
            $allItems = $this->redis->lRange($processingKey, 0, -1);
            $this->redis->del($processingKey);
            
            $count = 0;
            foreach ($allItems as $item) {
                if ($item !== $payload) {
                    $this->redis->rPush($processingKey, $item);
                } else {
                    $count++;
                }
            }
            
            return $count;
        }
    }

    /**
     * Get jobs from the delayed queue that are due.
     */
    public function getDueDelayedJobs(int $timestamp, ?string $queue = null): array
    {
        $key = $this->getDelayedKey($queue);
        // Get jobs with score (timestamp) <= now
        return $this->redis->zrangebyscore($key, 0, $timestamp);
    }

    /**
     * Atomically move due jobs from delayed to pending.
     * Uses Lua script for atomicity.
     */
    public function migrateExpiredJobs(?string $queue = null): int
    {
        $delayedKey = $this->getDelayedKey($queue);
        $pendingKey = $this->getPendingKey($queue);
        $timestamp = now()->getTimestamp();

        // Lua script:
        // 1. Find jobs in the delayed set with score <= current timestamp.
        // 2. Add these jobs to the beginning of the pending list.
        // 3. Remove these jobs from the delayed set.
        // 4. Return the number of jobs moved.
        $lua = <<<'LUA'
            local expired = redis.call('zrangebyscore', KEYS[1], 0, ARGV[1])
            if #expired > 0 then
                -- Use unpack in batches if needed for very large numbers of expired jobs
                redis.call('lpush', KEYS[2], unpack(expired))
                redis.call('zremrangebyscore', KEYS[1], 0, ARGV[1])
            end
            return #expired
            LUA;

        try {
            // Keys: [1] = delayed_key, [2] = pending_key
            // Args: [1] = current_timestamp
            return (int) $this->redis->eval($lua, [$delayedKey, $pendingKey, $timestamp], 2);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Redis eval error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Push a raw payload onto the failed queue (simple list).
     */
    public function pushFailed(string $payload, ?string $queue = null): int|false
    {
        return $this->redis->lpush($this->getFailedKey($queue), $payload);
    }

    /**
     * Get queue statistics for monitoring.
     */
    public function getQueueStats(?string $queue = null): array
    {
        $pendingKey = $this->getPendingKey($queue);
        $delayedKey = $this->getDelayedKey($queue);
        $processingKey = $this->getProcessingKey($queue);
        $failedKey = $this->getFailedKey($queue);
        
        try {
            $pendingCount = $this->redis->lLen($pendingKey);
            $delayedCount = $this->redis->zCard($delayedKey);
            $processingCount = $this->redis->lLen($processingKey);
            $failedCount = $this->redis->lLen($failedKey);
            
            return [
                'pending' => $pendingCount,
                'delayed' => $delayedCount,
                'processing' => $processingCount,
                'failed' => $failedCount,
                'total' => $pendingCount + $delayedCount + $processingCount,
                'queue' => $queue ?: $this->defaultQueue,
            ];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Redis stats error: ' . $e->getMessage());
            return [
                'error' => $e->getMessage(),
                'queue' => $queue ?: $this->defaultQueue,
            ];
        }
    }

    /**
     * Recover any jobs that are stuck in the processing list
     * (likely due to worker crashes) by moving them back to pending.
     */
    public function recoverStuckJobs(?string $queue = null): int
    {
        $processingKey = $this->getProcessingKey($queue);
        $pendingKey = $this->getPendingKey($queue);
        
        $lua = <<<'LUA'
            local jobs = redis.call('lrange', KEYS[1], 0, -1)
            local count = #jobs
            if count > 0 then
                redis.call('del', KEYS[1])
                redis.call('rpush', KEYS[2], unpack(jobs))
            end
            return count
            LUA;
            
        try {
            return (int) $this->redis->eval($lua, [$processingKey, $pendingKey], 2);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Redis recovery error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get count of pending jobs for a queue.
     */
    public function getPendingCount(?string $queue = null): int
    {
        try {
            return (int) $this->redis->lLen($this->getPendingKey($queue));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Redis lLen error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Pop a job from the pending queue (non-blocking).
     * Returns payload or null if no jobs available.
     */
    public function pop(?string $queue = null): ?string
    {
        $pendingKey = $this->getPendingKey($queue);
        
        try {
            // LPOP removes and returns the first element of the list
            return $this->redis->lPop($pendingKey);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Redis LPOP error: ' . $e->getMessage());
            return null;
        }
    }
} 