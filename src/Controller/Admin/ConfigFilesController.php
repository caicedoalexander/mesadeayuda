<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Utility\SettingKeys;
use App\Utility\ValidationConstants;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Log\Log;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Config Files Controller
 *
 * Handles uploading and managing configuration files like:
 * - Gmail client_secret.json
 */
class ConfigFilesController extends AppController
{
    /**
     * Before filter - require admin role
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event.
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        // Unlock file upload action
        $this->FormProtection->setConfig('unlockedActions', ['upload']);

        $user = $this->Authentication->getIdentity();
        if (!$user || $user->get('role') !== ValidationConstants::ROLE_ADMIN) {
            $this->Flash->error('Solo los administradores pueden acceder a esta sección.');
            return $this->redirect(['controller' => 'Tickets', 'action' => 'index', 'prefix' => false]);
        }
    }

    /**
     * Supported config file types and their destinations
     */
    private const FILE_DESTINATIONS = [
        'gmail' => [
            'filename' => 'client_secret.json',
            'subdir' => 'google',
            'setting_key' => SettingKeys::GMAIL_CLIENT_SECRET_PATH,
            'success_message' => 'Gmail client_secret.json subido correctamente.',
        ],
    ];

    /**
     * Upload configuration file
     *
     * @return \Cake\Http\Response|null|void
     */
    public function upload()
    {
        $this->request->allowMethod(['post']);

        $fileType = $this->request->getData('file_type');
        $file = $this->request->getData('config_file');

        $redirect = ['controller' => 'Settings', 'action' => 'index'];

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error('No se pudo cargar el archivo. Por favor intenta nuevamente.');
            return $this->redirect($redirect);
        }

        $error = $this->validateJsonFile($file);
        if ($error !== null) {
            $this->Flash->error($error);
            return $this->redirect($redirect);
        }

        $destination = $this->getDestination($fileType);
        $targetPath = $destination['path'];

        try {
            $this->saveConfigFile($file, $targetPath);
            $this->updateConfigPath($destination['setting_key'], $targetPath);

            $this->Flash->success($destination['success_message']);
            Log::info('Config file uploaded', [
                'type' => $fileType,
                'path' => $targetPath,
                'user' => $this->Authentication->getIdentity()?->get('email'),
            ]);
        } catch (\Exception $e) {
            $this->Flash->error('Error al guardar el archivo: ' . $e->getMessage());
            Log::error('Config file upload failed', [
                'type' => $fileType,
                'error' => $e->getMessage(),
                'user' => $this->Authentication->getIdentity()?->get('email'),
            ]);
        }

        return $this->redirect($redirect);
    }

    /**
     * Download/view current config file
     *
     * @param string $type File type (gmail)
     * @return \Cake\Http\Response
     */
    public function download(string $type)
    {
        $filePath = $this->getDestination($type)['path'];

        if (!file_exists($filePath)) {
            $this->Flash->error('El archivo de configuración aún no existe.');
            return $this->redirect(['controller' => 'Settings', 'action' => 'index']);
        }

        $this->response = $this->response->withFile(
            $filePath,
            ['download' => true, 'name' => basename($filePath)]
        );

        return $this->response;
    }

    /**
     * Delete config file
     *
     * @param string $type File type (gmail)
     * @return \Cake\Http\Response
     */
    public function delete(string $type)
    {
        $this->request->allowMethod(['post', 'delete']);

        $filePath = $this->getDestination($type)['path'];

        if (file_exists($filePath)) {
            unlink($filePath);
            $this->Flash->success('Archivo de configuración eliminado correctamente.');
            Log::info('Config file deleted', [
                'type' => $type,
                'user' => $this->Authentication->getIdentity()?->get('email'),
            ]);
        } else {
            $this->Flash->warning('El archivo ya no existe.');
        }

        return $this->redirect(['controller' => 'Settings', 'action' => 'index']);
    }

    /**
     * Get destination config for a file type
     *
     * @param string $fileType File type key
     * @return array{path: string, setting_key: string, success_message: string}
     * @throws \Cake\Http\Exception\BadRequestException|\Cake\Http\Exception\NotFoundException
     */
    private function getDestination(string $fileType): array
    {
        if (!isset(self::FILE_DESTINATIONS[$fileType])) {
            throw new BadRequestException('Tipo de archivo no soportado.');
        }

        $config = self::FILE_DESTINATIONS[$fileType];

        return [
            'path' => CONFIG . $config['subdir'] . DS . $config['filename'],
            'setting_key' => $config['setting_key'],
            'success_message' => $config['success_message'],
        ];
    }

    /**
     * Validate uploaded file is valid JSON
     *
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @return string|null Error message or null if valid
     */
    private function validateJsonFile(UploadedFileInterface $file): ?string
    {
        $allowedTypes = ['application/json', 'text/plain'];
        if (!in_array($file->getClientMediaType(), $allowedTypes)) {
            return 'El archivo debe ser un JSON válido.';
        }

        $content = file_get_contents($file->getStream()->getMetadata('uri'));
        json_decode($content);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return 'El archivo no es un JSON válido: ' . json_last_error_msg();
        }

        return null;
    }

    /**
     * Save config file to target path with proper permissions
     *
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param string $targetPath Destination path
     * @return void
     */
    private function saveConfigFile(UploadedFileInterface $file, string $targetPath): void
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file->moveTo($targetPath);
        chmod($targetPath, 0664);

        if (function_exists('posix_getpwnam')) {
            $wwwData = posix_getpwnam('www-data');
            if ($wwwData) {
                chown($targetPath, $wwwData['uid']);
                chgrp($targetPath, $wwwData['gid']);
            }
        }
    }

    /**
     * Update config path setting in database
     *
     * @param string $key Setting key
     * @param string $path File path
     * @return void
     */
    private function updateConfigPath(string $key, string $path): void
    {
        $settingsTable = $this->fetchTable('SystemSettings');
        $setting = $settingsTable->find()->where(['setting_key' => $key])->first();

        if ($setting) {
            $setting->setting_value = $path;
        } else {
            $setting = $settingsTable->newEntity([
                'setting_key' => $key,
                'setting_value' => $path,
                'setting_type' => 'string',
                'description' => 'Path to ' . $key . ' configuration file',
            ], ['accessibleFields' => ['setting_key' => true, 'setting_type' => true]]);
        }

        $settingsTable->saveOrFail($setting);
    }
}
