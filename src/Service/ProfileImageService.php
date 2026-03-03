<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;

/**
 * ProfileImageService
 *
 * Handles profile image upload, storage, and retrieval.
 * Supports both S3 and local filesystem storage.
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
     * Save profile image for a user (supports S3 and local storage)
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

        // Delete old profile image before uploading new one
        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($userId);
        if ($user->profile_image) {
            $this->deleteProfileImage($user->profile_image);
        }

        // Check if S3 is enabled
        $s3Service = new S3Service();
        if ($s3Service->isEnabled()) {
            return $this->saveProfileImageToS3($s3Service, $uploadedFile, $uniqueFilename, $mimeType);
        }

        return $this->saveProfileImageLocally($uploadedFile, $uniqueFilename);
    }

    /**
     * Save profile image to S3
     */
    private function saveProfileImageToS3(S3Service $s3Service, $uploadedFile, string $uniqueFilename, string $mimeType): array
    {
        $s3Key = 'profile_images/' . $uniqueFilename;
        $tempPath = sys_get_temp_dir() . DS . $uniqueFilename;

        try {
            $uploadedFile->moveTo($tempPath);

            if (!$s3Service->uploadFile($tempPath, $s3Key, $mimeType)) {
                @unlink($tempPath);
                return ['success' => false, 'message' => 'Error al subir la imagen a S3'];
            }

            @unlink($tempPath);

            return ['success' => true, 'filename' => $s3Key];
        } catch (\Exception $e) {
            Log::error('Failed to save profile image to S3', ['error' => $e->getMessage()]);
            @unlink($tempPath);
            return ['success' => false, 'message' => 'Error al guardar la imagen'];
        }
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
     * Delete a profile image file (S3 or local)
     */
    public function deleteProfileImage(string $filename): bool
    {
        if (empty($filename)) {
            return false;
        }

        if (!str_starts_with($filename, 'uploads/')) {
            $s3Service = new S3Service();
            if ($s3Service->isEnabled()) {
                return $s3Service->deleteFile($filename);
            }

            return false;
        }

        $fullPath = WWW_ROOT . $filename;
        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }

        return false;
    }

    /**
     * Get profile image URL with fallback to default avatar
     */
    public function getProfileImageUrl(?string $profileImage): string
    {
        if (empty($profileImage)) {
            return '/img/default-avatar.png';
        }

        if (!str_starts_with($profileImage, 'uploads/')) {
            $s3Service = new S3Service();
            if ($s3Service->isEnabled()) {
                $url = $s3Service->getPresignedUrl($profileImage, 60);
                if ($url) {
                    return $url;
                }
            }

            return '/img/default-avatar.png';
        }

        if (file_exists(WWW_ROOT . $profileImage)) {
            return '/' . str_replace(DS, '/', $profileImage);
        }

        return '/img/default-avatar.png';
    }
}
