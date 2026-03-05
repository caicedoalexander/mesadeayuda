<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use App\Service\AuthorizationService;
use App\Utility\ValidationConstants;

/**
 * TicketSystemViewTrait
 *
 * Single entity view method and its helpers.
 * Extracted from TicketSystemListingTrait for SRP compliance.
 */
trait TicketSystemViewTrait
{
    protected function viewEntity(string $entityType, int $id, array $config = []): ?\Cake\Http\Response
    {
        $components = $this->getEntityComponents($entityType);
        $tableName = $components['tableName'];
        $variableName = $this->getSingleEntityVariable($entityType);
        $contain = $config['contain'] ?? $this->getDefaultViewContain($entityType, $config['lazyLoadHistory'] ?? false);
        $entity = $this->fetchTable($tableName)->get($id, compact('contain'));
        if (isset($config['permissionCheck']) && is_callable($config['permissionCheck'])) {
            $permissionResult = $config['permissionCheck']($entity);
            if ($permissionResult !== null) {
                return $permissionResult;
            }
        }
        $agentsRoleFilter = $config['agentsRoleFilter'] ?? $this->getDefaultAgentsRoleFilter($entityType);
        $agents = $this->fetchTable('Users')
            ->find('list')
            ->where(['role IN' => $agentsRoleFilter, 'is_active' => true])
            ->toArray();
        $viewVars = [
            $variableName => $entity,
            'agents' => $agents,
        ];
        if (isset($config['additionalViewVars'])) {
            $viewVars = array_merge($viewVars, $config['additionalViewVars']);
        }
        if (isset($config['beforeSet']) && is_callable($config['beforeSet'])) {
            $viewVars = $config['beforeSet']($entity, $viewVars);
        }
        $allStatuses = $this->getStatusConfig($entityType);
        $selectableStatuses = array_filter($allStatuses, function($key) {
            return $key !== 'convertido';
        }, ARRAY_FILTER_USE_KEY);
        $user = $this->Authentication->getIdentity();
        $authService = new AuthorizationService();
        $viewVars = array_merge($viewVars, [
            'entityType' => $entityType,
            'entityMetadata' => $this->getEntityMetadata($entityType, $entity),
            'statuses' => $selectableStatuses,
            'priorities' => $this->getPriorityConfig($entityType),
            'resolvedStatuses' => $this->getResolvedStatuses($entityType),
            'isLocked' => $this->isEntityLocked($entityType, $entity),
            'isAssignmentDisabled' => $authService->isAssignmentDisabled($entityType, $user),
        ]);
        $this->set($viewVars);
        return null;
    }

    private function getDefaultViewContain(string $entityType, bool $lazyLoadHistory = false): array
    {
        $contain = match ($entityType) {
            'ticket' => [
                'Requesters' => ['Organizations'],
                'Assignees',
                'TicketComments' => ['Users'],
                'Attachments',
                'Tags',
                'TicketFollowers' => ['Users'],
            ],
            'pqrs' => [
                'Assignees',
                'PqrsComments' => [
                    'Users',
                    'PqrsAttachments',
                    'sort' => ['PqrsComments.created' => 'ASC']
                ],
                'PqrsAttachments',
            ],
            'compra' => [
                'Requesters',
                'Assignees',
                'ComprasComments' => ['Users'],
                'ComprasAttachments',
            ],
            default => [],
        };
        if (!$lazyLoadHistory) {
            $historyAssoc = match ($entityType) {
                'ticket' => 'TicketHistory',
                'pqrs' => 'PqrsHistory',
                'compra' => 'ComprasHistory',
                default => null,
            };
            if ($historyAssoc) {
                $contain[$historyAssoc] = [
                    'Users',
                    'sort' => [$historyAssoc . '.created' => 'DESC']
                ];
            }
        }
        return $contain;
    }

    private function getDefaultAgentsRoleFilter(string $entityType): array
    {
        return match ($entityType) {
            'ticket' => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT],
            'pqrs' => [ValidationConstants::ROLE_SERVICIO_CLIENTE],
            'compra' => [ValidationConstants::ROLE_COMPRAS],
            default => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT],
        };
    }

    private function getSingleEntityVariable(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'ticket',
            'pqrs' => 'pqrs',
            'compra' => 'compra',
            default => $entityType,
        };
    }
}
