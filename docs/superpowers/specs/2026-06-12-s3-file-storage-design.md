# Diseño: Almacenamiento de archivos en AWS S3

**Fecha:** 2026-06-12
**Estado:** Aprobado (pendiente plan de implementación)

## Contexto y motivación

El estándar de despliegue del proyecto exige separar el VPS de aplicación del
servidor de almacenamiento: en producción el VPS no debe guardar archivos de
usuario. Hoy todos los adjuntos viven en `webroot/uploads/attachments/{ticket_id}/`
(volumen Docker) y las fotos de perfil en el filesystem local. Este diseño mueve
**todo** el almacenamiento de archivos de usuario a AWS S3.

El proyecto aún está en desarrollo: no hay migración de archivos legacy.

## Decisiones tomadas

| Decisión | Valor |
|---|---|
| Proveedor | AWS S3 real (bucket privado, Block Public Access activado) |
| Credenciales | `config/.env` / `app_local.php` (capa file-based, NO `system_settings`) |
| Alcance | Adjuntos de tickets (formulario, Gmail, inline, WhatsApp) + fotos de perfil |
| Servido | Redirect 302 a URL presignada (exp. 15 min), tras verificación de permisos |
| Entorno dev | Bucket de desarrollo en AWS — un solo camino de código, sin driver local |
| Integración | `S3StorageService` propio sobre `aws/aws-sdk-php` (sin Flysystem, sin interfaz multi-driver) |
| Migración legacy | Fuera de alcance — archivos existentes se re-suben o descartan |

## Arquitectura

Un único servicio nuevo, `S3StorageService`, encapsula toda la comunicación con
S3. Nada fuera de él conoce el SDK de AWS. Los consumidores actuales del
filesystem (`GenericAttachmentTrait`, `ProfileImageService`) delegan en él.
La columna `attachments.file_path` pasa a guardar la **clave S3** en lugar de la
ruta relativa local.

```
Navegador ── GET /attachments/view/{id} ──► Controller (verifica acceso al ticket)
                                               │ 302
                                               ▼
                                        URL presignada S3 (exp. 15 min)

Upload/Gmail/WhatsApp ──► GenericAttachmentTrait ──► S3StorageService::put()
                              (validación intacta)        │
                                                          ▼
                                                  s3://bucket/attachments/{ticket_id}/{uuid}.ext
```

### Configuración

Variables en `config/.env` → leídas en `config/app.php` bajo la clave `S3`:

- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_REGION`
- `S3_BUCKET`

Se actualizan `config/app_local.example.php` y el README. El cliente `S3Client`
se construye lazy (mismo criterio que `TicketServiceInitializerTrait`).

### Estructura de claves S3

- Adjuntos de tickets: `attachments/{ticket_id}/{uuid}.{ext}`
- Fotos de perfil: `profile_images/{user_id}/{uuid}.{ext}`

## Componentes

### `src/Service/S3StorageService.php` (nuevo)

Cuatro operaciones, nada más:

- `put(string $key, string|resource $body, string $mimeType): bool`
- `delete(string $key): bool`
- `presignedUrl(string $key, string $downloadFilename, bool $inline): string` —
  expiración fija de 15 minutos (constante de clase). `$downloadFilename` y
  `$inline` controlan `response-content-disposition` para que el navegador
  muestre el nombre original del archivo.
- `getStream(string $key)` — para consumidores que necesitan los bytes en el
  servidor (ej. adjuntar archivos a correos salientes desde `EmailService`).

Se inyecta en los servicios consumidores (constructor opcional, instanciación
lazy por defecto) para poder mockearlo en tests.

### `GenericAttachmentTrait` (modificado)

Cambios quirúrgicos; la validación de seguridad queda **intacta** (allowlist de
extensiones/MIME, sniff con finfo, sanitización de nombre). El sniff de MIME
ocurre sobre el archivo temporal o el binario **antes** de subir, igual que hoy.

- `mkdir` + `moveTo` / `file_put_contents` → `S3StorageService::put()` con clave
  `attachments/{ticket_id}/{uuid}.ext`.
- `getWebUrl()` → devuelve la ruta estable de la app (`/attachments/view/{id}`),
  nunca una URL presignada. Las imágenes inline van incrustadas en HTML guardado
  en BD y no pueden expirar.
- `getFullPath()` se elimina; `getFileStream()` delega en
  `S3StorageService::getStream()`.
- `deleteGenericAttachment()` borra el objeto S3 en lugar de `unlink`.

### Ruta y acción de servido (nueva)

`GET /attachments/view/{id}` (con `?download=1` para forzar descarga):

1. Carga el attachment.
2. Verifica que el usuario autenticado tenga acceso al ticket — misma regla de
   autorización que la vista de detalle del ticket.
3. Responde 302 a la URL presignada.

Las imágenes inline usan esta misma ruta; el navegador envía la cookie de
sesión, así que la autorización funciona igual que en cualquier vista.

### `ProfileImageService` (modificado)

Mismo patrón: clave `profile_images/{user_id}/{uuid}.ext`, servido vía
`GET /profile-images/view/{userId}` + redirect 302 (siempre disposición inline).
Autorización: cualquier usuario autenticado.

## Flujo de datos (cambios respecto a hoy)

1. **Upload formulario**: validar → sniff MIME del temporal → `put()` a S3 →
   guardar entidad con `file_path` = clave S3. Si el `save()` de BD falla, se
   borra el objeto S3 (compensación; reemplaza al `@unlink` actual).
2. **Gmail/WhatsApp binario**: idéntico, con `verifyMimeTypeFromBinary` antes
   de subir.
3. **Imágenes inline**: el mapa `content_id => URL` que reescribe el cuerpo del
   correo apunta a `/attachments/view/{id}` — URL estable, sin expiración. El
   sanitizador (`HtmlSanitizerTrait`) no necesita cambios: sigue siendo una URL
   relativa.
4. **Correo saliente con adjuntos**: `EmailService` obtiene los bytes con
   `getStream()` en vez de leer del disco.

## Manejo de errores

Mismo contrato que hoy: fallos de S3 se loguean con contexto (`Log::error`) y el
método devuelve `null`/`false`; nunca se propaga excepción al caller. Sin
reintentos propios — el SDK de AWS ya reintenta errores transitorios. Si S3 está
caído, el upload falla limpiamente y el ticket se crea sin adjunto
(comportamiento equivalente al actual ante fallo de disco).

## Testing

- `S3StorageService` mockeado en tests de servicios/trait — siguen siendo unit
  tests puros, sin red.
- Tests actualizados: guardado (clave correcta, compensación si BD falla),
  borrado, `getWebUrl` estable, validación de seguridad sin regresiones.
- Tests nuevos: autorización de la ruta de servido (usuario sin acceso al
  ticket → 403/404), generación de presignada con
  `response-content-disposition`.

## Fuera de alcance / tareas de cierre

- **Sin migración de archivos legacy**: los archivos existentes en
  `webroot/uploads/` no se migran (re-subir o descartar manualmente).
- Al final de la implementación: eliminar el volumen
  `webroot/uploads/attachments` de `docker-compose.yml` y el directorio del
  repo.
- La creación del bucket y su política IAM (usuario con permisos mínimos
  `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` sobre el bucket) es tarea de
  infraestructura; este diseño asume que el bucket existe.
