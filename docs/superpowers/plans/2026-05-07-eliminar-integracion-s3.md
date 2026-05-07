# Eliminación de la integración con S3 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar completamente la integración con AWS S3 del proyecto Mesa de Ayuda dejando un único backend de almacenamiento (disco local bajo `webroot/uploads/`).

**Architecture:** Refactor en orden topológico: primero los consumidores (`UserHelper`, `ProfileImageService`, `GenericAttachmentTrait`) para que dejen de depender de `S3Service`; después se borran las clases del backend (`S3Service`, `S3StorageAdapter`, `FileStorageInterface`); finalmente se limpia `composer.json`, configuración y documentación. Cada commit deja la app en estado compilable.

**Tech Stack:** PHP 8.1+, CakePHP 5.x, Composer, Docker (Nginx + PHP-FPM). Sin suite de tests automatizados — la verificación se hace con `composer cs-check`, grep, arranque del servidor y pruebas manuales.

**Convención del proyecto (importante):**
- Todos los archivos PHP llevan `declare(strict_types=1);`.
- Coding standard: CakePHP CodeSniffer (`phpcs.xml`). Ejecutar `composer cs-fix && composer cs-check` antes de commitear.
- Mensajes de commit en español, sin scope específico (`feat:`, `chore:`, `refactor:`, `docs:`).
- Working directory: `C:\Users\sistema\Documents\mesa-de-ayuda` (Windows + PowerShell). Comandos `bin/cake` se invocan vía `docker compose exec web bin/cake ...` cuando se trabaja en Docker.

---

## Task 1: Pre-flight check — verificar que no hay datos S3 en BD

**Files:**
- Solo lectura: BD MySQL (tablas `attachments`, `users`)

El spec asume que `AWS_S3_ENABLED` siempre estuvo en `false` y que todos los `file_path` empiezan por `uploads/`. Confirmamos antes de tocar código.

- [ ] **Step 1: Listar attachments con paths que NO sean locales**

Conectarse a la BD (cliente MySQL, Adminer, etc.) y ejecutar:

```sql
SELECT id, ticket_id, original_filename, file_path
FROM attachments
WHERE file_path NOT LIKE 'uploads/%';
```

Esperado: **0 filas**.

- [ ] **Step 2: Listar usuarios con profile_image que NO sea local**

```sql
SELECT id, name, email, profile_image
FROM users
WHERE profile_image IS NOT NULL
  AND profile_image != ''
  AND profile_image NOT LIKE 'uploads/%';
```

Esperado: **0 filas**.

- [ ] **Step 3: Decidir según resultado**

- Si ambos queries devuelven 0 filas → continuar con Task 2.
- Si hay filas → **detener la implementación** y consultar al usuario qué hacer con esos registros (limpiar manualmente, dejarlos huérfanos, o ampliar el plan con un script de migración). El spec define que esos registros aparecerán como avatares por defecto / adjuntos no descargables tras la implementación.

- [ ] **Step 4: Sin commit en este task**

No se modifica código en este paso.

---

## Task 2: Refactor `UserHelper` — eliminar dependencia de `S3Service`

**Files:**
- Modify: `src/View/Helper/UserHelper.php`

`UserHelper::profileImage()` actualmente discrimina entre paths S3 y locales. Como solo habrá local, simplificamos.

- [ ] **Step 1: Reemplazar el contenido completo del archivo**

Reemplazar `src/View/Helper/UserHelper.php` con:

