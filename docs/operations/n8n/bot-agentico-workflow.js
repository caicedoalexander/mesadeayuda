// Fuente SDK (n8n Workflow SDK) del workflow "Mesa de Ayuda - WhatsApp Bot".
// Workflow ID en n8n: lO3uLa8uKFTHFW1l  (proyecto personal de Alexander).
// Spec:  docs/superpowers/specs/2026-06-23-bot-agentico-whatsapp-design.md
// Plan:  docs/superpowers/plans/2026-06-23-bot-agentico-whatsapp.md
//
// Snapshot versionado para historial en git. La fuente de verdad operativa
// vive en n8n; regenerar tras cada cambio en la UI. Las credenciales
// (Meta WhatsApp Trigger / Bearer, OpenCode Zen, Mesa de Ayuda - WhatsApp
// Import Token, Redis) se asignan en n8n; no viven aqui.
import { workflow, node, trigger, ifElse, merge, languageModel, memory, tool, newCredential, fromAi, expr, nodeJson } from '@n8n/workflow-sdk';

const waTrigger = trigger({
  type: 'n8n-nodes-base.whatsAppTrigger',
  version: 1,
  config: {
    name: 'WhatsApp Trigger',
    parameters: { updates: ['messages'], options: { messageStatusUpdates: ['all'] } },
    credentials: { whatsAppTriggerApi: newCredential('Meta WhatsApp Trigger') },
    position: [-1600, 0],
  },
  output: [{ messages: [{ id: 'wamid.X', from: '573001234567', type: 'text', text: { body: 'Hola' } }], contacts: [{ profile: { name: 'Ana' } }], metadata: { phone_number_id: '123456' } }],
});

const parseData = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: {
    name: 'Parse Data',
    parameters: {
      mode: 'runOnceForAllItems',
      language: 'javaScript',
      jsCode: "if ($input.first().json.statuses) {\n  return [];\n}\n\nconst value = $input.first().json;\nconst metadata = value.metadata;\nconst message = value.messages[0];\nconst contact = value.contacts && value.contacts[0];\n\nconst phoneNumber = message.from;\nconst userName = (contact && contact.profile && contact.profile.name) || 'Usuario';\nconst messageId = message.id;\nconst timestamp = message.timestamp;\nconst messageType = message.type;\n\nlet messageContent = '';\nlet mediaData = null;\n\nif (messageType === 'text') {\n  messageContent = (message.text && message.text.body) || '';\n} else if (messageType === 'interactive') {\n  const inter = message.interactive || {};\n  messageContent = (inter.button_reply && inter.button_reply.id) || (inter.list_reply && inter.list_reply.id) || '';\n} else if (['image', 'document', 'video', 'audio', 'voice'].includes(messageType)) {\n  const media = message[messageType];\n  messageContent = media.caption || '';\n  const extByType = { image: 'jpg', video: 'mp4', audio: 'ogg', voice: 'ogg' };\n  mediaData = {\n    id: media.id,\n    mime: media.mime_type,\n    filename: media.filename || (messageType + '_' + media.id + '.' + (extByType[messageType] || 'bin')),\n  };\n}\n\nconst apiVersion = 'v24.0';\nconst phoneNumberId = metadata.phone_number_id;\n\nreturn [{\n  json: {\n    phoneNumber: phoneNumber,\n    userName: userName,\n    messageId: messageId,\n    timestamp: timestamp,\n    messageType: messageType,\n    messageContent: messageContent,\n    agentInput: messageContent || ('[El usuario adjunto ' + (mediaData ? mediaData.filename : 'un archivo') + ']'),\n    hasAttachment: mediaData !== null,\n    mediaId: mediaData ? mediaData.id : null,\n    mediaMime: mediaData ? mediaData.mime : null,\n    mediaFilename: mediaData ? mediaData.filename : null,\n    sendUrl: 'https://graph.facebook.com/' + apiVersion + '/' + phoneNumberId + '/messages',\n    mediaUrl: mediaData ? ('https://graph.facebook.com/' + apiVersion + '/' + mediaData.id) : null,\n  },\n}];",
    },
    position: [-1400, 0],
  },
  output: [{ phoneNumber: '573001234567', userName: 'Ana', messageId: 'wamid.X', messageType: 'text', messageContent: 'Hola', agentInput: 'Hola', hasAttachment: false, mediaId: null, mediaMime: null, mediaFilename: null, sendUrl: 'https://graph.facebook.com/v24.0/123456/messages', mediaUrl: null }],
});

