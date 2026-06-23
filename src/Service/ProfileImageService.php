<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use Psr\Http\Message\UploadedFileInterface;

/**
 * ProfileImageService
 *
 * Handles profile image upload, storage (AWS S3), and retrieval.
 *
 * Extracted from UsersTable for SRP compliance.
 */
class ProfileImageService
{
    use LocatorAwareTrait;

    private const STORAGE_KEY_PREFIX = 'profile_images/';
    private const MAX_IMAGE_SIZE = 2097152;

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

    private ?S3StorageService $storage;

    /**
     * @param \App\Service\S3StorageService|null $storage Inyección para tests;
     *        en producción se construye lazy.
     */
    public function __construct(?S3StorageService $storage = null)
    {
        $this->storage = $storage;
    }

    /**
     * Save profile image for a user (S3 storage)
     *
     * @param int $userId User ID
     * @param \Psr\Http\Message\UploadedFileInterface $uploadedFile Uploaded file
     * @return array Result with success status and filename (S3 key) or error message
     */
    public function saveProfileImage(int $userId, UploadedFileInterface $uploadedFile): array
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

        if ($size > self::MAX_IMAGE_SIZE) {
            return ['success' => false, 'message' => 'La imagen no debe superar 2MB'];
        }

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

                    return [
                        'success' => false,
                        'message' => 'El contenido del archivo no corresponde a una imagen válida',
                    ];
                }
            }
        }

        $uniqueFilename = Text::uuid() . '.' . $extension;
        $key = self::STORAGE_KEY_PREFIX . $userId . '/' . $uniqueFilename;

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($userId);
        $previousKey = (string)$user->profile_image;

        $stream = $uploadedFile->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        if (!$this->storage()->put($key, (string)$stream, (string)$mimeType)) {
            Log::error('Failed to upload profile image to S3', ['key' => $key]);

            return ['success' => false, 'message' => 'Error al guardar la imagen'];
        }

        // Borrar la imagen anterior solo cuando la nueva ya está en S3:
        // si el put falla, el usuario conserva su avatar actual (best-effort,
        // las claves son UUID únicas y no colisionan).
        if ($previousKey !== '') {
            $this->deleteProfileImage($previousKey);
        }

        return ['success' => true, 'filename' => $key];
    }

    /**
     * Delete a profile image object from S3
     *
     * @param string $key S3 key as stored in users.profile_image
     * @return bool True on success
     */
    public function deleteProfileImage(string $key): bool
    {
        if (empty($key) || !str_starts_with($key, self::STORAGE_KEY_PREFIX)) {
            return false;
        }

        return $this->storage()->delete($key);
    }

    /**
     * URL presignada (inline) para servir la imagen de perfil vía 302.
     *
     * @param string $key S3 key as stored in users.profile_image
     * @return string|null
     */
    public function presignedImageUrl(string $key): ?string
    {
        if (!str_starts_with($key, self::STORAGE_KEY_PREFIX)) {
            return null;
        }

        return $this->storage()->presignedUrl($key, basename($key), inline: true);
    }

    /**
     * @return \App\Service\S3StorageService
     */
    private function storage(): S3StorageService
    {
        return $this->storage ??= new S3StorageService();
    }
}
