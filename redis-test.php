<?php

echo "Testing Redis connection...\n";

try {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    echo "Connected to Redis\n";
    
    // Test basic operations
    $redis->set('test_key', 'test_value');
    $value = $redis->get('test_key');
    echo "Retrieved value: {$value}\n";
    
    // Test blocking operations (similar to what's failing)
    echo "Testing blocking operation (BLPOP)...\n";
    
    // Push an item so we can pop it immediately
    $redis->lpush('test_list', 'test_item');
    
    // Try blocking pop with a short timeout
    $result = $redis->blpop(['test_list'], 1);
    echo "BLPOP result: " . print_r($result, true) . "\n";
    
    echo "All Redis tests completed successfully!\n";
    
} catch (Exception $e) {
    echo "Redis error: " . $e->getMessage() . "\n";
} 