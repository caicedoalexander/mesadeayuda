# HIGH-1 — Frontera transaccional en `TicketPipelineService::handleResponse()`

- **Fecha:** 2026-05-15
- **Hallazgo de origen:** `docs/audits/2026-05-14-tickets-module-audit.md` §4 HIGH-1
- **Archivo principal:** `src/Service/TicketPipelineService.php` (líneas 73-159)
- **Estado pre-fix:** Operación compuesta (comentario + attachments + status + notificaciones) sin `Connection::transactional(...)`. Si la subida de adjuntos falla a medio camino, el comentario queda persistido y la notificación dispara con estado parcial.

---

## 1. Objetivo

Introducir frontera transaccional en `handleResponse()` y diferir el dispatch de eventos de dominio hasta post-commit, **preservando la semántica actual** de "comentario sobrevive si la transición de estado falla" (decisión de producto codificada en el catch de `InvalidStatusTransitionException`).

Esta intervención cierra HIGH-1 estricto. **No cierra CRIT-3** (Outbox): reduce la ventana entre `save()` y `dispatch()` a casi nula pero no garantiza at-least-once si el proceso muere entre commit y dispatch.

---

## 2. Arquitectura

Reestructurar `handleResponse()` en **dos transacciones explícitas + buffer de eventos diferidos**.

```
handleResponse()
├── TX1: comment + uploads        (atomic; rollback → unlink files)
├── TX2: status change             (atomic; failure → semántica actual preservada)
├── Post-commit: dispatch events   (buffer flush)
└── sendResponseNotifications      (fuera de TX, como hoy)
```

**Justificación de dos TX separadas (vs. una única):** el catch de `InvalidStatusTransitionException` en líneas 132-153 conserva el comentario a propósito para evitar que el usuario re-postee. Una sola TX rompe esto.

---

## 3. Componentes a tocar

### 3.1 `TicketPipelineService::handleResponse()`

- Obtener `Connection` vía `$this->fetchTable('Tickets')->getConnection()`.
- Variable local `array $writtenFilePaths = []` para tracking de archivos.
- Variable local `array $pendingEvents = []` para dispatch diferido.
- TX1 envuelve: `addComment()` + loop de `saveUploadedFile()`. Retorno `false` desde el callback → rollback. Envolver en `try { } finally { if (!$tx1Ok) cleanup(); }` para cubrir excepciones inesperadas.
- TX2 envuelve: llamada a `changeStatus(..., deferDispatch: true)` + push del evento a `$pendingEvents`.
- Post-TX2: foreach dispatch.
- Método privado nuevo `cleanupOrphanedFiles(array $paths): void` con `@unlink` + log warning best-effort.

### 3.2 `TicketPipelineService::changeStatus()` — nuevo parámetro `bool $deferDispatch`

- Firma: añadir `bool $deferDispatch = false` como último parámetro (default preserva comportamiento actual).
- Cuando `true` y `$sendNotifications` también `true`: no invocar `$this->eventManager->dispatch(...)`. El caller asume la responsabilidad.
- `changeStatus` sigue siendo el dueño del evento; solo pospone el dispatch. No expone el evento al caller.

### 3.3 `TicketAttachmentService::saveUploadedFile()` — verificar campo de path

- Confirmar nombre exacto del campo en la entidad `Attachment` (probable `file_path`). No se cambia firma; sólo se documenta el accessor usado por `cleanupOrphanedFiles`.

---

## 4. Flujo de datos

```php
public function handleResponse(int $entityId, int $userId, array $data, array $files): array
{
    // ... parsing y validaciones existentes (líneas 75-103) sin cambios ...

    $connection = $this->fetchTable('Tickets')->getConnection();
    $writtenFilePaths = [];
    $pendingEvents = [];
    $comment = null;
    $uploadedCount = 0;

    // TX1: comment + uploads
    if ($hasComment) {
        $tx1Ok = false;
        try {
            $tx1Ok = $connection->transactional(function () use (
                $entityId, $userId, $commentBody, $commentType, $emailTo, $emailCc,
                $files, $entity, &$comment, &$uploadedCount, &$writtenFilePaths
            ) {
                $comment = $this->comments->addComment($entityId, $userId, $commentBody, $commentType, false, $emailTo, $emailCc);
                if (!$comment) {
                    return false; // rollback
                }
                foreach ($files['attachments'] ?? [] as $file) {
                    if ($file->getError() !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $att = $this->attachments->saveUploadedFile($entity, $file, $comment->id, $userId);
                    if ($att) {
                        $writtenFilePaths[] = $att->file_path;
                        $uploadedCount++;
                    }
                }
                return true;
            });
        } finally {
            if ($tx1Ok !== true) {
                $this->cleanupOrphanedFiles($writtenFilePaths);
            }
        }

        if ($tx1Ok !== true) {
            return ['success' => false, 'message' => 'Error al agregar el comentario.', 'entity' => $entity];
        }
    }

    // TX2: status change (sólo si aplica)
    if ($hasStatusChange) {
        try {
            $connection->transactional(function () use ($entity, $newStatus, $userId, $oldStatus, &$pendingEvents) {
                $ok = $this->changeStatus($entity, $newStatus, $userId, null, true, deferDispatch: true);
                if (!$ok) {
                    return false; // rollback; pendingEvents intacto
                }
                $pendingEvents[] = new TicketStatusChanged(
                    ticketId: (int)$entity->id,
                    oldStatus: $oldStatus,
                    newStatus: $newStatus,
                    actorId: $userId,
                );
                return true;
            });
        } catch (InvalidStatusTransitionException $e) {
            Log::warning('Response committed but status transition rejected', [
                'ticket_id' => $entityId,
                'from' => $oldStatus,
                'to' => $newStatus,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => sprintf('Comentario guardado, pero no se pudo cambiar el estado: %s', $e->getMessage()),
                'entity' => $entity,
            ];
        }
    }

    // Post-commit dispatch
    foreach ($pendingEvents as $event) {
        $this->eventManager->dispatch($event);
    }

    $this->notifications->sendResponseNotifications(/* ... */);
    return $this->buildResponseResult($hasComment, $hasStatusChange, $uploadedCount, $entity);
}

private function cleanupOrphanedFiles(array $paths): void
{
    foreach ($paths as $path) {
        if (@unlink($path) === false) {
            Log::warning('Failed to cleanup orphaned attachment after TX rollback', ['path' => $path]);
        }
    }
}
```

