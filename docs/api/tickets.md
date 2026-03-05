# API de Tickets

Modulo de mesa de ayuda para gestion de tickets de soporte interno.

**Controlador**: `src/Controller/TicketsController.php`
**Servicio**: `src/Service/TicketService.php`

## Endpoints

### Listar tickets

```
GET /tickets
GET /tickets.json
```

**Filtros disponibles**:

| Parametro | Tipo | Descripcion |
|---|---|---|
| `status` | string | Estado del ticket |
| `priority` | string | Prioridad |
| `assignee_id` | int | Agente asignado |
| `search` | string | Busqueda en asunto/descripcion |
| `view` | string | Vista predefinida (ej: `my_tickets`, `unassigned`) |

**Respuesta**: Lista paginada de tickets con datos de solicitante y agente asignado.

---

### Ver detalle de ticket

```
GET /tickets/view/{id}
GET /tickets/view/{id}.json
```

**Respuesta**: Ticket completo con comentarios, adjuntos, historial, tags y seguidores.

---

### Agregar comentario

```
POST /tickets/add-comment/{id}
```

**Body (form-data)**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `body` | string | Si | Contenido del comentario (HTML) |
| `comment_type` | string | No | `public` (default), `internal` |
| `attachments[]` | file | No | Archivos adjuntos |
| `status` | string | No | Nuevo estado (si se quiere cambiar simultaneamente) |

**Respuesta**: Redireccion a vista del ticket con mensaje flash.

---

### Asignar agente

```
POST /tickets/assign/{id}
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `assignee_id` | int | Si | ID del usuario a asignar |

---

### Cambiar estado

```
POST /tickets/change-status/{id}
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `status` | string | Si | Nuevo estado |

**Estados validos**: `nuevo`, `abierto`, `pendiente`, `resuelto`, `convertido`

---

### Cambiar prioridad

```
POST /tickets/change-priority/{id}
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `priority` | string | Si | Nueva prioridad |

**Prioridades validas**: `baja`, `media`, `alta`, `urgente`

---

### Agregar etiqueta

```
POST /tickets/add-tag/{id}
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `tag_id` | int | Si | ID de la etiqueta |

---

### Eliminar etiqueta

```
POST /tickets/remove-tag/{id}/{tagId}
```

**Parametros de ruta**:
- `id`: ID del ticket
- `tagId`: ID de la etiqueta a eliminar

---

### Agregar seguidor

```
POST /tickets/add-follower/{id}
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `user_id` | int | Si | ID del usuario seguidor |

---

### Convertir a compra

```
POST /tickets/convert-to-compra/{id}
```

Convierte el ticket en una solicitud de compra. Copia comentarios y adjuntos. El ticket cambia a estado `convertido`.

**Respuesta**: Redireccion a la nueva compra creada.

---

### Descargar adjunto

```
GET /tickets/download-attachment/{id}
```

**Parametros**: `id` es el ID del adjunto (no del ticket).

**Respuesta**: Descarga del archivo.

---

### Ver historial

```
GET /tickets/history/{id}
GET /tickets/history/{id}.json
```

**Respuesta**: Lista de cambios del ticket con usuario, campo, valor anterior, valor nuevo y descripcion.

---

### Estadisticas

```
GET /tickets/statistics
GET /tickets/statistics.json
```

**Filtros**:

| Parametro | Tipo | Descripcion |
|---|---|---|
| `date_range` | string | `all`, `30days`, `7days`, `today`, `custom` |
| `start_date` | string | Fecha inicio (para rango custom) |
| `end_date` | string | Fecha fin (para rango custom) |

**Respuesta**: Distribucion por estado, prioridad, canal; tiempos de respuesta/resolucion; rendimiento de agentes; tendencias.

---

## Acciones Masivas (Bulk)

### Asignacion masiva

```
POST /tickets/bulk-assign
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `ticket_ids[]` | int[] | Si | IDs de tickets |
| `assignee_id` | int | Si | Agente a asignar |

### Cambio masivo de prioridad

```
POST /tickets/bulk-change-priority
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `ticket_ids[]` | int[] | Si | IDs de tickets |
| `priority` | string | Si | Nueva prioridad |

### Etiquetado masivo

```
POST /tickets/bulk-add-tag
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `ticket_ids[]` | int[] | Si | IDs de tickets |
| `tag_id` | int | Si | ID de la etiqueta |

### Eliminacion masiva

```
POST /tickets/bulk-delete
```

**Body**:

| Campo | Tipo | Requerido | Descripcion |
|---|---|---|---|
| `ticket_ids[]` | int[] | Si | IDs de tickets a eliminar |

---

## Permisos

| Accion | admin | agent | compras | servicio_cliente | requester |
|---|---|---|---|---|---|
| Listar | Todos | Todos | Solo lectura | Solo lectura | Propios |
| Ver | Si | Si | Si | Si | Propios |
| Comentar | Si | Si | No | No | Propios |
| Asignar | Si | Si | No | No | No |
| Cambiar estado | Si | Si | No | No | No |
| Convertir a compra | Si | Si | No | No | No |
| Bulk actions | Si | Si | No | No | No |
