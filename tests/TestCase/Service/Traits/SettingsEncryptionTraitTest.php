<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Traits;

use App\Constants\SettingKeys;
use App\Service\Exception\SettingsEncryptionException;
use App\Service\Traits\SettingsEncryptionTrait;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\Utility\Security;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for {@see SettingsEncryptionTrait}.
 *
 * Covers the CR-001 hardening:
 *  - encryptSetting is idempotent (no double-encrypt on round-trip).
 *  - decryptSetting fails loud (throws) on real failures instead of returning ''.
 *  - processSettings excludes failed keys (absence is safer than empty string).
 *  - Backwards-compatibility: empty input ⇒ '', plaintext fallthrough preserved.
 */
#[CoversClass(SettingsEncryptionTrait::class)]
final class SettingsEncryptionTraitTest extends TestCase
{
    private const TEST_SALT = '__test-salt-of-sufficient-length-for-cake-security-32__';

    private object $harness;

    private ?string $originalSalt = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->originalSalt = Security::getSalt();
        } catch (Throwable) {
            // No salt configured by the bootstrap; nothing to restore to.
            $this->originalSalt = null;
        }
        Security::setSalt(self::TEST_SALT);
        Configure::write('Security.cipher', 'aes');
        // Logger must be configured because processSettings() logs on failures.
        // 'debug' engine writes to a stream we don't read; just register it.
        if (!Log::getConfig('error')) {
            Log::setConfig('error', [
                'className' => 'Array',
                'levels' => ['error', 'warning', 'notice', 'info', 'debug'],
            ]);
        }

        $this->harness = $this->makeHarness();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Several tests rotate the global salt mid-test; restore the prior salt
        // (or fall back to the test salt) so no rotated value leaks into other
        // suites in the same process. The public API cannot represent "unset".
        Security::setSalt($this->originalSalt ?? self::TEST_SALT);
        Log::drop('error');
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plain = 'super-secret-token-1234';

        $cipher = $this->harness->encrypt($plain, SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN);

        $this->assertNotSame($plain, $cipher);
        $this->assertStringStartsWith('{encrypted}', $cipher);

        $back = $this->harness->decrypt($cipher, SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN);

        $this->assertSame($plain, $back);
    }

    public function testEncryptIsIdempotent(): void
    {
        $cipher = $this->harness->encrypt('abc', SettingKeys::WHATSAPP_API_KEY);

        $reEncrypted = $this->harness->encrypt($cipher, SettingKeys::WHATSAPP_API_KEY);

        $this->assertSame(
            $cipher,
            $reEncrypted,
            'Already-encrypted payloads must pass through unchanged to prevent double-encryption.',
        );
    }

    public function testEncryptEmptyReturnsEmpty(): void
    {
        $this->assertSame('', $this->harness->encrypt('', SettingKeys::N8N_API_KEY));
    }

    public function testDecryptEmptyReturnsEmpty(): void
    {
        $this->assertSame('', $this->harness->decrypt(null, SettingKeys::N8N_API_KEY));
        $this->assertSame('', $this->harness->decrypt('', SettingKeys::N8N_API_KEY));
    }

    public function testDecryptPlaintextFallthrough(): void
    {
        // Backwards compatibility: a non-prefixed value is treated as plaintext.
        // This supports legacy rows that pre-date the encryption migration.
        $this->assertSame(
            'legacy-plaintext',
            $this->harness->decrypt('legacy-plaintext', SettingKeys::WHATSAPP_API_KEY),
        );
    }

    public function testDecryptCorruptedBase64Throws(): void
    {
        $this->expectException(SettingsEncryptionException::class);
        $this->expectExceptionMessageMatches('/base64-decode/');

        // '@@@@' is invalid in strict base64 mode.
        $this->harness->decrypt('{encrypted}@@@@', SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN);
    }

    public function testDecryptTamperedCiphertextThrows(): void
    {
        $cipher = $this->harness->encrypt('original-secret', SettingKeys::WHATSAPP_API_KEY);

        // Corrupt the payload by truncating one base64 quartet.
        // Cake's Security::decrypt validates HMAC and returns false on mismatch.
        $tampered = substr($cipher, 0, -8) . 'AAAAAAAA';

        $this->expectException(SettingsEncryptionException::class);

        $this->harness->decrypt($tampered, SettingKeys::WHATSAPP_API_KEY);
    }

    public function testDecryptWithDifferentSaltThrows(): void
    {
        // Encrypt under salt A.
        $cipher = $this->harness->encrypt('rotation-target', SettingKeys::GMAIL_REFRESH_TOKEN);

        // Rotate salt (simulate operator mistake / key rotation without re-encrypt).
        Security::setSalt('__different-salt-that-cannot-decrypt-the-payload-32__');

        $this->expectException(SettingsEncryptionException::class);

        $this->harness->decrypt($cipher, SettingKeys::GMAIL_REFRESH_TOKEN);
    }

    public function testProcessSettingsDecryptsKnownKeysAndPassesThroughUnknown(): void
    {
        $cipher = $this->harness->encrypt('the-token', SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN);

        $processed = $this->harness->process([
            SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN => $cipher,
            'system_title' => 'Mesa de Ayuda',
        ]);

        $this->assertSame('the-token', $processed[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN]);
        $this->assertSame('Mesa de Ayuda', $processed['system_title']);
    }

    public function testProcessSettingsExcludesUndecryptableKeys(): void
    {
        $cipherA = $this->harness->encrypt('valid-secret', SettingKeys::WHATSAPP_API_KEY);
        $cipherB = $this->harness->encrypt('rotated-out', SettingKeys::N8N_API_KEY);

        // Rotate salt — both ciphers become undecryptable.
        Security::setSalt('__rotated-salt-different-from-original-of-good-len__');

        $processed = $this->harness->process([
            SettingKeys::WHATSAPP_API_KEY => $cipherA,
            SettingKeys::N8N_API_KEY => $cipherB,
            'system_title' => 'Helpdesk',
        ]);

        // Failed keys must be ABSENT (not '' — empty would pass auth checks via hash_equals).
        $this->assertArrayNotHasKey(SettingKeys::WHATSAPP_API_KEY, $processed);
        $this->assertArrayNotHasKey(SettingKeys::N8N_API_KEY, $processed);
        $this->assertSame('Helpdesk', $processed['system_title']);
    }

    public function testEncryptUsesCurrentSaltSoCiphersDifferAfterRotation(): void
    {
        $first = $this->harness->encrypt('payload', SettingKeys::WHATSAPP_API_KEY);

        Security::setSalt('__rotated-salt-different-from-original-of-good-len__');

        $second = $this->harness->encrypt('payload', SettingKeys::WHATSAPP_API_KEY);

        $this->assertNotSame(
            $first,
            $second,
            'A different salt must produce a different ciphertext (sanity: salt actually used).',
        );
    }

    /**
     * Build a tiny harness that exposes the trait's protected methods as public.
     *
     * Anonymous classes with traits work fine with PHPUnit and avoid leaking a
     * test-only class to the production autoloader.
     */
    private function makeHarness(): object
    {
        return new class {
            use SettingsEncryptionTrait;

            public function encrypt(string $value, string $key): string
            {
                return $this->encryptSetting($value, $key);
            }

            public function decrypt(?string $value, string $key): string
            {
                return $this->decryptSetting($value, $key);
            }

            public function process(array $settings): array
            {
                return $this->processSettings($settings);
            }
        };
    }
}
