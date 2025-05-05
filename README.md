# Laravel Background Jobs

A Laravel package for handling background jobs using Redis. This package provides a robust job queuing system with features like delayed jobs, retries, monitoring, and job recovery.

## Features

- Redis-based job queue system with event-driven worker
- Multiple queues with priorities
- Delayed job scheduling with perform_in
- Automatic retries with configurable attempts and exponential backoff
- Job recovery for crashed workers
- Real-time monitoring via CLI
- Detailed logging

## Installation

1. Make sure you have the Redis PHP extension installed:
   ```bash
   brew install php-redis   # MacOS
   sudo apt install php-redis   # Ubuntu/Debian
   ```

2. Add the package to your Laravel project
   (This package is not published on Packagist yet, so it's included directly in your project)

3. Register the service provider in `config/app.php`:
   ```php
   'providers' => [
       // ...
       App\Providers\BackgroundJobsServiceProvider::class,
   ],
   ```

4. Publish the configuration (optional):
   ```bash
   php artisan vendor:publish --provider="App\Providers\BackgroundJobsServiceProvider"
   ```

## Usage

### Creating Jobs

Create a job class that implements the `App\Library\BackgroundJobs\Contracts\Job` interface and uses the `Queueable` trait:

```php
<?php

namespace App\Jobs;

use App\Library\BackgroundJobs\Contracts\Job;
use App\Library\BackgroundJobs\Concerns\Queueable;

class MyJob implements Job
{
    use Queueable;

    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
        $this->captureArguments([$data]); // Important for serialization
    }

    public function handle(): void
    {
        // Job processing logic here
    }
    
    // Optional: Handle failures
    public function failed(\Throwable $exception): void
    {
        // Handle job failure
    }
}
```

### Dispatching Jobs

You can dispatch jobs using the static methods provided by the `Queueable` trait:

```php
// Dispatch immediately
MyJob::perform_async($data, ['queue' => 'high_priority']);

// Dispatch after a delay (in seconds)
MyJob::perform_in(60, $data);
```

### Event-Driven Worker

Our worker uses an event-driven approach, which means it only processes jobs when they're available instead of continuously polling the queue:

1. When started, the worker checks if there are any jobs in the queue
2. If jobs are available, it processes them until the queue is empty
3. If no jobs are available, it sleeps for a configurable time before checking again
4. For delayed jobs, the worker applies the delay by sleeping the required time within the job processing method

This approach is more efficient than continuous polling and avoids the overhead of maintaining separate pending and delayed queues.

### Running Workers

To process jobs in the queue:

```bash
php artisan bgj:work
```

Worker options:
```
--queue=default    # Specify which queue to process
--tries=3          # Number of retry attempts
--sleep=3          # Sleep time when no jobs are available
--max-memory=128   # Memory limit in MB before worker restarts
--max-jobs=0       # Max jobs to process (0 = unlimited)
```

### Monitoring

View current queue status:

```bash
php artisan bgj:status
php artisan bgj:status --all    # Show all queues
php artisan bgj:status --watch  # Live updating view
```

### Job Recovery

Recover stuck jobs from crashed workers:

```bash
php artisan bgj:recover
php artisan bgj:recover --all   # Recover from all queues
```

### Testing

Test the queue by dispatching a sample job:

```bash
php artisan bgj:dispatch-test
```

Test options:
```
--data="test data"       # Custom data to pass to the job
--queue=high             # Target queue
--delay=30               # Delay in seconds
--sleep=5                # Job execution time
--fail                   # Force job to fail (for testing retries)
--tries=5                # Number of retry attempts
--method=async|in        # Dispatch method
```

## Configuration

Key configuration options in `config/background-jobs.php`:

```php
return [
    'key_prefix' => 'bgj:',
    'default_queue' => 'default',
    'log_channel' => 'background-jobs',
    'connection' => 'default', // Redis connection to use
];
```

## Architecture

The package follows a service-repository pattern with these key components:

- **Job Interface**: Defines the contract for all jobs
- **Queueable Trait**: Provides static dispatch methods and serialization
- **RedisQueue**: Handles Redis interactions for queue operations
- **RedisDispatcher**: Dispatches jobs to Redis
- **WorkCommand**: The event-driven worker that processes jobs
- **RecoverCommand**: Recovers stuck jobs
- **StatusCommand**: Monitors queue status

## Logging

All job processing, failures, and worker activities are logged to the configured channel. Configure this in `config/logging.php` by adding a custom channel:

```php
'background-jobs' => [
    'driver' => 'daily',
    'path' => storage_path('logs/background-jobs.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],
```
