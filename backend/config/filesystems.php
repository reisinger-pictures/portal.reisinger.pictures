<?php

$photoStoragePath = env('PHOTO_STORAGE_PATH', base_path('../photos'));

/*
 * Keep the local default absolute, but never let an explicitly configured
 * empty or relative value silently resolve against the process directory.
 */
if (! is_string($photoStoragePath) || trim($photoStoragePath) === '' || ! str_starts_with($photoStoragePath, '/')) {
    throw new InvalidArgumentException('PHOTO_STORAGE_PATH must be a non-empty absolute path.');
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'photos' => [
            'driver' => 'local',
            'root' => $photoStoragePath,
            'throw' => false,
        ],

        'ftp_inbox' => [
            'driver' => 'local',
            'root' => env('FTP_STORAGE_PATH', base_path('../ftp')),
            'throw' => false,
        ],

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Shared Temp Directory
    |--------------------------------------------------------------------------
    |
    | Scratch directory for downloads, archives and in-flight processing.
    | It is deliberately configurable so a test can point a single consumer
    | at its own directory: the default is a single absolute path, and
    | paratest runs separate test classes in separate worker processes, so a
    | flat namespace lets concurrently running classes overwrite each
    | other's files.
    |
    | Each consumer additionally gets its own subdirectory, resolved through
    | App\Support\TempDirectory. The keys here are the consumer identifiers
    | that class passes in; the values are the on-disk folder names.
    |
    */

    'temp_dir' => storage_path('app/private/temp'),

    'temp_subdirs' => [
        'import_locations' => 'import-locations',
        'photo_download' => 'photo-download',
        'ai' => 'ai',
    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
