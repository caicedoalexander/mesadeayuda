# Gmail client_secret en base de datos (cifrado)

**Fecha:** 2026-05-06
**Estado:** Validado, listo para implementar
**Contexto:** Hoy el admin sube `client_secret.json` via `ConfigFilesController` y el archivo vive en `config/google/client_secret.json`. La ruta queda guardada en `system_settings.gmail_client_secret_path`. Queremos eliminar el archivo en disco y guardar el JSON cifrado en BD, igual que `gmail_refresh_token`, `whatsapp_api_key`, `n8n_api_key` y `webhook_gmail_import_token`.

## Motivación

- Eliminar el paso manual de subir un archivo cuando se despliega o reconfigura.
- Consolidar todos los secretos en `system_settings` con cifrado AES (`Security::encrypt` + `SECURITY_SALT`).
- Cero archivos sensibles en el filesystem del contenedor.

## Modelo de datos

**Nueva clave en `system_settings`:**

| setting_key | setting_value (almacenado) | cifrado |
|---|---|---|
| `gmail_client_secret_json` | `{encrypted}<base64-AES>` (JSON crudo de Google Cloud) | sí |

**Clave eliminada:** `gmail_client_secret_path`. La migration la borra de la tabla.

**Por qué guardar el JSON crudo y no campos sueltos:**
`Google\Client::setAuthConfig()` acepta tanto un path como un array. Pasar el resultado de `json_decode($json, true)` evita mapear campos manualmente y nos protege de cambios futuros en el formato del JSON de Google. Validamos en escritura que existan `client_id`, `client_secret` y `redirect_uris` bajo la raíz `web` o `installed`.

## Cambios en código

### 1. `src/Utility/SettingKeys.php`
- Agregar `GMAIL_CLIENT_SECRET_JSON = 'gmail_client_secret_json'`.
- Eliminar `GMAIL_CLIENT_SECRET_PATH` (la BD ya no la contiene tras la migration).

### 2. `src/Utility/SettingsEncryptionTrait.php`
- Agregar `SettingKeys::GMAIL_CLIENT_SECRET_JSON` al array `$encryptedSettings`.

### 3. `src/Service/GmailService.php`

`loadConfigFromDatabase()` lee ahora `gmail_client_secret_json` y `gmail_refresh_token`, y devuelve:

```php
['client_secret' => array, 'refresh_token' => string]
```

`initializeClient()`:

```php
if (!empty($this->config['client_secret']) && is_array($this->config['client_secret'])) {
    $this->client->setAuthConfig($this->config['client_secret']);
} else {
    Log::error('Gmail client_secret not configured');
}
```

### 4. `src/Service/EmailService.php` (`getGmailService`)
Reemplazar el bloque que lee `client_secret_path` por la decodificación del JSON cifrado leído via `getSettingValue(SettingKeys::GMAIL_CLIENT_SECRET_JSON)` + `decryptSetting`.

### 5. `src/Controller/AppController.php`
En el array `$sensitiveKeys`, sustituir `GMAIL_CLIENT_SECRET_PATH` por `GMAIL_CLIENT_SECRET_JSON`.

### 6. `src/Controller/Admin/SettingsController.php`
- Nuevo action `gmailClientSecret()`: acepta POST con `client_secret_json`, valida JSON + estructura, guarda con `SettingsService::saveSetting()` (cifra automáticamente).
- `gmailAuth()` y `testGmail()`: leer `gmail_client_secret_json` (decodificar) en lugar del path.
- Agregar `gmailClientSecret` a `unlockedActions` del FormProtection.

### 7. `templates/Admin/Settings/index.php`
Reemplazar el bloque "Archivo de Configuración de Gmail" (líneas 505–577) por un formulario con textarea que apunta a `/admin/settings/gmail-client-secret`. Indicador de estado leído de `$settings[SettingKeys::GMAIL_CLIENT_SECRET_JSON]` (presente / ausente — nunca mostrar el contenido).

### 8. Eliminación
- Borrar `src/Controller/Admin/ConfigFilesController.php`. Las rutas `/admin/config-files/*` se resuelven por `fallbacks()`; al desaparecer el controller devuelven 404 automáticamente.
- Limpiar mención en `CLAUDE.md`.

### 9. Migration: `MigrateGmailClientSecretToDatabase`

```php
public function up(): void
{
    // Buscar path actual
    $row = $this->fetchRow(
        "SELECT setting_value FROM system_settings WHERE setting_key = 'gmail_client_secret_path' LIMIT 1"
    );

    if ($row && !empty($row['setting_value'])) {
        $path = $row['setting_value'];
        if (file_exists($path) && is_readable($path)) {
            $json = file_get_contents($path);
            if (json_decode($json, true) !== null) {
                $encrypted = '{encrypted}' . base64_encode(Security::encrypt($json, Security::getSalt()));
                $now = date('Y-m-d H:i:s');
                $this->table('system_settings')->insert([[
                    'setting_key' => 'gmail_client_secret_json',
                    'setting_value' => $encrypted,
                    'setting_type' => 'string',
                    'description' => 'Gmail OAuth client secret (JSON cifrado)',
                    'created' => $now,
                    'modified' => $now,
                ]])->save();
                @unlink($path);
            }
        }
    }

    $this->execute("DELETE FROM system_settings WHERE setting_key = 'gmail_client_secret_path'");
}
```

**Idempotencia:** si la migration corre dos veces, el `SELECT` no encuentra el path en la segunda → no hace nada.

## Plan de verificación manual

1. Pre-deploy: `bin/cake import_gmail --max 1` funciona, archivo existe en disco.
2. `bin/cake migrations migrate`:
   - Fila `gmail_client_secret_json` con prefijo `{encrypted}` en BD.
   - Fila `gmail_client_secret_path` eliminada.
   - Archivo `config/google/client_secret.json` borrado.
3. `bin/cake import_gmail --max 1` post-deploy: funciona igual.
4. `POST /webhooks/gmail/import` con token: importa tickets.
5. UI nueva en `/admin/settings`:
   - Pegar JSON válido → mensaje verde, fila actualizada.
   - Pegar JSON inválido → mensaje rojo.
   - Pegar JSON sin `client_id` → mensaje rojo específico.
6. Instalación nueva (BD limpia): pegar JSON, autorizar OAuth → flujo completo.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Archivo borrado/corrupto pre-migration | Migration loggea warning y sigue; admin re-pega manualmente. |
| `Security.salt` rotado | Mismo riesgo que ya existe con `gmail_refresh_token`. |
| Cache stale | `SettingsService::clearAllCaches()` se invoca en cada save y limpia `gmail_settings`, `system_settings`, etc. La migration limpia explícitamente al final. |

## Fuera de alcance

- Rotación automática del `Security.salt`.
- UI para descargar el JSON guardado (no hace falta — el admin tiene la copia original).
- Action de delete (basta con re-pegar otro JSON o vaciar desde BD).