```php
<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\View\Helper;

/**
 * User Helper
 *
 * Provides helper methods for user-related display
 */
class UserHelper extends Helper
{
    /**
     * Get profile image URL with fallback to default avatar.
     *
     * Convención: el valor en BD es siempre 'uploads/...' o vacío.
     *
     * @param string|null $profileImage Profile image path from user entity
     * @return string URL to profile image or default avatar
     */
    public function profileImage(?string $profileImage): string
    {
        if (empty($profileImage)) {
            return $this->defaultAvatar();
        }

        if (!str_starts_with($profileImage, 'uploads/')) {
            return $this->defaultAvatar();
        }

        $normalizedPath = str_replace('/', DS, $profileImage);
        $fullPath = WWW_ROOT . $normalizedPath;

        if (file_exists($fullPath)) {
            return '/' . str_replace('\\', '/', $profileImage);
        }

        return $this->defaultAvatar();
    }

    /**
     * Get default avatar URL.
     *
     * @return string URL to default avatar
     */
    public function defaultAvatar(): string
    {
        return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"%3E%3Ccircle cx="20" cy="20" r="20" fill="%23cbd5e1"/%3E%3Cpath d="M20 20a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5z" fill="%23475569"/%3E%3C/svg%3E';
    }

    /**
     * Generate HTML img tag for user profile image.
     *
     * @param \App\Model\Entity\User|null $user User entity
     * @param array $options HTML attributes for img tag
     * @return string HTML img tag
     */
    public function profileImageTag($user, array $options = []): string
    {
        $defaults = [
            'class' => 'rounded-circle',
            'width' => '40',
            'height' => '40',
            'alt' => $user ? h($user->name) : 'Usuario',
        ];

        $options = array_merge($defaults, $options);
        $imageUrl = $user && $user->profile_image
            ? $this->profileImage($user->profile_image)
            : $this->defaultAvatar();

        $attributes = [];
        foreach ($options as $key => $value) {
            $attributes[] = sprintf('%s="%s"', h($key), h($value));
        }

        return sprintf('<img loading="lazy" src="%s" %s>', h($imageUrl), implode(' ', $attributes));
    }

    /**
     * Generate user avatar with name and optional profile image.
     *
     * @param \App\Model\Entity\User|null $user User entity
     * @param array $options Options for display (size, showName, imgClass)
     * @return string HTML for user avatar
     */
    public function avatar($user, array $options = []): string
    {
        $defaults = [
            'size' => 40,
            'showName' => true,
            'imgClass' => 'rounded-circle me-2',
            'nameClass' => '',
            'containerClass' => 'd-flex align-items-center',
        ];

        $options = array_merge($defaults, $options);

        if (!$user) {
            return '<span class="text-muted">Usuario desconocido</span>';
        }

        $imgOptions = [
            'class' => $options['imgClass'],
            'width' => $options['size'],
            'height' => $options['size'],
            'alt' => h($user->name),
        ];

        $html = '<div class="' . h($options['containerClass']) . '">';
        $html .= $this->profileImageTag($user, $imgOptions);

        if ($options['showName']) {
            $html .= '<span class="' . h($options['nameClass']) . '">' . h($user->name) . '</span>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Generate initials from user name for fallback display.
     *
     * @param string $name User name
     * @return string Initials (max 2 characters)
     */
    public function initials(string $name): string
    {
        $words = explode(' ', trim($name));
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
        }

        return strtoupper(substr($name, 0, 2));
    }
}
```

- [ ] **Step 2: Verificar que ya no hay referencias a S3 en este archivo**

Run (PowerShell):
```powershell
Select-String -Path "src\View\Helper\UserHelper.php" -Pattern "S3|s3"
```
Expected: sin coincidencias.

- [ ] **Step 3: cs-check**

Run:
```powershell
composer cs-check
```
Expected: PASS sin errores en `UserHelper.php`. Si falla, ejecutar `composer cs-fix` y re-validar.

- [ ] **Step 4: Commit**

```powershell
git add src/View/Helper/UserHelper.php
git commit -m "refactor: remove S3 dependency from UserHelper"
```

---

## Task 3: Refactor `ProfileImageService` — eliminar ramas S3

**Files:**
- Modify: `src/Service/ProfileImageService.php`

Eliminar el método `saveProfileImageToS3()` y todas las ramas que llaman a `S3Service`.

- [ ] **Step 1: Reemplazar el contenido completo del archivo**

Reemplazar `src/Service/ProfileImageService.php` con:

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;

/**
 * ProfileImageService
 *
 * Handles profile image upload, storage, and retrieval (local filesystem only).
 *
 * Extracted from UsersTable for SRP compliance.
 */
class ProfileImageService
{
    use LocatorAwareTrait;

    /**
     * Allowed image MIME types for profile images
     */
    private const ALLOWED_IMAGE_MIMES = [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    /**
     * Save profile image for a user (local storage only)
     *
     * @param int $userId User ID
     * @param \Psr\Http\Message\UploadedFileInterface $uploadedFile Uploaded file
     * @return array Result with success status and filename or error message
     */
    public function saveProfileImage(int $userId, $uploadedFile): array
    {
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            Log::error('Profile image upload error', ['error' => $uploadedFile->getError()]);
            return ['success' => false, 'message' => 'Error al subir el archivo'];
        }

        $filename = basename($uploadedFile->getClientFilename());
        $mimeType = $uploadedFile->getClientMediaType();
        $size = $uploadedFile->getSize();

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!isset(self::ALLOWED_IMAGE_MIMES[$extension])) {
            return ['success' => false, 'message' => 'Solo se permiten imágenes (JPG, PNG, GIF, WEBP)'];
        }

        if (!in_array($mimeType, self::ALLOWED_IMAGE_MIMES[$extension])) {
            return ['success' => false, 'message' => 'El tipo MIME no coincide con la extensión del archivo'];
        }

        if ($size > 2097152) {
            return ['success' => false, 'message' => 'La imagen no debe superar 2MB'];
        }

