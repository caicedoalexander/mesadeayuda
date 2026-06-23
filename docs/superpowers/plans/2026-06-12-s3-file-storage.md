# Almacenamiento de archivos en AWS S3 — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover todo el almacenamiento de archivos de usuario (adjuntos de tickets y fotos de perfil) del filesystem local del VPS a un bucket privado de AWS S3, servido vía redirect 302 a URLs presignadas.

**Architecture:** Un único servicio nuevo `S3StorageService` encapsula el SDK de AWS (put/delete/presignedUrl/getStream). `GenericAttachmentTrait` y `ProfileImageService` delegan en él; `attachments.file_path` y `users.profile_image` pasan a guardar la clave S3. Dos rutas estables (`/attachments/view/{id}`, `/profile-images/view/{id}`) verifican autenticación y responden 302 a la presignada (15 min).

**Tech Stack:** CakePHP 5.3, PHP 8.5, `aws/aws-sdk-php` (S3Client + MockHandler en tests), PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-06-12-s3-file-storage-design.md`

**Hallazgo previo:** existe código S3 muerto de un intento anterior (`src/Service/S3Service.php`, `src/Service/Storage/FileStorageInterface.php`, `src/Service/Storage/S3StorageAdapter.php`) sin ningún consumidor y roto (importa `Aws\S3\S3Client` sin que el SDK esté en composer.json). La Task 2 lo elimina porque este trabajo lo reemplaza directamente.

**Convenciones obligatorias:** `declare(strict_types=1);` en todo archivo PHP. Antes de cada commit: `composer cs-fix && composer cs-check`.

---

### Task 1: Dependencia y configuración

**Files:**
- Modify: `composer.json` (vía composer require)
- Modify: `config/app.php` (después del bloque `'Security'`, ~línea 80)
- Modify: `config/app_local.example.php` (después del bloque `'Security'`)

- [ ] **Step 1: Instalar el SDK**

Run: `composer require aws/aws-sdk-php`
Expected: instala sin conflictos (PHP >= 8.5 es compatible).

- [ ] **Step 2: Agregar configuración S3 en `config/app.php`**

Insertar después del bloque `'Security' => [...]`:

```php
    /*
     * Almacenamiento de archivos en AWS S3 (adjuntos de tickets y fotos de
     * perfil). Bucket privado; los archivos se sirven vía URL presignada.
     * Credenciales SIEMPRE vía .env / app_local.php — nunca en system_settings.
     */
    'S3' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_REGION', 'us-east-1'),
        'bucket' => env('S3_BUCKET'),
    ],
```

- [ ] **Step 3: Replicar el bloque en `config/app_local.example.php`**

Insertar después del bloque `'Security' => [...]` del example:

```php
    /*
     * AWS S3 — bucket privado para adjuntos y fotos de perfil.
     * Define estas variables en config/.env o reemplaza los env() aquí.
     */
    'S3' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_REGION', 'us-east-1'),
        'bucket' => env('S3_BUCKET'),
    ],
