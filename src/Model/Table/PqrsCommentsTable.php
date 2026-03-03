<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Utility\ValidationConstants;

/**
 * PqrsComments Model
 *
 * @property \App\Model\Table\PqrsTable&\Cake\ORM\Association\BelongsTo $Pqrs
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @property \App\Model\Table\PqrsAttachmentsTable&\Cake\ORM\Association\HasMany $PqrsAttachments
 *
 * @method \App\Model\Entity\PqrsComment newEmptyEntity()
 * @method \App\Model\Entity\PqrsComment newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\PqrsComment> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\PqrsComment get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\PqrsComment findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\PqrsComment patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\PqrsComment> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\PqrsComment|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\PqrsComment saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class PqrsCommentsTable extends Table
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

        $this->setTable('pqrs_comments');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Pqrs', [
            'foreignKey' => 'pqrs_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('PqrsAttachments', [
            'foreignKey' => 'comment_id',
            'dependent' => true,
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
            ->integer('user_id')
            ->allowEmptyString('user_id');

        $validator
            ->scalar('comment_type')
            ->maxLength('comment_type', 20)
            ->requirePresence('comment_type', 'create')
            ->notEmptyString('comment_type')
            ->inList('comment_type', ValidationConstants::COMMENT_TYPES);

        $validator
            ->scalar('body')
            ->requirePresence('body', 'create')
            ->notEmptyString('body');

        $validator
            ->boolean('is_system_comment')
            ->notEmptyString('is_system_comment');

        $validator
            ->boolean('sent_as_email')
            ->notEmptyString('sent_as_email');

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
        $rules->add($rules->existsIn(['user_id'], 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
