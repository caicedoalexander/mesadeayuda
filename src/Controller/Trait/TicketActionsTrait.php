<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Service\Exception\InvalidStatusTransitionException;
use App\Service\Exception\UnauthorizedAssignmentException;
use App\Service\TicketPipelineService;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use LogicException;

/**
 * Single-entity action region for TicketsController:
 * assign, status/priority change, comment, attachment download, tag/follower mutations.
 */
trait TicketActionsTrait
{
    // region: Actions — public dispatchers

    /**
     * @param string|null $id Ticket id
     */
    public function addComment(?string $id = null)
    {
        return $this->addTicketComment((int)$id);
    }

    /**
     * @param string|null $id Ticket id
     */
    public function assign(?string $id = null)
    {
        return $this->assignTicket((int)$id, $this->request->getData('assignee_id'));
    }

    /**
     * @param string|null $id Ticket id
     */
    public function changeStatus(?string $id = null)
    {
        return $this->changeTicketStatus((int)$id, $this->request->getData('status'));
    }

    /**
     * @param string|null $id Ticket id
     */
    public function changePriority(?string $id = null)
    {
        return $this->changeTicketPriority((int)$id, $this->request->getData('priority'));
    }

    /**
     * @param string|null $id Ticket id
     */
    public function addTag(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $tagId = (int)$this->request->getData('tag_id');
        $result = $this->ticketPipeline->addTag((int)$id, $tagId, $this->getCurrentUserId());

        $this->Flash->{$result['success'] ? 'success' : ($result['message'] === TicketPipelineService::MESSAGE_TAG_ALREADY_ADDED ? 'warning' : 'error')}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Ticket id
     * @param string|null $tagId Tag id
     */
    public function removeTag(?string $id = null, ?string $tagId = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $result = $this->ticketPipeline->removeTag((int)$id, (int)$tagId, $this->getCurrentUserId());

        $this->Flash->{$result['success'] ? 'success' : 'error'}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Ticket id
     */
    public function addFollower(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $userId = (int)$this->request->getData('user_id');
        $result = $this->ticketPipeline->addFollower((int)$id, $userId, $this->getCurrentUserId());

        $this->Flash->{$result['success'] ? 'success' : ($result['message'] === 'Este usuario ya está siguiendo el ticket.' ? 'warning' : 'error')}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Attachment id
     */
    public function downloadAttachment(?string $id = null)
    {
        return $this->downloadTicketAttachment((int)$id);
    }

    // endregion

    // region: Actions — protected workhorses

    protected function assignTicket(
        int $entityId,
        $assigneeId,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $assigneeId = $this->normalizeAssigneeId($assigneeId);
        $userId = $this->getCurrentUserId();
        $actor = $this->Authentication->getIdentity();

        $entity = $this->fetchTable('Tickets')->get($entityId);

        // Early actor guard: better UX than tripping the service exception
        if ($this->authService->isAssignmentDisabled($actor)) {
            $this->Flash->error(__('No tienes permisos para asignar tickets.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        if ($entity->isLocked()) {
            $this->Flash->error(__('No se puede modificar una Ticket en estado final.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        try {
            $result = $this->ticketPipeline->assign($entity, $assigneeId, $userId, $actor);
        } catch (UnauthorizedAssignmentException $e) {
            $this->Flash->error($e->getMessage());

            return $this->redirect(['action' => $redirectAction]);
        }

        if ($result) {
            $this->Flash->success(__('Ticket asignada correctamente.'));
        } else {
            $this->Flash->error(__('No se pudo asignar la Ticket.'));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    /**
     * @param int $entityId Ticket id
     * @param string $newStatus New status value
     * @param string $redirectAction Action to redirect to on completion
     */
    protected function changeTicketStatus(
        int $entityId,
        string $newStatus,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        $entity = $this->fetchTable('Tickets')->get($entityId);

        if ($entity->isLocked()) {
            $this->Flash->error(__('No se puede modificar una Ticket en estado final.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        try {
            $result = $this->ticketPipeline->changeStatus($entity, $newStatus, $userId);
        } catch (InvalidStatusTransitionException $e) {
            $this->Flash->error(__('Transición de estado no permitida: {0}', [$e->getMessage()]));

            return $this->redirect(['action' => $redirectAction]);
        }
        if ($result) {
            $this->Flash->success(__('Estado de Ticket actualizado.'));
        } else {
            $this->Flash->error(__('Error al cambiar el estado de Ticket.'));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    /**
     * @param int $entityId Ticket id
     * @param string $newPriority New priority value
     * @param string $redirectAction Action to redirect to on completion
     */
    protected function changeTicketPriority(
        int $entityId,
        string $newPriority,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        $entity = $this->fetchTable('Tickets')->get($entityId);

        if ($entity->isLocked()) {
            $this->Flash->error(__('No se puede modificar una Ticket en estado final.'));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $this->ticketPipeline->changePriority($entity, $newPriority, $userId);
        if ($result) {
            $this->Flash->success(__('Prioridad de Ticket actualizada.'));
        } else {
            $this->Flash->error(__('Error al cambiar la prioridad de Ticket.'));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    /**
     * @param int $entityId Ticket id
     */
    protected function addTicketComment(int $entityId): Response
    {
        $this->request->allowMethod(['post']);
        $userId = $this->getCurrentUserId();

        $data = $this->request->getData();
        $files = $this->request->getUploadedFiles();

        $result = $this->ticketPipeline->handleResponse($entityId, $userId, $data, $files);

        if ($result['success']) {
            $this->Flash->success($result['message'] ?? __('Comentario agregado a Ticket.'));
        } else {
            $this->Flash->error($result['message'] ?? __('Error al agregar comentario a Ticket.'));
        }

        return $this->redirect(['action' => 'view', $entityId]);
    }

    /**
     * @param int $attachmentId Attachment id
     */
    protected function downloadTicketAttachment(int $attachmentId): Response
    {
        $attachmentsTable = $this->fetchTable('Attachments');
        $attachment = $attachmentsTable->get($attachmentId);
        $filePath = $this->getFullPath($attachment);

        if (!$filePath || !file_exists($filePath)) {
            throw new NotFoundException('Archivo no encontrado.');
        }

        return $this->response
            ->withFile($filePath, ['download' => true, 'name' => $attachment->original_filename])
            ->withType($attachment->mime_type ?? 'application/octet-stream');
    }

    /**
     * @return int
     */
    protected function getCurrentUserId(): int
    {
        $user = $this->Authentication->getIdentity();
        if (!$user) {
            throw new LogicException('No authenticated user');
        }

        return (int)$user->get('id');
    }

    protected function normalizeAssigneeId($value): ?int
    {
        if ($value === '' || $value === null || $value === '0' || $value === 0) {
            return null;
        }

        return (int)$value;
    }

    /**
     * @param array $result Service result with success/message keys
     * @param string $redirectUrl URL to redirect to
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

    // endregion
}