```

- [ ] **Step 4: Verificar que nada se rompió**

Run: `composer test`
Expected: PASS (misma cantidad de tests que antes).

- [ ] **Step 5: Commit**

```bash
composer cs-fix && composer cs-check
git add composer.json composer.lock config/app.php config/app_local.example.php
git commit -m "feat: Agregar aws-sdk-php y configuración S3 vía variables de entorno"
```

---

### Task 2: Eliminar el código S3 muerto

El trío `S3Service` / `FileStorageInterface` / `S3StorageAdapter` no tiene consumidores (verificado por grep: solo se referencian entre sí) y contradice el spec aprobado ("un único servicio; nada fuera de él conoce el SDK").

**Files:**
- Delete: `src/Service/S3Service.php`
- Delete: `src/Service/Storage/S3StorageAdapter.php`
- Delete: `src/Service/Storage/FileStorageInterface.php`

- [ ] **Step 1: Borrar los tres archivos y el directorio `src/Service/Storage/`**

```bash
git rm src/Service/S3Service.php src/Service/Storage/S3StorageAdapter.php src/Service/Storage/FileStorageInterface.php
```

- [ ] **Step 2: Verificar que no queda ninguna referencia**

Run: `grep -rn "S3Service\|S3StorageAdapter\|FileStorageInterface" src/ tests/ config/`
Expected: sin resultados.

- [ ] **Step 3: Correr la suite**

Run: `composer test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git commit -m "chore: Eliminar capa S3 legacy sin consumidores (reemplazada por S3StorageService)"
```

---

### Task 3: `S3StorageService` (TDD)

**Files:**
- Create: `src/Service/S3StorageService.php`
- Test: `tests/TestCase/Service/S3StorageServiceTest.php`

Notas de diseño: el `S3Client` se inyecta (tests) o se construye lazy desde `Configure::read('S3.*')` (producción). El SDK trae `Aws\MockHandler` para tests sin red, y `createPresignedRequest()` firma localmente sin red — ambos permiten unit tests puros.

- [ ] **Step 1: Escribir los tests (fallarán: la clase no existe)**

```php
<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\S3StorageService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Cake\Core\Configure;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class S3StorageServiceTest extends TestCase
{
    private MockHandler $mock;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('S3', [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'test-bucket',
        ]);
        $this->mock = new MockHandler();
    }

    protected function tearDown(): void
    {
        Configure::delete('S3');
        parent::tearDown();
    }

    private function makeService(): S3StorageService
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $this->mock,
        ]);

        return new S3StorageService($client);
    }

    public function testPutSendsPutObjectWithKeyMimeAndEncryption(): void
    {
        $this->mock->append(new Result([]));
        $service = $this->makeService();

        $ok = $service->put('attachments/1000/abc.pdf', 'binario', 'application/pdf');

        $this->assertTrue($ok);
        $cmd = $this->mock->getLastCommand();
        $this->assertSame('PutObject', $cmd->getName());
        $this->assertSame('test-bucket', $cmd['Bucket']);
        $this->assertSame('attachments/1000/abc.pdf', $cmd['Key']);
        $this->assertSame('application/pdf', $cmd['ContentType']);
        $this->assertSame('AES256', $cmd['ServerSideEncryption']);
    }

    public function testPutReturnsFalseOnAwsError(): void
    {
        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('acceso denegado', $cmd);
        });
        $service = $this->makeService();

        $this->assertFalse($service->put('attachments/1000/abc.pdf', 'x', 'application/pdf'));
    }

    public function testDeleteSendsDeleteObject(): void
    {
        $this->mock->append(new Result([]));
        $service = $this->makeService();

        $this->assertTrue($service->delete('attachments/1000/abc.pdf'));
        $cmd = $this->mock->getLastCommand();
        $this->assertSame('DeleteObject', $cmd->getName());
        $this->assertSame('attachments/1000/abc.pdf', $cmd['Key']);
    }

    public function testDeleteReturnsFalseOnAwsError(): void
    {
        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('fallo', $cmd);
        });
        $service = $this->makeService();

        $this->assertFalse($service->delete('attachments/1000/abc.pdf'));
    }

    public function testPresignedUrlContainsKeySignatureAndAttachmentDisposition(): void
    {
        $service = $this->makeService();

        $url = $service->presignedUrl('attachments/1000/abc.pdf', 'informe final.pdf');

        $this->assertNotNull($url);
        $this->assertStringContainsString('attachments/1000/abc.pdf', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringContainsString(
            rawurlencode('attachment; filename="informe final.pdf"'),
            $url,
        );
    }

    public function testPresignedUrlInlineDisposition(): void
    {
        $service = $this->makeService();

        $url = $service->presignedUrl('attachments/1000/img.png', 'foto.png', inline: true);

        $this->assertNotNull($url);
        $this->assertStringContainsString(
            rawurlencode('inline; filename="foto.png"'),
            $url,
        );
    }

    public function testPresignedUrlEscapesQuotesInFilename(): void
    {
        $service = $this->makeService();

        $url = $service->presignedUrl('attachments/1000/a.pdf', 'ra"ro.pdf');

        $this->assertNotNull($url);
        $this->assertStringContainsString(rawurlencode('filename="ra\\"ro.pdf"'), $url);
    }

    public function testGetStreamReturnsResourceWithBody(): void
    {
        $this->mock->append(new Result(['Body' => Utils::streamFor('contenido')]));
        $service = $this->makeService();

        $stream = $service->getStream('attachments/1000/abc.pdf');

        $this->assertIsResource($stream);
        $this->assertSame('contenido', stream_get_contents($stream));
        fclose($stream);
    }

    public function testGetStreamReturnsNullOnAwsError(): void
    {
        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('no existe', $cmd);
        });
        $service = $this->makeService();

        $this->assertNull($service->getStream('attachments/1000/no.pdf'));
    }
}
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `vendor/bin/phpunit tests/TestCase/Service/S3StorageServiceTest.php`
Expected: FAIL — `Class "App\Service\S3StorageService" not found`.

