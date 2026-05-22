# Diseño — Cierre de ítems informativos de la auditoría Gmail (I-1 + I-2 + P3 #10)

- **Fecha:** 2026-05-22
- **Auditoría de origen:** `docs/audits/2026-05-16-gmail-api-audit.md`
- **Tipo de trabajo:** 100% documental — sin código, sin migraciones, sin infraestructura.

---

## 1. Contexto

La auditoría de la Gmail API tiene los cuatro bloques de trabajo de código
cerrados:

| Bloque | Hallazgos | Estado |
|--------|-----------|--------|
| P0 | H-1, H-3, M-1 | Cerrado (2026-05-16) |
| P1 | H-2, M-3, B-4 | Cerrado (2026-05-16) |
| P2 | M-4, M-5, M-2 | Cerrado (2026-05-18) |
| P3 | B-2, B-3, I-3 + B-1 WONT_FIX | Cerrado (2026-05-19) |

Quedan tres ítems que **nunca entraron en un bloque P** y que no requieren
código:

- **I-2** — Documentar el requisito de que el proyecto OAuth en Google Cloud
  esté en `Publishing status: In production`. El archivo propuesto en §12.14
  del audit (`docs/operations/gmail-oauth-setup.md`) no existe.
- **I-1** — `google/apiclient` está en modo mantenimiento; falta una rutina de
  vigilancia.
- **P3 #10** — Evaluar `watch()` + Pub/Sub. El audit (§2.3) y el cierre de M-2
  ya establecieron que el polling delta cubre el volumen objetivo.

## 2. Objetivo

Dejar el documento `docs/audits/2026-05-16-gmail-api-audit.md` **100% cerrado**:
todos los hallazgos con estado resuelto y los marcadores de estado internos del
documento reconciliados.

## 3. Alcance

**Dentro de alcance:**

1. Crear `docs/operations/gmail-oauth-setup.md` (I-2 + I-1).
2. Añadir un cross-link recíproco en `docs/operations/n8n-gmail-webhook.md`.
3. Marcar P3 #10 como `DEFERRED` con criterios de reapertura.
4. Reconciliar los marcadores de estado del documento de auditoría
   (tabla §1, banners §6, lista §8) — ver §6 de esta spec.

**Fuera de alcance:**

- Cualquier cambio de código en `src/`.
- Los checklists operativos post-deploy del §11 del audit (consent screen en
  GCP, smoke tests). Son tareas de operaciones, no de este trabajo.
- Migración real a los SDKs per-service de Google (I-1 solo documenta la
  vigilancia; la migración es condicional y futura).

## 4. Entregable 1 — `docs/operations/gmail-oauth-setup.md` (nuevo)

Archivo nuevo. Cubre I-2 (secciones 1–4) e I-1 (sección 5). Contenido completo:

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

> Nota de implementación: el bloque de arriba es el contenido íntegro y
> definitivo del archivo — escribirlo **verbatim**. Las URIs de ejemplo van
> como bloques indentados de 4 espacios (no fenced): así se muestran aquí
> únicamente para evitar fences anidados dentro de esta spec, y los bloques
> indentados son Markdown válido y renderizan igual que un fence.

## 5. Entregable 2 — cross-link en `n8n-gmail-webhook.md`

Insertar, después del párrafo introductorio y antes de `## Pasos en n8n`, un
bloque de referencia:

```markdown
> **Ver también:** `gmail-oauth-setup.md` cubre los pre-requisitos de OAuth en
> Google Cloud Console (estado de publicación del proyecto, scope único,
> redirect URI) y la vigilancia de la dependencia `google/apiclient`.
```

Es el único cambio en ese archivo. No se toca el `TODO` pre-existente del
workflow exportado (§"Workflow exportado") — es deuda ajena a este trabajo.

## 6. Entregable 3 — actualización de `2026-05-16-gmail-api-audit.md`

### 6.1 Banners de cierre en §6 (Hallazgos Informativo)

Añadir un blockquote de cierre bajo cada heading, con el mismo formato que usan
el resto de hallazgos cerrados del documento.

**Bajo `### I-1 — google/apiclient en modo mantenimiento`:**

```markdown
> **Cerrado 2026-05-22 — documental.** Estado de la dependencia y cadencia de
> revisión trimestral (próxima: 2026-08-22) documentados en
> `docs/operations/gmail-oauth-setup.md` §5. Sin acción de código:
> `google/apiclient` sigue siendo el cliente oficial soportado. Disparadores de
> reapertura (migración a SDKs per-service): deprecación anunciada o CVE sin
> parche.
```

