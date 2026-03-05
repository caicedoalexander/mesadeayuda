<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Service\S3Service;
use Cake\View\Helper;

/**
 * User Helper
 *
 * Provides helper methods for user-related display
 */
class UserHelper extends Helper
{
    private ?S3Service $s3Service = null;

    /**
     * Get S3Service instance (lazy loaded)
     *
     * @return S3Service
     */
    private function getS3Service(): S3Service
    {
        return $this->s3Service ??= new S3Service();
    }

    /**
     * Get profile image URL with fallback to default avatar
     *
     * Supports both S3 and local paths.
     * Convention: 'uploads/' prefix = local, no prefix = S3.
     *
     * @param string|null $profileImage Profile image path from user entity
     * @return string URL to profile image or default avatar
     */
    public function profileImage(?string $profileImage): string
    {
        if (empty($profileImage)) {
            return $this->defaultAvatar();
        }

        // S3 file (no 'uploads/' prefix)
        if (!str_starts_with($profileImage, 'uploads/')) {
            $s3Service = $this->getS3Service();
            if ($s3Service->isEnabled()) {
                $url = $s3Service->getPresignedUrl($profileImage, 60);
                if ($url) {
                    return $url;
                }
            }

            return $this->defaultAvatar();
        }

        // Local file
        $normalizedPath = str_replace('/', DS, $profileImage);
        $fullPath = WWW_ROOT . $normalizedPath;

        if (file_exists($fullPath)) {
            return '/' . str_replace('\\', '/', $profileImage);
        }

        return $this->defaultAvatar();
    }

    /**
     * Get default avatar URL (can be customized later)
     *
     * @return string URL to default avatar
     */
    public function defaultAvatar(): string
    {
        // For now, return a data URI for a simple gray circle with user icon
        // In future, could use actual image file
        return 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"%3E%3Ccircle cx="20" cy="20" r="20" fill="%23cbd5e1"/%3E%3Cpath d="M20 20a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.42 0-8 2.24-8 5v3h16v-3c0-2.76-3.58-5-8-5z" fill="%23475569"/%3E%3C/svg%3E';
    }

    /**
     * Generate HTML img tag for user profile image
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
     * Generate user avatar with name and optional profile image
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
     * Generate initials from user name for fallback display
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
