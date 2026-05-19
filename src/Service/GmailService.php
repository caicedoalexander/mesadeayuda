<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Service\Exception\GmailApiException;
use App\Service\Exception\GmailAuthenticationException;
use App\Service\Exception\SettingsEncryptionException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\Gmail\RetryHandler;
use App\Service\Traits\HtmlSanitizerTrait;
use App\Service\Traits\SettingsEncryptionTrait;
use App\Service\Util\EmailHeaderParser;
use App\Service\Util\NotificationStamp;
use Cake\Cache\Cache;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\ModifyMessageRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Gmail Service
 *
 * Handles all Gmail API interactions including:
 * - OAuth2 authentication
 * - Fetching messages
 * - Parsing email content
 * - Downloading attachments
 * - Sending emails
 */
class GmailService
{
    use HtmlSanitizerTrait;
    use LocatorAwareTrait;
    use SettingsEncryptionTrait;

    /**
     * Microseconds to sleep before each attachment download.
     *
     * Gmail API quota is ~250 req/s; throttling at 5 req/s (200ms) leaves
     * ample headroom for parsing/list calls happening in parallel and
     * keeps batches predictable. Property of the API client, not the consumer.
     */
    private const ATTACHMENT_THROTTLE_US = 200_000;

    private GoogleClient $client;
    private ?Gmail $service = null;
    private array $config;