const lockGet = node({
  type: 'n8n-nodes-base.redis',
  version: 1,
  config: {
    name: 'Lock Get',
    parameters: { operation: 'get', key: expr("mesadeayuda:lock:{{ $json.phoneNumber }}"), propertyName: 'lockStatus', keyType: 'string' },
    credentials: { redis: newCredential('Redis') },
    position: [-1200, 0],
  },
  output: [{ phoneNumber: '573001234567', lockStatus: null }],
});

const ifLockFree = ifElse({
  version: 2.3,
  config: {
    name: 'Lock Libre?',
    parameters: {
      conditions: {
        options: { caseSensitive: true, leftValue: '', typeValidation: 'loose', version: 2 },
        conditions: [{ id: 'lockfree', leftValue: expr('{{ $json.lockStatus }}'), rightValue: '', operator: { type: 'string', operation: 'empty', singleValue: true } }],
        combinator: 'and',
      },
    },
    position: [-1000, 0],
  },
});

const sendProcessing = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Avisar Procesando',
    parameters: {
      method: 'POST',
      url: expr("{{ $('Parse Data').item.json.sendUrl }}"),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpBearerAuth',
      sendBody: true,
      contentType: 'json',
      specifyBody: 'json',
      jsonBody: expr('{\n  "messaging_product": "whatsapp",\n  "recipient_type": "individual",\n  "to": "{{ $(\'Parse Data\').item.json.phoneNumber }}",\n  "type": "text",\n  "text": { "preview_url": false, "body": "Estoy procesando tu mensaje anterior, dame unos segundos." }\n}'),
    },
    credentials: { httpBearerAuth: newCredential('Meta WhatsApp Bearer') },
    position: [-800, 200],
  },
  output: [{ messages: [{ id: 'wamid.proc' }] }],
});

const lockSet = node({
  type: 'n8n-nodes-base.redis',
  version: 1,
  config: {
    name: 'Lock Set',
    parameters: { operation: 'set', key: expr("mesadeayuda:lock:{{ $('Parse Data').item.json.phoneNumber }}"), value: expr("{{ $('Parse Data').item.json.messageId }}"), keyType: 'string', expire: true, ttl: 60 },
    credentials: { redis: newCredential('Redis') },
    position: [-800, -100],
  },
  output: [{ phoneNumber: '573001234567' }],
});

const ifHasAttachment = ifElse({
  version: 2.3,
  config: {
    name: 'Trae Adjunto?',
    parameters: {
      conditions: {
        options: { caseSensitive: true, leftValue: '', typeValidation: 'loose', version: 2 },
        conditions: [{ id: 'hasatt', leftValue: expr("{{ $('Parse Data').item.json.hasAttachment }}"), rightValue: '', operator: { type: 'boolean', operation: 'true', singleValue: true } }],
        combinator: 'and',
      },
    },
    position: [-600, -100],
  },
});

const getMediaUrl = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Get Media URL',
    parameters: {
      method: 'GET',
      url: expr("{{ $('Parse Data').item.json.mediaUrl }}"),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpBearerAuth',
    },
    credentials: { httpBearerAuth: newCredential('Meta WhatsApp Bearer') },
    position: [-400, -250],
  },
  output: [{ url: 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=X', mime_type: 'image/jpeg', file_size: 12345 }],
});

