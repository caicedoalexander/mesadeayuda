<?php
declare(strict_types=1);

namespace App\Service;

use App\Utility\ValidationConstants;
use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * SLA Management Service
 *
 * Centralized service for handling SLA (Service Level Agreement) calculations
 * and management across all modules (Tickets, Compras, PQRS).
 *
 * SLA Configuration is stored in SystemSettings table with keys:
 * - sla_pqrs_{tipo}_first_response_days (peticion, queja, reclamo, sugerencia)
 * - sla_pqrs_{tipo}_resolution_days
 * - sla_compras_first_response_days
 * - sla_compras_resolution_days
 * - sla_tickets_first_response_hours (if implemented)
 * - sla_tickets_resolution_hours (if implemented)
 */
class SlaManagementService
{
    use LocatorAwareTrait;

    private const CACHE_KEY = 'sla_settings';
    private const CACHE_CONFIG = ValidationConstants::CACHE_CONFIG;

    /**
     * Get SLA settings for PQRS by type
     *
     * @param string $type PQRS type (peticion, queja, reclamo, sugerencia)
     * @return array{first_response_days: int, resolution_days: int}
     */
    public function getPqrsSlaSettings(string $type): array
    {
        $settings = $this->getSlaSettings();

        $firstResponseKey = "sla_pqrs_{$type}_first_response_days";
        $resolutionKey = "sla_pqrs_{$type}_resolution_days";

        return [
            'first_response_days' => (int)($settings[$firstResponseKey] ?? $this->getDefaultPqrsSla($type)['first_response_days']),
            'resolution_days' => (int)($settings[$resolutionKey] ?? $this->getDefaultPqrsSla($type)['resolution_days']),
        ];
    }

    /**
     * Get SLA settings for Compras
     *
     * @return array{first_response_days: int, resolution_days: int}
     */
    public function getComprasSlaSettings(): array
    {
        $settings = $this->getSlaSettings();

        return [
            'first_response_days' => (int)($settings['sla_compras_first_response_days'] ?? 1),
            'resolution_days' => (int)($settings['sla_compras_resolution_days'] ?? 3),
        ];
    }

    /**
     * Calculate SLA deadlines for PQRS
     *
     * @param string $type PQRS type
     * @param \Cake\I18n\DateTime|null $createdDate Creation date (defaults to now)
     * @return array{first_response_sla_due: DateTime, resolution_sla_due: DateTime}
     */
    public function calculatePqrsSlaDeadlines(string $type, ?\Cake\I18n\DateTime $createdDate = null): array
    {
        $createdDate = $createdDate ?? new DateTime();
        $slaConfig = $this->getPqrsSlaSettings($type);

        return [
            'first_response_sla_due' => (clone $createdDate)->modify("+{$slaConfig['first_response_days']} days"),
            'resolution_sla_due' => (clone $createdDate)->modify("+{$slaConfig['resolution_days']} days"),
        ];
    }

    /**
     * Calculate SLA deadlines for Compras
     *
     * @param \Cake\I18n\DateTime|null $createdDate Creation date (defaults to now)
     * @return array{first_response_sla_due: DateTime, resolution_sla_due: DateTime}
     */
    public function calculateComprasSlaDeadlines(?\Cake\I18n\DateTime $createdDate = null): array
    {
        $createdDate = $createdDate ?? new DateTime();
        $slaConfig = $this->getComprasSlaSettings();

        return [
            'first_response_sla_due' => (clone $createdDate)->modify("+{$slaConfig['first_response_days']} days"),
            'resolution_sla_due' => (clone $createdDate)->modify("+{$slaConfig['resolution_days']} days"),
        ];
    }

