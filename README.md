# Mesa de Ayuda

Plataforma corporativa de mesa de ayuda desarrollada en **CakePHP 5.x**. Incluye integraciones nativas con Gmail, n8n, WhatsApp (Evolution API) y AWS S3.

[![CakePHP](https://img.shields.io/badge/CakePHP-5.x-D33C44?style=flat-square&logo=cakephp&logoColor=white)](https://cakephp.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ED?style=flat-square&logo=docker&logoColor=white)](https://www.docker.com)
[![n8n](https://img.shields.io/badge/n8n-webhooks-EF2C5A?style=flat-square&logo=n8n&logoColor=white)](https://n8n.io)
[![WhatsApp](https://img.shields.io/badge/WhatsApp-Evolution_API-25D366?style=flat-square&logo=whatsapp&logoColor=white)](https://evolution-api.com)

---

## Tabla de contenidos

- [Módulos](#módulos)
- [Integraciones](#integraciones)
- [Stack técnico](#stack-técnico)
- [Requisitos](#requisitos)
- [Instalación local](#instalación-local)
- [Despliegue con Docker](#despliegue-con-docker)
- [Configuración](#configuración)
- [Comandos útiles](#comandos-útiles)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Estándares de código](#estándares-de-código)

---

## Módulos

### Soporte Interno (Tickets)
Mesa de ayuda para colaboradores con ciclo de vida completo del ticket, conversión automática de correos a tickets vía Gmail, asignación por agente, seguidores, etiquetado y auditoría de cambios.

### Administración
Prefijo `/admin` para gestión de configuración del sistema, plantillas de email, etiquetas y archivos de configuración.

---

## Integraciones

| Integración | Propósito |
|---|---|
| **Gmail API** | Importación de correos como tickets, lectura de adjuntos y mapeo de hilos. Disparada por n8n vía `POST /webhooks/gmail/import` (ver `docs/operations/n8n-gmail-webhook.md`). |
| **n8n** | Webhooks bidireccionales para automatizaciones externas (clasificación, notificaciones avanzadas, orquestación). |
| **WhatsApp (Evolution API)** | Notificaciones transaccionales a usuarios y agentes. |
| **AWS S3** | Almacenamiento de adjuntos a través de `FileStorageInterface` (conmutable con `AWS_S3_ENABLED`). |

---

## Stack técnico

- **Backend:** CakePHP 5.2, PHP 8.1+
- **Frontend:** Bootstrap 5, JavaScript vanilla, plantillas server-side (`.php`)
- **Base de datos:** MySQL / MariaDB
- **Autenticación:** `cakephp/authentication` (Form + Session)
- **Migraciones:** `cakephp/migrations`
- **AWS SDK:** `aws/aws-sdk-php`
- **Google API:** `google/apiclient`
- **Infraestructura:** Docker (Nginx + PHP-FPM), worker independiente

---

## Requisitos

- PHP **8.1** o superior con extensiones: `intl`, `mbstring`, `pdo_mysql`, `openssl`, `curl`, `zip`
- Composer 2.x
- MySQL 8.x o MariaDB 10.5+
- Docker y Docker Compose (opcional, recomendado para producción)

---

## Instalación local

```bash
# 1. Clonar e instalar dependencias
composer install

# 2. Configurar el entorno
cp config/app_local.example.php config/app_local.php
# Editar credenciales de DB y claves de API

# 3. Aplicar migraciones
bin/cake migrations migrate

# 4. Levantar el servidor de desarrollo
bin/cake server
# Disponible en http://localhost:8765
```

---

## Despliegue con Docker

El `Dockerfile` construye una imagen única usada por el servicio definido en `docker-compose.yml`:

- **`web`** — Nginx + PHP-FPM (puerto 80, mapeado a `8082` en el host).

La importación de Gmail antes corría en un servicio `worker` continuo; ahora se dispara desde n8n vía `POST /webhooks/gmail/import`. El comando `bin/cake import_gmail` se conserva para depuración manual.

```bash
docker compose up -d --build
```

> La base de datos **no** está incluida en el `docker-compose.yml`. Debe proveerse externamente (instancia gestionada, MySQL en host o contenedor independiente) y referenciarse mediante `DB_HOST`.

El endpoint `/health` valida Nginx, PHP-FPM y conectividad con la base de datos para healthchecks.

---

## Configuración

La configuración base vive en `config/app_local.php` (ignorado por Git). Variables de entorno principales:

| Variable | Descripción |
|---|---|
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Conexión a base de datos |
| `SECURITY_SALT` | Salt para CSRF y encriptación |
| `FULL_BASE_URL` | URL pública absoluta del sitio |
| `TRUST_PROXY` | Habilitar cuando hay un proxy reverso al frente |
| `AWS_S3_ENABLED`, `AWS_S3_BUCKET`, `AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | Configuración de S3 |

Opcionalmente puede usarse un archivo `config/.env` (cargado por `josegonzalez/dotenv` desde `config/bootstrap.php`).

> Ajustes por tenant (tokens OAuth de Gmail, credenciales de integración, plantillas de email) se gestionan desde la interfaz `/admin`, persistidos en las tablas `system_settings` y `email_templates`.

---

## Comandos útiles

```bash
# Calidad de código
composer cs-check          # Verifica estilo (CakePHP CodeSniffer)
composer cs-fix            # Corrección automática

# Migraciones
bin/cake migrations migrate
bin/cake migrations status
bin/cake bake migration CreateFooTable

# Generación de código
bin/cake bake model Foos

# Importación de correo
bin/cake import_gmail --max 5   # Importación puntual para depuración (el flujo
                                # productivo lo dispara n8n vía webhook)

# Utilidades
bin/cake test_email        # Verifica configuración de envío
```

> El proyecto **no incluye** suite de pruebas automatizadas. Las verificaciones se realizan manualmente sobre los flujos afectados (navegador, comando CLI, logs del worker).

---

## Estructura del proyecto

```
src/
├── Controller/             # Capa HTTP (delgada, delega en servicios)
│   ├── Admin/              # Prefijo /admin (Settings, EmailTemplates, Tags…)
│   └── Traits/             # Comportamiento compartido entre módulos de tickets
├── Service/                # Lógica de negocio
│   ├── Storage/            # Abstracción FileStorageInterface (local / S3)
│   └── Traits/             # Mixins reutilizables (Notification, Attachment…)
├── Model/
│   ├── Table/              # ORM de CakePHP
│   ├── Entity/
│   └── Behavior/           # AuditBehavior (alimenta tablas *_history)
├── Command/                # Comandos CLI (Gmail worker, import, test email)
└── View/                   # AppView, AjaxView, Cells y Helpers

config/
├── Migrations/             # Fuente de verdad del esquema de BD
├── routes.php              # Mapa de rutas canónico
└── app_local.example.php   # Plantilla de configuración local

templates/                  # Vistas server-side por controlador
docker-compose.yml          # Servicios web + worker
Dockerfile                  # Imagen Nginx + PHP-FPM
```

### Convenciones transversales

- **Auditoría:** todos los módulos operativos escriben en su tabla `*_history` mediante `AuditBehavior`. No se debe omitir esta capa al mutar entidades.
- **Notificaciones:** salen a través de `NotificationDispatcherTrait` + `EmailTemplateRenderer`, que orquestan email, WhatsApp y webhooks de n8n. Para tipos nuevos extender el renderer y las plantillas, no llamar integraciones desde controladores.
- **Adjuntos:** uso compartido vía `GenericAttachmentTrait` y `FileStorageInterface`, lo que permite alternar entre disco local y S3 sin cambios en los controladores.
- **Contadores del sidebar:** centralizados en `SidebarCountsService`. Reutilizarlo en lugar de consultar tablas desde las vistas.

---

## Estándares de código

- `declare(strict_types=1);` obligatorio en todos los archivos PHP.
- Cumplimiento del ruleset oficial CakePHP CodeSniffer (`phpcs.xml`).
- Patrón **fat-service / thin-controller**: lógica en `src/Service/`, controladores delegando.
- Antes de cada commit:

  ```bash
  composer cs-fix
  composer cs-check
  ```

---

_Plataforma construida con tipado estricto, arquitectura modular y separación clara de responsabilidades._