        // Verify actual MIME type from file content (finfo security check)
        $tempPath = $uploadedFile->getStream()->getMetadata('uri');
        if ($tempPath && file_exists($tempPath)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $actualMime = finfo_file($finfo, $tempPath);
                finfo_close($finfo);
                if ($actualMime !== false && !in_array($actualMime, self::ALLOWED_IMAGE_MIMES[$extension])) {
                    Log::error('Profile image MIME verification failed', [
                        'claimed' => $mimeType,
                        'actual' => $actualMime,
                    ]);
                    return ['success' => false, 'message' => 'El contenido del archivo no corresponde a una imagen válida'];
                }
            }
        }

        $uniqueFilename = 'user_' . $userId . '_' . Text::uuid() . '.' . $extension;

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($userId);
        if ($user->profile_image) {
            $this->deleteProfileImage($user->profile_image);
        }

        return $this->saveProfileImageLocally($uploadedFile, $uniqueFilename);
    }

    /**
     * Save profile image to local filesystem
     */
    private function saveProfileImageLocally($uploadedFile, string $uniqueFilename): array
    {
        $uploadDir = WWW_ROOT . 'uploads' . DS . 'profile_images' . DS;
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                Log::error('Failed to create profile images directory', ['dir' => $uploadDir]);
                return ['success' => false, 'message' => 'Error al crear directorio de imágenes'];
            }
        }

        $fullPath = $uploadDir . $uniqueFilename;

        try {
            $uploadedFile->moveTo($fullPath);
        } catch (\Exception $e) {
            Log::error('Failed to save profile image', [
                'error' => $e->getMessage(),
                'path' => $fullPath,
            ]);
            return ['success' => false, 'message' => 'Error al guardar la imagen'];
        }

        return ['success' => true, 'filename' => 'uploads/profile_images/' . $uniqueFilename];
    }

    /**
     * Delete a profile image file (local only)
     */
    public function deleteProfileImage(string $filename): bool
    {
        if (empty($filename)) {
            return false;
        }

        if (!str_starts_with($filename, 'uploads/')) {
            return false;
        }

        $fullPath = WWW_ROOT . $filename;
        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }

        return false;
    }

    /**
     * Get profile image URL with fallback to default avatar.
     */
    public function getProfileImageUrl(?string $profileImage): string
    {
        if (empty($profileImage)) {
            return '/img/default-avatar.png';
        }

        if (!str_starts_with($profileImage, 'uploads/')) {
            return '/img/default-avatar.png';
        }

        if (file_exists(WWW_ROOT . $profileImage)) {
            return '/' . str_replace(DS, '/', $profileImage);
        }

        return '/img/default-avatar.png';
    }
}
```

- [ ] **Step 2: Verificar ausencia de S3**

Run:
```powershell
Select-String -Path "src\Service\ProfileImageService.php" -Pattern "S3|s3"
```
Expected: sin coincidencias.

- [ ] **Step 3: cs-check**

Run:
```powershell
composer cs-check
```
Expected: PASS. Si falla en `ProfileImageService.php`, `composer cs-fix` y re-validar.

- [ ] **Step 4: Commit**

```powershell
git add src/Service/ProfileImageService.php
git commit -m "refactor: remove S3 branches from ProfileImageService"
```

---

## Task 4: Refactor `GenericAttachmentTrait` — eliminar ramas S3

**Files:**
- Modify: `src/Service/Traits/GenericAttachmentTrait.php`

Eliminar `S3_PREFIX`, `getS3Service()`, `buildS3Key()` y todas las ramas `if ($useS3)`. Conservar la validación de seguridad y el almacenamiento local intactos.

- [ ] **Step 1: Reemplazar el contenido completo del archivo**

Reemplazar `src/Service/Traits/GenericAttachmentTrait.php` con:

```php
<?php
declare(strict_types=1);

namespace App\Service\Traits;

use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\Utility\Text;
use Psr\Http\Message\UploadedFileInterface;

/**
 * GenericAttachmentTrait
 *
 * Ticket attachment handling with security validation (local filesystem only).
 *
 * Requires using class to have:
 * - fetchTable() method (from LocatorAwareTrait)
 */
trait GenericAttachmentTrait
{
    private const ATTACHMENTS_TABLE = 'Attachments';
    private const LOCAL_BASE = 'attachments';
    private const UPLOAD_BASE_DIR = 'uploads' . DS . 'attachments';

    /**
     * Allowed file extensions with their valid MIME types
     */
    private const ALLOWED_TYPES = [
        // Images
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'webp' => ['image/webp'],

        // Documents
        'pdf' => ['application/pdf', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],

        // Text
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],

