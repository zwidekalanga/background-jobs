<?php

namespace App\Console\Commands;

use App\Jobs\TestBackgroundJob;
use App\Library\BackgroundJobs\Contracts\Dispatcher;
use Illuminate\Console\Command;

class DispatchTestJob extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bgj:dispatch-test
                            {--data= : Data to pass to the job}
                            {--queue= : Queue to dispatch to}
                            {--delay= : Delay in seconds before the job is processed}
                            {--sleep= : Seconds the job will sleep during execution}
                            {--fail : Make the job fail deliberately}
                            {--tries= : Number of retry attempts}
                            {--method= : Dispatch method: async or in (default: async)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a test job to the BackgroundJobs queue';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $data = [];
        
        // Base data
        if ($this->option('data')) {
            $data['message'] = $this->option('data');
        } else {
            $data['message'] = 'test data at ' . now()->toDateTimeString();
        }
        
        // Add sleep time if specified
        if ($this->option('sleep')) {
            $data['sleep'] = (int) $this->option('sleep');
        }
        
        // Add fail flag if specified
        if ($this->option('fail')) {
            $data['fail'] = true;
        }
        
        // Options for job configuration
        $options = [];
        
        if ($this->option('queue')) {
            $options['queue'] = $this->option('queue');
        }
        
        if ($this->option('tries')) {
            $options['tries'] = (int) $this->option('tries');
        }
        
        // Determine which dispatch method to use
        $method = $this->option('method') ?: 'async';
        $delay = $this->option('delay') ? (int) $this->option('delay') : null;
        
        switch ($method) {
            case 'in':
                if (!$delay) {
                    $this->error('You must specify a delay when using method=in');
                    return Command::FAILURE;
                }
                $jobId = TestBackgroundJob::perform_in($delay, $data, $options);
                $this->info("Job dispatched to be executed in {$delay} seconds with ID: {$jobId}");
                break;
                
            case 'async':
            default:
                $jobId = TestBackgroundJob::perform_async($data, $options);
                $this->info("Job dispatched for immediate processing with ID: {$jobId}");
                break;
        }
        
        $queueInfo = $options['queue'] ?? 'default';
        $this->info("Check job status with: php artisan bgj:status {$queueInfo}");
        
        return Command::SUCCESS;
    }
}
