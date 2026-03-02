# Docker Deployment Guide

This guide explains how to deploy Mesa de Ayuda using Docker containers.

## Architecture

Three separate services communicating over a Docker network:

1. **web** - PHP-FPM application container
2. **nginx** - Nginx web server (reverse proxy to PHP-FPM)
3. **worker** - Background worker for Gmail import automation

**External Dependencies** (not in Docker):
- MySQL 8.0+ database
- n8n Automation Platform (optional, configured via SystemSettings)
- Evolution API for WhatsApp (optional, configured via SystemSettings)

## Prerequisites

- Docker Engine 20.10+
- Docker Compose V2+
- External MySQL 8.0+ database
- HTTPS termination (required for Gmail OAuth in production)

## Quick Start

### 1. Configure Environment

```bash
cp .env.docker.example .env
nano .env
```

**Required variables:**

```env
# Database
DB_HOST=your-database-host
DB_PASSWORD=your-db-password

# Security (CRITICAL - generate a unique salt)
SECURITY_SALT=$(php -r "echo bin2hex(random_bytes(32));")

# HTTPS behind reverse proxy
TRUST_PROXY=true
```

### 2. Run Database Migrations

```bash
# From a machine with DB access
php bin/cake.php migrations migrate
```

Or after containers are running:
```bash
docker compose exec web php bin/cake.php migrations migrate
```

### 3. Start Containers

```bash
docker compose up -d --build
```

### 4. Verify

```bash
docker compose ps
curl http://localhost/health
```

## Environment Variables

### Required

| Variable | Description |
|----------|-------------|
| `DB_HOST` | Database server hostname |
| `DB_DATABASE` | Database name (default: `mesadeayuda`) |
| `DB_USERNAME` | Database username |
| `DB_PASSWORD` | Database password |
| `SECURITY_SALT` | Encryption key for OAuth tokens. Generate with `php -r "echo bin2hex(random_bytes(32));"` |

### Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `production` | Application environment |
| `DEBUG` | `false` | Enable debug mode |
| `APP_PORT` | `80` | Host port for Nginx |
| `TRUST_PROXY` | `true` | Detect HTTPS from `X-Forwarded-Proto` header |
| `FULL_BASE_URL` | _(auto-detected)_ | Force specific base URL (e.g., `https://yourdomain.com`) |

### Worker

| Variable | Default | Description |
|----------|---------|-------------|
| `WORKER_ENABLED` | `true` | Enable/disable Gmail worker |

### AWS S3 (Optional)

| Variable | Default | Description |
|----------|---------|-------------|
| `AWS_S3_ENABLED` | `false` | Enable S3 for file storage |
| `AWS_ACCESS_KEY_ID` | - | AWS access key |
| `AWS_SECRET_ACCESS_KEY` | - | AWS secret key |
| `AWS_REGION` | `us-east-1` | AWS region |
| `AWS_S3_BUCKET` | - | S3 bucket name |

> **Important:** Without `SECURITY_SALT`, the worker cannot decrypt Gmail OAuth tokens and will fail silently. Both `web` and `worker` services must receive this variable.

## Gmail Worker

The worker container continuously imports emails from Gmail at intervals configured in SystemSettings.

### Setup

1. Access Admin Panel: `/admin/settings`
2. Upload `client_secret.json` (Google OAuth credentials)
3. Click "Authorize Gmail Access" → complete Google OAuth flow
4. Set `gmail_check_interval` (default: 5 minutes)

### Behavior

- Waits for database connectivity on startup (10 attempts, 5s apart)
- Handles `SIGTERM`/`SIGINT` for graceful shutdown in Docker
- Uses exponential backoff on errors (60s → 120s → 240s → max 600s)
- Resets backoff after a successful iteration
- If Gmail is not configured, keeps checking at the configured interval

### Monitor

```bash
docker compose logs -f worker
docker compose restart worker
```

### Disable

Set `WORKER_ENABLED=false` in `.env` and restart:
```bash
docker compose restart worker
```

## File Persistence

```yaml
volumes:
  - ./logs:/var/www/html/logs
  - ./tmp:/var/www/html/tmp
  - ./webroot/uploads:/var/www/html/webroot/uploads
```

Source code is baked into the image at build time. Only data directories are mounted.

## Production Deployment

### 1. Prepare

```bash
# Generate security salt
php -r "echo bin2hex(random_bytes(32));"

# Configure .env
cp .env.docker.example .env
nano .env
```

### 2. Build & Start

```bash
docker compose up -d --build
```

### 3. Run Migrations

```bash
docker compose exec web php bin/cake.php migrations migrate
```

### 4. HTTPS

Gmail OAuth **requires HTTPS** in production. Use a reverse proxy (Nginx, Traefik, Caddy, or your platform's built-in proxy) for SSL termination. Set `TRUST_PROXY=true` so CakePHP detects HTTPS from the `X-Forwarded-Proto` header.

Example reverse proxy config:

```nginx
server {
    listen 443 ssl http2;
    server_name mesadeayuda.example.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://localhost:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Container Management

```bash
# View logs
docker compose logs -f [service]

# Execute commands
docker compose exec web php bin/cake.php <command>
docker compose exec web php bin/cake.php migrations migrate
docker compose exec web php bin/cake.php cache clear_all

# Rebuild after code changes
docker compose up -d --build

# Stop
docker compose down
```

## Monitoring

### Health Check

```
GET /health
```

Returns JSON with application and database status.

### Log Locations

| Location | Description |
|----------|-------------|
| `./logs/` | Application logs (mounted from container) |
| `./logs/php_errors.log` | PHP error log |
| Docker JSON logs | Container stdout/stderr (rotated: 10MB x 3 files) |

## Troubleshooting

### Worker not importing emails

1. Check logs: `docker compose logs -f worker`
2. Verify `SECURITY_SALT` is set for the worker service
3. Verify Gmail is configured: `/admin/settings`
4. Test manually: `docker compose exec web php bin/cake.php import_gmail --max 5`

### Database connection errors

```bash
docker compose exec web php bin/cake.php migrations status
docker compose exec web env | grep DB_
```

### Permission issues

```bash
docker compose exec web chown -R www-data:www-data logs tmp webroot/uploads
```

### Container won't start

```bash
docker compose logs web
docker compose down && docker compose up -d --build --force-recreate
```