        // Archives
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar-compressed', 'application/octet-stream'],
        '7z' => ['application/x-7z-compressed'],
    ];

    /**
     * Dangerous executable extensions that are NEVER allowed
     */
    private const FORBIDDEN_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar',
        'sh', 'app', 'deb', 'rpm', 'dmg', 'pkg', 'run', 'msi', 'dll',
        'sys', 'drv', 'cpl', 'scf', 'lnk', 'inf', 'reg',
        'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'cgi',
        'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd',
    ];

    private const MAX_FILE_SIZE = 10485760;
    private const MAX_IMAGE_SIZE = 5242880;

    /**
     * Save uploaded file with robust security validation.
     */
    public function saveGenericUploadedFile(
        EntityInterface $entity,
        UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null
    ): ?EntityInterface {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            Log::error('Ticket file upload error', ['error' => $file->getError()]);
            return null;
        }

        $originalFilename = $this->sanitizeFilename($file->getClientFilename());
        $mimeType = $file->getClientMediaType();
        $size = $file->getSize();

        $validation = $this->validateFile($originalFilename, $size, $mimeType);
        if ($validation !== true) {
            Log::error('Ticket file validation failed', [
                'filename' => $originalFilename,
                'reason' => $validation,
            ]);
            return null;
        }

        $tempPath = $file->getStream()->getMetadata('uri');
        if ($tempPath && !$this->verifyMimeTypeFromContent($tempPath, $mimeType, $originalFilename)) {
            Log::error('Ticket MIME type verification failed', [
                'filename' => $originalFilename,
                'claimed_mime' => $mimeType,
            ]);
            return null;
        }

        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $filename = Text::uuid() . '.' . $extension;
        $entityNumber = (string)$entity->ticket_number;

        $uploadDir = $this->getUploadDirectory($entityNumber);
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                Log::error('Failed to create ticket upload directory', ['dir' => $uploadDir]);
                return null;
            }
        }

        $fullPath = $uploadDir . DS . $filename;
        try {
            $file->moveTo($fullPath);
        } catch (\Exception $e) {
            Log::error('Failed to move ticket file', ['error' => $e->getMessage()]);
            return null;
        }

        $filePath = $this->buildLocalPath($entityNumber, $filename);

        $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);

        $data = $this->buildAttachmentData(
            $entity->id,
            $commentId,
            $userId,
            $originalFilename,
            $filename,
            $mimeType,
            $size,
            $filePath
        );

        $attachment = $attachmentsTable->newEntity($data, ['accessibleFields' => [
            'filename' => true, 'file_path' => true, 'mime_type' => true, 'file_size' => true,
            'is_inline' => true, 'content_id' => true, 'uploaded_by' => true,
        ]]);

        if (!$attachmentsTable->save($attachment)) {
            Log::error('Failed to save ticket attachment to database', [
                'errors' => $attachment->getErrors(),
            ]);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            return null;
        }

        return $attachment;
    }

    private function buildLocalPath(string $entityNumber, string $filename): string
    {
        return 'uploads/' . self::LOCAL_BASE . '/' . $entityNumber . '/' . $filename;
    }

    private function getUploadDirectory(string $entityNumber): string
    {
        return WWW_ROOT . self::UPLOAD_BASE_DIR . DS . $entityNumber;
    }

    protected function getAttachmentTableName(): string
    {
        return self::ATTACHMENTS_TABLE;
    }

    private function buildAttachmentData(
        int $entityId,
        ?int $commentId,
        ?int $userId,
        string $originalFilename,
        string $filename,
        string $mimeType,
        int $size,
        string $filePath
    ): array {
        return [
            'ticket_id' => $entityId,
            'comment_id' => $commentId,
            'uploaded_by' => $userId,
            'is_inline' => false,
            'original_filename' => $originalFilename,
            'filename' => $filename,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'file_size' => $size,
        ];
    }

    /**
     * Save attachment from binary content (for email attachments).
     */
    public function saveAttachmentFromBinary(
        EntityInterface $entity,
        string $filename,
        string $binaryContent,
        string $mimeType,
        ?int $commentId,
        int $userId
    ): ?EntityInterface {
        $filename = $this->sanitizeFilename($filename);
        $size = strlen($binaryContent);

        $validation = $this->validateFile($filename, $size, $mimeType);
        if ($validation !== true) {
            Log::error('Ticket attachment validation failed', [
                'filename' => $filename,
                'reason' => $validation,
            ]);
            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $uniqueFilename = Text::uuid() . '.' . $extension;
        $entityNumber = (string)$entity->ticket_number;

        $uploadDir = $this->getUploadDirectory($entityNumber);
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                Log::error('Failed to create ticket upload directory', ['dir' => $uploadDir]);
                return null;
            }
        }

        $fullPath = $uploadDir . DS . $uniqueFilename;
        if (file_put_contents($fullPath, $binaryContent) === false) {
            Log::error('Failed to write ticket file', ['path' => $fullPath]);
            return null;
        }

        $filePath = $this->buildLocalPath($entityNumber, $uniqueFilename);

        $data = $this->buildAttachmentData(
            $entity->id,
            $commentId,
            $userId,
            $filename,
            $uniqueFilename,
            $mimeType,
            $size,
            $filePath
        );

        $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);
        $attachment = $attachmentsTable->newEntity($data, ['accessibleFields' => [
            'filename' => true, 'file_path' => true, 'mime_type' => true, 'file_size' => true,
            'is_inline' => true, 'content_id' => true, 'uploaded_by' => true,
        ]]);

        if ($attachmentsTable->save($attachment)) {
            Log::info('Ticket attachment saved from binary', [
                'entity_id' => $entity->id,
                'filename' => $filename,
            ]);
            return $attachment;
        }

        @unlink($fullPath);

        Log::error('Failed to save ticket attachment to database', [
            'errors' => $attachment->getErrors(),
        ]);
        return null;
    }

    /**
     * Delete attachment file and database record.
     */
    public function deleteGenericAttachment(int $attachmentId): bool
    {
        try {
            $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);
            $attachment = $attachmentsTable->get($attachmentId);

            $fullPath = WWW_ROOT . $attachment->file_path;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }

            return $attachmentsTable->delete($attachment);
        } catch (\Exception $e) {
            Log::error('Failed to delete ticket attachment', [
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Validate file for security threats.
     */
    private function validateFile(string $filename, int $size, ?string $mimeType = null)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::FORBIDDEN_EXTENSIONS)) {
            return 'Executable files are not allowed';
        }

        if (!isset(self::ALLOWED_TYPES[$extension])) {
            return 'File type not allowed: ' . $extension;
        }

        $maxSize = str_starts_with($mimeType ?? '', 'image/')
            ? self::MAX_IMAGE_SIZE
            : self::MAX_FILE_SIZE;

        if ($size > $maxSize) {
            $maxMB = round($maxSize / 1048576, 1);
            return "File too large. Maximum size: {$maxMB}MB";
        }

        if ($size === 0) {
            return 'File is empty';
        }

        if ($mimeType !== null) {
            $allowedMimes = self::ALLOWED_TYPES[$extension];
            if (!in_array($mimeType, $allowedMimes)) {
                return 'MIME type does not match file extension';
            }
        }

        $parts = explode('.', $filename);
        if (count($parts) > 2) {
            foreach ($parts as $part) {
                $partExt = strtolower($part);
                if (in_array($partExt, self::FORBIDDEN_EXTENSIONS)) {
                    return 'Suspicious filename detected';
                }
            }
        }

        return true;
    }

    private function verifyMimeTypeFromContent(string $filePath, string $claimedMime, string $originalFilename): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            Log::warning('finfo not available for MIME verification, rejecting file');
            return false;
        }

        $actualMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if ($actualMime === false) {
            return false;
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_TYPES[$extension])) {
            return false;
        }

        $allowedMimes = self::ALLOWED_TYPES[$extension];

        if (in_array($actualMime, $allowedMimes)) {
            return true;
        }

        if ($actualMime === 'application/zip' && in_array($extension, ['docx', 'xlsx', 'pptx'])) {
            return true;
        }

        if (in_array($claimedMime, $allowedMimes)) {
            return true;
        }

        return false;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = str_replace("\0", '', $filename);
        $filename = str_replace(['../', '..\\'], '', $filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        if (strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $basename = substr($basename, 0, 250 - strlen($extension));
            $filename = $basename . '.' . $extension;
        }

        return $filename;
    }

    /**
     * Get full filesystem path for an attachment.
     */
    public function getFullPath(EntityInterface $attachment): ?string
    {
        $fullPath = WWW_ROOT . $attachment->file_path;
        return file_exists($fullPath) ? $fullPath : null;
    }

    /**
     * Get web URL for an attachment (always relative, local filesystem).
     */
    public function getWebUrl(EntityInterface $attachment): ?string
    {
        if (!str_starts_with((string)$attachment->file_path, 'uploads/')) {
            return null;
        }

        return '/' . str_replace(DS, '/', $attachment->file_path);
    }

    /**
     * Stream file content directly (for downloads).
     *
     * @return resource|null
     */
    public function getFileStream(EntityInterface $attachment)
    {
        $fullPath = WWW_ROOT . $attachment->file_path;
        if (file_exists($fullPath)) {
            return fopen($fullPath, 'rb');
        }

        return null;
    }
}
```

- [ ] **Step 2: Verificar ausencia de S3**

Run:
```powershell
Select-String -Path "src\Service\Traits\GenericAttachmentTrait.php" -Pattern "S3|s3"
```
Expected: sin coincidencias.

- [ ] **Step 3: cs-check**

Run:
```powershell
composer cs-check
```
Expected: PASS. Si falla, `composer cs-fix` y re-validar.

- [ ] **Step 4: Commit**

```powershell
git add src/Service/Traits/GenericAttachmentTrait.php
git commit -m "refactor: remove S3 branches from GenericAttachmentTrait"
```

---

## Task 5: Borrar `S3Service`, `S3StorageAdapter`, `FileStorageInterface` y carpeta `Storage/`

**Files:**
- Delete: `src/Service/S3Service.php`
- Delete: `src/Service/Storage/S3StorageAdapter.php`
- Delete: `src/Service/Storage/FileStorageInterface.php`
- Delete: `src/Service/Storage/` (carpeta vacía tras los deletes)

A esta altura ningún archivo en `src/` referencia estas clases. Es seguro borrarlas.

- [ ] **Step 1: Verificar que no quedan referencias activas**

Run:
```powershell
Select-String -Path "src\**\*.php" -Pattern "S3Service|S3StorageAdapter|FileStorageInterface" -Recurse
```
Expected: sin coincidencias (o solo dentro de los archivos a borrar).

Si aparecen otras referencias **fuera** de los tres archivos a borrar, detenerse y revisar — significa que un task anterior dejó algo pendiente.

- [ ] **Step 2: Borrar los tres archivos**

Run:
```powershell
Remove-Item src\Service\S3Service.php
Remove-Item src\Service\Storage\S3StorageAdapter.php
Remove-Item src\Service\Storage\FileStorageInterface.php
```

- [ ] **Step 3: Borrar la carpeta Storage/ (debería estar vacía)**

Run:
```powershell
Remove-Item src\Service\Storage -Recurse
```
Expected: éxito. Si Windows reporta "directory not empty" significa que quedó algún archivo — investigar antes de continuar.

- [ ] **Step 4: cs-check completo**

Run:
```powershell
composer cs-check
```
Expected: PASS. La eliminación no debería romper coding style en ningún archivo restante.

- [ ] **Step 5: Verificar que la app aún boota (sintaxis válida)**

Run:
```powershell
docker compose exec web php -l src/Service/Traits/GenericAttachmentTrait.php
docker compose exec web php -l src/Service/ProfileImageService.php
docker compose exec web php -l src/View/Helper/UserHelper.php
```
Expected: `No syntax errors detected` para los tres.

Si Docker no está corriendo, alternativa local:
```powershell
php -l src\Service\Traits\GenericAttachmentTrait.php
php -l src\Service\ProfileImageService.php
php -l src\View\Helper\UserHelper.php
```

- [ ] **Step 6: Commit**

```powershell
git add -A src/Service/S3Service.php src/Service/Storage
git commit -m "refactor: drop S3Service, S3StorageAdapter and FileStorageInterface"
```

Nota: `git add -A` con paths específicos registra los deletes. `Storage/` se borra como directorio porque ya no contiene archivos rastreados.

---

## Task 6: Eliminar `aws/aws-sdk-php` de Composer

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock` (regenerado por composer)

