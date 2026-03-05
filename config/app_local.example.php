<?php

use function Cake\Core\env;

/*
 * Local configuration file to provide any overrides to your app.php configuration.
 * Copy and save this file as app_local.php and make changes as required.
 * Note: It is not recommended to commit files with credentials such as app_local.php
 * into source code version control.
 */
return [
    /*
     * Debug Level:
     *
     * Production Mode:
     * false: No error messages, errors, or warnings shown.
     *
     * Development Mode:
     * true: Errors and warnings shown.
     */
    'debug' => filter_var(env('DEBUG', true), FILTER_VALIDATE_BOOLEAN),

    /*
     * Security and encryption configuration
     *
     * - salt - A random string used in security hashing methods.
     *   The salt value is also used as the encryption key.
     *   You should treat it as extremely sensitive data.
     */
    'Security' => [
        'salt' => env('SECURITY_SALT', '__SALT__'),
    ],

    /*
     * Connection information used by the ORM to connect
     * to your application's datastores.
     *
     * IMPORTANT: For Docker/production deployments, this file should use
     * environment variables. The values below are defaults for local development.
     *
     * Environment variables:
     * - DB_HOST: Database host (default: localhost)
     * - DB_PORT: Database port (default: 3306)
     * - DB_USERNAME: Database username
     * - DB_PASSWORD: Database password
     * - DB_DATABASE: Database name
     *
     * See app.php for more configuration options.
     */
    'Datasources' => [
        'default' => [
            'driver' => Cake\Database\Driver\Mysql::class,
            'host' => env('DB_HOST'),
            'port' => env('DB_PORT'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'database' => env('DB_DATABASE'),

            /*
             * If not using the default 'public' schema with the PostgreSQL driver
             * set it here.
             */
            //'schema' => 'myapp',

            /*
             * You can use a DSN string to set the entire configuration
             */
            'url' => env('DATABASE_URL', null),
        ],

        /*
         * The test connection is used during the test suite.
         */
        'test' => [
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 3306),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'database' => env('DB_DATABASE', 'test_soporte'),
            //'schema' => 'myapp',
            'url' => env('DATABASE_TEST_URL', 'sqlite://127.0.0.1/tmp/tests.sqlite'),
        ],
    ],

    /*
     * Email configuration.
     *
     * Host and credential configuration in case you are using SmtpTransport
     *
     * See app.php for more configuration options.
     */
    'EmailTransport' => [
        'default' => [
            'host' => 'localhost',
            'port' => 25,
            'username' => null,
            'password' => null,
            'client' => null,
            'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
        ],
    ],

    /*
     * AWS S3 Configuration
     *
     * Configuration for AWS S3 file storage integration.
     * Set AWS_S3_ENABLED=true to enable S3 storage.
     *
     * Environment variables:
     * - AWS_ACCESS_KEY_ID: AWS IAM user access key
     * - AWS_SECRET_ACCESS_KEY: AWS IAM user secret key
     * - AWS_REGION: AWS region (e.g., us-east-1)
     * - AWS_S3_BUCKET: S3 bucket name
     * - AWS_S3_ENABLED: Enable/disable S3 storage (true/false)
     */
    'AWS' => [
        'S3' => [
            'enabled' => filter_var(env('AWS_S3_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'key' => env('AWS_ACCESS_KEY_ID', ''),
            'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            'region' => env('AWS_REGION', ''),
            'bucket' => env('AWS_S3_BUCKET', ''),
        ],
    ],
];