- [ ] **Step 3: Implementar el servicio**

```php
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
     * @param string|resource $body Contenido (string o stream)
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
            $disposition = ($inline ? 'inline' : 'attachment')
                . '; filename="' . addcslashes($downloadFilename, '"\\') . '"';

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
```

- [ ] **Step 4: Correr los tests y verificar que pasan**

Run: `vendor/bin/phpunit tests/TestCase/Service/S3StorageServiceTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Service/S3StorageService.php tests/TestCase/Service/S3StorageServiceTest.php
git commit -m "feat: Agregar S3StorageService (put/delete/presigned/stream) con tests sin red"
```

---

### Task 4: `GenericAttachmentTrait` a S3

**Files:**
- Modify: `src/Service/Traits/GenericAttachmentTrait.php`
- Modify: `src/Service/TicketAttachmentService.php`
- Test: `tests/TestCase/Service/Traits/GenericAttachmentTraitTest.php` (agregar tests de `getWebUrl`)

La validación de seguridad (allowlist, finfo, sanitización) NO se toca. Cambian solo: escritura, borrado, URLs y streams.

- [ ] **Step 1: Escribir tests de `getWebUrl` con el nuevo contrato (fallarán)**

Agregar al final de `GenericAttachmentTraitTest` (dentro de la clase). El harness existente (`makeHarness()`) expone el trait; agregar un método al harness si solo expone `validate`/`sanitize` — revisar `makeHarness()` y exponer `getWebUrl` igual que los demás:

```php
    // -------------------------------------------------------------------
    // getWebUrl()
    // -------------------------------------------------------------------

    public function testGetWebUrlReturnsStableAppRouteForS3Keys(): void
    {
        $attachment = new \App\Model\Entity\Attachment([
            'id' => 42,
            'file_path' => 'attachments/1000/abc.pdf',
        ]);

        $this->assertSame('/attachments/view/42', $this->harness->webUrl($attachment));
    }

    public function testGetWebUrlReturnsNullForLegacyLocalPaths(): void
    {
        $attachment = new \App\Model\Entity\Attachment([
            'id' => 42,
            'file_path' => 'uploads/attachments/1000/abc.pdf',
        ]);

        $this->assertNull($this->harness->webUrl($attachment));
    }
```

Y en el harness (clase anónima dentro de `makeHarness()`), agregar:

```php
            public function webUrl(\Cake\Datasource\EntityInterface $attachment): ?string
            {
                return $this->getWebUrl($attachment);
            }
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `vendor/bin/phpunit --filter GenericAttachmentTraitTest`
Expected: FAIL — `getWebUrl` actual devuelve la ruta `/uploads/...` para el caso legacy y null para claves `attachments/...`.

- [ ] **Step 3: Modificar el trait**

En `src/Service/Traits/GenericAttachmentTrait.php`:

3a. Imports y docblock: agregar `use App\Service\S3StorageService;`, quitar el comentario "(local filesystem only)" del docblock de la clase y reemplazarlo por "Ticket attachment handling with security validation (storage: AWS S3)."

3b. Reemplazar las constantes de rutas locales:

```php
    private const ATTACHMENTS_TABLE = 'Attachments';
    private const STORAGE_KEY_PREFIX = 'attachments/';
```

(eliminar `LOCAL_BASE` y `UPLOAD_BASE_DIR`).

3c. Agregar inyección lazy del storage (después de las constantes de tamaño):

```php
    private ?S3StorageService $s3Storage = null;

    /**
     * Inyección para tests. En producción se construye lazy.
     */
    public function setS3Storage(S3StorageService $storage): void
    {
        $this->s3Storage = $storage;
    }

    protected function s3Storage(): S3StorageService
    {
        return $this->s3Storage ??= new S3StorageService();
    }
```

3d. En `saveGenericUploadedFile()`, reemplazar el bloque desde `$uploadDir = $this->getUploadDirectory(...)` hasta `$filePath = $this->buildLocalPath(...)` (líneas 129-147 actuales) por:

```php
        $key = $this->buildStorageKey($entityNumber, $filename);

        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        if (!$this->s3Storage()->put($key, (string)$stream, (string)$mimeType)) {
            Log::error('Failed to upload ticket file to S3', ['key' => $key]);

            return null;
        }

        $filePath = $key;
```

3e. En el mismo método, reemplazar la compensación cuando falla el save de BD (el bloque `if (file_exists($fullPath)) { @unlink($fullPath); }`) por:

```php
            $this->s3Storage()->delete($key);
