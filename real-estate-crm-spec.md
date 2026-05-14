# Spec completa: Real Estate Lead CRM — Oi Digital Media

## Contexto

Dashboard web estilo CRM pra gestão de leads de real estate (mercado de luxo, EUA). O gestor precisa visualizar em qual etapa do pipeline cada lead está, e editar status e observações sem abrir planilha.

---

## Stack

- **Frontend:** HTML5 + Tailwind CSS (via CDN) + Vanilla JS (sem frameworks)
- **Backend:** 1 arquivo `api.php` (PHP 7.4+)
- **Armazenamento:** `data.json` no servidor (gerado no primeiro import do CSV)
- **Hospedagem:** SiteGround (shared hosting com PHP)
- **Acesso:** URL pública, sem autenticação

---

## Estrutura de arquivos

```
real-estate-crm/
├── index.html       → Frontend completo
├── api.php          → Backend (todos os endpoints)
└── data.json        → Gerado automaticamente no primeiro import
```

Não há dependências externas além do Tailwind CDN.

---

## Schema do data.json

```json
{
  "leads": [
    {
      "Lead_ID": "lead_0001",
      "Name": "Erik Brown",
      "Type": "Individual Agent",
      "Phone": "+1 424-333-6697",
      "Email": "erik@compass.com",
      "Website": "https://erikbrown.com",
      "Instagram": "https://instagram.com/erikbrown",
      "Linkedin": "https://linkedin.com/in/erikbrown",
      "Observação": "",
      "Score_Fase1": "100",
      "Tier_Fase1": "A",
      "Recommended_Angle": "Personal Brand Authority",
      "IG_Followers": "45000",
      "IG_PostCount": "320",
      "IG_Posts_30d": "8",
      "IG_Last_Post_Days": "3",
      "IG_Activity": "High",
      "LI_Followers": "1200",
      "LI_Connections": "500+",
      "LI_Headline": "Luxury Real Estate Agent at Compass",
      "LI_Company": "Compass",
      "Website_Active": "Yes",
      "Website_Summary": "Personal branding site with listings",
      "Gender": "Male",
      "Brand_Score": "92",
      "Approach_Type": "Content Partnership",
      "New_Tier": "A",
      "Website_Match": "Yes",
      "Short_Note": "High-profile agent with strong personal brand",
      "First_Message": "Hey Erik, vi seu trabalho no Instagram...",
      "Status": "Novo",
      "Internal_Notes": ""
    }
  ],
  "last_updated": "2026-05-13T12:00:00Z"
}
```

**Notas sobre o schema:**
- Todos os campos do CSV vêm como string (não converter tipos)
- `Status` e `Internal_Notes` são os únicos campos não presentes no CSV original — são adicionados pelo sistema
- `Status` default na importação = `"Novo"`
- `Internal_Notes` default = `""`
- `last_updated` atualizado em cada write

---

## API PHP — Endpoints

### GET /api.php?action=get_leads
Retorna todos os leads.

**Response:**
```json
{
  "success": true,
  "leads": [...],
  "last_updated": "2026-05-13T12:00:00Z"
}
```

---

### POST /api.php?action=update_lead
Atualiza campos editáveis de um lead.

**Request body (JSON):**
```json
{
  "Lead_ID": "lead_0001",
  "Status": "Contatado",
  "Observação": "Já tem contrato com outra agência",
  "Internal_Notes": "Ligar na quinta"
}
```

Só os campos `Status`, `Observação`, e `Internal_Notes` são aceitos neste endpoint (ignorar qualquer outro campo enviado).

**Response:**
```json
{ "success": true }
```

---

### POST /api.php?action=import
Recebe o CSV via multipart/form-data, parseia e salva como data.json.

- Aceita arquivo CSV com encoding UTF-8
- Separador: vírgula
- Primeira linha = headers
- Campos do CSV esperados (na ordem ou por nome de coluna):
  `Lead_ID, Name, Type, Phone, Email, Website, Instagram, Linkedin, Observação, Score_Fase1, Tier_Fase1, Recommended_Angle, IG_Followers, IG_PostCount, IG_Posts_30d, IG_Last_Post_Days, IG_Activity, LI_Followers, LI_Connections, LI_Headline, LI_Company, Website_Active, Website_Summary, Gender, Brand_Score, Approach_Type, New_Tier, Website_Match, Short_Note, First_Message`
