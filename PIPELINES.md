# CRM Multi-Pipeline — Referência Operacional

## Pipelines ativos

| Key | Nome | Planilha |
|-----|------|----------|
| `real_estate` | Real Estate | `data.json` (SiteGround, intocável) |
| `lancamento_maio_2026` | Lançamento Maio 2026 | AMERICANS-(MAI/JUN)-2026 · Dados Iniciais |
| `brazilians_mai_jun_2026` | BRAZILIANS (MAI/JUN) 2026 | BRAZILIANS-(MAI/JUN)-2026 · Forms 1 |
| `bsls_jun_2026` | BSLS (JUN) 2026 | BSLS-(JUN)-2026 · Pesquisa |

---

## Adicionar um pipeline novo

### 1. Publicar a aba do Google Sheets como CSV
`Arquivo → Compartilhar → Publicar na web → seleciona a aba → CSV → Publicar`

Copiar a URL gerada (formato `https://docs.google.com/spreadsheets/d/e/…/pub?gid=0&…&output=csv`).

### 2. INSERT no Neon (projeto `nameless-cloud-33492738`, banco `neondb`)

```sql
INSERT INTO pipelines (key, name, statuses, board_statuses, sheet_urls, supports_sync, supports_delete, display)
VALUES (
  'chave_do_pipeline',
  'Nome Exibido',
  '["Novo","Contatado","Respondeu","Qualificado","Call Marcada","Fechado","Descartado"]',
  '["Novo","Contatado","Respondeu","Qualificado","Call Marcada"]',
  '["https://url_do_csv_publicado"]',
  true, true,
  '{
    "title_keys": ["Nome"],
    "subtitle_keys": ["Email"],
    "field_map": {
      "Number": "Nome da coluna de telefone na planilha",
      "Padronized Number": "Nome da coluna de telefone padronizado",
      "DateTime": "Nome da coluna de data"
    }
  }'::jsonb
);
```

Depois disso: recarregar o CRM → Sincronizar agora. **Sem deploy, sem código.**

---

## field_map — quando usar

Cada pipeline pode ter colunas com nomes diferentes. O `field_map` no `display` traduz os nomes internos do CRM para o nome real na planilha.

| Nome interno (CRM) | Exemplos de nome real na planilha |
|--------------------|-----------------------------------|
| `Nome` | `Nome`, `Qual é o seu nome?` |
| `Number` | `Number`, `Número`, `Whatsapp` |
| `Padronized Number` | `Padronized Number`, `Número Padronizado`, `Padronized Whatsapp` |
| `DateTime` | `DateTime`, `Date & Time`, `Data de Entrada` |
| `Score AI` | geralmente já é `Score AI` |

Se a planilha já usa os nomes internos (ex.: `Nome`, `Email`), não precisa mapeá-los.

### Atualizar field_map de um pipeline existente

```sql
UPDATE pipelines
SET display = '{
  "title_keys": ["Nome da coluna de nome"],
  "subtitle_keys": ["Email"],
  "field_map": {
    "Number": "Coluna telefone",
    "Padronized Number": "Coluna telefone padronizado",
    "DateTime": "Coluna data"
  }
}'::jsonb
WHERE key = 'chave_do_pipeline';
```

---

## Outros comandos úteis

```sql
-- Listar todos os pipelines
SELECT key, name, sheet_urls FROM pipelines;

-- Renomear um pipeline
UPDATE pipelines SET name = 'Novo Nome' WHERE key = 'chave_do_pipeline';

-- Trocar ou adicionar URL de aba (array JSON)
UPDATE pipelines SET sheet_urls = '["https://nova_url"]' WHERE key = 'chave_do_pipeline';

-- Ver leads de um pipeline
SELECT lead_id, status, data->>'Nome' AS nome FROM leads WHERE pipeline_key = 'chave_do_pipeline';

-- Deletar todos os leads de um pipeline (para re-sync limpo)
DELETE FROM leads WHERE pipeline_key = 'chave_do_pipeline';
```

---

## Arquitetura resumida

- **Frontend:** `index.html` — Tailwind CDN + JS vanilla
- **Backend:** `api.php` — PHP puro, sem framework
- **`real_estate`** → driver arquivo (`data.json` no SiteGround). **Nunca alterar.**
- **Outros pipelines** → driver Neon (HTTP SQL API via `file_get_contents`)
- **Credencial:** `NEON_DATABASE_URL` como GitHub Secret → gera `config.php` no deploy
- **Deploy:** push na `main` → GitHub Actions → FTP pro SiteGround