    /**
     * Load Gmail configuration from database
     *
     * Centralized method to get Gmail config from system settings with automatic decryption.
     * Used by TicketService, ImportGmailCommand, and any other class needing Gmail access.
     *
     * @return array Configuration array with 'client_secret' (decoded array) and 'refresh_token'
     */
    public static function loadConfigFromDatabase(): array
    {
        return Cache::remember('gmail_settings', function () {
            // Create temporary instance to use traits
            $instance = new self([]);

            $settingsTable = $instance->fetchTable('SystemSettings');
            $settings = $settingsTable->find()
                ->where(['setting_key IN' => [
                    SettingKeys::GMAIL_REFRESH_TOKEN,
                    SettingKeys::GMAIL_CLIENT_SECRET_JSON,
                ]])
                ->all();

            $config = ['refresh_token' => '', 'client_secret' => []];
            foreach ($settings as $setting) {
                try {
                    $decrypted = $instance->decryptSetting($setting->setting_value, $setting->setting_key);
                } catch (SettingsEncryptionException $e) {
                    // Fail-loud in logs but don't break the whole config load.
                    // Leaving the field empty surfaces a clear GmailNotConfiguredException downstream
                    // instead of silently authenticating with garbage.
                    Log::error('Gmail setting cannot be decrypted; skipping', [
                        'key' => $setting->setting_key,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($setting->setting_key === SettingKeys::GMAIL_REFRESH_TOKEN) {
                    $config['refresh_token'] = $decrypted;
                } elseif ($setting->setting_key === SettingKeys::GMAIL_CLIENT_SECRET_JSON && !empty($decrypted)) {
                    $decoded = json_decode($decrypted, true);
                    if (is_array($decoded)) {
                        $config['client_secret'] = $decoded;
                    } else {
                        Log::error('Gmail client_secret JSON in DB is malformed; skipping');
                    }
                }
            }

            return $config;
        }, CacheConstants::CACHE_CONFIG);
    }

    /**
     * Constructor
     *
     * @param array $config Configuration array with 'client_secret' (decoded array) and 'refresh_token'
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->initializeClient();
    }

    /**
     * Initialize Google Client with OAuth2
     *
     * @return void
     */
    private function initializeClient(): void
    {
        $this->client = new GoogleClient();

        // Client secret is loaded from system_settings (encrypted JSON), decoded into an array.
        if (!empty($this->config['client_secret']) && is_array($this->config['client_secret'])) {
            $this->client->setAuthConfig($this->config['client_secret']);
        } else {
            Log::error('Gmail client_secret not configured in system_settings');
        }

        // H-1: gmail.modify subsumes both readonly and send for every API call
        // this codebase makes (messages.list/get/modify/attachments.get and
        // messages.send). Requesting only this minimizes blast radius if the
        // refresh_token is compromised and reduces friction in Google's OAuth
        // verification flow.
        $this->client->addScope(Gmail::GMAIL_MODIFY);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent'); // Force to always get refresh_token

        // M-1: PSR-6 cache so the access_token persists across GmailService
        // instances and consecutive requests within its ~1h TTL. Without this,
        // every new instance burns a token-endpoint round trip on construction.
        $cacheDir = TMP . 'gmail_oauth_cache';
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            Log::warning('Failed to create Gmail OAuth cache dir', ['cache_dir' => $cacheDir]);
        }
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $pool = new FilesystemAdapter('gmail_oauth', 3500, $cacheDir);
            $this->client->setCache($pool);
            $this->client->setCacheConfig(['lifetime' => 3500]); // < 3600s access_token TTL
            $this->client->setTokenCallback(static function (string $cacheKey, string $accessToken): void {
                Log::debug('Gmail access token refreshed by SDK', ['cache_key' => $cacheKey]);
            });
        } else {
            Log::warning('Gmail OAuth cache dir not writable; falling back to per-request token refresh', [
                'cache_dir' => $cacheDir,
            ]);
        }

        // H-2: register a Guzzle HandlerStack with retry middleware so every
        // Gmail API call survives transient 429/5xx pressure. The Google SDK
        // applies its own auth middleware on top of the handler we provide,
        // so OAuth headers are not bypassed.
        $stack = HandlerStack::create();
        $stack->push(
            Middleware::retry(
                RetryHandler::decider(),
                RetryHandler::delay(),
            ),
            'retry',
        );
        $this->client->setHttpClient(new GuzzleClient([
            'handler' => $stack,
            'timeout' => 30,
            'connect_timeout' => 10,
        ]));

        // Set redirect URI for OAuth2 flow
        if (!empty($this->config['redirect_uri'])) {
            $this->client->setRedirectUri($this->config['redirect_uri']);
        }

        // Set refresh token and fetch access token if available
        if (!empty($this->config['refresh_token'])) {
            try {
                // Exchange refresh token for access token
                $token = $this->client->fetchAccessTokenWithRefreshToken($this->config['refresh_token']);

                if (isset($token['error'])) {
                    Log::error('OAuth token refresh failed', ['error' => $token]);
                    throw new GmailAuthenticationException('Gmail authentication failed: ' . ($token['error_description'] ?? $token['error']));
                }
            } catch (Exception $e) {
                Log::error('Failed to refresh OAuth token: ' . $e->getMessage());
                throw new GmailAuthenticationException('Gmail authentication failed. Please re-authenticate in Admin Settings.');
            }
        }
    }

    /**
     * Get Gmail service instance
     *
     * @return \Google\Service\Gmail
     */
    private function getService(): Gmail
    {
        if ($this->service === null) {
            $this->service = new Gmail($this->client);
        }

        return $this->service;
    }

    /**
     * Get authorization URL for OAuth2 flow
     *
     * @return string
     */
    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code Authorization code
     * @return array Token data including refresh_token
     */
    public function authenticate(string $code): array
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            Log::error('Gmail authentication error: ' . $token['error']);
            throw new GmailAuthenticationException('Failed to authenticate with Gmail: ' . $token['error']);
        }

