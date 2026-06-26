<?php
declare(strict_types=1);

namespace App\View\Helper;

use Cake\I18n\DateTime;
use Cake\View\Helper;
use DateTimeInterface;
use Throwable;

class TimeHumanHelper extends Helper
{
    /**
     * Formato corto para fechas de tickets:
     * - < 1 hora: "hace N minutos/segundos"
     * - Hoy y >= 1 hora: "g:i A" (12h)
     * - Otro día: "d MMM" en español
     */
    public function short(string|DateTimeInterface|null $date): string
    {
        if ($date === null) {
            return '-';
        }

        if (!($date instanceof DateTime)) {
            try {
                $date = new DateTime($date);
            } catch (Throwable $e) {
                return (string)$date;
            }
        }

        $now = DateTime::now();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 3600) {
            if ($diff < 60) {
                $secs = max(1, (int)$diff);

                return "hace {$secs} segundos";
            }
            $mins = (int)floor($diff / 60);

            return "hace {$mins} minuto" . ($mins === 1 ? '' : 's');
        }

        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            return $date->format('g:i A');
        }

        return $date->i18nFormat('d MMM', null, 'es_US');
    }

    /**
     * Formato largo para fechas completas
     */
    public function long(string|DateTimeInterface|null $date): string
    {
        if ($date === null) {
            return '-';
        }

        if (!($date instanceof DateTime)) {
            try {
                $date = new DateTime($date);
            } catch (Throwable $e) {
                return (string)$date;
            }
        }

        return $date->i18nFormat('d MMMM, h:mm a', null, 'es_US');
    }
}
