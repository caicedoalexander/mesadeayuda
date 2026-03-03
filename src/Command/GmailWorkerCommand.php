<?php
declare(strict_types=1);

namespace App\Command;

use App\Utility\SettingsEncryptionTrait;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Log\Log;
use Cake\Console\Exception\StopException;
use Cake\Datasource\ConnectionManager;

/**
 * GmailWorker command
 *
 * Continuously runs Gmail import at configured intervals
 * Designed for Docker worker container
 *
 * Usage: bin/cake gmail_worker
 */
class GmailWorkerCommand extends Command
{
    use LocatorAwareTrait;
    use SettingsEncryptionTrait;

    private bool $shouldStop = false;

    private const MIN_RETRY_SECONDS = 60;
    private const MAX_RETRY_SECONDS = 600;

    /**
     * Hook method for defining this command's option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser->setDescription('Continuously import emails from Gmail at configured intervals (for Docker worker)');

        $parser->addOption('once', [
            'help' => 'Run import once and exit (for testing)',
            'boolean' => true,
            'default' => false,
        ]);

        return $parser;
    }

    /**
     * Main execution method for the worker
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $runOnce = $args->getOption('once');

        $io->out('Gmail Worker Starting...');
        $io->out('Press CTRL+C to stop');
        $io->hr();

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers($io);

        // Check if worker is enabled
        if (!$this->isWorkerEnabled()) {
            $io->warning('Worker is disabled via WORKER_ENABLED environment variable');
            return self::CODE_ERROR;
        }

        // Wait for database connectivity before entering main loop
        if (!$this->waitForDatabase($io)) {
            $io->error('Could not connect to database after multiple attempts. Exiting.');
            return self::CODE_ERROR;
        }

        $iteration = 0;
        $consecutiveErrors = 0;

        // Main worker loop
        while (!$this->shouldStop) {
            $iteration++;
            $startTime = microtime(true);

            $io->out('[' . date('Y-m-d H:i:s') . "] Iteration #{$iteration}");

            try {
                // Get interval from settings (in minutes)
                $intervalMinutes = $this->getImportInterval();
                $io->verbose("  Import interval: {$intervalMinutes} minutes");

                // Check if Gmail is configured
                if (!$this->isGmailConfigured()) {
                    $io->warning('  Gmail OAuth not configured yet. Skipping import.');
                    $io->out('  Configure Gmail at /admin/settings before starting the worker.');
                    $io->out('  Worker will continue checking every ' . $intervalMinutes . ' minutes.');
                } else {
                    // Execute the import
                    $io->out('  Running Gmail import...');
                    $result = $this->executeImport($io);

                    if ($result === self::CODE_SUCCESS) {
                        $io->success('  Import completed successfully');
                    } else {
                        $io->warning('  Import completed with errors');
                    }
                }

                // Reset backoff on successful iteration
                $consecutiveErrors = 0;

                $duration = round(microtime(true) - $startTime, 2);
                $io->verbose("  Duration: {$duration}s");

                // If --once flag is set, exit after first run
                if ($runOnce) {
                    $io->out('Running in once mode, exiting...');
                    break;
                }

                // Calculate wait time
                $waitSeconds = $intervalMinutes * 60;
                $nextRun = date('Y-m-d H:i:s', time() + $waitSeconds);

                $io->out("  Next import at: {$nextRun}");
                $io->hr();

                // Sleep in small increments to allow signal handling
                $this->interruptibleSleep($waitSeconds);
            } catch (StopException $e) {
                $io->out('Worker stopped by user');
                break;
            } catch (\Exception $e) {
                $consecutiveErrors++;
                $io->error("  Worker error: {$e->getMessage()}");
                Log::error('Gmail worker error', [
                    'iteration' => $iteration,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                // Exponential backoff: 60s, 120s, 240s, 480s, capped at 600s
                $retrySeconds = min(
                    self::MIN_RETRY_SECONDS * (2 ** ($consecutiveErrors - 1)),
                    self::MAX_RETRY_SECONDS
                );
                $io->out("  Waiting {$retrySeconds} seconds before retry (attempt #{$consecutiveErrors})...");
                $this->interruptibleSleep((int)$retrySeconds);
            }
        }

        $io->out('Gmail Worker Stopped');
        return self::CODE_SUCCESS;
    }

    /**
     * Register POSIX signal handlers for graceful shutdown
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return void
     */
    private function registerSignalHandlers(ConsoleIo $io): void
    {
        if (!function_exists('pcntl_signal')) {
            $io->verbose('pcntl extension not available, signal handling disabled');
            return;
        }

        pcntl_signal(SIGTERM, function () use ($io) {
            $io->out('');
            $io->out('Received SIGTERM, shutting down gracefully...');
            $this->shouldStop = true;
        });

        pcntl_signal(SIGINT, function () use ($io) {
            $io->out('');
            $io->out('Received SIGINT, shutting down gracefully...');
            $this->shouldStop = true;
        });

        pcntl_async_signals(true);
    }

