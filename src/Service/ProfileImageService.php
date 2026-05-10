<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use Exception;
use Psr\Http\Message\UploadedFileInterface;

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

        if ($size > 2097152) {
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
     *
     * @param \Psr\Http\Message\UploadedFileInterface $uploadedFile Uploaded file
     * @param string $uniqueFilename Generated unique filename
     * @return array Result with success status and filename or error message
     */
    private function saveProfileImageLocally(UploadedFileInterface $uploadedFile, string $uniqueFilename): array
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
        } catch (Exception $e) {
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
     *
     * @param string $filename Filename relative path
     * @return bool True on success
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
}
