# Vision General del Sistema

## Descripcion

Mesa de Ayuda es un sistema corporativo que integra tres modulos principales bajo una unica plataforma:

- **Helpdesk (Tickets)**: Gestion de tickets de soporte interno con conversion automatica de correos via Gmail
- **Compras**: Flujo de adquisiciones con cadena de aprobacion y trazabilidad completa
- **PQRS**: Canal de atencion al cliente para peticiones, quejas, reclamos y sugerencias con portal publico
- **Admin**: Configuracion de integraciones, gestion de SLA, estadisticas y ajustes del sistema

## Stack Tecnologico

| Componente | Tecnologia |
|---|---|
| Framework | CakePHP 5.x (PHP 8.1+) |
| Base de datos | MySQL 8.0+ (utf8mb4) |
| Frontend | Bootstrap 5, server-side rendering |
| Email | Google Gmail API (OAuth2) |
| Automatizacion | n8n (webhooks) |
| Mensajeria | WhatsApp Business via Evolution API |
| Almacenamiento | AWS S3 (opcional) / local |
| Contenedores | Docker (PHP-FPM + Nginx + Worker) |
| Zona horaria | America/Bogota (UTC-5) |

## Diagrama de Componentes

```
┌──────────────────────────────────────────────────────────┐
│                    CLIENTES                               │
│  Navegador Web  │  Email (Gmail)  │  Formulario Publico  │
└────────┬────────────────┬──────────────────┬─────────────┘
         │                │                  │
         ▼                ▼                  ▼
┌──────────────────────────────────────────────────────────┐
│               CAPA DE CONTROLADORES                       │
│  TicketsController │ ComprasController │ PqrsController   │
│  Admin\SettingsController │ Admin\SlaManagementController │
│  Admin\ConfigFilesController │ HealthController           │
└────────┬─────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────┐
│               CAPA DE SERVICIOS                           │
│  TicketService    │ ComprasService   │ PqrsService        │
│  GmailService     │ EmailService     │ WhatsappService    │
│  N8nService       │ S3Service        │ SlaManagementSvc   │
│  StatisticsService│ SettingsService  │ AuthorizationSvc   │
└────────┬─────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────┐
│           INTEGRACIONES EXTERNAS                          │
│  Gmail API  │  Evolution API  │  n8n Webhooks  │  AWS S3 │
└──────────────────────────────────────────────────────────┘
         │
         ▼
┌──────────────────────────────────────────────────────────┐
│               BASE DE DATOS (MySQL 8.0+)                  │
│  23 tablas: tickets, compras, pqrs, users, organizations  │
│  + comentarios, adjuntos, historial, tags, configuracion  │
└──────────────────────────────────────────────────────────┘
```

## Roles del Sistema

| Rol | Descripcion | Acceso |
|---|---|---|
| `admin` | Administrador del sistema | Todo: configuracion, usuarios, integraciones, todos los modulos |
| `agent` | Agente de soporte | Tickets: gestion completa. Compras/PQRS: solo lectura |
| `compras` | Personal de compras | Compras: gestion completa. Tickets/PQRS: solo lectura |
| `servicio_cliente` | Servicio al cliente | PQRS: gestion completa. Tickets/Compras: solo lectura |
| `requester` | Solicitante | Tickets: crear y ver propios. Sin acceso a Compras/PQRS internos |

## Patrones Arquitectonicos

### Service Layer
La logica de negocio reside en clases de servicio (`src/Service/`) inyectadas en los controladores. Los controladores solo manejan HTTP request/response y delegan al servicio correspondiente.

### Traits Reutilizables
Comportamientos comunes entre servicios se extraen en traits (`src/Service/Traits/`):
- `TicketSystemTrait` - Comentarios, cambios de estado, historial, manejo de respuestas
- `NotificationDispatcherTrait` - Despacho de notificaciones (email + WhatsApp)
- `GenericAttachmentTrait` - Gestion de archivos adjuntos
- `EntityConversionTrait` - Conversion entre entidades (Ticket <-> Compra)
- `SlaAwareTrait` - Verificacion de cumplimiento de SLA
- `ConfigResolutionTrait` - Resolucion de configuracion en 3 niveles
- `SecureHttpTrait` - Peticiones HTTP seguras (webhooks)

Traits de controlador (`src/Controller/Traits/`):
- `TicketSystemControllerTrait` - Trait compuesto que agrupa los sub-traits de controlador
- `TicketSystemActionsTrait` - Asignar, cambiar estado/prioridad, comentar, descargar
- `TicketSystemBulkTrait` - Acciones masivas (asignar, prioridad, tags, eliminar)
- `TicketSystemListingTrait` - Listado de entidades con filtros y paginacion
- `TicketSystemViewTrait` - Vista individual de entidad con helpers de vista
- `TicketSystemHistoryTrait` - Historial de entidad (API JSON)

### MVC (Model-View-Controller)
Patron estandar de CakePHP con:
- **Models**: Table classes (ORM, validaciones, relaciones) + Entity classes (representacion de datos)
- **Views**: Templates PHP server-rendered con Bootstrap 5
- **Controllers**: Manejo de requests HTTP con routing RESTful

### Auditoria
Todas las entidades principales (Tickets, Compras, PQRS) mantienen tablas de historial que registran cada cambio de campo con usuario responsable y timestamp.
