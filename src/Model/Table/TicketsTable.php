<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Constants\RoleConstants;
use App\Constants\TicketConstants;
use App\Service\NumberGenerationService;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Tickets Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Requesters
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Assignees
 * @property \App\Model\Table\AttachmentsTable&\Cake\ORM\Association\HasMany $Attachments
 * @property \App\Model\Table\TicketCommentsTable&\Cake\ORM\Association\HasMany $TicketComments
 * @property \App\Model\Table\TicketFollowersTable&\Cake\ORM\Association\HasMany $TicketFollowers
 * @property \App\Model\Table\TicketTagsTable&\Cake\ORM\Association\HasMany $TicketTags
 * @method \App\Model\Entity\Ticket newEmptyEntity()
 * @method \App\Model\Entity\Ticket newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Ticket> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Ticket get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Ticket findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Ticket patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Ticket> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Ticket|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Ticket saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Ticket>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Ticket> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('tickets');
        $this->setDisplayField('ticket_number');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Requesters', [
            'foreignKey' => 'requester_id',
            'className' => 'Users',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Assignees', [
            'foreignKey' => 'assignee_id',
            'className' => 'Users',
        ]);
        $this->hasMany('Attachments', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketComments', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketFollowers', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketTags', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->hasMany('TicketHistory', [
            'foreignKey' => 'ticket_id',
            'sort' => ['TicketHistory.created' => 'DESC'],
        ]);
        $this->belongsToMany('Tags', [
            'foreignKey' => 'ticket_id',
            'targetForeignKey' => 'tag_id',
            'joinTable' => 'ticket_tags',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('ticket_number')
            ->maxLength('ticket_number', 20)
            ->requirePresence('ticket_number', 'create')
            ->notEmptyString('ticket_number')
            ->add('ticket_number', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('gmail_message_id')
            ->maxLength('gmail_message_id', 255)
            ->allowEmptyString('gmail_message_id')
            ->add('gmail_message_id', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        // Note: email_to and email_cc accept arrays (converted to JSON by entity setters)
        // No validation rules needed - let the entity handle it

        $validator
            ->scalar('gmail_thread_id')
            ->maxLength('gmail_thread_id', 255)
            ->allowEmptyString('gmail_thread_id');

        $validator
            ->scalar('subject')
            ->maxLength('subject', 255)
            ->requirePresence('subject', 'create')
            ->notEmptyString('subject');

        $validator
            ->scalar('description')
            ->allowEmptyString('description');

        $validator
            ->scalar('status')
            ->maxLength('status', 20)
            ->notEmptyString('status')
            ->inList('status', TicketConstants::STATUSES, 'Estado no válido.');

        $validator
            ->scalar('priority')
            ->maxLength('priority', 20)
            ->notEmptyString('priority')
            ->inList('priority', TicketConstants::PRIORITIES, 'Prioridad no válida.');

        $validator
            ->integer('requester_id')
            ->notEmptyString('requester_id');

        $validator
            ->integer('assignee_id')
            ->allowEmptyString('assignee_id');

        $validator
            ->scalar('channel')
            ->maxLength('channel', 50)
            ->notEmptyString('channel');

        $validator
            ->scalar('source_email')
            ->maxLength('source_email', 255)
            ->allowEmptyString('source_email');

        $validator
            ->dateTime('resolved_at')
            ->allowEmptyDateTime('resolved_at');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['ticket_number']), ['errorField' => 'ticket_number']);
        $rules->add($rules->isUnique(['gmail_message_id'], ['allowMultipleNulls' => true]), ['errorField' => 'gmail_message_id']);
        $rules->add($rules->existsIn(['requester_id'], 'Requesters'), ['errorField' => 'requester_id']);

        // Allow null assignee_id (unassigned tickets)
        $rules->add(
            $rules->existsIn(['assignee_id'], 'Assignees'),
            [
                'errorField' => 'assignee_id',
                'allowNullableNulls' => true,
            ],
        );

        return $rules;
    }

    /**
     * Generate unique ticket number in format TKT-YYYY-NNNNN
     *
     * @return string
     */
    public function generateTicketNumber(): string
    {
        return (new NumberGenerationService())->generate();
    }

    /**
     * Find tickets with filters
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query object
     * @param string $view Predefined view name
     * @param array $filters Filter values keyed by field
     * @param mixed $user Authenticated identity (or null)
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithFilters(
        SelectQuery $query,
        string $view = 'todos_sin_resolver',
        array $filters = [],
        mixed $user = null,
    ): SelectQuery {
        $userRole = $user ? $user->get('role') : null;
        $userId = $user ? $user->get('id') : null;

        // Determine if user is agent (filter by assigned tickets for certain views)
        $isAgent = $userRole === RoleConstants::ROLE_AGENT;
        $isAdmin = $userRole === RoleConstants::ROLE_ADMIN;

        // Apply view-based filters (if no search is active)
        if (empty($filters['search'])) {
            switch ($view) {
                case 'sin_asignar':
                    $query->where([
                        'Tickets.assignee_id IS' => null,
                        'Tickets.status NOT IN' => TicketConstants::RESOLVED_STATUSES,
                    ]);
                    break;
                case 'mis_tickets':
                    if ($user) {
                        $query->where([
                            'Tickets.assignee_id' => $user->get('id'),
                            'Tickets.status NOT IN' => TicketConstants::RESOLVED_STATUSES,
                        ]);
                    }
                    break;
                case 'creados_por_mi':
                    if ($user) {
                        $query->where([
                            'Tickets.requester_id' => $user->get('id'),
                        ]);
                    }
                    break;
                case 'todos_sin_resolver':
                    $query->where(['Tickets.status NOT IN' => TicketConstants::RESOLVED_STATUSES]);
                    break;
                case 'pendientes':
                    $conditions = ['Tickets.status' => TicketConstants::STATUS_PENDIENTE];
                    // Agents see only their assigned tickets, admins see all
                    if ($isAgent && $userId) {
                        $conditions['Tickets.assignee_id'] = $userId;
                    }
                    $query->where($conditions);
                    break;
                case 'nuevos':
                    $conditions = ['Tickets.status' => TicketConstants::STATUS_NUEVO];
                    // Agents see only their assigned tickets, admins see all
                    if ($isAgent && $userId) {
                        $conditions['Tickets.assignee_id'] = $userId;
                    }
                    $query->where($conditions);
                    break;
                case 'abiertos':
                    $conditions = ['Tickets.status' => TicketConstants::STATUS_ABIERTO];
                    // Agents see only their assigned tickets, admins see all
                    if ($isAgent && $userId) {
                        $conditions['Tickets.assignee_id'] = $userId;
                    }
                    $query->where($conditions);
                    break;
                case 'resueltos':
                    $query->where(['Tickets.status' => TicketConstants::STATUS_RESUELTO]);
                    break;
                case 'recientes':
                    $query->where([
                        'Tickets.created >=' => date('Y-m-d', strtotime('-7 days')),
                    ]);
                    break;
            }
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where([
                'OR' => [
                    'Tickets.ticket_number LIKE' => '%' . $search . '%',
                    'Tickets.subject LIKE' => '%' . $search . '%',
                    'Tickets.description LIKE' => '%' . $search . '%',
                    'Tickets.source_email LIKE' => '%' . $search . '%',
                    'Requesters.name LIKE' => '%' . $search . '%',
                    'Requesters.email LIKE' => '%' . $search . '%',
                ],
            ]);
        }

        // Apply specific filters
        if (!empty($filters['status'])) {
            $query->where(['Tickets.status' => $filters['status']]);
        }
        if (!empty($filters['priority'])) {
            $query->where(['Tickets.priority' => $filters['priority']]);
        }
        if (!empty($filters['assignee_id'])) {
            if ($filters['assignee_id'] === 'unassigned') {
                $query->where(['Tickets.assignee_id IS' => null]);
            } else {
                $query->where(['Tickets.assignee_id' => $filters['assignee_id']]);
            }
        }
        if (!empty($filters['date_from'])) {
            $query->where(['Tickets.created >=' => $filters['date_from'] . ' 00:00:00']);
        }
        if (!empty($filters['date_to'])) {
            $query->where(['Tickets.created <=' => $filters['date_to'] . ' 23:59:59']);
        }

        return $query;
    }
}