```

3f. En `saveAttachmentFromBinary()`, reemplazar el bloque desde `$uploadDir = ...` hasta `$filePath = $this->buildLocalPath(...)` (líneas 309-325 actuales) por:

```php
        $key = $this->buildStorageKey($entityNumber, $uniqueFilename);

        if (!$this->s3Storage()->put($key, $binaryContent, $mimeType)) {
            Log::error('Failed to upload ticket attachment to S3', ['key' => $key]);

            return null;
        }

        $filePath = $key;
```

y la compensación final `@unlink($fullPath);` por:

```php
        $this->s3Storage()->delete($key);
```

3g. Reemplazar `buildLocalPath()` y `getUploadDirectory()` por:

```php
    /**
     * @param string $entityNumber Ticket id
     * @param string $filename Stored filename
     * @return string S3 key stored in attachments.file_path
     */
    private function buildStorageKey(string $entityNumber, string $filename): string
    {
        return self::STORAGE_KEY_PREFIX . $entityNumber . '/' . $filename;
    }
```

3h. En `deleteGenericAttachment()`, reemplazar:

```php
            $fullPath = WWW_ROOT . $attachment->file_path;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
```

por:

```php
            $this->s3Storage()->delete((string)$attachment->file_path);
```

3i. Eliminar `getFullPath()` completo (sus dos callers se actualizan en Tasks 5 y 6 — el orden de commits dentro de esta task y las siguientes debe dejar la suite verde: hacer 3i, Task 5 Step 1 y Task 6 Step 1 en el mismo commit si la suite o phpstan acusan el método faltante; ver Step 5).

3j. Reemplazar `getWebUrl()` por:

```php
    /**
     * Ruta estable de la app para ver/incrustar un adjunto. NUNCA una URL
     * presignada: estas URLs se guardan incrustadas en HTML en BD (imágenes
     * inline) y no pueden expirar.
     *
     * @param \Cake\Datasource\EntityInterface $attachment Attachment entity
     * @return string|null Web URL
     */
    public function getWebUrl(EntityInterface $attachment): ?string
    {
        if (!str_starts_with((string)$attachment->file_path, self::STORAGE_KEY_PREFIX)) {
            return null;
        }

        return '/attachments/view/' . $attachment->id;
    }
```

3k. Reemplazar `getFileStream()` por:

```php
    /**
     * Stream del contenido del adjunto desde S3 (consumo server-side).
     *
     * @param \Cake\Datasource\EntityInterface $attachment Attachment entity
     * @return resource|null
     */
    public function getFileStream(EntityInterface $attachment)
    {
        return $this->s3Storage()->getStream((string)$attachment->file_path);
    }
```

3l. Agregar método nuevo (lo usan los controllers en Task 5):

```php
    /**
     * URL presignada de S3 para servir el adjunto vía redirect 302.
     *
     * @param \Cake\Datasource\EntityInterface $attachment Attachment entity
     * @param bool $inline True para disposición inline (imágenes en el navegador)
     * @return string|null
     */
    public function getPresignedUrlFor(EntityInterface $attachment, bool $inline = false): ?string
    {
        if (!str_starts_with((string)$attachment->file_path, self::STORAGE_KEY_PREFIX)) {
            return null;
        }

        return $this->s3Storage()->presignedUrl(
            (string)$attachment->file_path,
            (string)$attachment->original_filename,
            $inline,
        );
    }
```

- [ ] **Step 4: Agregar `deleteStoredObject()` a `TicketAttachmentService`**

Lo usa `TicketPipelineService::cleanupOrphanedFiles` (Task 6) para limpiar objetos huérfanos tras rollback (el registro de BD ya no existe, así que `deleteGenericAttachment(id)` no sirve):

```php
    /**
     * Borrado directo de un objeto S3 por clave. Solo para compensación de
     * rollbacks (el registro attachments ya no existe).
     *
     * @param string $key Clave S3 tal como estaba en attachments.file_path
     * @return bool True si se borró
     */
    public function deleteStoredObject(string $key): bool
    {
        return $this->s3Storage()->delete($key);
    }