const getMediaBinary = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Get Media Binary',
    parameters: {
      method: 'GET',
      url: expr('{{ $json.url }}'),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpBearerAuth',
      options: { response: { response: { responseFormat: 'file', outputPropertyName: 'data' } } },
    },
    credentials: { httpBearerAuth: newCredential('Meta WhatsApp Bearer') },
    position: [-200, -250],
  },
  output: [{}],
});

const encodeBase64 = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: {
    name: 'Encode Base64 + Push',
    parameters: {
      mode: 'runOnceForAllItems',
      language: 'javaScript',
      jsCode: "const MAX_BYTES = 10 * 1024 * 1024;\nconst parsed = $('Parse Data').first().json;\nconst items = $input.all();\nconst out = [];\nfor (let i = 0; i < items.length; i++) {\n  const buffer = await this.helpers.getBinaryDataBuffer(i, 'data');\n  if (!buffer) continue;\n  if (buffer.length > MAX_BYTES) {\n    out.push({ json: { skipped: true, reason: 'too_large', size: buffer.length } });\n    continue;\n  }\n  out.push({ json: { attachment: { filename: parsed.mediaFilename, mime: parsed.mediaMime, size: buffer.length, content_base64: buffer.toString('base64') } } });\n}\nreturn out;",
    },
    position: [0, -250],
  },
  output: [{ attachment: { filename: 'foto.jpg', mime: 'image/jpeg', size: 12345, content_base64: 'AAAA' } }],
});

const pushAttachment = node({
  type: 'n8n-nodes-base.redis',
  version: 1,
  config: {
    name: 'Push Attachment',
    parameters: { operation: 'push', list: expr("mesadeayuda:att:{{ $('Parse Data').item.json.phoneNumber }}"), messageData: expr('{{ JSON.stringify($json.attachment) }}'), tail: true },
    credentials: { redis: newCredential('Redis') },
    position: [200, -250],
  },
  output: [{}],
});

const attachmentMerge = merge({
  version: 3.2,
  config: { name: 'Convergencia Adjunto', parameters: { mode: 'append' }, position: [400, -100] },
  output: [{}],
});

const loadAttachments = node({
  type: 'n8n-nodes-base.redis',
  version: 1,
  config: {
    name: 'Load Attachments',
    parameters: { operation: 'get', key: expr("mesadeayuda:att:{{ $('Parse Data').item.json.phoneNumber }}"), propertyName: 'pendingAttRaw', keyType: 'list' },
    credentials: { redis: newCredential('Redis') },
    position: [600, -100],
  },
  output: [{ pendingAttRaw: [] }],
});

const parseAttachments = node({
  type: 'n8n-nodes-base.code',
  version: 2,
  config: {
    name: 'Parse Attachments',
    parameters: {
      mode: 'runOnceForAllItems',
      language: 'javaScript',
      jsCode: "const raw = $('Load Attachments').first().json.pendingAttRaw;\nlet list = [];\nif (Array.isArray(raw)) {\n  list = raw.map(function (x) { try { return typeof x === 'string' ? JSON.parse(x) : x; } catch (e) { return null; } }).filter(Boolean);\n}\nreturn [{ json: { attachments: list } }];",
    },
    position: [800, -100],
  },
  output: [{ attachments: [] }],
});

const chatModel = languageModel({
  type: '@n8n/n8n-nodes-langchain.lmChatOpenAi',
  version: 1.3,
  config: {
    name: 'OpenCode Zen Model',
    parameters: {
      model: { __rl: true, mode: 'id', value: 'opencode/qwen3.5-plus' },
      options: { baseURL: 'https://opencode.ai/zen/v1', temperature: 0.3 },
    },
    credentials: { openAiApi: newCredential('OpenCode Zen') },
    position: [900, 150],
  },
});