- Se `data.json` já existir: MERGE por `Lead_ID`. Leads existentes mantêm `Status`, `Observação` e `Internal_Notes`. Novos leads entram com `Status = "Novo"`.
- Se `data.json` não existir: cria do zero com todos os leads tendo `Status = "Novo"`.

**Response:**
```json
{
  "success": true,
  "imported": 583,
  "updated": 0,
  "new": 583
}
```

---

## Frontend — Layout e comportamento

### Estrutura visual geral

```
┌─────────────────────────────────────────────────────────────────┐
│  HEADER                                                         │
│  [Logo/Título "Real Estate CRM"]   [Importar CSV] [Contador]   │
├─────────────────────────────────────────────────────────────────┤
│  FILTROS                                                        │
│  [🔍 Buscar por nome...]  [Tier ▼]  [Approach ▼]  [Limpar]    │
├─────────────────────────────────────────────────────────────────┤
│  KANBAN BOARD (scroll horizontal)                               │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ...      │
│  │  Novo    │ │Contatado │ │Respondeu │ │Em negocia│          │
│  │  (n)     │ │  (n)     │ │   (n)    │ │   (n)   │           │
│  ├──────────┤ ├──────────┤ ├──────────┤ ├──────────┤           │
│  │  [card] │ │  [card]  │ │  [card]  │ │  [card] │           │
│  │  [card] │ │  [card]  │ │          │ │         │            │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘           │
└─────────────────────────────────────────────────────────────────┘
```

**Colunas do Kanban (nessa ordem):**
1. Novo
2. Contatado
3. Respondeu
4. Em negociação
5. Fechado
6. Descartado

---

### Card do lead

```
┌─────────────────────────┐
│ [A]  Erik Brown         │  ← Badge Tier (A=verde, B=amarelo, C=cinza)
│ Individual Agent · Male │
│ ─────────────────────── │
│ Brand Score: 92         │
│ Approach: Content Part. │
│ ─────────────────────── │
│ High-profile agent...   │  ← Short_Note (truncado em 2 linhas)
└─────────────────────────┘
```

