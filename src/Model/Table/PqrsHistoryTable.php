<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * PqrsHistory Model
 *
 * @property \App\Model\Table\PqrsTable&\Cake\ORM\Association\BelongsTo $Pqrs
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 *
 * @method \App\Model\Entity\PqrsHistory newEmptyEntity()
 * @method \App\Model\Entity\PqrsHistory newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\PqrsHistory> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\PqrsHistory get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\PqrsHistory findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\PqrsHistory patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\PqrsHistory> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\PqrsHistory|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\PqrsHistory saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class PqrsHistoryTable extends Table
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

        $this->setTable('pqrs_history');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
        $this->addBehavior('Audit', ['foreignKey' => 'pqrs_id']);

        $this->belongsTo('Pqrs', [
            'foreignKey' => 'pqrs_id',
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
            ->integer('pqrs_id')
            ->requirePresence('pqrs_id', 'create')
            ->notEmptyString('pqrs_id');

        $validator
            ->integer('changed_by')
            ->requirePresence('changed_by', 'create')
            ->notEmptyString('changed_by');

        $validator
            ->scalar('field_name')
            ->maxLength('field_name', 50)
            ->requirePresence('field_name', 'create')
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
        $rules->add($rules->existsIn(['pqrs_id'], 'Pqrs'), ['errorField' => 'pqrs_id']);
        $rules->add($rules->existsIn(['changed_by'], 'Users'), ['errorField' => 'changed_by']);

        return $rules;
    }

}
