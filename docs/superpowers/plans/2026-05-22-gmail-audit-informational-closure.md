# Cierre de ítems informativos de la auditoría Gmail — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar el documento `docs/audits/2026-05-16-gmail-api-audit.md` 100% cerrado, atendiendo los tres ítems que quedaron fuera de los bloques P0–P3 (I-1, I-2, P3 #10).

**Architecture:** Trabajo puramente documental. Se crea una guía operativa nueva (`gmail-oauth-setup.md`), se enlaza recíprocamente con la guía del webhook n8n, y se reconcilian los marcadores de estado del documento de auditoría (tabla resumen, banners de cierre, lista de plan de acción). Cero código PHP, cero migraciones, cero infraestructura.

**Tech Stack:** Markdown. Sin toolchain — no aplican `composer cs-check`, `phpstan` ni `composer test`.

**Spec:** `docs/superpowers/specs/2026-05-22-gmail-audit-informational-closure-design.md`

**Pre-requisito de ejecución:** trabajar en una rama dedicada (p. ej. `docs/gmail-audit-informational-closure`) — el repositorio está en `main`. Si se ejecuta vía worktree, la rama la crea `superpowers:using-git-worktrees`.

---

## Task 1: Crear la guía de configuración OAuth de Gmail

Cubre el hallazgo I-2 (secciones 1–4) y el I-1 (sección 5) de la auditoría.

**Files:**
- Create: `docs/operations/gmail-oauth-setup.md`
- Commit también (ya existen, sin commitear): `docs/superpowers/specs/2026-05-22-gmail-audit-informational-closure-design.md`, `docs/superpowers/plans/2026-05-22-gmail-audit-informational-closure.md`

- [ ] **Step 1: Crear `docs/operations/gmail-oauth-setup.md` con este contenido exacto (verbatim)**

```markdown
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
```

- [ ] **Step 2: Verificar que el archivo existe y tiene las secciones esperadas**

Run: `grep -nE '^## [0-9]\.' docs/operations/gmail-oauth-setup.md`
Expected: cinco líneas — `## 1.`, `## 2.`, `## 3.`, `## 4.`, `## 5.`

Abrir el archivo en un previsualizador de Markdown y confirmar que las dos URIs (`https://www.googleapis.com/auth/gmail.modify` y `https://<dominio>/oauth/gmail/callback`) renderizan como bloques de código indentados, no como texto plano.

- [ ] **Step 3: Commit**

```bash
git add docs/operations/gmail-oauth-setup.md \
        docs/superpowers/specs/2026-05-22-gmail-audit-informational-closure-design.md \
        docs/superpowers/plans/2026-05-22-gmail-audit-informational-closure.md
git commit -m "docs(gmail): add OAuth setup guide and dependency maintenance note"
```

---

## Task 2: Cross-link desde la guía del webhook n8n

**Files:**
- Modify: `docs/operations/n8n-gmail-webhook.md` (entre el párrafo introductorio y `## Pasos en n8n`)

- [ ] **Step 1: Insertar el bloque de referencia**

Buscar este texto exacto:

```markdown
`POST /webhooks/gmail/import`.

## Pasos en n8n
```

Reemplazarlo por:

```markdown
`POST /webhooks/gmail/import`.

> **Ver también:** `gmail-oauth-setup.md` cubre los pre-requisitos de OAuth en
> Google Cloud Console (estado de publicación del proyecto, scope único,
> redirect URI) y la vigilancia de la dependencia `google/apiclient`.

## Pasos en n8n
```

- [ ] **Step 2: Verificar la inserción**

Run: `grep -n 'gmail-oauth-setup.md' docs/operations/n8n-gmail-webhook.md`
Expected: una línea, dentro del blockquote `> **Ver también:**`.

- [ ] **Step 3: Commit**

```bash
git add docs/operations/n8n-gmail-webhook.md
git commit -m "docs(gmail): cross-link OAuth setup guide from n8n webhook doc"
```

---

## Task 3: Cerrar y reconciliar el documento de auditoría

