<?php
declare(strict_types=1);

namespace App\Model\Entity\Trait;

/**
 * EmailRecipientsTrait
 *
 * Provides JSON encode/decode for email_to and email_cc fields.
 * Used by Ticket entity that share identical logic.
 */
trait EmailRecipientsTrait
{
    /**
     * Set email_to as JSON (encode array)
     *
     * @param array|string|null $value Array of recipients or JSON string
     * @return string|null JSON string or null
     */
    protected function _setEmailTo(array|string|null $value): ?string
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
    protected function _setEmailCc(array|string|null $value): ?string
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
        return $this->decodeRecipients('email_to');
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
        return $this->decodeRecipients('email_cc');
    }

    /**
     * @param string $field Field name (email_to or email_cc)
     * @return array Array of recipients with 'name' and 'email' keys
     */
    private function decodeRecipients(string $field): array
    {
        // Read via the public Entity API rather than reaching into $this->_fields,
        // which is internal to Cake\ORM\Entity and could change between releases.
        // get() also runs through any registered virtual accessors — important if
        // these fields ever gain wrapping behaviour.
        $value = $this->get($field);

        if (empty($value)) {
            return [];
        }

        $decoded = json_decode((string)$value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
