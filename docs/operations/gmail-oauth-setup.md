# Gmail — Configuración de OAuth en Google Cloud

Pre-requisitos en Google Cloud Console para que la integración con Gmail
(ingestión vía webhook + envío de notificaciones transaccionales) funcione de
forma estable. Complementa a `n8n-gmail-webhook.md`, que cubre el disparador
del import; este documento cubre la capa de credenciales OAuth.

## 1. Estado de publicación del proyecto OAuth

El proyecto OAuth **debe estar en `Publishing status: In production`**.

- Google Cloud Console -> APIs & Services -> OAuth consent screen.
- Si el proyecto está en **`Testing`** con `User type: External`, **todos los
  refresh tokens caducan a los 7 días**. Pasado ese plazo el sistema deja de
  importar correos y de enviar notificaciones hasta una nueva autorización
  manual. Es la causa más común de una integración que "funcionaba y dejó de
  funcionar" sin cambios de código.
- Publicar el proyecto ("PUBLISH APP") elimina esa caducidad: los refresh
  tokens pasan a durar hasta que se revoquen o expiren por inactividad
  (>6 meses sin uso).

## 2. Scope solicitado

La aplicación solicita **un único scope sensible**:

    https://www.googleapis.com/auth/gmail.modify

`gmail.modify` cubre los tres usos del sistema: leer mensajes, marcarlos como
leídos y enviar respuestas transaccionales. **No** se solicitan
`gmail.readonly` ni `gmail.send` (son redundantes — ver hallazgo H-1 de
`docs/audits/2026-05-16-gmail-api-audit.md`).

- En el OAuth consent screen, la lista de scopes sensibles declarados debe
  contener solo `gmail.modify`. Si históricamente se declararon los tres,
  eliminar `gmail.readonly` y `gmail.send`.
- Un único scope reduce la superficie ante un refresh token comprometido y
  simplifica la verificación de Google.

## 3. Redirect URI autorizado

En las credenciales OAuth (tipo `Web application`), el redirect URI autorizado
debe coincidir exactamente con el callback de la app:

    https://<dominio>/oauth/gmail/callback

(`<dominio>` = el host de `FULL_BASE_URL`.)

## 4. Autorización y re-autorización

- La autorización se completa desde `/admin/settings/gmailAuth`.
- `initializeClient` solicita `prompt=consent`, por lo que cualquier cambio de
  scope o de cuenta fuerza una pantalla de consentimiento nueva.
- Tras autorizar, el `refresh_token` se persiste cifrado en `system_settings`
  y el `GMAIL_USER_EMAIL` se auto-puebla vía `users.getProfile` (hallazgo B-4).

## 5. Mantenimiento de la dependencia `google/apiclient`

`google/apiclient` está en **modo mantenimiento**: su equipo solo publica
correcciones de bugs críticos y parches de seguridad, sin funcionalidades
nuevas. Sigue siendo el cliente PHP **oficial y soportado** para la Gmail API,
por lo que no hay acción inmediata (hallazgo I-1).

**Cadencia de revisión — trimestral.** Cada tres meses, revisar:

- El `SECURITY.md` y la pestaña Releases de
  https://github.com/googleapis/google-api-php-client
- Avisos de deprecación o CVEs abiertos contra la versión fijada en
  `composer.lock`.

**Próxima revisión: 2026-08-22.**

Reabrir como tarea de migración (a los SDKs per-service
`googleapis/google-api-php-client-services`) solo si se materializa uno de
estos disparadores:

- Google anuncia una fecha de deprecación de `google/apiclient`.
- Aparece un CVE sin parche disponible.

La capa `GmailService` aísla el resto del sistema del SDK, por lo que una
migración futura quedaría contenida en esa clase.
