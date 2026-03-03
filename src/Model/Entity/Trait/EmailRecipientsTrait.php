<?php
declare(strict_types=1);

namespace App\Model\Entity\Trait;

/**
 * EmailRecipientsTrait
 *
 * Provides JSON encode/decode for email_to and email_cc fields.
 * Used by Ticket and Compra entities that share identical logic.
 */
trait EmailRecipientsTrait
{
    /**
     * Set email_to as JSON (encode array)
     *
     * @param array|string|null $value Array of recipients or JSON string
     * @return string|null JSON string or null
     */
    protected function _setEmailTo($value): ?string
    {
        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            return json_encode($value);
        }

        return $value;
    }

    /**
     * Set email_cc as JSON (encode array)
     *
     * @param array|string|null $value Array of recipients or JSON string
     * @return string|null JSON string or null
     */
    protected function _setEmailCc($value): ?string
    {
        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            return json_encode($value);
        }

        return $value;
    }

    /**
     * Get decoded email_to as array (virtual property)
     *
     * Access via $entity->email_to_array
     *
     * @return array Array of recipients with 'name' and 'email' keys
     */
    protected function _getEmailToArray(): array
    {
        $value = $this->_fields['email_to'] ?? null;

        if (empty($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get decoded email_cc as array (virtual property)
     *
     * Access via $entity->email_cc_array
     *
     * @return array Array of recipients with 'name' and 'email' keys
     */
    protected function _getEmailCcArray(): array
    {
        $value = $this->_fields['email_cc'] ?? null;

        if (empty($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
