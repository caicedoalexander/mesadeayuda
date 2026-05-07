# Eliminación de la integración con S3

**Fecha:** 2026-05-07
**Autor:** Alexander
**Estado:** Aprobado para implementación

## Contexto

Mesa de Ayuda incluye una integración con AWS S3 para almacenar adjuntos de tickets e imágenes de perfil. La abstracción está implementada vía `FileStorageInterface` con un único adapter (`S3StorageAdapter`) y se conmuta con `AWS_S3_ENABLED`. La integración nunca se usó en producción: todos los `file_path` actuales en BD apuntan a disco local (`uploads/...`).

Mantener este código añade dependencias innecesarias (`aws/aws-sdk-php`), expone variables de entorno sin uso y duplica caminos de ejecución en cada operación de archivos.

## Objetivo

Eliminar completamente la integración con S3 del proyecto, dejando un único camino de almacenamiento: disco local bajo `webroot/uploads/`.

## Alcance

- **Sí incluye:** código PHP, dependencias Composer, configuración de Docker y CakePHP, documentación.
- **No incluye:** migración de datos (no hay archivos en S3 por confirmación del usuario), nuevas funcionalidades, refactor de la lógica de validación de archivos.

## Decisiones de diseño

### D1. Eliminación total de la abstracción

Se elimina `FileStorageInterface` junto con su único adapter. Los consumidores (`GenericAttachmentTrait`, `ProfileImageService`) hacen I/O local directamente con `WWW_ROOT`. No se introduce un `LocalStorageAdapter` ni una clase intermedia: YAGNI, hoy no hay un segundo backend ni planes inmediatos de reintroducirlo.

### D2. Sin migración de datos

El usuario confirmó que `AWS_S3_ENABLED` siempre estuvo en `false` en producción y todos los `file_path` empiezan por `uploads/`. No se requiere script de migración ni lógica transitoria.

### D3. Validación de seguridad intacta

`validateFile`, `verifyMimeTypeFromContent`, `sanitizeFilename`, `FORBIDDEN_EXTENSIONS`, `ALLOWED_TYPES` y los límites de tamaño se conservan sin cambios. La eliminación es puramente del backend de almacenamiento.

## Cambios detallados

### Archivos a eliminar

- `src/Service/S3Service.php`
- `src/Service/Storage/S3StorageAdapter.php`
- `src/Service/Storage/FileStorageInterface.php`
- Carpeta `src/Service/Storage/` (queda vacía)

### `src/Service/Traits/GenericAttachmentTrait.php`

**Eliminar:**

- `use App\Service\S3Service;`
- Constante `S3_PREFIX`.
- Propiedad `$s3Service` y método `getS3Service()`.
- Método privado `buildS3Key()`.
- Toda la rama `if ($useS3) { ... }` en `saveGenericUploadedFile()` y `saveAttachmentFromBinary()`.

**Simplificar:**

- `deleteGenericAttachment()`: eliminar el discriminador `str_starts_with($file_path, 'uploads/')` y borrar siempre `WWW_ROOT . $file_path` si existe.
- `getFullPath($attachment)`: devolver siempre `WWW_ROOT . $file_path`.
- `getWebUrl($attachment)`: devolver siempre `'/' . str_replace(DS, '/', $file_path)`.
- `getFileStream($attachment)`: `fopen(WWW_ROOT . $file_path, 'rb')` directo si el archivo existe; `null` en caso contrario.

Resultado: una sola ruta de código por método, sin condicional de backend.

### `src/Service/ProfileImageService.php`

**Eliminar:**

- Método `saveProfileImageToS3()` completo.

**Simplificar:**

- `saveProfileImage()`: quitar el bloque `if ($s3Service->isEnabled())` y llamar directamente a `saveProfileImageLocally()`.
- `deleteProfileImage()`: eliminar la rama S3. Si `!str_starts_with($filename, 'uploads/')` devolver `false` (registro inválido); en caso contrario, `unlink` local.
- `getProfileImageUrl()`: si `!str_starts_with($profileImage, 'uploads/')` devolver `/img/default-avatar.png`; el resto de la lógica local se conserva.

### `src/View/Helper/UserHelper.php`

- Eliminar `use App\Service\S3Service;`, la propiedad `$s3Service` y el método `getS3Service()`.
- Reemplazar la lógica que invocaba S3 por: si el path empieza por `uploads/` y el archivo existe en `WWW_ROOT`, devolver `'/' . $path`; si no, devolver `/img/default-avatar.png`.

### `composer.json`

- Eliminar `"aws/aws-sdk-php": "^3.369"` de `require`.
- Regenerar `composer.lock` con `composer update --lock` (o `composer install`).

### `config/app_local.example.php`

- Eliminar el bloque completo `'AWS' => ['S3' => [...]]` (aprox. líneas 104-123) y los comentarios introductorios.

### `docker-compose.yml`

- Eliminar las 5 variables `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`, `AWS_S3_BUCKET`, `AWS_S3_ENABLED` del bloque `environment:`.

### Documentación

- **`README.md`:** quitar menciones a S3 en introducción (línea 3), tabla de integraciones (línea 46), stack técnico (línea 57), tabla de variables de entorno (línea 120), estructura de carpetas (línea 163), sección de adjuntos (línea 186).
- **`CLAUDE.md`:** actualizar la convención de attachments (eliminar mención a `FileStorageInterface` y al toggle `AWS_S3_ENABLED`); reflejar que el almacenamiento es siempre local.

## Verificación

Sin suite automatizada — checklist manual:

1. `composer install` y `composer cs-check` pasan sin errores.
2. `bin/cake server` arranca sin warnings de clases faltantes.
3. `GET /health` responde 200.
4. **Adjuntos en tickets:**
   - Subir un archivo en un ticket nuevo → se guarda en `webroot/uploads/attachments/{ticket_number}/{uuid}.ext`.
   - Visualizar/descargar el adjunto → la URL `/uploads/...` resuelve.
   - Eliminar el adjunto → desaparece del disco y de la BD.
5. **Email-to-ticket:** disparar `POST /webhooks/gmail/import` con un correo que tenga adjunto → entra por `saveAttachmentFromBinary()` y se guarda local.
6. **Imagen de perfil:** subir nueva imagen → se guarda en `webroot/uploads/profile_images/` y se renderiza en sidebar/perfil.
7. **Grep final:** ninguna mención a `S3`, `AWS`, `aws-sdk`, ni `FileStorageInterface` en `src/`, `config/`, `docker-compose.yml`, `composer.json`.

## Riesgos

- **Registros legacy con `file_path` no-`uploads/`:** si existieran filas en BD apuntando a S3 keys (no debería haberlas), aparecerán como avatares por defecto / adjuntos no descargables. Mitigación: ejecutar antes de implementar `SELECT id, file_path FROM attachments WHERE file_path NOT LIKE 'uploads/%'` y `SELECT id, profile_image FROM users WHERE profile_image IS NOT NULL AND profile_image NOT LIKE 'uploads/%'`. Si devuelven filas, decidir caso por caso.
- **Vendors descargados en CI:** tras eliminar `aws/aws-sdk-php`, asegurar que el siguiente build de Docker no use cache de capa con la dependencia anterior (rebuild limpio).

## Fuera de alcance

- Introducir un nuevo backend de almacenamiento (S3 alternativo, MinIO, etc.).
- Refactorizar la validación de archivos o cambiar tamaños/MIME types permitidos.
- Migración o limpieza de archivos huérfanos en `webroot/uploads/`.