Marca I-1, I-2 y P3 #10 como cerrados, y reconcilia los marcadores de estado
que P2/P3 dejaron desfasados (tabla §1, §8 #12/#13, banner §6 I-3).

**Files:**
- Modify: `docs/audits/2026-05-16-gmail-api-audit.md` (§1, §6, §8, §11)

- [ ] **Step 1: Actualizar la tabla resumen de §1**

Buscar este bloque exacto:

```markdown
| Severidad | Cantidad inicial | Estado actual (2026-05-16) |
|-----------|------------------|----------------------------|
| Alto      | 3 | 0 (H-1, H-2, H-3 cerrados) |
| Medio     | 5 | 3 (M-1, M-3 cerrados) |
| Bajo      | 4 | 2 (B-1 WONT_FIX, B-4 cerrado) |
| Informativo | 3 | 3 |
```

Reemplazarlo por:

```markdown
| Severidad | Cantidad inicial | Estado actual (2026-05-22) |
|-----------|------------------|----------------------------|
| Alto      | 3 | 0 — todos cerrados |
| Medio     | 5 | 0 — todos cerrados |
| Bajo      | 4 | 0 — B-2/B-3/B-4 cerrados, B-1 WONT_FIX |
| Informativo | 3 | 0 — I-1/I-2/I-3 cerrados |
```

- [ ] **Step 2: Añadir el banner de cierre de I-1 en §6**

Buscar este texto exacto:

```markdown
### I-1 — `google/apiclient` en modo mantenimiento

El repositorio oficial declara
```

Reemplazarlo por:

```markdown
### I-1 — `google/apiclient` en modo mantenimiento

> **Cerrado 2026-05-22 — documental.** Estado de la dependencia y cadencia de
> revisión trimestral (próxima: 2026-08-22) documentados en
> `docs/operations/gmail-oauth-setup.md` §5. Sin acción de código:
> `google/apiclient` sigue siendo el cliente oficial soportado. Disparadores de
> reapertura (migración a SDKs per-service): deprecación anunciada o CVE sin
> parche.

El repositorio oficial declara
```

- [ ] **Step 3: Añadir el banner de cierre de I-2 en §6**

Buscar este texto exacto:

```markdown
### I-2 — Proyecto OAuth en GCP debe estar **Published / Production**

Si el proyecto OAuth está en
```

Reemplazarlo por:

```markdown
### I-2 — Proyecto OAuth en GCP debe estar **Published / Production**

> **Cerrado 2026-05-22 — documental.** Requisito de proyecto OAuth en
> `Publishing status: In production`, scope único `gmail.modify` y redirect URI
> documentados en el nuevo `docs/operations/gmail-oauth-setup.md`. Cross-link
> recíproco añadido desde `n8n-gmail-webhook.md`.

Si el proyecto OAuth está en
```

- [ ] **Step 4: Añadir el banner de cierre de I-3 en §6 (reconciliación)**

I-3 se cerró en P3 (commit `1f58e5d`) pero quedó sin banner. Buscar este texto
exacto:

```markdown
### I-3 — Logs incluyen `to`/`subject`

**Archivos:** `EmailService.php:117-128`
```

Reemplazarlo por:

```markdown
### I-3 — Logs incluyen `to`/`subject`

> **Cerrado 2026-05-19 — commit `1f58e5d`.** `LogMasker::email` aplicado en los
> call sites de log `info`. Ver §11, entrada P3.

**Archivos:** `EmailService.php:117-128`
```

- [ ] **Step 5: Marcar P3 #10 como DEFERRED en §8**

Buscar esta línea exacta:

```markdown
10. `watch()` + Pub/Sub si crece volumen.
```

Reemplazarla por:

```markdown
10. `watch()` + Pub/Sub si crece volumen. — **DEFERRED 2026-05-22**. M-2 ya
    migró el polling a `history.list` con checkpoint delta (cuota proporcional
    al delta real, latencia ~60s) — suficiente para el volumen de un helpdesk
    pyme. `watch()` + Pub/Sub añade infraestructura (topic Pub/Sub, suscripción
    push, renovación diaria de la lease) sin beneficio proporcional al volumen
    actual. Reabrir si: se requiere latencia sub-60s, o el cap por corrida se
    satura de forma sostenida.
```

- [ ] **Step 6: Marcar P3 #12 como completado en §8 (reconciliación)**

Buscar esta línea exacta:

```markdown
12. `B-2` selección correcta de rama en `multipart/alternative`.
```

Reemplazarla por:

```markdown
12. `B-2` selección correcta de rama en `multipart/alternative`. — **Completado** (commit `a98bf4e`).
```

- [ ] **Step 7: Marcar P3 #13 como completado en §8 (reconciliación)**

Buscar esta línea exacta:

```markdown
13. `I-3` enmascarar PII en logs `info`.
```

Reemplazarla por:

```markdown
13. `I-3` enmascarar PII en logs `info`. — **Completado** (commit `1f58e5d`).
```

- [ ] **Step 8: Añadir la entrada de bitácora en §11**

Buscar este texto exacto (final de la entrada P3 de §11):

```markdown
3. Smoke de `multipart/alternative`: reenviar un email forwardeado de Gmail con cuerpo HTML y verificar que el comentario o ticket persistido no muestre el cuerpo duplicado.

---
```

Reemplazarlo por:

```markdown
3. Smoke de `multipart/alternative`: reenviar un email forwardeado de Gmail con cuerpo HTML y verificar que el comentario o ticket persistido no muestre el cuerpo duplicado.

### 2026-05-22 — Cierre de ítems informativos (I-1 + I-2 + P3 #10)

**Hallazgos cubiertos:** los tres ítems que quedaron fuera de los bloques
P0–P3. Cierre 100% documental — sin código, sin migraciones. Sigue el plan en
`docs/superpowers/plans/2026-05-22-gmail-audit-informational-closure.md` y la
spec `docs/superpowers/specs/2026-05-22-gmail-audit-informational-closure-design.md`.

| Ítem | Resumen |
|---|---|
| I-2 | Nuevo `docs/operations/gmail-oauth-setup.md`: requisito de proyecto OAuth en `In production` (caducidad de 7 días en Testing/External), scope único `gmail.modify`, redirect URI, flujo de re-consentimiento. Cross-link recíproco con `n8n-gmail-webhook.md`. |
| I-1 | Sección 5 de `gmail-oauth-setup.md`: `google/apiclient` en modo mantenimiento documentado, cadencia de revisión trimestral (próxima 2026-08-22), disparadores de migración. |
| P3 #10 | `watch()` + Pub/Sub marcado **DEFERRED**: el polling delta de M-2 cubre el volumen pyme; criterios de reapertura documentados en §8. |

**Reconciliación de estado del documento:** además de los tres ítems, se
actualizó la tabla de §1 y los marcadores de §8 (#12, #13) y §6 (banner de
I-3) que habían quedado desfasados tras los cierres de P2/P3. El documento de
auditoría queda 100% cerrado.

**Verificación:** revisión manual de Markdown (los tres archivos renderizados).
Sin cambios de código — `composer cs-check` / `phpstan` / `composer test` no
aplican.

---
```

- [ ] **Step 9: Verificar la reconciliación**

Run: `grep -nE 'I-1|I-2|I-3' docs/audits/2026-05-16-gmail-api-audit.md | grep -i cerrado`
Expected: al menos tres líneas, una de banner por cada hallazgo informativo (I-1 2026-05-22, I-2 2026-05-22, I-3 2026-05-19).

Run: `grep -n 'Estado actual (2026-05-22)' docs/audits/2026-05-16-gmail-api-audit.md`
Expected: una línea (la cabecera de la tabla de §1).

Run: `grep -n 'DEFERRED 2026-05-22' docs/audits/2026-05-16-gmail-api-audit.md`
Expected: una línea (P3 #10 en §8).

Abrir el documento en un previsualizador de Markdown y confirmar que la tabla de §1, la nueva entrada de §11 y los banners de §6 renderizan correctamente.

- [ ] **Step 10: Commit**

```bash
git add docs/audits/2026-05-16-gmail-api-audit.md
git commit -m "docs(audit): close Gmail audit informational items I-1, I-2, P3 #10"
```

---

## Verificación final

Tras los tres commits:

- `git log --oneline -3` muestra los tres commits `docs(...)`.
- Ningún hallazgo del audit (H/M/B/I) queda sin banner de cierre o marcador de estado.
- Los cross-links entre `gmail-oauth-setup.md` y `n8n-gmail-webhook.md` apuntan a nombres de archivo existentes en `docs/operations/`.
- La tabla de §1 reporta `0` abiertos en las cuatro severidades.

No aplican `composer cs-check`, `phpstan` ni `composer test` — el cambio no toca PHP.
