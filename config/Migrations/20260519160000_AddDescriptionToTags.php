<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class AddDescriptionToTags extends BaseMigration
{
    /**
     * Adds an optional text description column to the tags table so the
     * admin UI can persist context and n8n's auto-tagging LLM has richer
     * signal when classifying tickets.
     *
     * @return void
     */
    public function change(): void
    {
        $this->table('tags')
            ->addColumn('description', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'color',
                'comment' => 'Human-readable description sent to n8n/LLM as auto-tagging context',
            ])
            ->update();
    }
}