    /**
     * Sleep in 1-second increments to allow signal processing
     *
     * @param int $seconds Total seconds to sleep
     * @return void
     */
    private function interruptibleSleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->shouldStop; $i++) {
            sleep(1);
            $triggerFile = TMP . 'gmail_worker_trigger';
            if (file_exists($triggerFile)) {
                @unlink($triggerFile);
                break;
            }
        }
    }

    /**
     * Wait for database connectivity with retries
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return bool True if connected, false if all retries exhausted
     */
    private function waitForDatabase(ConsoleIo $io): bool
    {
        $maxAttempts = 10;
        $waitSeconds = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->shouldStop) {
                return false;
            }

            try {
                /** @var \Cake\Database\Connection $connection */
                $connection = ConnectionManager::get('default');
                $connection->execute('SELECT 1');
                $io->success('Database connection established');
                return true;
            } catch (\Exception $e) {
                $io->warning("Database connection attempt {$attempt}/{$maxAttempts} failed: {$e->getMessage()}");
                if ($attempt < $maxAttempts) {
                    $io->out("  Retrying in {$waitSeconds} seconds...");
                    $this->interruptibleSleep($waitSeconds);
                }
            }
        }

        return false;
    }

    /**
     * Execute the import command
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int Exit code
     */
    private function executeImport(ConsoleIo $io): int
    {
        $command = new ImportGmailCommand();
        $command->initialize();

        // Create arguments with default options
        $args = new Arguments([], ['max' => '50', 'query' => 'is:unread', 'delay' => '1000'], []);

        return $command->execute($args, $io) ?? self::CODE_SUCCESS;
    }

    /**
     * Check if Gmail OAuth is configured
     *
     * @return bool
     */
    private function isGmailConfigured(): bool
    {
        $settingsTable = $this->fetchTable('SystemSettings');

        // Check for refresh token (required for OAuth)
        $refreshTokenSetting = $settingsTable->find()
            ->where(['setting_key' => 'gmail_refresh_token'])
            ->first();

        if (!$refreshTokenSetting || empty($refreshTokenSetting->setting_value)) {
            return false;
        }

        // Decrypt the refresh token to verify it's valid
        try {
            $decryptedToken = $this->shouldEncrypt('gmail_refresh_token')
                ? $this->decryptSetting($refreshTokenSetting->setting_value, 'gmail_refresh_token')
                : $refreshTokenSetting->setting_value;

            if (empty($decryptedToken)) {
                return false;
            }
        } catch (\Exception $e) {
            // Decryption failed, token is invalid
            Log::error('Failed to decrypt Gmail refresh token', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Check for client_secret.json file
        $clientSecretPath = $settingsTable->find()
            ->where(['setting_key' => 'gmail_client_secret_path'])
            ->first();

        if (!$clientSecretPath || empty($clientSecretPath->setting_value)) {
            return false;
        }

        if (!file_exists($clientSecretPath->setting_value)) {
            return false;
        }

        return true;
    }

    /**
     * Get import interval from system settings
     *
     * @return int Interval in minutes
     */
    private function getImportInterval(): int
    {
        $settingsTable = $this->fetchTable('SystemSettings');

        $setting = $settingsTable->find()
            ->where(['setting_key' => 'gmail_check_interval'])
            ->first();

        if ($setting) {
            $interval = (int) $setting->setting_value;
            // Ensure minimum interval of 1 minute
            return max(1, $interval);
        }

        // Default to 5 minutes if not configured
        return 5;
    }

    /**
     * Check if worker is enabled via environment variable
     *
     * @return bool
     */
    private function isWorkerEnabled(): bool
    {
        $enabled = env('WORKER_ENABLED', 'true');

        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }
}
