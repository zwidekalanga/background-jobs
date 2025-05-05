<?php

namespace App\Library\BackgroundJobs\Console\Commands;

use App\Library\BackgroundJobs\Services\RedisQueue;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'bgj:status
                            {queue? : The name of the queue to check status}
                            {--all : Show status for all queues}
                            {--watch : Watch mode, refreshes every 5 seconds}';

    protected $description = 'Display the current status of BackgroundJobs queues';

    protected RedisQueue $redisQueue;

    public function __construct(RedisQueue $redisQueue)
    {
        parent::__construct();
        $this->redisQueue = $redisQueue;
    }

    public function handle(): int
    {
        $allQueues = $this->option('all');
        $queueName = $this->argument('queue');
        $watchMode = $this->option('watch');
        
        if (!$allQueues && !$queueName) {
            $queueName = config('background-jobs.default_queue');
            $queueNames = [$queueName];
        } elseif ($allQueues) {
            $queueNames = $this->getQueueNames();
            
            if (empty($queueNames)) {
                $this->warn('No queues found.');
                return Command::SUCCESS;
            }
        } else {
            $queueNames = [$queueName];
        }
        
        if ($watchMode) {
            // Clear console and move cursor to top-left in watch mode
            $this->runWatchMode($queueNames);
        } else {
            $this->displayQueueStatus($queueNames);
        }

        return Command::SUCCESS;
    }
    
    protected function runWatchMode(array $queueNames): void
    {
        $this->info('Press Ctrl+C to exit watch mode.');
        $this->newLine();
        
        while (true) {
            // Clear screen and move cursor to top-left
            $this->output->write("\033[2J\033[1;1H");
            
            $this->info('Background Jobs Queue Status - ' . now()->format('Y-m-d H:i:s'));
            $this->newLine();
            
            $this->displayQueueStatus($queueNames);
            
            sleep(5);
        }
    }

    protected function displayQueueStatus(array $queueNames): void
    {
        $headers = ['Queue', 'Pending', 'Processing', 'Delayed', 'Failed', 'Total'];
        $rows = [];
        
        foreach ($queueNames as $queueName) {
            $stats = $this->redisQueue->getQueueStats($queueName);
            
            if (isset($stats['error'])) {
                $this->error("Error getting stats for queue {$queueName}: {$stats['error']}");
                continue;
            }
            
            $rows[] = [
                $queueName,
                $stats['pending'],
                $stats['processing'],
                $stats['delayed'],
                $stats['failed'],
                $stats['total'],
            ];
        }
        
        $this->table($headers, $rows);
    }

    /**
     * Get a list of all available queue names.
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