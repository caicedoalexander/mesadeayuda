<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Attachments Model
 *
 * @property \App\Model\Table\TicketsTable&\Cake\ORM\Association\BelongsTo $Tickets
 * @property \App\Model\Table\TicketCommentsTable&\Cake\ORM\Association\BelongsTo $Comments
 *
 * @method \App\Model\Entity\Attachment newEmptyEntity()
 * @method \App\Model\Entity\Attachment newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Attachment> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Attachment get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Attachment findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Attachment patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Attachment> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Attachment|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Attachment saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Attachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Attachment>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Attachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Attachment> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Attachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Attachment>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Attachment>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Attachment> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class AttachmentsTable extends Table
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

        $this->setTable('attachments');
        $this->setDisplayField('filename');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tickets', [
            'foreignKey' => 'ticket_id',
        ]);
        $this->belongsTo('Comments', [
            'foreignKey' => 'comment_id',
            'className' => 'TicketComments',
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
            ->allowEmptyString('ticket_id');

        $validator
            ->integer('comment_id')
            ->allowEmptyString('comment_id');

        $validator
            ->scalar('filename')
            ->maxLength('filename', 255)
            ->requirePresence('filename', 'create')
            ->notEmptyString('filename');

        $validator
            ->scalar('original_filename')
            ->maxLength('original_filename', 255)
            ->requirePresence('original_filename', 'create')
            ->notEmptyString('original_filename');

        $validator
            ->scalar('file_path')
            ->maxLength('file_path', 500)
            ->requirePresence('file_path', 'create')
            ->notEmptyString('file_path');

        $validator
            ->scalar('mime_type')
            ->maxLength('mime_type', 100)
            ->requirePresence('mime_type', 'create')
            ->notEmptyString('mime_type');

        $validator
            ->integer('file_size')
            ->requirePresence('file_size', 'create')
            ->notEmptyString('file_size');

        $validator
            ->boolean('is_inline')
            ->notEmptyString('is_inline');

        $validator
            ->scalar('content_id')
            ->maxLength('content_id', 255)
            ->allowEmptyString('content_id');

        $validator
            ->integer('uploaded_by')
            ->requirePresence('uploaded_by', 'create')
            ->notEmptyString('uploaded_by');

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
        $rules->add($rules->existsIn(['comment_id'], 'Comments'), ['errorField' => 'comment_id']);

        return $rules;
    }
}