```

- [ ] **Step 5: Correr la suite completa**

Run: `composer test`
Expected: PASS, incluyendo los dos tests nuevos de `getWebUrl`. Si fallan compilación/phpstan por los callers de `getFullPath` (EmailService, TicketActionsTrait), aplicar Task 5 Step 1 y Task 6 Step 1 antes de commitear y commitear junto.

- [ ] **Step 6: Commit**

```bash
composer cs-fix && composer cs-check
git add -A src/ tests/
git commit -m "feat: Migrar GenericAttachmentTrait de filesystem local a S3"
```

---

### Task 5: Servir adjuntos — rutas y acciones

**Files:**
- Modify: `src/Controller/Trait/TicketActionsTrait.php` (líneas 95-104 y 242-258 actuales)
- Modify: `config/routes.php` (scope `/`)

- [ ] **Step 1: Reescribir `downloadTicketAttachment` y agregar la acción `viewAttachment`**

En `TicketActionsTrait`, reemplazar el método `downloadTicketAttachment` por:

```php
    /**
     * Sirve un adjunto vía redirect 302 a una URL presignada de S3.
     * La autorización es la misma que el resto del módulo de tickets:
     * usuario autenticado (Authentication en AppController).
     *
     * @param int $attachmentId Attachment id
     * @param bool $inline True para mostrar en el navegador (imágenes inline)
     */
    protected function downloadTicketAttachment(int $attachmentId, bool $inline = false): Response
    {
        $attachmentsTable = $this->fetchTable('Attachments');
        $attachment = $attachmentsTable->get($attachmentId);

        $url = $this->getPresignedUrlFor($attachment, $inline);
        if ($url === null) {
            throw new NotFoundException('Archivo no encontrado.');
        }

        return $this->redirect($url);
    }
```

Y junto a la acción pública `downloadAttachment` existente (línea ~101), agregar:

```php
    /**
     * Ruta estable para imágenes inline y previsualizaciones
     * (/attachments/view/{id}). Las URLs guardadas en HTML en BD apuntan
     * aquí; la presignada se genera en cada request.
     *
     * @param string|null $id Attachment id
     */
    public function viewAttachment(?string $id = null)
    {
        return $this->downloadTicketAttachment((int)$id, inline: true);
    }
```

- [ ] **Step 2: Conectar la ruta estable en `config/routes.php`**

Dentro del scope `/` (después de la ruta `/health`):

```php
        // Ruta estable de adjuntos: las URLs incrustadas en HTML guardado en
        // BD (imágenes inline) apuntan aquí; la acción redirige 302 a una URL
        // presignada de S3. NO cambiar este path sin migrar el HTML guardado.
        $builder->connect(
            '/attachments/view/{id}',
            ['controller' => 'Tickets', 'action' => 'viewAttachment'],
            ['_name' => 'attachment_view']
        )
            ->setPatterns(['id' => '\d+'])
            ->setPass(['id']);
```

- [ ] **Step 3: Verificar rutas y suite**

Run: `bin/cake routes | grep -i attachment`
Expected: aparece `attachment_view` → `Tickets::viewAttachment`.

Run: `composer test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Controller/Trait/TicketActionsTrait.php config/routes.php
git commit -m "feat: Servir adjuntos vía redirect 302 a URL presignada de S3"
```

---

### Task 6: Consumidores server-side — `EmailService` y `TicketPipelineService`

**Files:**
- Modify: `src/Service/EmailService.php` (líneas 143-149 actuales + `finally`)
- Modify: `src/Service/TicketPipelineService.php` (método `cleanupOrphanedFiles`, líneas 748-761 actuales)

- [ ] **Step 1: Materializar adjuntos S3 a archivos temporales en `EmailService::sendEmail`**

Contexto: `GmailService::createMimeMessage` lee cada adjunto con `file_get_contents($filePath)` y usa `basename($filePath)` como nombre del archivo en el MIME (GmailService.php:1143-1146). Por eso cada adjunto se materializa en un subdirectorio temporal propio con su `original_filename` real.

En `sendEmail()`, reemplazar:

```php
            $attachmentPaths = [];
            foreach ($attachments as $attachment) {
                $filePath = $this->getFullPath($attachment);
                if (file_exists($filePath)) {
                    $attachmentPaths[] = $filePath;
                }
            }