    /**
     * Check if first response SLA is breached
     *
     * @param \Cake\I18n\DateTime|null $firstResponseSladue First response deadline
     * @param \Cake\I18n\DateTime|null $firstResponseAt Actual first response time
     * @param string $status Current status
     * @return bool True if breached
     */
    public function isFirstResponseSlaBreached(
        ?\Cake\I18n\DateTime $firstResponseSlaDue,
        ?\Cake\I18n\DateTime $firstResponseAt,
        string $status
    ): bool {
        // If already responded, no breach
        if ($firstResponseAt !== null) {
            return false;
        }

        // If closed statuses, consider no breach (data might be incomplete)
        if (in_array($status, ['completado', 'cerrado', 'rechazado', 'resuelto'])) {
            return false;
        }

        // No SLA deadline set
        if ($firstResponseSlaDue === null) {
            return false;
        }

        $now = new DateTime();
        return $now > $firstResponseSlaDue;
    }

    /**
     * Check if resolution SLA is breached
     *
     * @param \Cake\I18n\DateTime|null $resolutionSlaDue Resolution deadline
     * @param \Cake\I18n\DateTime|null $resolvedAt Actual resolution time
     * @param string $status Current status
     * @return bool True if breached
     */
    public function isResolutionSlaBreached(
        ?\Cake\I18n\DateTime $resolutionSlaDue,
        ?\Cake\I18n\DateTime $resolvedAt,
        string $status
    ): bool {
        // If already resolved, no breach
        if ($resolvedAt !== null) {
            return false;
        }

        // If closed statuses, consider no breach (data might be incomplete)
        if (in_array($status, ['completado', 'cerrado', 'rechazado', 'resuelto'])) {
            return false;
        }

        // No SLA deadline set
        if ($resolutionSlaDue === null) {
            return false;
        }

        $now = new DateTime();
        return $now > $resolutionSlaDue;
    }

    /**
     * Get SLA status badge information
     *
     * @param \Cake\I18n\DateTime|null $slaDue SLA deadline
     * @param \Cake\I18n\DateTime|null $completedAt Completion time
     * @param string $status Current status
     * @return array{status: string, class: string, label: string}
     */
    public function getSlaStatus(
        ?\Cake\I18n\DateTime $slaDue,
        ?\Cake\I18n\DateTime $completedAt,
        string $status
    ): array {
        // Completed on time
        if ($completedAt !== null && $slaDue !== null && $completedAt <= $slaDue) {
            return [
                'status' => 'met',
                'class' => 'success',
                'label' => 'SLA Cumplido'
            ];
        }

        // Completed but breached
        if ($completedAt !== null && $slaDue !== null && $completedAt > $slaDue) {
            return [
                'status' => 'breached_resolved',
                'class' => 'warning',
                'label' => 'SLA Incumplido (Resuelto)'
            ];
        }

        // Not completed - check if breached
        if ($slaDue !== null) {
            $now = new DateTime();

            if ($now > $slaDue) {
                return [
                    'status' => 'breached',
                    'class' => 'danger',
                    'label' => 'SLA Vencido'
                ];
            }

            // Check if approaching (less than 25% time remaining)
            $totalTime = $slaDue->getTimestamp() - (new DateTime())->modify('-7 days')->getTimestamp();
            $remainingTime = $slaDue->getTimestamp() - $now->getTimestamp();

            if ($remainingTime < ($totalTime * 0.25)) {
                return [
                    'status' => 'approaching',
                    'class' => 'warning',
                    'label' => 'SLA Próximo a Vencer'
                ];
            }

            return [
                'status' => 'on_track',
                'class' => 'info',
                'label' => 'Dentro de SLA'
            ];
        }

        // No SLA defined
        return [
            'status' => 'none',
            'class' => 'secondary',
            'label' => 'Sin SLA'
        ];
    }

    /**
     * Get all SLA settings from database with caching
     *
     * @return array
     */
    private function getSlaSettings(): array
    {
        return Cache::remember(self::CACHE_KEY, function () {
            $settingsTable = $this->fetchTable('SystemSettings');

            $slaSettings = $settingsTable->find()
                ->where(['setting_key LIKE' => 'sla_%'])
                ->all();

            $settings = [];
            foreach ($slaSettings as $setting) {
                $settings[$setting->setting_key] = $setting->setting_value;
            }

            return $settings;
        }, self::CACHE_CONFIG);
    }