- [ ] **Step 1: Quitar la línea `aws/aws-sdk-php` de `composer.json`**

Editar `composer.json` y eliminar la línea 9:

Antes:
```json
"require": {
    "php": ">=8.1",
    "aws/aws-sdk-php": "^3.369",
    "cakephp/authentication": "^3.0",
```

Después:
```json
"require": {
    "php": ">=8.1",
    "cakephp/authentication": "^3.0",
```

- [ ] **Step 2: Regenerar el lockfile y vendor**

Run (preferentemente dentro del contenedor para que el lockfile quede consistente con la plataforma de runtime):

```powershell
docker compose exec web composer update --lock
docker compose exec web composer install --no-dev --optimize-autoloader
```

Si trabajas fuera de Docker, alternativa local:
```powershell
composer update --lock
composer install
```

Expected: composer regenera `composer.lock` sin paquetes `aws/*` y `mtdowling/jmespath.php`/etc. (dependencias transitivas del SDK).

- [ ] **Step 3: Verificar que no quedan paquetes AWS en el lock**

Run:
```powershell
Select-String -Path "composer.lock" -Pattern '"name": "aws/'
```
Expected: sin coincidencias.

- [ ] **Step 4: Smoke test — la app boota**

Run:
```powershell
docker compose up -d --build
```