```

por:

```php
            $tempDir = null;
            $attachmentPaths = [];
            if ($attachments !== []) {
                $tempDir = sys_get_temp_dir() . DS . 'mesa_att_' . bin2hex(random_bytes(8));
                foreach ($attachments as $i => $attachment) {
                    $stream = $this->getFileStream($attachment);
                    if ($stream === null) {
                        Log::warning('Skipping email attachment, S3 object unavailable', [
                            'attachment_id' => $attachment->id ?? null,
                        ]);
                        continue;
                    }
                    // Subdirectorio por adjunto: basename() del path es el
                    // nombre que verá el destinatario, y dos adjuntos pueden
                    // llamarse igual.
                    $itemDir = $tempDir . DS . $i;
                    if (!is_dir($itemDir) && !mkdir($itemDir, 0700, true)) {
                        fclose($stream);
                        continue;
                    }
                    $localPath = $itemDir . DS . basename((string)$attachment->original_filename);
                    $dest = fopen($localPath, 'wb');
                    if ($dest === false) {
                        fclose($stream);
                        continue;
                    }
                    stream_copy_to_stream($stream, $dest);
                    fclose($dest);
                    fclose($stream);
                    $attachmentPaths[] = $localPath;
                }
            }
```

El `try` existente de `sendEmail` debe ganar un `finally` que limpie el directorio temporal (agregar después del bloque `catch` actual):

```php
        } finally {
            if (isset($tempDir) && $tempDir !== null && is_dir($tempDir)) {
                foreach (glob($tempDir . DS . '*' . DS . '*') ?: [] as $f) {
                    @unlink($f);
                }
                foreach (glob($tempDir . DS . '*') ?: [] as $d) {
                    @rmdir($d);
                }
                @rmdir($tempDir);
            }
        }
```

Nota: `$tempDir` debe declararse ANTES del `try` (mover `$tempDir = null;` arriba del `try` para que el `finally` lo vea).

- [ ] **Step 2: Compensación de rollback en `TicketPipelineService`**

Reemplazar `cleanupOrphanedFiles` completo por:

```php
    /**
     * Best-effort removal of attachment objects uploaded to S3 during a
     * transaction that subsequently rolled back. Failures are logged but never
     * propagated — the caller's primary error is more important than cleanup.
     *
     * @param array<int, string> $storageKeys S3 keys as stored in attachments.file_path
     *        (e.g., "attachments/1000/uuid.pdf").
     */
    private function cleanupOrphanedFiles(array $storageKeys): void
    {
        foreach ($storageKeys as $key) {
            if (!$this->attachments->deleteStoredObject($key)) {
                Log::warning('Failed to cleanup orphaned attachment after TX rollback', [
                    'key' => $key,
                ]);
            }
        }
    }
```

(`$this->attachments` es el `TicketAttachmentService` ya inyectado; `deleteStoredObject` se agregó en Task 4 Step 4.)

- [ ] **Step 3: Verificar suite y análisis estático**

Run: `composer test`
Expected: PASS.

Run: `vendor/bin/phpstan analyse src`
Expected: sin errores nuevos (sin referencias a `getFullPath`).

- [ ] **Step 4: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Service/EmailService.php src/Service/TicketPipelineService.php
git commit -m "feat: Leer adjuntos desde S3 en correo saliente y limpieza de rollback"
```

---

### Task 7: Fotos de perfil a S3

**Files:**
- Modify: `src/Service/ProfileImageService.php`
- Modify: `src/View/Helper/UserHelper.php` (métodos `profileImage` y `profileImageTag`)
- Modify: `src/Controller/UsersController.php` (acción nueva `profileImage`)
- Modify: `config/routes.php`

- [ ] **Step 1: Migrar `ProfileImageService` a S3**

Reemplazar el archivo (validación intacta, cambia solo el almacenamiento):

