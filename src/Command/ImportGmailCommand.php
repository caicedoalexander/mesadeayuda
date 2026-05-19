<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\Exception\GmailNotConfiguredException;
use App\Service\GmailImportService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;
use Throwable;

/**
 * ImportGmail command
 *
 * Wrapper CLI sobre GmailImportService (debug manual).
 * Usage: bin/cake import_gmail [--max 50] [--query 'is:unread'] [--delay 1000]
 */
class ImportGmailCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->setDescription('Import emails from Gmail and create tickets (debug CLI for GmailImportService)');
        $parser->addOption('max', ['short' => 'm', 'help' => 'Maximum messages', 'default' => 50]);
        $parser->addOption('query', [
            'help' => 'Gmail search query — overrides M-2 history.list checkpoint (MANUAL_OVERRIDE mode). '
                   . 'Omit to use the checkpoint state machine.',
        ]);
        $parser->addOption('delay', ['short' => 'd', 'help' => 'Delay between messages (ms)', 'default' => 1000]);

        return $parser;
    }

    /**
     * @param \Cake\Console\Arguments $args Command arguments
     * @param \Cake\Console\ConsoleIo $io Console io
     * @return int|null Exit code
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $max = (int)$args->getOption('max');
        $queryRaw = $args->getOption('query');
        $queryOverride = is_string($queryRaw) && $queryRaw !== '' ? $queryRaw : null;
        $delay = (int)$args->getOption('delay');

        $queryDisplay = $queryOverride ?? '(checkpoint)';
        $io->out("Gmail import — max={$max}, query='{$queryDisplay}', delay={$delay}ms");
        $io->hr();

        try {
            $result = GmailImportService::fromSettings()->run($max, $queryOverride, $delay);
        } catch (GmailNotConfiguredException $e) {
            $io->error($e->getMessage());

            return self::CODE_ERROR;
        } catch (Throwable $e) {
            $io->error('Fatal error: ' . $e->getMessage());
            Log::error('Gmail import fatal error', ['error' => $e->getMessage()]);

            return self::CODE_ERROR;
        }

        $io->hr();
        $io->out('Import completed!');
        $io->out("  Fetched:  {$result->fetched}");
        $io->out("  Created:  {$result->created}");
        $io->out("  Comments: {$result->comments}");
        $io->out("  Skipped:  {$result->skipped}");
        $io->out("  Errors:   {$result->errors}");
        $io->out('  Duration: ' . round($result->durationSeconds, 2) . 's');

        if ($result->errors > 0) {
            $io->warning('Errors during import:');
            foreach ($result->errorMessages as $msg) {
                $io->warning('  - ' . $msg);
            }
        }

        return self::CODE_SUCCESS;
    }
}