Esperar ~30s y luego:
```powershell
curl http://localhost:8082/health
```
Expected: respuesta HTTP 200 con JSON de health.

- [ ] **Step 5: Commit**

```powershell
git add composer.json composer.lock
git commit -m "chore: remove aws/aws-sdk-php dependency"
```

---

## Task 7: Limpiar `config/app_local.example.php`

**Files:**
- Modify: `config/app_local.example.php`

Eliminar el bloque `'AWS' => ['S3' => [...]]` y los comentarios introductorios.

- [ ] **Step 1: Eliminar el bloque AWS**

Borrar las líneas 103-124 de `config/app_local.example.php`. El bloque a eliminar es:

```php
    /*
     * AWS S3 Configuration
     *
     * Configuration for AWS S3 file storage integration.
     * Set AWS_S3_ENABLED=true to enable S3 storage.
     *
     * Environment variables:
     * - AWS_ACCESS_KEY_ID: AWS IAM user access key
     * - AWS_SECRET_ACCESS_KEY: AWS IAM user secret key
     * - AWS_REGION: AWS region (e.g., us-east-1)
     * - AWS_S3_BUCKET: S3 bucket name
     * - AWS_S3_ENABLED: Enable/disable S3 storage (true/false)
     */
    'AWS' => [
        'S3' => [
            'enabled' => filter_var(env('AWS_S3_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'key' => env('AWS_ACCESS_KEY_ID', ''),
            'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
            'region' => env('AWS_REGION', ''),
            'bucket' => env('AWS_S3_BUCKET', ''),
        ],
    ],
```

El archivo debe terminar con la coma de cierre del bloque `EmailTransport` y luego `];`. Verificar que la última línea siga siendo `];`.