```php
<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Text;
use Psr\Http\Message\UploadedFileInterface;

/**
 * ProfileImageService
 *
 * Handles profile image upload, storage (AWS S3), and retrieval.
 *
 * Extracted from UsersTable for SRP compliance.
 */
class ProfileImageService
{
    use LocatorAwareTrait;

    private const STORAGE_KEY_PREFIX = 'profile_images/';
    private const MAX_IMAGE_SIZE = 2097152;

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

    private ?S3StorageService $storage;

    /**
     * @param \App\Service\S3StorageService|null $storage Inyección para tests;
     *        en producción se construye lazy.
     */
    public function __construct(?S3StorageService $storage = null)
    {
        $this->storage = $storage;
    }

    /**
     * Save profile image for a user (S3 storage)
     *
     * @param int $userId User ID
     * @param \Psr\Http\Message\UploadedFileInterface $uploadedFile Uploaded file
     * @return array Result with success status and filename (S3 key) or error message
     */
    public function saveProfileImage(int $userId, UploadedFileInterface $uploadedFile): array
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

        if ($size > self::MAX_IMAGE_SIZE) {
            return ['success' => false, 'message' => 'La imagen no debe superar 2MB'];
        }

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

                    return [
                        'success' => false,
                        'message' => 'El contenido del archivo no corresponde a una imagen válida',
                    ];
                }
            }
        }

        $uniqueFilename = Text::uuid() . '.' . $extension;
        $key = self::STORAGE_KEY_PREFIX . $userId . '/' . $uniqueFilename;

        $usersTable = $this->fetchTable('Users');
        $user = $usersTable->get($userId);
        if ($user->profile_image) {
            $this->deleteProfileImage($user->profile_image);
        }

        $stream = $uploadedFile->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        if (!$this->storage()->put($key, (string)$stream, (string)$mimeType)) {
            Log::error('Failed to upload profile image to S3', ['key' => $key]);

            return ['success' => false, 'message' => 'Error al guardar la imagen'];
        }

        return ['success' => true, 'filename' => $key];
    }

    /**
     * Delete a profile image object from S3
     *
     * @param string $key S3 key as stored in users.profile_image
     * @return bool True on success
     */
    public function deleteProfileImage(string $key): bool
    {
        if (empty($key) || !str_starts_with($key, self::STORAGE_KEY_PREFIX)) {
            return false;
        }

        return $this->storage()->delete($key);
    }

    /**
     * URL presignada (inline) para servir la imagen de perfil vía 302.
     *
     * @param string $key S3 key as stored in users.profile_image
     * @return string|null
     */
    public function presignedImageUrl(string $key): ?string
    {
        if (!str_starts_with($key, self::STORAGE_KEY_PREFIX)) {
            return null;
        }

        return $this->storage()->presignedUrl($key, basename($key), inline: true);
    }

    private function storage(): S3StorageService
    {
        return $this->storage ??= new S3StorageService();
    }
}
```

- [ ] **Step 2: Acción de servido en `UsersController`**

Agregar (respetando los imports/estilo existentes del controller — necesita `use App\Service\ProfileImageService;`, `use Cake\Http\Exception\NotFoundException;` y `use Cake\Http\Response;` si no están):

```php
    /**
     * Sirve la foto de perfil vía redirect 302 a URL presignada de S3.
     * Autorización: cualquier usuario autenticado (igual que el resto de
     * vistas que muestran avatares).
     *
     * @param string|null $id User id
     */
    public function profileImage(?string $id = null): Response
    {
        $user = $this->fetchTable('Users')->get((int)$id);

        $key = (string)$user->profile_image;
        $url = $key !== '' ? (new ProfileImageService())->presignedImageUrl($key) : null;

        if ($url === null) {
            throw new NotFoundException('Imagen de perfil no encontrada.');
        }

        return $this->redirect($url);
    }
```

Si `UsersController::beforeFilter` usa `allowUnauthenticated`, NO agregar esta acción a esa lista — requiere sesión.

- [ ] **Step 3: Ruta en `config/routes.php`**

Debajo de la ruta `attachment_view` agregada en Task 5:

```php
        // Ruta estable de fotos de perfil → 302 a URL presignada de S3.
        $builder->connect(
            '/profile-images/view/{id}',
            ['controller' => 'Users', 'action' => 'profileImage'],
            ['_name' => 'profile_image_view']
        )
            ->setPatterns(['id' => '\d+'])
            ->setPass(['id']);
```

- [ ] **Step 4: Actualizar `UserHelper`**

Reemplazar `profileImage()` (líneas 24-42) por:

```php
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
```

Y en `profileImageTag()` (líneas 71-73), reemplazar:

```php
        $imageUrl = $user && $user->profile_image
            ? $this->profileImage($user->profile_image)
            : $this->defaultAvatar();
```

por:

```php
        $imageUrl = $user && $user->profile_image
            ? $this->profileImage($user->profile_image, (int)$user->id)
            : $this->defaultAvatar();
```

(Verificado por grep: los templates solo usan `profileImageTag`/`avatar`, nunca `profileImage()` directo, así que el cambio de firma no rompe vistas.)

- [ ] **Step 5: Verificar suite y rutas**

Run: `composer test`
Expected: PASS.

Run: `bin/cake routes | grep -i profile`
Expected: aparece `profile_image_view` → `Users::profileImage`.

- [ ] **Step 6: Commit**

```bash
composer cs-fix && composer cs-check
git add src/Service/ProfileImageService.php src/View/Helper/UserHelper.php src/Controller/UsersController.php config/routes.php
git commit -m "feat: Migrar fotos de perfil a S3 con servido vía URL presignada"
```

---

### Task 8: Cierre — Docker, documentación y verificación final

