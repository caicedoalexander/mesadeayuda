<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * Resultado inmutable de una corrida del import de Gmail.
 *
 * Reemplaza la salida por consola del comando con datos estructurados
 * que pueden serializarse a JSON para la respuesta del webhook.
 */
final readonly class GmailImportResult
{
    /**
     * @param list<string> $errorMessages Mensajes de errores no fatales por mensaje individual
     */
    public function __construct(
        public int $fetched,
        public int $created,
        public int $comments,
        public int $skipped,
        public int $errors,
        public float $durationSeconds,
        public array $errorMessages = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fetched' => $this->fetched,
            'created' => $this->created,
            'comments' => $this->comments,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'duration_seconds' => round($this->durationSeconds, 3),
            'error_messages' => $this->errorMessages,
        ];
    }
}
