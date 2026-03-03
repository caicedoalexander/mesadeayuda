<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Utility\ValidationConstants;

class ComprasTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('compras');
        $this->setDisplayField('compra_number');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        // Asociaciones
        $this->belongsTo('Requesters', [
            'className' => 'Users',
            'foreignKey' => 'requester_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('Assignees', [
            'className' => 'Users',
            'foreignKey' => 'assignee_id',
            'joinType' => 'LEFT',
        ]);

        $this->hasMany('ComprasComments', [
            'foreignKey' => 'compra_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
            'sort' => ['ComprasComments.created' => 'ASC'],
        ]);

        $this->hasMany('ComprasAttachments', [
            'foreignKey' => 'compra_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);

        $this->hasMany('ComprasHistory', [
            'foreignKey' => 'compra_id',
            'dependent' => true,
            'sort' => ['ComprasHistory.created' => 'DESC'],
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('compra_number')
            ->maxLength('compra_number', 20)
            ->requirePresence('compra_number', 'create')
            ->notEmptyString('compra_number')
            ->add('compra_number', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('original_ticket_number')
            ->maxLength('original_ticket_number', 20)
            ->allowEmptyString('original_ticket_number');

        $validator
            ->scalar('subject')
            ->maxLength('subject', 255)
            ->requirePresence('subject', 'create')
            ->notEmptyString('subject');

        $validator
            ->scalar('description')
            ->requirePresence('description', 'create')
            ->notEmptyString('description');

        $validator
            ->scalar('status')
            ->inList('status', ValidationConstants::COMPRA_STATUSES)
            ->notEmptyString('status');

        $validator
            ->scalar('priority')
            ->inList('priority', ValidationConstants::PRIORITIES)
            ->notEmptyString('priority');

        $validator
            ->integer('requester_id')
            ->notEmptyString('requester_id');

        $validator
            ->integer('assignee_id')
            ->allowEmptyString('assignee_id');

        $validator
            ->scalar('channel')
            ->maxLength('channel', 20)
            ->notEmptyString('channel')
            ->inList('channel', ['email', 'whatsapp']);

        $validator
            ->dateTime('sla_due_date')
            ->allowEmptyDateTime('sla_due_date');

        $validator
            ->dateTime('resolved_at')
            ->allowEmptyDateTime('resolved_at');

        $validator
            ->dateTime('first_response_at')
            ->allowEmptyDateTime('first_response_at');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['compra_number']), ['errorField' => 'compra_number']);
        $rules->add($rules->existsIn(['requester_id'], 'Requesters'), ['errorField' => 'requester_id']);

        // Allow null assignee_id (unassigned compras)
        $rules->add(
            $rules->existsIn(['assignee_id'], 'Assignees'),
            [
                'errorField' => 'assignee_id',
                'allowNullableNulls' => true
            ]
        );

        return $rules;
    }

    /**
     * Genera número único de compra
     * Formato: CPR-{YEAR}-{SEQUENCE}
     * Ejemplo: CPR-2025-00001
     */
    public function generateCompraNumber(): string
    {
        return (new \App\Service\NumberGenerationService())->generate('compra');
    }

    /**
     * Custom finder con filtros (mismo patrón que TicketsTable y PqrsTable)
     */
    public function findWithFilters(SelectQuery $query, array $options): SelectQuery
    {
        $filters = $options['filters'] ?? [];
        $view = $options['view'] ?? 'todos_sin_resolver';
        $user = $options['user'] ?? null;
        $userId = $user ? $user->get('id') : null;

        // Vistas predefinidas
        if (empty($filters['search'])) {
            switch ($view) {
                case 'sin_asignar':
                    $query->where([
                        'Compras.assignee_id IS' => null,
                        'Compras.status NOT IN' => ['completado', 'rechazado', 'convertido']
                    ]);
                    break;
                case 'mis_compras':
                    if ($userId) {
                        $query->where([
                            'Compras.assignee_id' => $userId,
                            'Compras.status NOT IN' => ['completado', 'rechazado', 'convertido']
                        ]);
                    }
                    break;
                case 'todos_sin_resolver':
                    $query->where(['Compras.status NOT IN' => ['completado', 'rechazado', 'convertido']]);
                    break;
                case 'nuevos':
                    $query->where(['Compras.status' => 'nuevo']);
                    break;
                case 'en_revision':
                    $query->where(['Compras.status' => 'en_revision']);
                    break;
                case 'aprobados':
                    $query->where(['Compras.status' => 'aprobado']);
                    break;
                case 'en_proceso':
                    $query->where(['Compras.status' => 'en_proceso']);
                    break;
                case 'completados':
                    $query->where(['Compras.status' => 'completado']);
                    break;
                case 'rechazados':
                    $query->where(['Compras.status' => 'rechazado']);
                    break;
                case 'convertidos':
                    $query->where(['Compras.status' => 'convertido']);
                    break;
                case 'vencidos_sla':
                    $query->where([
                        'Compras.sla_due_date <' => new \DateTime(),
                        'Compras.status NOT IN' => ['completado', 'rechazado', 'convertido']
                    ]);
                    break;
            }
        }

        // Búsqueda full-text
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where([
                'OR' => [
                    'Compras.compra_number LIKE' => '%' . $search . '%',
                    'Compras.subject LIKE' => '%' . $search . '%',
                    'Compras.description LIKE' => '%' . $search . '%',
                    'Compras.original_ticket_number LIKE' => '%' . $search . '%',
                    'Requesters.name LIKE' => '%' . $search . '%',
                    'Requesters.email LIKE' => '%' . $search . '%',
                ]
            ]);
            // Exclude converted compras from search unless explicitly viewing convertidos
            if ($view !== 'convertidos') {
                $query->where(['Compras.status !=' => 'convertido']);
            }
        }

        // Filtros específicos
        if (!empty($filters['status'])) {
            $query->where(['Compras.status' => $filters['status']]);
        }
        if (!empty($filters['priority'])) {
            $query->where(['Compras.priority' => $filters['priority']]);
        }
        if (!empty($filters['assignee_id'])) {
            if ($filters['assignee_id'] === 'unassigned') {
                $query->where(['Compras.assignee_id IS' => null]);
            } else {
                $query->where(['Compras.assignee_id' => $filters['assignee_id']]);
            }
        }
        if (!empty($filters['date_from'])) {
            $query->where(['Compras.created >=' => $filters['date_from'] . ' 00:00:00']);
        }
        if (!empty($filters['date_to'])) {
            $query->where(['Compras.created <=' => $filters['date_to'] . ' 23:59:59']);
        }

        return $query;
    }
}
