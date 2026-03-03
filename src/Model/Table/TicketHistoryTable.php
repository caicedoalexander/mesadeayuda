<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TicketHistory Model
 *
 * Stores audit trail of all changes made to tickets
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 *
 * @method \App\Model\Entity\TicketHistory newEmptyEntity()
 * @method \App\Model\Entity\TicketHistory newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketHistory> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TicketHistory get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TicketHistory findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TicketHistory patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketHistory> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TicketHistory|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TicketHistory saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHistory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHistory>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHistory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHistory> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHistory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHistory>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketHistory>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketHistory> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketHistoryTable extends Table
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

        $this->setTable('ticket_history');
        $this->setDisplayField('description');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ]
            ]
        ]);

        $this->addBehavior('Audit', ['foreignKey' => 'ticket_id']);

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'changed_by',
            'joinType' => 'LEFT',
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
            ->integer('ticket_id')
            ->notEmptyString('ticket_id');

        $validator
            ->integer('changed_by')
            ->allowEmptyString('changed_by');

        $validator
            ->scalar('field_name')
            ->maxLength('field_name', 50)
            ->notEmptyString('field_name');

        $validator
            ->scalar('old_value')
            ->maxLength('old_value', 255)
            ->allowEmptyString('old_value');

        $validator
            ->scalar('new_value')
            ->maxLength('new_value', 255)
            ->allowEmptyString('new_value');

        $validator
            ->scalar('description')
            ->maxLength('description', 500)
            ->allowEmptyString('description');

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
        $rules->add($rules->existsIn(['ticket_id'], 'Tickets'), ['errorField' => 'ticket_id']);
        $rules->add($rules->existsIn(['changed_by'], 'Users'), ['errorField' => 'changed_by']);

        return $rules;
    }

}
