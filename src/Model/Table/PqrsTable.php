<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Utility\ValidationConstants;

/**
 * Pqrs Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Assignees
 * @property \App\Model\Table\PqrsCommentsTable&\Cake\ORM\Association\HasMany $PqrsComments
 * @property \App\Model\Table\PqrsAttachmentsTable&\Cake\ORM\Association\HasMany $PqrsAttachments
 * @property \App\Model\Table\PqrsHistoryTable&\Cake\ORM\Association\HasMany $PqrsHistory
 *
 * @method \App\Model\Entity\Pqr newEmptyEntity()
 * @method \App\Model\Entity\Pqr newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Pqr> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Pqr get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Pqr findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Pqr patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Pqr> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Pqr|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Pqr saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Pqr>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Pqr>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Pqr>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Pqr> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Pqr>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Pqr>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Pqr>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Pqr> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class PqrsTable extends Table
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

        $this->setTable('pqrs');
        $this->setDisplayField('pqrs_number');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Assignees', [
            'className' => 'Users',
            'foreignKey' => 'assignee_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('PqrsComments', [
            'foreignKey' => 'pqrs_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
            'sort' => ['PqrsComments.created' => 'ASC'],
        ]);
        $this->hasMany('PqrsAttachments', [
            'foreignKey' => 'pqrs_id',
            'dependent' => true,
            'cascadeCallbacks' => true,
        ]);
        $this->hasMany('PqrsHistory', [
            'foreignKey' => 'pqrs_id',
            'dependent' => true,
            'sort' => ['PqrsHistory.created' => 'DESC'],
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
            ->scalar('pqrs_number')
            ->maxLength('pqrs_number', 20)
            ->requirePresence('pqrs_number', 'create')
            ->notEmptyString('pqrs_number')
            ->add('pqrs_number', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('type')
            ->maxLength('type', 20)
            ->requirePresence('type', 'create')
            ->notEmptyString('type')
            ->inList('type', ValidationConstants::PQRS_TYPES);

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
            ->maxLength('status', 20)
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->inList('status', ValidationConstants::PQRS_STATUSES);

        $validator
            ->scalar('priority')
            ->maxLength('priority', 20)
            ->requirePresence('priority', 'create')
            ->notEmptyString('priority')
            ->inList('priority', ValidationConstants::PRIORITIES);

        $validator
            ->scalar('requester_name')
            ->maxLength('requester_name', 255)
            ->requirePresence('requester_name', 'create')
            ->notEmptyString('requester_name');

        $validator
            ->email('requester_email')
            ->requirePresence('requester_email', 'create')
            ->notEmptyString('requester_email');

        $validator
            ->scalar('requester_phone')
            ->maxLength('requester_phone', 50)
            ->allowEmptyString('requester_phone');

        $validator
            ->scalar('requester_id_number')
            ->maxLength('requester_id_number', 50)
            ->allowEmptyString('requester_id_number');

        $validator
            ->scalar('requester_address')
            ->allowEmptyString('requester_address');

        $validator
            ->scalar('requester_city')
            ->maxLength('requester_city', 100)
            ->allowEmptyString('requester_city');

        $validator
            ->scalar('channel')
            ->maxLength('channel', 20)
            ->notEmptyString('channel')
            ->inList('channel', ['web', 'whatsapp']);

        $validator
            ->integer('assignee_id')
            ->allowEmptyString('assignee_id');

        $validator
            ->scalar('source_url')
            ->maxLength('source_url', 500)
            ->allowEmptyString('source_url');

        $validator
            ->scalar('ip_address')
            ->maxLength('ip_address', 45)
            ->allowEmptyString('ip_address');

        $validator
            ->scalar('user_agent')
            ->allowEmptyString('user_agent');

        $validator
            ->dateTime('resolved_at')
            ->allowEmptyDateTime('resolved_at');

        $validator
            ->dateTime('first_response_at')
            ->allowEmptyDateTime('first_response_at');

        $validator
            ->dateTime('closed_at')
            ->allowEmptyDateTime('closed_at');

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
        $rules->add($rules->isUnique(['pqrs_number']), ['errorField' => 'pqrs_number']);

        // Allow null assignee_id (unassigned PQRS)
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
     * Find PQRS with filters
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query object
     * @param array $options Filter options
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithFilters(SelectQuery $query, array $options): SelectQuery
    {
        $filters = $options['filters'] ?? [];
        $view = $options['view'] ?? 'todos_sin_resolver';
        $user = $options['user'] ?? null;

        // Apply view-based filters
        if (empty($filters['search'])) {
            switch ($view) {
                case 'sin_asignar':
                    $query->where(['Pqrs.assignee_id IS' => null, 'Pqrs.status NOT IN' => ['resuelto', 'cerrado']]);
                    break;
                case 'mis_pqrs':
                    if ($user) {
                        $query->where(['Pqrs.assignee_id' => $user->get('id'), 'Pqrs.status NOT IN' => ['resuelto', 'cerrado']]);
                    }
                    break;
                case 'todos_sin_resolver':
                    $query->where(['Pqrs.status NOT IN' => ['resuelto', 'cerrado']]);
                    break;
                case 'nuevas':
                    $query->where(['Pqrs.status' => 'nuevo']);
                    break;
                case 'en_revision':
                    $query->where(['Pqrs.status' => 'en_revision']);
                    break;
                case 'en_proceso':
                    $query->where(['Pqrs.status' => 'en_proceso']);
                    break;
                case 'resueltas':
                    $query->where(['Pqrs.status' => 'resuelto']);
                    break;
                case 'cerradas':
                    $query->where(['Pqrs.status' => 'cerrado']);
                    break;
            }
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where([
                'OR' => [
                    'Pqrs.pqrs_number LIKE' => '%' . $search . '%',
                    'Pqrs.subject LIKE' => '%' . $search . '%',
                    'Pqrs.requester_name LIKE' => '%' . $search . '%',
                    'Pqrs.requester_email LIKE' => '%' . $search . '%',
                    'Pqrs.description LIKE' => '%' . $search . '%',
                ]
            ]);
        }

        // Apply specific filters
        if (!empty($filters['status'])) {
            $query->where(['Pqrs.status' => $filters['status']]);
        }
        if (!empty($filters['type'])) {
            $query->where(['Pqrs.type' => $filters['type']]);
        }
        if (!empty($filters['priority'])) {
            $query->where(['Pqrs.priority' => $filters['priority']]);
        }
        if (!empty($filters['assignee_id'])) {
            $query->where(['Pqrs.assignee_id' => $filters['assignee_id']]);
        }
        if (!empty($filters['date_from'])) {
            $query->where(['Pqrs.created >=' => $filters['date_from'] . ' 00:00:00']);
        }
        if (!empty($filters['date_to'])) {
            $query->where(['Pqrs.created <=' => $filters['date_to'] . ' 23:59:59']);
        }

        return $query;
    }

    /**
     * Generate next PQRS number
     *
     * @return string Format: PQRS-2025-00001
     */
    public function generatePqrsNumber(): string
    {
        return (new \App\Service\NumberGenerationService())->generate('pqrs');
    }
}