const chatMemory = memory({
  type: '@n8n/n8n-nodes-langchain.memoryRedisChat',
  version: 1.6,
  config: {
    name: 'Conversacion Redis',
    parameters: {
      sessionIdType: 'customKey',
      sessionKey: nodeJson(parseData, 'phoneNumber'),
      sessionTTL: 3600,
      contextWindowLength: 15,
    },
    credentials: { redis: newCredential('Redis') },
    position: [1050, 150],
  },
});

const createTicketTool = tool({
  type: 'n8n-nodes-base.httpRequestTool',
  version: 4.4,
  config: {
    name: 'create_ticket',
    parameters: {
      method: 'POST',
      url: expr('{{ $env.MESADEAYUDA_URL }}/webhooks/whatsapp/import'),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpHeaderAuth',
      sendBody: true,
      contentType: 'json',
      specifyBody: 'keypair',
      bodyParameters: {
        parameters: [
          { name: 'subject', value: fromAi('subject', 'Asunto corto y descriptivo del ticket (max 200 caracteres)') },
          { name: 'description', value: fromAi('description', 'Descripcion detallada del problema del usuario') },
          { name: 'message_id', value: nodeJson(parseData, 'messageId') },
          { name: 'phone_number', value: nodeJson(parseData, 'phoneNumber') },
          { name: 'contact_name', value: nodeJson(parseData, 'userName') },
          { name: 'attachments', value: nodeJson(parseAttachments, 'attachments') },
        ],
      },
    },
    credentials: { httpHeaderAuth: newCredential('Mesa de Ayuda - WhatsApp Import Token') },
    position: [1200, 150],
  },
});

const aiAgent = node({
  type: '@n8n/n8n-nodes-langchain.agent',
  version: 3.1,
  config: {
    name: 'Agente Mesa de Ayuda',
    parameters: {
      promptType: 'define',
      text: expr("{{ $('Parse Data').item.json.agentInput }}"),
      options: {
        systemMessage: "Eres el asistente virtual de la Mesa de Ayuda de soporte interno. Conversas por WhatsApp en espanol, de forma cordial y concisa.\n\nTu UNICA funcion es ayudar al usuario a CREAR un ticket de soporte. No puedes consultar el estado de tickets existentes ni hacer otras gestiones; si te lo piden, explicalo con amabilidad y ofrece crear un ticket.\n\nFlujo que debes seguir:\n1. Saluda brevemente y pregunta en que puedes ayudar (si el usuario ya describio su problema, no repreguntes lo obvio).\n2. Asegurate de tener dos cosas: un ASUNTO corto (una frase) y una DESCRIPCION con el detalle suficiente para que soporte entienda el problema. Si falta detalle, pide lo minimo necesario, una pregunta a la vez.\n3. Si el usuario adjunto archivos, veras notas como \"[El usuario adjunto foto.jpg]\". Reconocelos; se incluiran automaticamente en el ticket. No pidas que reenvien archivos ya adjuntados.\n4. Antes de crear el ticket, RESUME el asunto y la descripcion y pide confirmacion explicita (por ejemplo: \"Confirmo la creacion del ticket con estos datos?\").\n5. Solo cuando el usuario confirme, usa la herramienta create_ticket con subject y description. NUNCA la uses sin confirmacion.\n6. Tras crear el ticket, comunica el numero de ticket que devuelve la herramienta y despidete ofreciendo ayuda futura.\n\nReglas:\n- No inventes datos. No pidas ni manejes numeros de telefono, IDs ni datos internos: el sistema los anade solo.\n- Si el usuario cambia un dato, actualizalo antes de confirmar.\n- Si la herramienta create_ticket devuelve un error, disculpate y pide que lo intente de nuevo en unos minutos.\n- Se breve: mensajes cortos, sin formato Markdown pesado (WhatsApp es texto plano).",
        maxIterations: 10,
      },
    },
    subnodes: { model: chatModel, memory: chatMemory, tools: [createTicketTool] },
    position: [1000, -100],
  },
  output: [{ output: 'Listo, tu ticket #1042 fue creado. Quedo atento si necesitas algo mas.' }],
});