        return $token;
    }

    /**
     * Get messages from Gmail inbox
     *
     * @param string $query Gmail search query (e.g., 'is:unread')
     * @param int $maxResults Maximum number of messages to retrieve
     * @return array Array of message IDs
     */
    public function getMessages(string $query = 'is:unread', int $maxResults = 50): array
    {
        try {
            $service = $this->getService();
            $results = $service->users_messages->listUsersMessages('me', [
                'q' => $query,
                'maxResults' => $maxResults,
            ]);

            $messages = $results->getMessages();

            if (empty($messages)) {
                return [];
            }

            $messageIds = [];
            foreach ($messages as $message) {
                $messageIds[] = $message->getId();
            }

            return $messageIds;
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return [];
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * Parse Gmail message and extract relevant data
     *
     * @param string $messageId Gmail message ID
     * @return array Parsed message data with keys: gmail_message_id, gmail_thread_id, from, to, subject, date,
     *               email_to, email_cc, body_html, body_text, attachments, inline_images, is_auto_reply, is_system_notification
     */
    public function parseMessage(string $messageId): array
    {
        try {
            $service = $this->getService();
            $message = $service->users_messages->get('me', $messageId, ['format' => 'full']);

            $headers = $message->getPayload()->getHeaders();
            $parts = $message->getPayload()->getParts();

            // Parse To and CC recipients
            $toHeader = $this->getHeader($headers, 'To');
            $ccHeader = $this->getHeader($headers, 'Cc');

            // M-4: RFC 5322 threading headers used by TicketIngestionService
            // to reattach replies to the existing ticket when Gmail's threadId
            // is missing or wrong (e.g. external mailer rewriting threads).
            $rfcMessageId = EmailHeaderParser::extractMessageId($this->getHeader($headers, 'Message-ID'));
            $inReplyTo = EmailHeaderParser::extractMessageId($this->getHeader($headers, 'In-Reply-To'));
            $referencesRaw = $this->getHeader($headers, 'References');
            $referencesHeader = trim($referencesRaw) !== '' ? trim($referencesRaw) : null;

            $data = [
                'gmail_message_id' => $messageId,
                'gmail_thread_id' => $message->getThreadId(),
                'gmail_history_id' => (string)($message->getHistoryId() ?? ''),
                'from' => $this->getHeader($headers, 'From'),
                'to' => $this->getHeader($headers, 'To'),
                'subject' => $this->getHeader($headers, 'Subject'),
                'date' => $this->getHeader($headers, 'Date'),
                'email_to' => EmailHeaderParser::parseRecipients($toHeader),
                'email_cc' => EmailHeaderParser::parseRecipients($ccHeader),
                'rfc_message_id' => $rfcMessageId,
                'in_reply_to' => $inReplyTo,
                'references_header' => $referencesHeader,
                'body_html' => '',
                'body_text' => '',
                'attachments' => [],
                'inline_images' => [],
                'is_auto_reply' => $this->isAutoReply($headers),
                'is_system_notification' => $this->isSystemNotification($headers),
            ];

            // Extract body and attachments
            $this->extractMessageParts($message->getPayload(), $data);

            // Defense in depth: sanitize at the trust boundary so any consumer
            // (ingestion, future indexers, debug dumps) gets safe HTML even if
            // they forget to call HtmlSanitizerTrait themselves.
            if ($data['body_html'] !== '') {
                $data['body_html'] = $this->sanitizeHtml($data['body_html']);
            }

            return $data;
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw new GmailApiException(GmailErrorCategory::UNKNOWN, 0, $e->getMessage(), previous: $e);
        }
    }

    /**
     * Extract message parts recursively (body, attachments, inline images)
     *
     * @param \Google\Service\Gmail\MessagePart $payload Message payload
     * @param array &$data Reference to data array to populate
     * @return void
     */
    private function extractMessageParts(MessagePart $payload, array &$data): void
    {
        $mimeType = $payload->getMimeType();

        // B-2: multipart/alternative carries equivalent renderings of one body.
        // Pick the richest branch and skip the others — visiting every child
        // duplicates body_html when forwards are nested (RFC 2046 §5.1.4).
        if ($mimeType === 'multipart/alternative') {
            $chosen = $this->pickAlternativeBranch($payload->getParts() ?? []);
            if ($chosen !== null) {
                $this->extractMessageParts($chosen, $data);
            }

            return;
        }

        $parts = $payload->getParts();
        $body = $payload->getBody();

        // Handle body content - preserve ALL HTML including styles
        if ($mimeType === 'text/html' && $body->getSize() > 0 && $body->getData() !== null) {
            $htmlContent = base64_decode(strtr($body->getData(), '-_', '+/'));
            $data['body_html'] = empty($data['body_html']) ? $htmlContent : $data['body_html'] . "\n" . $htmlContent;
        } elseif ($mimeType === 'text/plain' && $body->getSize() > 0 && $body->getData() !== null) {
            $textContent = base64_decode(strtr($body->getData(), '-_', '+/'));
            $data['body_text'] = empty($data['body_text']) ? $textContent : $data['body_text'] . "\n" . $textContent;
        }

        // Handle attachments
        $filename = $payload->getFilename();

        if (!empty($filename)) {
            $headers = $payload->getHeaders();
            $contentId = $this->getHeader($headers, 'Content-ID');
            $contentDisposition = $this->getHeader($headers, 'Content-Disposition');
            $attachmentId = $body->getAttachmentId();

            $attachment = [
                'filename' => $filename,
                'mime_type' => $mimeType,
                'attachment_id' => $attachmentId,
                'size' => $body->getSize(),
            ];

            // Check Content-Disposition first (official way to distinguish inline vs attachment)
            $isExplicitAttachment = stripos($contentDisposition, 'attachment') !== false;
            $isExplicitInline = stripos($contentDisposition, 'inline') !== false;

            if ($isExplicitAttachment) {
                // Explicitly marked as attachment - treat as regular attachment
                $data['attachments'][] = $attachment;
            } elseif ($isExplicitInline && !empty($contentId) && stripos($mimeType, 'image/') === 0) {
                // Explicitly inline AND has Content-ID AND is an image - treat as inline image
                $attachment['content_id'] = trim($contentId, '<>');
                $data['inline_images'][] = $attachment;
            } elseif (!empty($contentId) && stripos($mimeType, 'image/') === 0) {
                // Has Content-ID AND is an image (no explicit disposition) - treat as inline image
                $attachment['content_id'] = trim($contentId, '<>');
                $data['inline_images'][] = $attachment;
            } else {
                // Default: treat as regular attachment
                $data['attachments'][] = $attachment;
            }
        }

        // Recursively process parts
        if (!empty($parts)) {
            foreach ($parts as $part) {
                $this->extractMessageParts($part, $data);
            }
        }
    }

    /**
     * B-2: pick the richest alternative for a multipart/alternative node.
     * Prefers a direct text/html child, then a multipart/* descendant that
     * contains text/html (e.g. multipart/related with inline images),
     * finally falling back to text/plain. Returns null when none match.
     *
     * @param array<int, \Google\Service\Gmail\MessagePart> $parts
     */
    private function pickAlternativeBranch(array $parts): ?MessagePart
    {
        $html = null;
        $multipartHtml = null;
        $plain = null;

        foreach ($parts as $part) {
            $mt = (string)$part->getMimeType();
            if ($mt === 'text/html' && $html === null) {
                $html = $part;
                continue;
            }
            if ($mt === 'text/plain' && $plain === null) {
                $plain = $part;
                continue;
            }
            if (str_starts_with($mt, 'multipart/') && $multipartHtml === null && $this->containsHtml($part)) {
                $multipartHtml = $part;
            }
        }

        return $html ?? $multipartHtml ?? $plain;
    }

    /**
     * Recursive check used by pickAlternativeBranch: true iff the subtree
     * rooted at $part contains a text/html node at any depth.
     */
    private function containsHtml(MessagePart $part): bool
    {
        if ((string)$part->getMimeType() === 'text/html') {
            return true;
        }
        foreach ($part->getParts() ?? [] as $child) {
            if ($this->containsHtml($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Download attachment from Gmail
     *
     * @param string $messageId Gmail message ID
     * @param string $attachmentId Gmail attachment ID
     * @return string Binary content of attachment
     */
    public function downloadAttachment(string $messageId, string $attachmentId): string
    {
        // Throttle BEFORE the API call — guarantees a minimum gap regardless of
        // caller. Not a circuit breaker, just a back-off to stay under quota.
        usleep(self::ATTACHMENT_THROTTLE_US);

        try {
            $service = $this->getService();
            $attachment = $service->users_messages_attachments->get('me', $messageId, $attachmentId);

            return base64_decode(strtr($attachment->getData(), '-_', '+/'));
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw new GmailApiException(GmailErrorCategory::UNKNOWN, 0, $e->getMessage(), previous: $e);
        }
    }

    /**
     * Mark message as read.
     *
     * M-5: now throws GmailApiException instead of returning false on failure,
     * so callers (GmailImportService, MarkReadQueueService) can enqueue the
     * messageId for retry based on the error category. Returns true only on
     * success — the bool return is preserved for backward-compatible call
     * sites that only check truthiness.
     *
     * @param string $messageId Gmail message ID
     * @throws \App\Service\Exception\GmailApiException
     */
    public function markAsRead(string $messageId): bool
    {
        try {
            $service = $this->getService();
            $mods = new ModifyMessageRequest();
            $mods->setRemoveLabelIds(['UNREAD']);

            $service->users_messages->modify('me', $messageId, $mods);

            return true;
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            throw new GmailApiException(GmailErrorCategory::UNKNOWN, 0, $e->getMessage(), previous: $e);
        }
    }

    /**
     * Detect if email is an auto-reply (out-of-office, auto-responder)
     *
     * Checks standard email headers that indicate automated responses:
     * - Auto-Submitted: auto-replied, auto-generated
     * - X-Autoreply: yes
     * - X-Autorespond: yes
     * - Precedence: bulk, list, junk
     *
     * @param array $headers Array of header objects from Gmail API
     * @return bool True if auto-reply detected, false otherwise
     */
    public function isAutoReply(array $headers): bool
    {
        // RFC 3834 §5: any non-"no" value of Auto-Submitted indicates automation.
        $autoSubmitted = strtolower(trim($this->getHeader($headers, 'Auto-Submitted')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no' && !str_starts_with($autoSubmitted, 'no;')) {
            return true;
        }

        // Legacy vendor headers.
        if (stripos($this->getHeader($headers, 'X-Autoreply'), 'yes') !== false) {
            return true;
        }
        if (stripos($this->getHeader($headers, 'X-Autorespond'), 'yes') !== false) {
            return true;
        }

        // RFC 2076: Precedence: bulk, list, junk.
        $precedence = strtolower(trim($this->getHeader($headers, 'Precedence')));
        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return true;
        }

        // RFC 2369 / 8058: any bulk/list mail carries List-Unsubscribe.
        if (trim($this->getHeader($headers, 'List-Unsubscribe')) !== '') {
            return true;
        }

        // Google/Yahoo bulk-sender requirement, 2024+.
        if (trim($this->getHeader($headers, 'Feedback-ID')) !== '') {
            return true;
        }

        return false;
    }

    /**
     * Detect if email is a response to a system notification.
     *
     * Three inclusive checks (any one is enough to skip ingest):
     *  1. HMAC stamp embedded by our own EmailService — see {@see NotificationStamp}.
     *  2. Legacy X-Mesa-Ayuda-Notification header, trusted ONLY when
     *     Authentication-Results reports dkim=pass for our own domain. This
     *     branch is a 30-day compatibility grace; remove after rollout.
     *  3. From header equals the configured system email.
     *
     * Prevents infinite loops where a customer replies to an automated
     * notification and the reply gets ingested as a brand-new ticket.
     *
     * @param array $headers Array of header objects from Gmail API
     * @return bool True if system notification reply detected, false otherwise
     */
    public function isSystemNotification(array $headers): bool
    {
        $subject = $this->getHeader($headers, 'Subject');

        // 1) Canonical: HMAC stamp embedded by our own EmailService (H-3).
        if (NotificationStamp::verifiedTicketNumber($subject) !== null) {
            return true;
        }

        // 2) Legacy header — trusted ONLY when Gmail-verified DKIM passes for
        //    our own domain. Grace window during the H-3 rollout; remove after
        //    ~30 days post-deploy (see docs/superpowers/specs/2026-05-16-gmail-audit-p0-design.md §5.2.6).
        $legacy = $this->getHeader($headers, 'X-Mesa-Ayuda-Notification');
        if ($legacy === 'true' && $this->dkimPassesForOwnDomain($headers)) {
            return true;
        }

        // 3) From == system email. DMARC-spoof-mitigated but not perfect; unchanged.
        $from = $this->getHeader($headers, 'From');
        $fromEmail = EmailHeaderParser::extractEmailAddress($from);
        $systemEmail = $this->getSystemEmail();
        if ($systemEmail !== '' && strtolower($fromEmail) === strtolower($systemEmail)) {
            return true;
        }

        // Removed (dead code): subject-prefix "Re: [Ticket #" / "Re: Tu Solicitud" check.
        // Templates produce "Tu ticket #N fue creado", never "[Ticket #N]", so the
        // branch never matched in production.
        return false;
    }

    /**
     * True iff the Authentication-Results header (added by Gmail itself on
     * inbound mail) reports dkim=pass for our own domain. Used by the H-3
     * grace-window branch to authenticate the legacy X-Mesa-Ayuda-Notification
     * header.
     *
     * @param array $headers Array of header objects from Gmail API
     * @return bool
     */
    private function dkimPassesForOwnDomain(array $headers): bool
    {
        $authResults = $this->getHeader($headers, 'Authentication-Results');
        $systemEmail = $this->getSystemEmail();
        $atPos = strrpos($systemEmail, '@');
        if ($authResults === '' || $atPos === false) {
            return false;
        }
        $ownDomain = substr($systemEmail, $atPos + 1);

        return (bool)preg_match(
            '/dkim=pass[^;]*?header\.d=' . preg_quote($ownDomain, '/') . '\b/i',
            $authResults,
        );
    }

    /**
     * Get system email address from settings
     *
     * @return string System email or empty string
     */
    private function getSystemEmail(): string
    {
        // Allow callers (and unit tests) to inject the system email through
        // the constructor config, bypassing the SystemSettings table lookup.
        if (!empty($this->config['user_email']) && is_string($this->config['user_email'])) {
            return $this->config['user_email'];
        }

        try {
            $settingsTable = $this->fetchTable('SystemSettings');
            $setting = $settingsTable->find()
                ->where(['setting_key' => SettingKeys::GMAIL_USER_EMAIL])
                ->first();

            return $setting ? (string)($setting->setting_value ?? '') : '';
        } catch (Exception $e) {
            Log::error('Failed to load system email: ' . $e->getMessage());

            return '';
        }
    }

    /**
     * Return the current historyId for the authenticated user's mailbox.
     * Used to bootstrap a fresh history.list checkpoint (M-2) or to refresh
     * one after a 404 fallback.
     *
     * @throws \App\Service\Exception\GmailApiException
     */
    public function getProfileHistoryId(): string
    {
        try {
            $profile = $this->getService()->users->getProfile('me');
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        }

        $historyId = (string)($profile->getHistoryId() ?? '');
        if ($historyId === '') {
            throw new GmailApiException(
                GmailErrorCategory::PERMANENT,
                0,
                'getProfile returned empty historyId',
            );
        }

        return $historyId;
    }

    /**
     * List the messageIds added since $startHistoryId via users.history.list,
     * paginating through nextPageToken. Returns null when Gmail responds 404
     * (the checkpoint is older than Gmail's history window — caller must fall
     * back to a full sync).
     *
     * @return list<string>|null
     * @throws \App\Service\Exception\GmailApiException
     */
    public function getHistoryDelta(string $startHistoryId): ?array
    {
        $messageIds = [];
        $pageToken = null;

        do {
            $params = [
                'startHistoryId' => $startHistoryId,
                'historyTypes' => ['messageAdded'],
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            try {
                $response = $this->getService()->users_history->listUsersHistory('me', $params);
            } catch (GoogleServiceException $e) {
                if ($e->getCode() === 404) {
                    return null;
                }
                $category = GmailErrorCategory::categorize($e);
                Log::error('Gmail API error', [
                    'method' => __FUNCTION__,
                    'category' => $category,
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ]);

                throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
            }

            foreach ($response->getHistory() ?? [] as $history) {
                foreach ($history->getMessagesAdded() ?? [] as $added) {
                    $msg = $added->getMessage();
                    if ($msg !== null && $msg->getId() !== null) {
                        $messageIds[] = $msg->getId();
                    }
                }
            }
            $pageToken = $response->getNextPageToken();
        } while (is_string($pageToken) && $pageToken !== '');

        return array_values(array_unique($messageIds));
    }

    /**
     * Return the authenticated mailbox's primary email address via
     * users.getProfile('me'). Used after OAuth callback to keep
     * GMAIL_USER_EMAIL in sync with the live account (B-4).
     *
     * Inherits retry/backoff (H-2) and categorized exception typing
     * (M-3) automatically because the call goes through the wrapped
     * GoogleClient.
     *
     * @throws \App\Service\Exception\GmailApiException
     */
    public function getUserEmail(): string
    {
        try {
            $profile = $this->getService()->users->getProfile('me');
            $email = (string)($profile->getEmailAddress() ?? '');
            if ($email === '') {
                throw new GmailApiException(
                    GmailErrorCategory::PERMANENT,
                    0,
                    'Empty emailAddress in users.getProfile response',
                );
            }

            return $email;
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            throw new GmailApiException($category, $e->getCode(), $e->getMessage(), previous: $e);
        }
    }

    /**
     * Send email via Gmail
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML body content
     * @param array $attachments Array of attachment file paths
     * @return bool Success status
     */

    /**
     * Send email via Gmail API
     *
     * @param array|string $to Recipient email or array of recipients ['email' => 'name', ...]
     * @param string $subject Subject
     * @param string $htmlBody HTML body
     * @param array $attachments Array of file paths
     * @param array $options Additional options: 'from', 'cc', 'bcc', 'replyTo'
     * @return bool Success status
     */
    public function sendEmail(string|array $to, string $subject, string $htmlBody, array $attachments = [], array $options = []): bool
    {
        try {
            $service = $this->getService();

            // Create MIME message
            $boundary = uniqid('boundary_');
            $rawMessage = $this->createMimeMessage($to, $subject, $htmlBody, $attachments, $boundary, $options);

            // Base64 encode for Gmail API
            $encodedMessage = base64_encode($rawMessage);
            $encodedMessage = strtr($encodedMessage, '+/', '-_');
            $encodedMessage = rtrim($encodedMessage, '=');

            $message = new Message();
            $message->setRaw($encodedMessage);

            $service->users_messages->send('me', $message);

            return true;
        } catch (GoogleServiceException $e) {
            $category = GmailErrorCategory::categorize($e);
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => $category,
                'code' => $e->getCode(),
                'to' => is_array($to) ? implode(', ', array_keys($to)) : $to,
                'subject' => $subject,
                'message' => $e->getMessage(),
            ]);

            return false;
        } catch (Exception $e) {
            Log::error('Gmail API error', [
                'method' => __FUNCTION__,
                'category' => GmailErrorCategory::UNKNOWN,
                'to' => is_array($to) ? implode(', ', array_keys($to)) : $to,
                'subject' => $subject,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Encode email header with name for UTF-8 characters
     *
     * @param string $name Name
     * @param string $email Email address
     * @return string Encoded header value (e.g., "=?UTF-8?B?...?= <email@example.com>")
     */
    private function encodeEmailHeader(string $name, string $email): string
    {
        // Sanitize to prevent CRLF injection
        $name = str_replace(["\r", "\n"], '', $name);
        $email = str_replace(["\r", "\n"], '', $email);

        // If name contains non-ASCII characters, encode it
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return mb_encode_mimeheader($name, 'UTF-8') . " <{$email}>";
        }

        return "{$name} <{$email}>";
    }

    /**
     * Create MIME message for sending
     *
     * @param array|string $to Recipient(s)
     * @param string $subject Subject
     * @param string $htmlBody HTML body
     * @param array $attachments Attachments (file paths)
     * @param string $boundary MIME boundary
     * @param array $options Additional options (from, cc, bcc, replyTo, headers)
     * @return string MIME message
     */
    private function createMimeMessage(string|array $to, string $subject, string $htmlBody, array $attachments, string $boundary, array $options = []): string
    {
        // Build From header
        if (!empty($options['from'])) {
            if (is_array($options['from'])) {
                // ['email' => 'name']
                $fromEmail = array_keys($options['from'])[0];
                $fromName = $options['from'][$fromEmail];
                $message = 'From: ' . $this->encodeEmailHeader($fromName, $fromEmail) . "\r\n";
            } else {
                $sanitizedFrom = str_replace(["\r", "\n"], '', (string)$options['from']);
                $message = "From: {$sanitizedFrom}\r\n";
            }
        } else {
            $message = '';
        }

        // Build To header
        if (is_array($to)) {
            $toList = [];
            foreach ($to as $email => $name) {
                if (is_numeric($email)) {
                    // Simple array of emails
                    $toList[] = str_replace(["\r", "\n"], '', $name);
                } else {
                    // Associative array ['email' => 'name']
                    $toList[] = $this->encodeEmailHeader($name, $email);
                }
            }
            $message .= 'To: ' . implode(', ', $toList) . "\r\n";
        } else {
            $sanitizedTo = str_replace(["\r", "\n"], '', (string)$to);
            $message .= "To: {$sanitizedTo}\r\n";
        }

        // Build CC header
        if (!empty($options['cc'])) {
            if (is_array($options['cc'])) {
                $ccList = [];
                foreach ($options['cc'] as $email => $name) {
                    if (is_numeric($email)) {
                        $ccList[] = str_replace(["\r", "\n"], '', $name);
                    } else {
                        $ccList[] = $this->encodeEmailHeader($name, $email);
                    }
                }
                $message .= 'Cc: ' . implode(', ', $ccList) . "\r\n";
            } else {
                $sanitizedCc = str_replace(["\r", "\n"], '', (string)$options['cc']);
                $message .= "Cc: {$sanitizedCc}\r\n";
            }
        }

        // Build BCC header
        if (!empty($options['bcc'])) {
            if (is_array($options['bcc'])) {
                $bccList = [];
                foreach ($options['bcc'] as $email => $name) {
                    if (is_numeric($email)) {
                        $bccList[] = str_replace(["\r", "\n"], '', $name);
                    } else {
                        $bccList[] = $this->encodeEmailHeader($name, $email);
                    }
                }
                $message .= 'Bcc: ' . implode(', ', $bccList) . "\r\n";
            } else {
                $sanitizedBcc = str_replace(["\r", "\n"], '', (string)$options['bcc']);
                $message .= "Bcc: {$sanitizedBcc}\r\n";
            }
        }

        // Reply-To header
        if (!empty($options['replyTo'])) {
            $sanitizedReplyTo = str_replace(["\r", "\n"], '', (string)$options['replyTo']);
            $message .= "Reply-To: {$sanitizedReplyTo}\r\n";
        }

        // Custom headers
        if (!empty($options['headers'])) {
            foreach ($options['headers'] as $headerName => $headerValue) {
                // Sanitize header name and value to prevent CRLF injection attacks
                $sanitizedName = str_replace(["\r", "\n"], '', (string)$headerName);
                $sanitizedValue = str_replace(["\r", "\n"], '', (string)$headerValue);
                $message .= "{$sanitizedName}: {$sanitizedValue}\r\n";
            }
        }

        // Sanitize and encode subject for UTF-8 characters (RFC 2047)
        $sanitizedSubject = str_replace(["\r", "\n"], '', $subject);
        $message .= 'Subject: ' . mb_encode_mimeheader($sanitizedSubject, 'UTF-8') . "\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

        // HTML body part
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        // Attachments
        foreach ($attachments as $filePath) {
            if (file_exists($filePath)) {
                $fileName = basename($filePath);
                $sanitizedFileName = str_replace(["\r", "\n"], '', $fileName);
                $encodedFileName = mb_encode_mimeheader($sanitizedFileName, 'UTF-8');
                $fileContent = file_get_contents($filePath);
                $mimeType = mime_content_type($filePath);

                $message .= "--{$boundary}\r\n";
                $message .= "Content-Type: {$mimeType}; name=\"{$encodedFileName}\"\r\n";
                $message .= "Content-Disposition: attachment; filename=\"{$encodedFileName}\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= chunk_split(base64_encode($fileContent)) . "\r\n";
            }
        }

        $message .= "--{$boundary}--";

        return $message;
    }

    /**
     * Get header value from headers array
     *
     * @param array $headers Array of header objects
     * @param string $name Header name to find
     * @return string Header value or empty string
     */
    private function getHeader(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            if (strtolower($header->getName()) === strtolower($name)) {
                return $header->getValue();
            }
        }

        return '';
    }

    /**
     * Extract email address from "Name <email@example.com>" format.
     *
     * @deprecated Use {@see \App\Service\Util\EmailHeaderParser::extractEmailAddress()}.
     *             Kept as a thin delegate for callers still holding a GmailService instance.
     * @param string $emailString Email string
     * @return string Email address
     */
    public function extractEmailAddress(string $emailString): string
    {
        return EmailHeaderParser::extractEmailAddress($emailString);
    }

    /**
     * Extract name from "Name <email@example.com>" format.
     *
     * @deprecated Use {@see \App\Service\Util\EmailHeaderParser::extractName()}.
     * @param string $emailString Email string
     * @return string Name or email if no name found
     */
    public function extractName(string $emailString): string
    {
        return EmailHeaderParser::extractName($emailString);
    }
}
