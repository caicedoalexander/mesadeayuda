<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Utility\ValidationConstants;

/**
 * ComprasComments Model
 *
 * @property \App\Model\Table\ComprasTable&\Cake\ORM\Association\BelongsTo $Compras
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @property \App\Model\Table\ComprasAttachmentsTable&\Cake\ORM\Association\HasMany $ComprasAttachments
 */
class ComprasCommentsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('compras_comments');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Compras', [
            'foreignKey' => 'compra_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('ComprasAttachments', [
            'foreignKey' => 'compras_comment_id',
            'dependent' => true,
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('compra_id')
            ->requirePresence('compra_id', 'create')
            ->notEmptyString('compra_id');

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

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['compra_id'], 'Compras'), ['errorField' => 'compra_id']);
        $rules->add($rules->existsIn(['user_id'], 'Users'), ['errorField' => 'user_id']);

        return $rules;
    }
}