- [ ] **Step 2: Verificar ausencia de AWS**

Run:
```powershell
Select-String -Path "config\app_local.example.php" -Pattern "AWS|S3|aws"
```
Expected: sin coincidencias.

- [ ] **Step 3: Validar sintaxis**

Run:
```powershell
php -l config\app_local.example.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```powershell
git add config/app_local.example.php
git commit -m "chore: remove AWS S3 config block from app_local.example"
```

---

## Task 8: Actualizar `README.md`

**Files:**
- Modify: `README.md`

Quitar todas las menciones a S3, AWS SDK y `FileStorageInterface`.

- [ ] **Step 1: Línea 3 — introducción**

Reemplazar:
```markdown
Plataforma corporativa de mesa de ayuda desarrollada en **CakePHP 5.x**. Incluye integraciones nativas con Gmail, n8n, WhatsApp (Evolution API) y AWS S3.
```

Con:
```markdown
Plataforma corporativa de mesa de ayuda desarrollada en **CakePHP 5.x**. Incluye integraciones nativas con Gmail, n8n y WhatsApp (Evolution API).
```

- [ ] **Step 2: Línea 46 — tabla de integraciones**

Eliminar la fila completa:
```markdown
| **AWS S3** | Almacenamiento de adjuntos a través de `FileStorageInterface` (conmutable con `AWS_S3_ENABLED`). |
```

- [ ] **Step 3: Línea 57 — stack técnico**

Eliminar la línea:
```markdown
- **AWS SDK:** `aws/aws-sdk-php`
```

- [ ] **Step 4: Línea 120 — tabla de variables de entorno**

Eliminar la fila completa:
```markdown
| `AWS_S3_ENABLED`, `AWS_S3_BUCKET`, `AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Configuración de S3 |
```

- [ ] **Step 5: Línea 163 — diagrama de estructura**

Reemplazar:
```
│   ├── Storage/            # Abstracción FileStorageInterface (local / S3)
│   └── Traits/             # Mixins reutilizables (Notification, Attachment…)
```

Con:
```
│   └── Traits/             # Mixins reutilizables (Notification, Attachment…)
```

- [ ] **Step 6: Línea 186 — convención de adjuntos**

Reemplazar:
```markdown
- **Adjuntos:** uso compartido vía `GenericAttachmentTrait` y `FileStorageInterface`, lo que permite alternar entre disco local y S3 sin cambios en los controladores.
```

Con:
```markdown
- **Adjuntos:** uso compartido vía `GenericAttachmentTrait`. Almacenamiento en disco local bajo `webroot/uploads/attachments/{ticket_number}/`.
```

- [ ] **Step 7: Verificar limpieza completa**

Run:
```powershell
Select-String -Path "README.md" -Pattern "S3|AWS|aws-sdk|FileStorage"
```
Expected: sin coincidencias.

- [ ] **Step 8: Commit**

```powershell
git add README.md
git commit -m "docs: remove S3 references from README"
```

---

## Task 9: Actualizar `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Línea 7 — descripción del proyecto**

Reemplazar:
```markdown
**Mesa de Ayuda** — CakePHP 5.x corporate helpdesk platform built around the **Tickets** module. Backend in PHP 8.1+, MySQL/MariaDB, server-rendered Bootstrap 5 templates. Integrates Gmail (email-to-ticket), n8n (webhooks), WhatsApp via Evolution API, and AWS S3.
```

Con:
```markdown
**Mesa de Ayuda** — CakePHP 5.x corporate helpdesk platform built around the **Tickets** module. Backend in PHP 8.1+, MySQL/MariaDB, server-rendered Bootstrap 5 templates. Integrates Gmail (email-to-ticket), n8n (webhooks), and WhatsApp via Evolution API.
```

- [ ] **Step 2: Línea 41 — capa de servicios**

Reemplazar:
```markdown
- **`src/Service/`** — Business logic. Domain service `TicketService`, integrations (`GmailService`, `EmailService`, `WhatsappService`, `N8nService`, `S3Service`), cross-cutting helpers (`SidebarCountsService`, `NumberGenerationService`, `EmailTemplateRenderer`, `SettingsService`, `AuthorizationService`, `ProfileImageService`). Reusable mixin logic lives in `src/Service/Traits/` (e.g. `NotificationDispatcherTrait`, `GenericAttachmentTrait`, `TicketSystemTrait`, `ConfigResolutionTrait`, `SecureHttpTrait`). File storage is abstracted through `src/Service/Storage/FileStorageInterface.php` with an `S3StorageAdapter` implementation.
```

Con:
```markdown
- **`src/Service/`** — Business logic. Domain service `TicketService`, integrations (`GmailService`, `EmailService`, `WhatsappService`, `N8nService`), cross-cutting helpers (`SidebarCountsService`, `NumberGenerationService`, `EmailTemplateRenderer`, `SettingsService`, `AuthorizationService`, `ProfileImageService`). Reusable mixin logic lives in `src/Service/Traits/` (e.g. `NotificationDispatcherTrait`, `GenericAttachmentTrait`, `TicketSystemTrait`, `ConfigResolutionTrait`, `SecureHttpTrait`). Attachments are stored on local disk under `webroot/uploads/`.
```