    /**
     * Clear SLA settings cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY, self::CACHE_CONFIG);
    }

    /**
     * Get default SLA for PQRS types (fallback)
     *
     * @param string $type PQRS type
     * @return array{first_response_days: int, resolution_days: int}
     */
    private function getDefaultPqrsSla(string $type): array
    {
        $defaults = [
            'peticion' => ['first_response_days' => 2, 'resolution_days' => 5],
            'queja' => ['first_response_days' => 1, 'resolution_days' => 3],
            'reclamo' => ['first_response_days' => 1, 'resolution_days' => 3],
            'sugerencia' => ['first_response_days' => 3, 'resolution_days' => 7],
        ];

        return $defaults[$type] ?? ['first_response_days' => 2, 'resolution_days' => 5];
    }

    /**
     * Update SLA setting
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool Success
     */
    public function updateSetting(string $key, mixed $value): bool
    {
        $settingsTable = $this->fetchTable('SystemSettings');

        $setting = $settingsTable->findBySettingKey($key)->first();

        if ($setting) {
            $setting->setting_value = (string)$value;
            $setting->modified = new DateTime();
        } else {
            $setting = $settingsTable->newEntity([
                'setting_key' => $key,
                'setting_value' => (string)$value,
                'setting_type' => 'integer',
                'description' => "SLA setting: {$key}",
            ], ['accessibleFields' => ['setting_key' => true, 'setting_type' => true]]);
        }

        $result = $settingsTable->save($setting);

        if ($result) {
            $this->clearCache();
            \Cake\Log\Log::info("SLA setting updated", ['key' => $key, 'value' => $value]);
        } else {
            \Cake\Log\Log::error("Failed to update SLA setting", [
                'key' => $key,
                'value' => $value,
                'errors' => $setting->getErrors()
            ]);
        }

        return (bool)$result;
    }

    /**
     * Save all SLA settings from form data
     *
     * @param array $data Form data with SLA setting keys/values
     * @return array{success: bool, message: string, count: int, errors: array}
     */
    public function saveAllSettings(array $data): array
    {
        $errors = [];
        $successCount = 0;

        $slaKeys = [
            'sla_pqrs_peticion_first_response_days',
            'sla_pqrs_peticion_resolution_days',
            'sla_pqrs_queja_first_response_days',
            'sla_pqrs_queja_resolution_days',
            'sla_pqrs_reclamo_first_response_days',
            'sla_pqrs_reclamo_resolution_days',
            'sla_pqrs_sugerencia_first_response_days',
            'sla_pqrs_sugerencia_resolution_days',
            'sla_compras_first_response_days',
            'sla_compras_resolution_days',
        ];

        foreach ($slaKeys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = (int) $data[$key];
            if ($value <= 0) {
                continue;
            }
            if ($this->updateSetting($key, $value)) {
                $successCount++;
            } else {
                $errors[] = "Error al guardar {$key}";
            }
        }

        if (empty($errors)) {
            return [
                'success' => true,
                'message' => "Se guardaron {$successCount} configuraciones de SLA correctamente. Los cambios se aplicarán inmediatamente a nuevas solicitudes.",
                'count' => $successCount,
                'errors' => [],
            ];
        }

        return [
            'success' => false,
            'message' => "Se guardaron {$successCount} configuraciones, pero hubo algunos errores: " . implode(', ', $errors),
            'count' => $successCount,
            'errors' => $errors,
        ];
    }

    /**
     * Get all SLA configurations for admin interface
     *
     * @return array
     */
    public function getAllSlaConfigurations(): array
    {
        $settings = $this->getSlaSettings();

        return [
            'pqrs' => [
                'peticion' => $this->getPqrsSlaSettings('peticion'),
                'queja' => $this->getPqrsSlaSettings('queja'),
                'reclamo' => $this->getPqrsSlaSettings('reclamo'),
                'sugerencia' => $this->getPqrsSlaSettings('sugerencia'),
            ],
            'compras' => $this->getComprasSlaSettings(),
        ];
    }
}