**Files:**
- Modify: `docker-compose.yml` (línea 14)
- Modify: `README.md` (sección de variables de entorno)
- Modify: `CLAUDE.md` (sección Attachments)

- [ ] **Step 1: Quitar el volumen de uploads de `docker-compose.yml`**

Eliminar la línea:

```yaml
      - ./webroot/uploads:/var/www/html/webroot/uploads
```

- [ ] **Step 2: Documentar variables de entorno en README**

En la sección de configuración/variables de entorno del README, agregar:

```markdown
### Almacenamiento de archivos (AWS S3)

Los adjuntos de tickets y las fotos de perfil se guardan en un bucket privado
de S3 (con Block Public Access activado) y se sirven mediante URLs presignadas.
Variables requeridas en `config/.env`:

| Variable | Descripción |
|---|---|
| `AWS_ACCESS_KEY_ID` | Access key del usuario IAM (permisos mínimos: `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` sobre el bucket) |
| `AWS_SECRET_ACCESS_KEY` | Secret key del usuario IAM |
| `AWS_REGION` | Región del bucket (ej. `us-east-1`) |
| `S3_BUCKET` | Nombre del bucket |

En desarrollo se usa un bucket de desarrollo separado con las mismas variables.
```

- [ ] **Step 3: Actualizar CLAUDE.md (sección Attachments)**

Reemplazar la frase sobre `webroot/uploads/attachments/{id}/` y el volumen por:

```markdown
`GenericAttachmentTrait` is the shared upload/validation entry point (security
tests in `tests/TestCase/Service/...`). Files are stored in a private AWS S3
bucket (`S3StorageService`, config via `.env`: `AWS_ACCESS_KEY_ID`,
`AWS_SECRET_ACCESS_KEY`, `AWS_REGION`, `S3_BUCKET`); `attachments.file_path`
holds the S3 key (`attachments/{ticket_id}/{uuid}.ext`). Files are served via
stable app routes (`/attachments/view/{id}`, `/profile-images/view/{id}`) that
302-redirect to short-lived presigned URLs — never embed presigned URLs in
stored HTML.
```

- [ ] **Step 4: Verificación final completa**

Run: `composer test`
Expected: PASS completo.

Run: `vendor/bin/phpstan analyse src`
Expected: sin errores nuevos (línea base previa).

Run: `grep -rn "WWW_ROOT . \$attachment\|uploads/attachments\|uploads' . DS . 'profile" src/`
Expected: sin resultados (ninguna ruta local de archivos de usuario queda en src/).

- [ ] **Step 5: Commit final**

```bash
composer cs-fix && composer cs-check
git add docker-compose.yml README.md CLAUDE.md
git commit -m "chore: Quitar volumen de uploads y documentar configuración S3"
```

---

## Desviaciones conscientes respecto al spec

1. **Descarga forzada**: el spec proponía `/attachments/view/{id}?download=1`. El plan conserva la acción existente `downloadAttachment` (los templates ya apuntan ahí) con disposición `attachment`, y agrega `viewAttachment` con disposición `inline`. Mismo resultado, menos cambios en templates.
2. **Tests de autorización de la ruta**: el spec pedía tests de integración 403/404 para la ruta de servido. El proyecto no tiene fixtures wired (`tests/bootstrap.php` sin fixtures; CLAUDE.md: "most existing tests are pure unit tests"). La autorización es el middleware global de Authentication (sin lógica nueva que testear); se cubre con los unit tests de `getWebUrl`/`getPresignedUrlFor` (devuelven null para claves inválidas → 404) y verificación manual. Si se desea el test de integración, requiere primero wiring de fixtures — fuera del alcance de este plan.

## Notas para el ejecutor

- **Orden estricto**: Tasks 4-6 tocan callers cruzados de `getFullPath`. Si la suite o phpstan fallan a mitad de camino por el método eliminado, agrupa los cambios de Task 4 Step 3i + Task 5 Step 1 + Task 6 Step 1 en un solo commit verde.
- **Datos legacy**: filas existentes de `attachments` con `file_path = 'uploads/...'` devuelven `null` en `getWebUrl`/`getPresignedUrlFor` (→ 404 al servir). Es el comportamiento aceptado por el spec (entorno de desarrollo, sin migración).
- **No tocar**: la validación de seguridad del trait (`validateFile`, `verifyMimeType*`, `sanitizeFilename`) y sus tests existentes quedan exactamente igual.
- El harness de `GenericAttachmentTraitTest` es una clase anónima — al agregar el método `webUrl()` revisa cómo expone los demás métodos y sigue el mismo patrón.
