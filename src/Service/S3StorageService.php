<?php
declare(strict_types=1);

namespace App\Service;

use Aws\S3\S3Client;
use Cake\Core\Configure;
use Cake\Log\Log;
use Throwable;

/**
 * S3StorageService
 *
 * Único punto de contacto con AWS S3. Nada fuera de esta clase conoce el SDK.
 * Bucket privado: los archivos se sirven vía presignedUrl() con expiración
 * corta, nunca por URL pública.
 *
 * Configuración en Configure 'S3.*' (key, secret, region, bucket), poblada
 * desde variables de entorno en config/app.php.
 */
class S3StorageService
{
    /**
     * Expiración de URLs presignadas. Corta a propósito: las URLs estables
     * son las rutas de la app (/attachments/view/{id}); la presignada solo
     * vive lo que dura la descarga.
     */
    private const PRESIGNED_EXPIRATION = '+15 minutes';

    private ?S3Client $client;
    private string $bucket;

    /**
     * @param \Aws\S3\S3Client|null $client Cliente pre-construido (tests).
     *        Cuando es null, se construye lazy desde Configure en el primer uso.
     */
    public function __construct(?S3Client $client = null)
    {
        $this->client = $client;
        $this->bucket = (string)Configure::read('S3.bucket', '');
    }

    /**
     * Subir un objeto a S3.
     *
     * @param string $key Clave S3 (ej. 'attachments/1000/uuid.pdf')
     * @param resource|string $body Contenido (string o stream)
     * @param string $mimeType MIME type del objeto
     * @return bool True si se subió correctamente
     */
    public function put(string $key, mixed $body, string $mimeType): bool
    {
        try {
            $this->client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $body,
                'ContentType' => $mimeType,
                'ServerSideEncryption' => 'AES256',
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('S3 put failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Borrar un objeto de S3.
     *
     * @param string $key Clave S3
     * @return bool True si se borró correctamente
     */
    public function delete(string $key): bool
    {
        try {
            $this->client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('S3 delete failed', ['key' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * URL presignada de descarga (firma local, sin red).
     *
     * @param string $key Clave S3
     * @param string $downloadFilename Nombre que verá el navegador
     * @param bool $inline True para mostrar en el navegador, false para descargar
     * @return string|null URL o null en fallo
     */
    public function presignedUrl(string $key, string $downloadFilename, bool $inline = false): ?string
    {
        try {
            // Los control chars (CRLF, null) permitirían inyectar headers
            // cuando S3 refleja la disposición; los filenames llegan
            // sanitizados upstream, pero este servicio no confía en callers.
            $safeFilename = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $downloadFilename);
            $disposition = ($inline ? 'inline' : 'attachment')
                . '; filename="' . addcslashes($safeFilename, '"\\') . '"';

            $cmd = $this->client()->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ResponseContentDisposition' => $disposition,
            ]);
            $request = $this->client()->createPresignedRequest($cmd, self::PRESIGNED_EXPIRATION);

            return (string)$request->getUri();
        } catch (Throwable $e) {
            Log::error('S3 presigned URL failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Stream de lectura de un objeto (para consumidores server-side, ej.
     * adjuntar archivos a correos salientes).
     *
     * @param string $key Clave S3
     * @return resource|null
     */
    public function getStream(string $key)
    {
        try {
            $result = $this->client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return $result->get('Body')->detach();
        } catch (Throwable $e) {
            Log::error('S3 get stream failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Cliente lazy: no se construye en el constructor para no penalizar
     * requests que no tocan archivos (mismo criterio que
     * TicketServiceInitializerTrait).
     */
    private function client(): S3Client
    {
        return $this->client ??= new S3Client([
            'version' => 'latest',
            'region' => (string)Configure::read('S3.region', 'us-east-1'),
            'credentials' => [
                'key' => (string)Configure::read('S3.key'),
                'secret' => (string)Configure::read('S3.secret'),
            ],
        ]);
    }
}