---

## 5. Manejo de errores

| Escenario | Comportamiento |
|---|---|
| `addComment` retorna null | TX1 callback retorna `false` → rollback; `finally` invoca cleanup → retorno `success=false` "Error al agregar el comentario" |
| Excepción inesperada en TX1 | `transactional` propaga; `finally` invoca cleanup; excepción sube al caller HTTP |
| `changeStatus` lanza `InvalidStatusTransitionException` | TX2 rollback automático; catch preserva semántica actual; comentario ya commited; retorno "Comentario guardado, pero..." |
| `changeStatus` retorna `false` (save falló) | Callback retorna `false` → rollback; `pendingEvents` intacto (vacío para esa rama); ningún evento dispatched |
| `unlink` falla | Log warning, no propagar |

---

## 6. Testing

Tests nuevos en `tests/TestCase/Service/TicketPipelineServiceTest.php` (crear si no existe).

| Test | Setup | Assert |
|---|---|---|
| `testHandleResponseRollsBackUploadsWhenCommentFails` | Mock `addComment` → null | No se llama `saveUploadedFile`; retorno `success=false` |
| `testHandleResponseCleansUpOrphanedFilesOnException` | `saveUploadedFile` lanza excepción tras 1 path tracked | `unlink` invocado para path tracked; excepción propaga |
| `testHandleResponsePreservesCommentOnInvalidStatusTransition` | `changeStatus` lanza `InvalidStatusTransitionException` | Comment commited; evento NO dispatched; retorno con "Comentario guardado, pero..." |
| `testHandleResponseDispatchesStatusEventAfterCommit` | Mock `EventManager` con orden de calls | `dispatch(TicketStatusChanged)` invocado una sola vez y después de TX2 |
| `testHandleResponseDoesNotDispatchOnTxRollback` | `changeStatus` retorna `false` | `dispatch` nunca llamado |
| `testChangeStatusDispatchesByDefault` | Llamada directa a `changeStatus` sin `deferDispatch` | Evento dispatched inline (regresión: callers existentes no rompen) |

Sin fixtures de BD reales — mocks de Table/Connection. Si la mock de `Connection::transactional()` resulta engorrosa, fallback a SQLite en memoria para los casos críticos (éxito completo y rollback de TX1).

---

## 7. Riesgos

1. **Nested transactions / savepoints.** `addComment` y `saveUploadedFile` invocan `$table->save()`, que abre su propia TX. CakePHP usa savepoints transparentemente. **Mitigación:** primer test de éxito completo va contra SQLite real para validar.
2. **Campo `file_path` exacto.** No verificado aún; trivial al implementar (lectura de `Attachment` entity).
3. **MED-1 sigue abierto.** `sendResponseNotifications` permanece fuera de bus. Fuera de scope.
4. **CRIT-3 sigue abierto.** Esta intervención reduce la ventana entre commit y dispatch pero no garantiza at-least-once. Outbox sigue siendo necesario.

---

## 8. Despliegue y rollback

- Sin migraciones.
- Sin cambios de firma pública en `handleResponse()`.
- Sin variables de entorno nuevas.
- `changeStatus()` añade parámetro con default → backward compatible.
- Rollback: revert del commit.

**Diff estimado:** +60/−10 líneas en `TicketPipelineService.php`. Tests ~150 líneas nuevas.

---

## 9. Validaciones requeridas antes de merge

- `composer cs-fix && composer cs-check` (sin errores nuevos).
- `vendor/bin/phpstan analyse src/Service/TicketPipelineService.php` (sin errores nuevos).
- `composer test` (todos verdes; nuevos tests pasan).
- Smoke manual: subir adjunto + comentario + status change desde UI.

---

## 10. Actualizaciones requeridas al audit

Al cerrar:
- §1 tabla: hallazgos Altos 4 → 3.
- §4 HIGH-1: marcar `✅ CERRADO 2026-05-15` con bitácora en §11.
- §9 acción #3: estado "Completado 2026-05-15".
- Reafirmar en §11 que CRIT-3 sigue abierto.
