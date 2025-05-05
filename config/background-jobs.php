<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default connection information for your Redis
    | queue workers. This connection will be used by default.
    |
    */
    'redis_connection' => env('BGJ_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Default Queue Name
    |--------------------------------------------------------------------------
    |
    | Default queue name for jobs pushed without specifying a queue.
    |
    */
    'default_queue' => env('BGJ_DEFAULT_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'log_channel' => env('BGJ_LOG_CHANNEL', null), // Default to Laravel's default log channel if null

     /*
    |--------------------------------------------------------------------------
    | Prefix for all Redis keys used by this library.
    |--------------------------------------------------------------------------
    */
    'key_prefix' => env('BGJ_KEY_PREFIX', 'bgj:'), // Example prefix
]; 