<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * SystemSettings Model
 *
 * @method \App\Model\Entity\SystemSetting newEmptyEntity()
 * @method \App\Model\Entity\SystemSetting newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\SystemSetting> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\SystemSetting get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\SystemSetting findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\SystemSetting patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\SystemSetting> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\SystemSetting|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\SystemSetting saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\SystemSetting>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SystemSetting>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SystemSetting>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SystemSetting> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SystemSetting>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SystemSetting>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\SystemSetting>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\SystemSetting> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class SystemSettingsTable extends Table
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

        $this->setTable('system_settings');
        $this->setDisplayField('setting_key');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
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
            ->scalar('setting_key')
            ->maxLength('setting_key', 100)
            ->requirePresence('setting_key', 'create')
            ->notEmptyString('setting_key')
            ->add('setting_key', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('setting_value')
            ->allowEmptyString('setting_value');

        $validator
            ->scalar('setting_type')
            ->maxLength('setting_type', 50)
            ->notEmptyString('setting_type');

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
        $rules->add($rules->isUnique(['setting_key']), ['errorField' => 'setting_key']);

        return $rules;
    }
}
