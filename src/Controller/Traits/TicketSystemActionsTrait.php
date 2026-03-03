<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use Cake\Http\Response;

/**
 * TicketSystemActionsTrait
 *
 * Individual entity action methods: assign, change status/priority, add comment, download.
 * Extracted from TicketSystemControllerTrait for SRP compliance.
 */
trait TicketSystemActionsTrait
{
    /**
     * Assign an entity to a user.
     *
     * @param string $entityType The entity type (ticket, compra, pqrs).
     * @param int $entityId The entity ID.
     * @param mixed $assigneeId The assignee user ID.
     * @param string $redirectAction The action to redirect to.
     * @return \Cake\Http\Response
     */
    protected function assignEntity(
        string $entityType,
        int $entityId,
        $assigneeId,
        string $redirectAction = 'index'
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $assigneeId = $this->normalizeAssigneeId($assigneeId);
        $userId = $this->getCurrentUserId();

        if ($entityType === 'ticket') {
            $entity = $this->Tickets->get($entityId);
            $service = $this->ticketService;
            $entityName = 'Ticket';
        } elseif ($entityType === 'compra') {
            $entity = $this->Compras->get($entityId);
            $service = $this->comprasService;
            $entityName = 'Compra';
        } else {
            $entity = $this->Pqrs->get($entityId);
            $service = $this->pqrsService;
            $entityName = 'PQRS';
        }

        if ($this->isEntityLocked($entityType, $entity)) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $service->assign($entity, $assigneeId, $userId);
        if ($result) {
            $this->Flash->success(__("{$entityName} asignada correctamente."));
        } else {
            $this->Flash->error(__("No se pudo asignar la {$entityName}."));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    /**
     * Change the status of an entity.
     *
     * @param string $entityType The entity type (ticket, compra, pqrs).
     * @param int $entityId The entity ID.
     * @param string $newStatus The new status value.
     * @param string $redirectAction The action to redirect to.
     * @return \Cake\Http\Response
     */
    protected function changeEntityStatus(
        string $entityType,
        int $entityId,
        string $newStatus,
        string $redirectAction = 'index'
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        if ($entityType === 'ticket') {
            $entity = $this->Tickets->get($entityId);
            $service = $this->ticketService;
            $entityName = 'Ticket';
        } elseif ($entityType === 'compra') {
            $entity = $this->Compras->get($entityId);
            $service = $this->comprasService;
            $entityName = 'Compra';
        } else {
            $entity = $this->Pqrs->get($entityId);
            $service = $this->pqrsService;
            $entityName = 'PQRS';
        }

        if ($this->isEntityLocked($entityType, $entity)) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $service->changeStatus($entity, $newStatus, $userId);
        if ($result['success']) {
            $this->Flash->success($result['message'] ?? __("Estado de {$entityName} actualizado."));
        } else {
            $this->Flash->error($result['message'] ?? __("Error al cambiar el estado de {$entityName}."));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    /**
     * Change the priority of an entity.
     *
     * @param string $entityType The entity type (ticket, compra, pqrs).
     * @param int $entityId The entity ID.
     * @param string $newPriority The new priority value.
     * @param string $redirectAction The action to redirect to.
     * @return \Cake\Http\Response
     */
    protected function changeEntityPriority(
        string $entityType,
        int $entityId,
        string $newPriority,
        string $redirectAction = 'index'
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        if ($entityType === 'ticket') {
            $entity = $this->Tickets->get($entityId);
            $service = $this->ticketService;
            $entityName = 'Ticket';
        } elseif ($entityType === 'compra') {
            $entity = $this->Compras->get($entityId);
            $service = $this->comprasService;
            $entityName = 'Compra';
        } else {
            $entity = $this->Pqrs->get($entityId);
            $service = $this->pqrsService;
            $entityName = 'PQRS';
        }

        if ($this->isEntityLocked($entityType, $entity)) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $service->changePriority($entity, $newPriority, $userId);
        if ($result) {
            $this->Flash->success(__("Prioridad de {$entityName} actualizada."));
        } else {
            $this->Flash->error(__("Error al cambiar la prioridad de {$entityName}."));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    /**
     * Add a comment/response to an entity.
     *
     * @param string $entityType The entity type (ticket, compra, pqrs).
     * @param int $entityId The entity ID.
     * @return \Cake\Http\Response
     */
    protected function addEntityComment(string $entityType, int $entityId): Response
    {
        $this->request->allowMethod(['post']);
        $userId = $this->getCurrentUserId();

        $entityName = match ($entityType) {
            'ticket' => 'Ticket',
            'compra' => 'Compra',
            default => 'PQRS',
        };

        $data = $this->request->getData();
        $files = $this->request->getUploadedFiles();

        $result = $this->responseService->processResponse(
            $entityType,
            $entityId,
            $userId,
            $data,
            $files
        );

        if ($result['success']) {
            $this->Flash->success($result['message'] ?? __("Comentario agregado a {$entityName}."));
        } else {
            $this->Flash->error($result['message'] ?? __("Error al agregar comentario a {$entityName}."));
        }

        return $this->redirect(['action' => 'view', $entityId]);
    }

    /**
     * Download an attachment for an entity.
     *
     * @param string $entityType The entity type (ticket, compra, pqrs).
     * @param int $attachmentId The attachment ID.
     * @return \Cake\Http\Response
     */
    protected function downloadEntityAttachment(string $entityType, int $attachmentId): Response
    {
        $tableMap = [
            'ticket' => 'Attachments',
            'compra' => 'ComprasAttachments',
            'pqrs' => 'PqrsAttachments',
        ];

        $tableName = $tableMap[$entityType] ?? 'Attachments';
        $attachmentsTable = $this->fetchTable($tableName);
        $attachment = $attachmentsTable->get($attachmentId);
        $filePath = $this->getFullPath($attachment);

        if (!$filePath || !file_exists($filePath)) {
            throw new \Cake\Http\Exception\NotFoundException('Archivo no encontrado.');
        }

        return $this->response
            ->withFile($filePath, ['download' => true, 'name' => $attachment->original_filename])
            ->withType($attachment->mime_type ?? 'application/octet-stream');
    }

    /**
     * Get the current authenticated user ID.
     *
     * @return int
     * @throws \RuntimeException If no authenticated user.
     */
    protected function getCurrentUserId(): int
    {
        $user = $this->Authentication->getIdentity();
        if (!$user) {
            throw new \RuntimeException('No authenticated user');
        }

        return (int)$user->get('id');
    }

    /**
     * Normalize an assignee ID value to int or null.
     *
     * @param mixed $value The raw assignee value.
     * @return int|null
     */
    protected function normalizeAssigneeId($value): ?int
    {
        if ($value === '' || $value === null || $value === '0' || $value === 0) {
            return null;
        }

        return (int)$value;
    }

    /**
     * Handle a service result array and redirect.
     *
     * @param array $result The service result with 'success' and 'message' keys.
     * @param string $redirectUrl The URL to redirect to.
     * @return \Cake\Http\Response
     */
    protected function handleServiceResult(array $result, string $redirectUrl): Response
    {
        if (!empty($result['success'])) {
            $this->Flash->success($result['message'] ?? 'Operación exitosa.');
        } else {
            $this->Flash->error($result['message'] ?? 'Error en la operación.');
        }

        return $this->redirect($redirectUrl);
    }
}
