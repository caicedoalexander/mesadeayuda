<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\ProfileImageService;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Users Model
 *
 * @property \App\Model\Table\OrganizationsTable&\Cake\ORM\Association\BelongsTo $Organizations
 * @property \App\Model\Table\TicketCommentsTable&\Cake\ORM\Association\HasMany $TicketComments
 * @property \App\Model\Table\TicketFollowersTable&\Cake\ORM\Association\HasMany $TicketFollowers
 *
 * @method \App\Model\Entity\User newEmptyEntity()
 * @method \App\Model\Entity\User newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\User> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\User get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\User findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\User patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\User> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\User|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\User saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\User>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\User>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\User>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\User> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\User>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\User>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\User>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\User> deleteManyOrFail(iterable $entities, array $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class UsersTable extends Table
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

        $this->setTable('users');
        $this->setDisplayField('first_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Organizations', [
            'foreignKey' => 'organization_id',
        ]);
        $this->hasMany('TicketComments', [
            'foreignKey' => 'user_id',
        ]);
        $this->hasMany('TicketFollowers', [
            'foreignKey' => 'user_id',
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
            ->email('email', false, 'Debe ser un correo electrónico válido')  // false = less strict, allows localhost
            ->requirePresence('email', 'create')
            ->notEmptyString('email')
            ->add('email', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->scalar('password')
            ->maxLength('password', 255)
            ->minLength('password', 8, 'La contraseña debe tener al menos 8 caracteres.')
            ->add('password', 'complexity', [
                'rule' => function ($value) {
                    if (empty($value)) {
                        return true;
                    }

                    return (bool) preg_match('/[A-Z]/', $value) &&
                        (bool) preg_match('/[a-z]/', $value) &&
                        (bool) preg_match('/[0-9]/', $value);
                },
                'message' => 'La contraseña debe contener al menos una mayúscula, una minúscula y un número.',
            ])
            ->allowEmptyString('password');

        $validator
            ->scalar('first_name')
            ->maxLength('first_name', 100)
            ->requirePresence('first_name', 'create')
            ->notEmptyString('first_name');

        $validator
            ->scalar('last_name')
            ->maxLength('last_name', 100)
            ->requirePresence('last_name', 'create')
            ->notEmptyString('last_name');

        $validator
            ->scalar('phone')
            ->maxLength('phone', 50)
            ->allowEmptyString('phone');

        $validator
            ->scalar('role')
            ->maxLength('role', 50)
            ->notEmptyString('role')
            ->inList('role', ['admin', 'agent', 'compras', 'servicio_cliente', 'requester'], 'Rol no válido');

        $validator
            ->integer('organization_id')
            ->allowEmptyString('organization_id');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

        $validator
            ->scalar('profile_image')
            ->maxLength('profile_image', 255)
            ->allowEmptyString('profile_image');

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
        $rules->add($rules->isUnique(['email']), ['errorField' => 'email']);
        $rules->add($rules->existsIn(['organization_id'], 'Organizations'), ['errorField' => 'organization_id']);

        return $rules;
    }

    /**
     * Save profile image for a user (delegates to ProfileImageService)
     *
     * @param int $userId User ID
     * @param \Psr\Http\Message\UploadedFileInterface $uploadedFile Uploaded file
     * @return array Result with success status and filename or error message
     */
    public function saveProfileImage(int $userId, $uploadedFile): array
    {
        return (new ProfileImageService())->saveProfileImage($userId, $uploadedFile);
    }

    /**
     * Delete a profile image file (delegates to ProfileImageService)
     *
     * @param string $filename Relative path to the profile image
     * @return bool Success status
     */
    public function deleteProfileImage(string $filename): bool
    {
        return (new ProfileImageService())->deleteProfileImage($filename);
    }

    /**
     * Get profile image URL (delegates to ProfileImageService)
     *
     * @param string|null $profileImage Profile image path
     * @return string URL to profile image or default avatar
     */
    public function getProfileImageUrl(?string $profileImage): string
    {
        return (new ProfileImageService())->getProfileImageUrl($profileImage);
    }
}
