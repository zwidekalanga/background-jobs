<?php

namespace App\Library\BackgroundJobs\Console\Commands;

use App\Library\BackgroundJobs\Services\RedisQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverCommand extends Command
{
    protected $signature = 'bgj:recover
                            {queue? : The name of the queue to recover jobs from}
                            {--all : Recover from all queues}';

    protected $description = 'Recover jobs that are stuck in the processing state';

    protected RedisQueue $redisQueue;
    protected ?string $logChannel;

    public function __construct(RedisQueue $redisQueue)
    {
        parent::__construct();
        $this->redisQueue = $redisQueue;
        $this->logChannel = config('background-jobs.log_channel');
    }

    public function handle(): int
    {
        $allQueues = $this->option('all');
        $queueName = $this->argument('queue');

        if (!$allQueues && !$queueName) {
            $queueName = config('background-jobs.default_queue');
            $this->recoverFromQueue($queueName);
        } elseif ($allQueues) {
            // Get all queue names
            $queues = $this->getQueueNames();
            
            if (empty($queues)) {
                $this->warn('No queues found to recover from.');
                return Command::SUCCESS;
            }
            
            foreach ($queues as $queue) {
                $this->recoverFromQueue($queue);
            }
        } else {
            $this->recoverFromQueue($queueName);
        }

        return Command::SUCCESS;
    }

    protected function recoverFromQueue(string $queueName): void
    {
        $this->info("Recovering jobs from queue: {$queueName}");
        
        try {
            $count = $this->redisQueue->recoverStuckJobs($queueName);
            
            if ($count > 0) {
                $this->info("Recovered {$count} jobs from the processing list of queue: {$queueName}");
                Log::channel($this->logChannel)->info(
                    "Recovered {$count} jobs from the processing list",
                    ['queue' => $queueName]
                );
            } else {
                $this->info("No jobs to recover from queue: {$queueName}");
            }
        } catch (\Throwable $e) {
            $this->error("Error recovering jobs from queue {$queueName}: " . $e->getMessage());
            Log::channel($this->logChannel)->error(
                "Error recovering jobs",
                ['queue' => $queueName, 'error' => $e->getMessage()]
            );
        }
    }

    /**
     * Get a list of all available queue names.
     * This is a simple implementation that lists queue keys from Redis.
     */
    protected function getQueueNames(): array
    {
        try {
            // Get the queue key pattern from config
            $keyPrefix = config('background-jobs.key_prefix', 'bgj:');
            $pattern = $keyPrefix . 'queues:*:pending';
            
            // Use the Redis instance from RedisQueue
            $redis = $this->redisQueue->getRedis();
            $keys = $redis->keys($pattern);
            
            $queues = [];
            $prefix = $keyPrefix . 'queues:';
            $suffix = ':pending';
            
            foreach ($keys as $key) {
                if (str_starts_with($key, $prefix) && str_ends_with($key, $suffix)) {
                    $queueName = substr($key, strlen($prefix), -strlen($suffix));
                    $queues[] = $queueName;
                }
            }
            
            return array_unique($queues);
        } catch (\Throwable $e) {
            $this->error("Error getting queue names: " . $e->getMessage());
            return [];
        }
    }
} 