- Clique no card = abre modal de detalhe
- Sem drag and drop (o status é mudado pelo dropdown dentro do modal — mais simples e mobile-friendly)
- Cor da borda lateral esquerda do card por Tier: A=verde (#22c55e), B=amarelo (#eab308), C=cinza (#9ca3af)

---

### Modal de detalhe (abre ao clicar no card)

```
┌────────────────────────────────────────────────────────┐
│  Erik Brown                              [X fechar]    │
│  Individual Agent · Male · New Tier: A                 │
├────────────────────────────────────────────────────────┤
│  STATUS  [dropdown: Novo/Contatado/...]  [Salvar]      │
├──────────────────────┬─────────────────────────────────┤
│  CONTATO             │  REDES SOCIAIS                  │
│  📞 +1 424-...       │  Instagram: @erikbrown (45k)    │
│  📧 erik@...         │  Posts/30d: 8 | Activity: High  │
│  🌐 erikbrown.com    │  ──────────────────────────     │
│                      │  LinkedIn: 1.2k followers       │
│                      │  Compass | Luxury Agent         │
├──────────────────────┴─────────────────────────────────┤
│  QUALIFICAÇÃO                                          │
│  Score Fase1: 100 | Tier Fase1: A | Brand Score: 92   │
│  Angle: Personal Brand Authority                       │
│  Approach: Content Partnership                         │
│  Website: erikbrown.com ✓ (match: Yes)                 │
│  Website Summary: Personal branding site...            │
├────────────────────────────────────────────────────────┤
│  PRIMEIRA MENSAGEM                          [Copiar]   │
│  ┌──────────────────────────────────────────────────┐ │
│  │ Hey Erik, vi seu trabalho no Instagram...        │ │
│  └──────────────────────────────────────────────────┘ │
├────────────────────────────────────────────────────────┤
│  OBSERVAÇÃO (campo editável do CSV)                    │
│  [textarea editável]                  [Salvar]         │
├────────────────────────────────────────────────────────┤
│  NOTAS INTERNAS (campo novo, não vem do CSV)           │
│  [textarea editável]                  [Salvar]         │
└────────────────────────────────────────────────────────┘
```

- Campos editáveis: Status (dropdown), Observação (textarea), Internal_Notes (textarea)
- Cada campo tem seu próprio botão Salvar — faz POST pra `/api.php?action=update_lead`
- Campos não-editáveis são só read-only text
- Links de Instagram, LinkedIn e Website abrem em nova aba
- Botão Copiar copia o First_Message pro clipboard

---

### Barra de filtros

- **Busca por nome:** filtra em tempo real nos leads do kanban (não faz request, filtra os dados já carregados)
- **Filtro Tier:** dropdown com opções All, A, B, C — filtra por `New_Tier`
- **Filtro Approach:** dropdown populado dinamicamente com os valores únicos de `Approach_Type` do dataset
- **Botão Limpar:** reseta todos os filtros
- Filtros se combinam (AND)

---

### Tela de import (estado inicial — quando data.json não existe ou está vazio)

```
┌────────────────────────────────────────────┐
│                                            │
│   📋 Nenhum lead importado ainda          │
│                                            │
│   [Selecionar CSV] ← input file           │
│   [Importar]                               │
│                                            │
│   Formato esperado: CSV com headers na    │
│   primeira linha, separador vírgula.       │
│                                            │
└────────────────────────────────────────────┘
```

Após import bem-sucedido: recarrega a página e mostra o kanban.

O botão "Importar CSV" também fica disponível no header do kanban (pra reimports futuros).

---

## Comportamento do PHP (api.php)

- Headers CORS não necessários (mesmo domínio)
- Content-Type: application/json em todas as responses
- Ler/escrever data.json com `file_get_contents` / `file_put_contents`
- Usar `json_encode` com `JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`
- Em caso de erro: `{ "success": false, "error": "mensagem" }`
- Não usar banco de dados, sem dependências externas

---

## Comportamento do JavaScript (index.html)

- Na inicialização: fetch GET `/api.php?action=get_leads`
- Se response vazia ou leads array vazio: mostrar tela de import
- Se tem leads: renderizar kanban
- Atualização de status/notas: fetch POST `/api.php?action=update_lead` com body JSON
- Após salvar: atualizar o card na memória (sem reload de página)
- Import: form com enctype multipart/form-data para `/api.php?action=import`, mostrar loading, após sucesso reload

---

## Design visual

- Fundo: cinza escuro (#111827 = gray-900 do Tailwind)
- Cards: fundo branco ou gray-800 (dark mode opcional — pode ir com dark por padrão)
- Fonte: padrão do sistema (sans-serif)
- Cores de Tier: A = green-500, B = yellow-500, C = gray-400
- Modal: overlay com backdrop blur

---

## Deploy no SiteGround

1. Subir os 3 arquivos (`index.html`, `api.php`, e uma `data.json` vazia `{"leads":[], "last_updated":""}`) via FTP ou File Manager
2. Garantir que a pasta tem permissão de escrita para o PHP escrever o `data.json`
3. Acessar via URL e importar o CSV pela interface

---

## Checklist de verificação (pra quem implementar)

- [ ] Importar CSV de teste com 5 leads e confirmar que aparecem no kanban
- [ ] Reimportar o CSV — leads existentes mantêm o Status que foi editado
- [ ] Abrir modal, mudar status de "Novo" para "Contatado", salvar, fechar e confirmar que mudou
- [ ] Editar Observação e Internal_Notes e confirmar que salva
- [ ] Filtrar por Tier A e confirmar que só aparecem leads A
- [ ] Buscar por nome parcial e confirmar que funciona
- [ ] Clicar em Copiar na First_Message e confirmar que copia pro clipboard
- [ ] Abrir em outro browser e confirmar que as edições feitas no primeiro browser aparecem
