<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Model\Entity\User;
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
     * Convención: el valor en BD es una clave S3 'profile_images/...' o vacío.
     * La URL devuelta es la ruta estable de la app, que redirige 302 a una
     * URL presignada de S3.
     *
     * @param string|null $profileImage Profile image S3 key from user entity
     * @param int|null $userId User id that owns the image
     * @return string URL to profile image or default avatar
     */
    public function profileImage(?string $profileImage, ?int $userId = null): string
    {
        if (empty($profileImage) || $userId === null) {
            return $this->defaultAvatar();
        }

        if (!str_starts_with($profileImage, 'profile_images/')) {
            return $this->defaultAvatar();
        }

        return '/profile-images/view/' . $userId;
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
    public function profileImageTag(?User $user, array $options = []): string
    {
        $defaults = [
            'class' => 'rounded-circle',
            'width' => '40',
            'height' => '40',
            'alt' => $user ? h($user->name) : 'Usuario',
        ];

        $options = array_merge($defaults, $options);
        $imageUrl = $user && $user->profile_image
            ? $this->profileImage($user->profile_image, (int)$user->id)
            : $this->defaultAvatar();

        $attributes = [];
        foreach ($options as $key => $value) {
            $attributes[] = sprintf('%s="%s"', h($key), h($value));
        }

        return sprintf('<img loading="lazy" src="%s" %s>', h($imageUrl), implode(' ', $attributes));
    }

    /**
     * Generate initials for fallback avatars. Accepts either a User entity or
     * a raw name string.
     *
     * @param \App\Model\Entity\User|string|null $userOrName User entity or name string
     * @param int $max Maximum number of initials to return (default 2)
     * @return string Initials (max 2 characters)
     */
    public function initials(User|string|null $userOrName, int $max = 2): string
    {
        $name = $userOrName instanceof User ? $userOrName->name : (string)$userOrName;
        $name = trim($name);
        if ($name === '') {
            return '?';
        }
        $words = preg_split('/\s+/', $name) ?: [];
        $initials = '';
        foreach ($words as $w) {
            if ($w === '') {
                continue;
            }
            $initials .= mb_substr($w, 0, 1);
            if (mb_strlen($initials) >= $max) {
                break;
            }
        }

        return mb_strtoupper($initials);
    }

    /**
     * Deterministic avatar color from a name. Same palette as the JS agent
     * picker (webroot/js/select2-init.js) so the same person always gets the
     * same color across the app.
     *
     * @param \App\Model\Entity\User|string|null $userOrName User entity or name string
     * @return string Hex color
     */
    public function avatarColor(User|string|null $userOrName): string
    {
        $palette = [
            '#00A85E', // admin-green
            '#CD6A15', // admin-orange
            '#0066cc', // admin-blue
            '#7c3aed', // violet
            '#0891b2', // cyan
            '#dc3545', // danger
            '#6366f1', // indigo
        ];
        $name = $userOrName instanceof User ? $userOrName->name : (string)$userOrName;
        $name = trim($name);
        if ($name === '') {
            return $palette[0];
        }
        $hash = 0;
        $len = mb_strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $char = mb_ord(mb_substr($name, $i, 1)) ?: 0;
            $hash = (($hash * 31 + $char) & 0x7FFFFFFF);
        }

        return $palette[$hash % count($palette)];
    }
}
