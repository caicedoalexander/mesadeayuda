# Documentacion Tecnica - Mesa de Ayuda

Sistema corporativo de gestion CakePHP 5.x que integra mesa de ayuda (tickets de soporte), gestion de compras y PQRS (peticiones, quejas, reclamos y sugerencias).

## Indice

### Arquitectura

- [Vision General](arquitectura/README.md) - Descripcion del sistema, stack tecnologico, roles y patrones
- [Modelo de Datos](arquitectura/modelo-datos.md) - Esquema de base de datos, tablas y relaciones
- [Flujos de Negocio](arquitectura/flujos.md) - Ciclos de vida, conversiones y procesos automatizados
- [Servicios](arquitectura/servicios.md) - Capa de servicios, traits, clases de utilidad y logica de negocio
- [Integraciones](arquitectura/integraciones.md) - Gmail, n8n, WhatsApp, AWS S3, trigger de worker y constantes centralizadas

### API

- [Convenciones API](api/README.md) - Autenticacion, formato de respuesta, codigos de error
- [Tickets](api/tickets.md) - Endpoints del modulo de tickets
- [Compras](api/compras.md) - Endpoints del modulo de compras
- [PQRS](api/pqrs.md) - Endpoints del modulo de PQRS
- [Webhooks y Admin](api/webhooks.md) - Webhooks n8n, callbacks y rutas administrativas
