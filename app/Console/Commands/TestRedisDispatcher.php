<?php

namespace App\Console\Commands;

use App\Library\BackgroundJobs\Services\RedisDispatcher;
use App\Library\BackgroundJobs\Services\RedisQueue;
use Illuminate\Console\Command;

class TestRedisDispatcher extends Command
{
    protected $signature = 'bgj:test-redis';

    protected $description = 'Test direct Redis interaction for the queue system';

    protected RedisDispatcher $dispatcher;
    protected RedisQueue $queue;

    public function __construct(RedisDispatcher $dispatcher, RedisQueue $queue)
    {
        parent::__construct();
        $this->dispatcher = $dispatcher;
        $this->queue = $queue;
    }

    public function handle(): int
    {
        $this->info('Testing Redis Connectivity...');
        
        try {
            $redis = $this->queue->getRedis();
            $pingResult = $redis->ping();
            $this->info("Redis ping result: " . $pingResult);
            
            // Check Redis version and extension info
            $this->info("Redis extension version: " . phpversion('redis'));
            $this->info("Redis server info:");
            $info = $redis->info();
            $this->info("  Redis version: " . ($info['redis_version'] ?? 'unknown'));
            
            // Test basic key setting and getting
            $testKey = 'bgj:test:' . uniqid();
            $testValue = 'Test value at ' . now()->toDateTimeString();
            
            $redis->set($testKey, $testValue);
            $retrievedValue = $redis->get($testKey);
            
            $this->info("Test key: {$testKey}");
            $this->info("Set value: {$testValue}");
            $this->info("Retrieved value: {$retrievedValue}");
            
            // Clean up
            $redis->del($testKey);
            
            // Test queue operations
            $this->info("\nTesting Queue Operations...");
            
            // Direct push to queue
            $queueName = 'testing';
            $payload = json_encode([
                'uuid' => uniqid(),
                'displayName' => 'TestJob',
                'data' => [
                    'command' => [
                        'class' => 'App\\Jobs\\TestBackgroundJob',
                        'args' => ['Direct test payload']
                    ]
                ]
            ]);
            
            $pushResult = $this->queue->push($payload, $queueName);
            $this->info("Push result: {$pushResult}");
            
            // Check queue status
            $stats = $this->queue->getQueueStats($queueName);
            $this->info("Queue stats for '{$queueName}':");
            foreach ($stats as $key => $value) {
                $this->info("  {$key}: {$value}");
            }
            
            // Try a safer approach to pop and acknowledge
            $pendingKey = $this->queue->getPendingKey($queueName);
            $processingKey = $this->queue->getProcessingKey($queueName);
            
            $this->info("\nPending key: {$pendingKey}");
            $this->info("Processing key: {$processingKey}");
            
            // Check what's in the queue
            $this->info("\nQueue contents:");
            $pendingItems = $redis->lRange($pendingKey, 0, -1);
            $this->info("  Pending items: " . count($pendingItems));
            foreach ($pendingItems as $i => $item) {
                $this->info("    Item {$i}: " . substr($item, 0, 50) . "...");
            }
            
            $processingItems = $redis->lRange($processingKey, 0, -1);
            $this->info("  Processing items: " . count($processingItems));
            foreach ($processingItems as $i => $item) {
                $this->info("    Item {$i}: " . substr($item, 0, 50) . "...");
            }
            
            // Attempt a safe lRem operation
            if (!empty($processingItems)) {
                $this->info("\nAttempting to remove first processing item manually...");
                $itemToRemove = $processingItems[0];
                $this->info("  Item type: " . gettype($itemToRemove));
                $this->info("  Item length: " . strlen($itemToRemove));
                
                $count = 0;
                try {
                    // Try directly with Redis extension
                    $count = $redis->lRem($processingKey, 1, $itemToRemove);
                    $this->info("  Manual lRem result: {$count}");
                } catch (\Throwable $e) {
                    $this->error("  Manual lRem failed: " . $e->getMessage());
                }
            }
            
            $this->info("\nAll tests completed!");
            
        } catch (\Throwable $e) {
            $this->error("Redis test failed: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
} 