const sendReply = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Enviar Respuesta WhatsApp',
    onError: 'continueErrorOutput',
    parameters: {
      method: 'POST',
      url: expr("{{ $('Parse Data').item.json.sendUrl }}"),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpBearerAuth',
      sendBody: true,
      contentType: 'json',
      specifyBody: 'json',
      jsonBody: expr('{\n  "messaging_product": "whatsapp",\n  "recipient_type": "individual",\n  "to": "{{ $(\'Parse Data\').item.json.phoneNumber }}",\n  "type": "text",\n  "text": { "preview_url": false, "body": {{ JSON.stringify($json.output) }} }\n}'),
    },
    credentials: { httpBearerAuth: newCredential('Meta WhatsApp Bearer') },
    position: [1300, -100],
  },
  output: [{ messages: [{ id: 'wamid.out' }] }],
});

const cleanupAttachments = node({
  type: 'n8n-nodes-base.redis',
  version: 1,
  config: {
    name: 'Limpiar Adjuntos',
    parameters: { operation: 'delete', key: expr("mesadeayuda:att:{{ $('Parse Data').item.json.phoneNumber }}") },
    credentials: { redis: newCredential('Redis') },
    position: [1500, -100],
  },
  output: [{}],
});

const unlock = node({
  type: 'n8n-nodes-base.redis',
  version: 1,
  config: {
    name: 'Liberar Lock',
    parameters: { operation: 'delete', key: expr("mesadeayuda:lock:{{ $('Parse Data').item.json.phoneNumber }}") },
    credentials: { redis: newCredential('Redis') },
    position: [1700, -100],
  },
  output: [{}],
});

const sendError = node({
  type: 'n8n-nodes-base.httpRequest',
  version: 4.4,
  config: {
    name: 'Notificar Error al Usuario',
    parameters: {
      method: 'POST',
      url: expr("{{ $('Parse Data').item.json.sendUrl }}"),
      authentication: 'genericCredentialType',
      genericAuthType: 'httpBearerAuth',
      sendBody: true,
      contentType: 'json',
      specifyBody: 'json',
      jsonBody: expr('{\n  "messaging_product": "whatsapp",\n  "recipient_type": "individual",\n  "to": "{{ $(\'Parse Data\').item.json.phoneNumber }}",\n  "type": "text",\n  "text": { "preview_url": false, "body": "Ups, tuvimos un problema procesando tu solicitud. Por favor reintenta en unos minutos. Si persiste, contacta a soporte." }\n}'),
    },
    credentials: { httpBearerAuth: newCredential('Meta WhatsApp Bearer') },
    position: [1500, 150],
  },
  output: [{ messages: [{ id: 'wamid.err' }] }],
});

const unlockOnError = node({
  type: 'n8n-nodes-base.redis',
  version: 1,
  config: {
    name: 'Liberar Lock (error)',
    parameters: { operation: 'delete', key: expr("mesadeayuda:lock:{{ $('Parse Data').item.json.phoneNumber }}") },
    credentials: { redis: newCredential('Redis') },
    position: [1700, 150],
  },
  output: [{}],
});

export default workflow('mesa-ayuda-whatsapp-bot', 'Mesa de Ayuda - WhatsApp Bot')
  .add(waTrigger)
  .to(parseData)
  .to(lockGet)
  .to(
    ifLockFree
      .onTrue(
        lockSet.to(
          ifHasAttachment
            .onTrue(getMediaUrl.to(getMediaBinary).to(encodeBase64).to(pushAttachment).to(attachmentMerge.input(0)))
            .onFalse(attachmentMerge.input(1)),
        ),
      )
      .onFalse(sendProcessing),
  )
  .add(attachmentMerge)
  .to(loadAttachments)
  .to(parseAttachments)
  .to(aiAgent)
  .to(sendReply.onError(sendError.to(unlockOnError)))
  .to(cleanupAttachments)
  .to(unlock);