**Bajo `### I-2 — Proyecto OAuth en GCP debe estar Published / Production`:**

```markdown
> **Cerrado 2026-05-22 — documental.** Requisito de proyecto OAuth en
> `Publishing status: In production`, scope único `gmail.modify` y redirect URI
> documentados en el nuevo `docs/operations/gmail-oauth-setup.md`. Cross-link
> recíproco añadido desde `n8n-gmail-webhook.md`.
```

### 6.2 Marcador de P3 #10 en §8

Reemplazar la línea 10 del bloque `### P3 (evaluar valor)`:

- **Antes:** `10. watch() + Pub/Sub si crece volumen.`
- **Después:**

```markdown
10. `watch()` + Pub/Sub si crece volumen. — **DEFERRED 2026-05-22**. M-2 ya
    migró el polling a `history.list` con checkpoint delta (cuota proporcional
    al delta real, latencia ~60s) — suficiente para el volumen de un helpdesk
    pyme. `watch()` + Pub/Sub añade infraestructura (topic Pub/Sub, suscripción
    push, renovación diaria de la lease) sin beneficio proporcional al volumen
    actual. Reabrir si: se requiere latencia sub-60s, o el cap por corrida se
    satura de forma sostenida.
```

### 6.3 Reconciliación de marcadores desfasados (staleness pre-existente)

El documento de auditoría tiene marcadores de estado que quedaron desfasados
tras los cierres de P2 y P3 — no fueron tocados por este trabajo, pero
contradicen el objetivo de "audit 100% cerrado". Se reconcilian aquí porque
viven en el mismo documento que este trabajo cierra:

1. **Tabla resumen de §1.** Hoy muestra `Medio 3`, `Bajo 2`, `Informativo 3`
   como abiertos (snapshot de 2026-05-16, nunca actualizado). Reemplazar por el
   estado final:

   ```markdown
   | Severidad | Cantidad inicial | Estado actual (2026-05-22) |
   |-----------|------------------|----------------------------|
   | Alto      | 3 | 0 — todos cerrados |
   | Medio     | 5 | 0 — todos cerrados |
   | Bajo      | 4 | 0 — B-2/B-3/B-4 cerrados, B-1 WONT_FIX |
   | Informativo | 3 | 0 — I-1/I-2/I-3 cerrados |
   ```

2. **§8 P3 #12 y #13.** Cerrados en P3 (commits `a98bf4e`, `1f58e5d`) pero sin
   marcar en §8. Añadir el sufijo `— **Completado** (commit \`...\`)` a cada
   línea, igual que el resto de ítems P0–P2.

3. **§6 I-3.** Cerrado en P3 (commit `1f58e5d`) pero sin banner de cierre.
   Añadir, bajo `### I-3 — Logs incluyen to/subject`:

   ```markdown
   > **Cerrado 2026-05-19 — commit `1f58e5d`.** `LogMasker::email` aplicado en
   > los call sites de log `info`. Ver §11, entrada P3.
   ```

### 6.4 Nueva entrada en §11

Añadir al final de §11:

```markdown
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
```

## 7. Verificación

Al ser un cambio puramente documental:

- Revisar que los tres archivos renderizan correctamente como Markdown (tablas,
  blockquotes, bloques de código sin fences rotos).
- Confirmar que los cross-links entre `gmail-oauth-setup.md` y
  `n8n-gmail-webhook.md` apuntan a nombres de archivo existentes.
- Confirmar que no queda ningún hallazgo del audit (H/M/B/I) sin banner de
  cierre y que la tabla de §1 refleja `0` abiertos en las cuatro severidades.

No aplican `composer cs-check`, `phpstan` ni `composer test` — no se toca PHP.

## 8. Riesgos

- **Bajo.** No hay riesgo de regresión funcional: cero código.
- Único riesgo de proceso: la reconciliación de §6.3 toca staleness
  pre-existente del documento. Se documenta explícitamente en esta spec y en la
  entrada §11 para que el cambio sea trazable y no parezca "scope creep".

## 9. Resumen de archivos

| Archivo | Acción |
|---------|--------|
| `docs/operations/gmail-oauth-setup.md` | Crear |
| `docs/operations/n8n-gmail-webhook.md` | Editar — cross-link |
| `docs/audits/2026-05-16-gmail-api-audit.md` | Editar — banners §6, §8 #10/#12/#13, tabla §1, entrada §11 |
