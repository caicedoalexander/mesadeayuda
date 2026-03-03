<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Utility\ValidationConstants;

/**
 * TicketComments Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 *
 * @method \App\Model\Entity\TicketComment newEmptyEntity()
 * @method \App\Model\Entity\TicketComment newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketComment> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TicketComment get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TicketComment findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TicketComment patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TicketComment> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TicketComment|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TicketComment saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TicketComment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketComment>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketComment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketComment> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketComment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketComment>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TicketComment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TicketComment> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class TicketCommentsTable extends Table
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

        $this->setTable('ticket_comments');
        $this->setDisplayField('comment_type');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'INNER',
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
            ->integer('user_id')
            ->notEmptyString('user_id');

        $validator
            ->scalar('comment_type')
            ->maxLength('comment_type', 20)
            ->notEmptyString('comment_type')
            ->inList('comment_type', ValidationConstants::TICKET_COMMENT_TYPES, 'Tipo de comentario no válido.');

        $validator
            ->scalar('body')
            ->requirePresence('body', 'create')
            ->notEmptyString('body');

        $validator
            ->boolean('is_system_comment')
            ->notEmptyString('is_system_comment');

        $validator
            ->scalar('gmail_message_id')
            ->maxLength('gmail_message_id', 255)
            ->allowEmptyString('gmail_message_id');

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
        $rules->add($rules->existsIn(['ticket_id'], 'Tickets'), ['errorField' => 'ticket_id']);
        $rules->add($rules->existsIn(['user_id'], 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
