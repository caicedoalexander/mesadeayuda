# Inbound quoted chain rendering — design

**Date:** 2026-05-23
**Scope:** UI-only. No backend, no DB, no templates.

## Problem

Cuando un cliente o agente externo responde a una notificación, el HTML del correo entrante incluye los mensajes anteriores citados como `<blockquote>` anidados (markup estándar generado por Gmail, Outlook, Apple Mail, Thunderbird). El sanitizer (`SanitizeHelper` + `HtmlSanitizerTrait`) ya los permite, pero `webroot/css/tickets-view.css` no tiene reglas para `<blockquote>` dentro de `.thread-message-content`. El resultado es un muro de texto plano que oculta la lineage de la conversación.

## Goal

Renderizar la cadena de citas dentro de un comentario inbound como un árbol indentado con guías verticales, colapsado por defecto detrás de un toggle. El mensaje nuevo queda prominente arriba; el historial está disponible bajo click.

## Non-goals

- Cambiar la estructura de la lista de comentarios (sigue cronológica lineal).
- Parsear texto plano de citas con "On X, Y wrote:" — confiamos en el markup `<blockquote>` que ya viene en el HTML.
- Modificar el flujo de ingesta o sanitización.
- Cubrir notas internas, mensajes outbound o el composer (selectores distintos).

## Files touched

- `webroot/css/tickets-view.css` — reglas para `<blockquote>` anidados dentro de `.thread-message-content` y para `.thread-message-quoted-toggle`.
- `webroot/js/tickets-view.js` — función `initQuotedToggles()` invocada desde el handler `DOMContentLoaded` existente.

Nada más. Sin migración, sin PHP, sin templates.

## CSS

View-scoped en `tickets-view.css` porque `.thread-message-*` solo existe en la vista de detalle del ticket. Lee tokens de `:root` definidos en `webroot/css/styles.css`:

```css
.thread-message-content blockquote {
    margin: 8px 0;
    padding: 4px 0 4px 14px;
    border-left: 2px solid var(--gray-300);
    color: var(--gray-600);
}
.thread-message-content blockquote blockquote {
    border-left-color: var(--gray-200);
}
.thread-message-quoted-toggle {
    display: inline-flex; align-items: center; gap: 4px;
    cursor: pointer; user-select: none;
    color: var(--gray-500); font-size: 12px;
    padding: 4px 10px; margin: 6px 0;
    border: 1px solid var(--gray-200); border-radius: var(--radius-xs);
    background: var(--gray-50);
}
.thread-message-quoted-toggle:hover { color: var(--gray-700); background: var(--gray-100); }
.thread-message-quoted-hidden { display: none !important; }
```

Los `<blockquote>` anidados se empujan progresivamente a la derecha por el `padding-left` que cada nivel hereda. La guía vertical (`border-left`) se atenúa en el segundo nivel para no recargar visualmente cadenas profundas.

## JS

```js
function initQuotedToggles() {
    document.querySelectorAll('.thread-message-content').forEach(msg => {
        const tops = Array.from(msg.children).filter(el => el.tagName === 'BLOCKQUOTE');
        if (!tops.length) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'thread-message-quoted-hidden';
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'thread-message-quoted-toggle';
        toggle.textContent = '··· Mostrar contenido anterior';
        tops[0].before(toggle);
        tops.forEach(bq => wrapper.appendChild(bq));
        toggle.after(wrapper);
        toggle.addEventListener('click', () => {
            const hidden = wrapper.classList.toggle('thread-message-quoted-hidden');
            toggle.textContent = hidden
                ? '··· Mostrar contenido anterior'
                : '⌃ Ocultar contenido anterior';
        });
    });
}
```

Invocada una vez desde el handler `DOMContentLoaded` ya existente en `tickets-view.js`. La página recarga tras postear comentarios (no es SPA), así que no se requiere observer ni re-cableado dinámico.

## Edge cases

| Escenario | Comportamiento |
|---|---|
| Comentario sin `<blockquote>` | `initQuotedToggles` retorna temprano; no se inyecta toggle. |
| Múltiples blockquotes top-level (Apple Mail emite varios) | Todos se mueven al mismo wrapper, un solo toggle los cubre. |
| Mensaje original del solicitante con citas inline | Recibe el mismo tratamiento — el selector `.thread-message-content` aplica también al primer mensaje del hilo. |
| Notas internas | No suelen traer `<blockquote>`; el código es no-op. |
| Composer (`.composer-editor blockquote`) | Selector distinto, no se ve afectado. |
| Cadenas muy profundas (10+ niveles) | El border-left atenuado y la indentación se acumulan; aceptable porque está colapsado por defecto. |

## Validación

- Manual: abrir un ticket que tenga al menos un comentario inbound con cadena citada de 3+ niveles. Verificar que aparece el toggle, que colapsa por defecto, que al expandir muestra el árbol con indentación y guías verticales.
- `composer cs-check` no aplica (cambios solo en CSS/JS).
- Sin tests automatizados (no hay framework JS configurado; cambios puramente visuales).

## Rollback

Revertir el commit. CSS y JS aditivos; no hay datos persistidos ni migraciones.
