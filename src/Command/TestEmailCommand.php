<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\EmailService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Exception;

class TestEmailCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->addArgument('ticket_id', [
            'help' => 'Ticket ID to test',
            'required' => true,
        ]);

        return $parser;
    }

    /**
     * @param \Cake\Console\Arguments $args Command arguments
     * @param \Cake\Console\ConsoleIo $io Console io
     * @return int|null Exit code
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $ticketId = (int)$args->getArgument('ticket_id');
        $io->out("Testing email for ticket ID: $ticketId");

        try {
            $emailService = new EmailService();
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticketId); // Don't contain here, let service do it

            $io->out('Ticket found: ' . $ticket->ticket_number);

            $result = $emailService->sendNewEntityNotification($ticket);

            if ($result) {
                $io->success('Email sent successfully!');

                return self::CODE_SUCCESS;
            } else {
                $io->error('Failed to send email (returned false). Check logs.');

                return self::CODE_ERROR;
            }
        } catch (Exception $e) {
            $io->error('Exception caught: ' . $e->getMessage());
            $io->out($e->getTraceAsString());

            return self::CODE_ERROR;
        }
    }
}
