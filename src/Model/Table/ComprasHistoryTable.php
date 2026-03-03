<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ComprasHistory Model
 *
 * @property \App\Model\Table\ComprasTable&\Cake\ORM\Association\BelongsTo $Compras
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 */
class ComprasHistoryTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('compras_history');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
        $this->addBehavior('Audit', ['foreignKey' => 'compra_id']);

        $this->belongsTo('Compras', [
            'foreignKey' => 'compra_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Users', [
            'foreignKey' => 'changed_by',
            'joinType' => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('compra_id')
            ->requirePresence('compra_id', 'create')
            ->notEmptyString('compra_id');

        $validator
            ->integer('changed_by')
            ->allowEmptyString('changed_by');

        $validator
            ->scalar('field_name')
            ->maxLength('field_name', 100)
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
            ->allowEmptyString('description');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['compra_id'], 'Compras'), ['errorField' => 'compra_id']);
        $rules->add($rules->existsIn(['changed_by'], 'Users'), ['errorField' => 'changed_by']);

        return $rules;
    }

}