- [ ] **Step 3: Línea 62 — convención de attachments**

Reemplazar:
```markdown
- **Attachments**: shared via `GenericAttachmentTrait` and the `FileStorageInterface` abstraction so the same code path works against local disk and S3 (toggled by `AWS_S3_ENABLED`).
```

Con:
```markdown
- **Attachments**: shared via `GenericAttachmentTrait`. Files are stored on local disk under `webroot/uploads/attachments/{ticket_number}/`. Profile images live under `webroot/uploads/profile_images/`.
```

- [ ] **Step 4: Línea 69 — variables de entorno enumeradas**

Reemplazar:
```markdown
- Runtime configuration is environment-driven; `docker-compose.yml` enumerates the variables (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SECURITY_SALT`, `TRUST_PROXY`, `FULL_BASE_URL`, AWS S3 vars).
```

Con:
```markdown
- Runtime configuration is environment-driven; `docker-compose.yml` enumerates the variables (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SECURITY_SALT`, `TRUST_PROXY`, `FULL_BASE_URL`).
```

- [ ] **Step 5: Verificar limpieza**

Run:
```powershell
Select-String -Path "CLAUDE.md" -Pattern "S3|AWS|FileStorage"
```
Expected: sin coincidencias.

- [ ] **Step 6: Commit**

```powershell
git add CLAUDE.md
git commit -m "docs: remove S3 references from CLAUDE.md"
```

---

## Task 10: Verificación final integral

**Files:** ninguno (solo verificación).

- [ ] **Step 1: Grep global por S3/AWS/aws-sdk/FileStorage en código y config**

Run:
```powershell
Select-String -Path "src","config","docker-compose.yml","composer.json" -Pattern "S3|aws-sdk|FileStorage|AWS_" -Recurse
```
Expected: sin coincidencias.

Si hay falsos positivos en otros contextos (p.ej. la palabra "aws" como parte de otro identificador), evaluar caso por caso.

- [ ] **Step 2: cs-check global**

Run:
```powershell
composer cs-check
```
Expected: PASS sin errores.

- [ ] **Step 3: Smoke test — boot del servidor**

Run:
```powershell
docker compose down
docker compose up -d --build
```

Esperar ~30 segundos y luego:
```powershell
curl http://localhost:8082/health
```
Expected: HTTP 200 con JSON `{"status":"ok",...}` o equivalente.

- [ ] **Step 4: Smoke test manual — adjunto en ticket**

1. Abrir `http://localhost:8082` en el navegador y autenticarse.
2. Crear un ticket nuevo o abrir uno existente.
3. Subir un adjunto válido (ej. PDF pequeño o imagen JPG).
4. Verificar que aparece en el listado del ticket.
5. Verificar que el archivo existe en `webroot/uploads/attachments/{ticket_number}/{uuid}.ext`.
6. Hacer clic en el adjunto → debe descargarse / mostrarse correctamente.
7. Eliminar el adjunto desde la UI → debe desaparecer del disco y de la BD.

- [ ] **Step 5: Smoke test manual — imagen de perfil**

1. Ir al perfil del usuario.
2. Subir una imagen JPG/PNG nueva.
3. Verificar que aparece en el sidebar/header.
4. Verificar que existe en `webroot/uploads/profile_images/`.

- [ ] **Step 6: Smoke test — webhook Gmail (opcional, si hay entorno disponible)**

Disparar `POST /webhooks/gmail/import` con el token configurado contra una cuenta de prueba con un correo que contenga adjunto. Verificar que el ticket se crea y que el adjunto entra al disco bajo `webroot/uploads/attachments/{ticket_number}/`.

- [ ] **Step 7: Confirmar `docker-compose.yml`**

Run:
```powershell
Select-String -Path "docker-compose.yml" -Pattern "AWS"
```
Expected: sin coincidencias.

(Nota: el archivo actual ya está limpio — confirmado en pre-flight. Este step es defensa en profundidad.)

- [ ] **Step 8: Sin commit**

Esta tarea es solo verificación; ningún cambio nuevo.

---

## Resumen de commits esperados

Al cerrar el plan, `git log --oneline` debería mostrar (en orden):

1. `refactor: remove S3 dependency from UserHelper`
2. `refactor: remove S3 branches from ProfileImageService`
3. `refactor: remove S3 branches from GenericAttachmentTrait`
4. `refactor: drop S3Service, S3StorageAdapter and FileStorageInterface`
5. `chore: remove aws/aws-sdk-php dependency`
6. `chore: remove AWS S3 config block from app_local.example`
7. `docs: remove S3 references from README`
8. `docs: remove S3 references from CLAUDE.md`

Más el commit del spec previo a la implementación (`docs: add spec for eliminar integración con S3`).
