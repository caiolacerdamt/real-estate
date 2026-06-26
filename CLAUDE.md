# Oi Digital Media

## O que é
Empresa onde o Caio trabalha como estagiário de AI/automação. Os projetos aqui são automações, agentes e ferramentas desenvolvidas pra uso interno da empresa ou para os clientes deles.

## Tipo
Empregador (estágio)

## Contato
- **Chefe:** Rafa Carmona

## Ferramentas usadas
N8n, Claude API, e demais ferramentas de automação e IA

## Projetos

### qualificacao-leads-real-estate
Automação pra qualificar uma lista de 500 leads do mercado de real estate nos EUA.

**O que faz:**
- Lê a planilha de leads (a ser compartilhada)
- Entra no LinkedIn de cada lead e analisa frequência de posts, número de seguidores e se o perfil é estruturado
- Entra no Instagram, se existir, e analisa os mesmos critérios
- Atribui uma nota/classificação pra cada lead com base nessas informações

**Status:** em planejamento — planilha de leads ainda não recebida

**Arquivos:**
- (planilha de leads será adicionada aqui quando recebida)

## CRM multi-pipeline (real-estate/)

### Arquitetura
- **Frontend:** `index.html` — HTML + Tailwind CDN + JS vanilla
- **Backend:** `api.php` — PHP puro, sem framework
- **Storage híbrido:**
  - `real_estate` → arquivo `data.json` no SiteGround (nunca tocar)
  - Outros pipelines → tabelas `pipelines` + `leads` no Neon (`nameless-cloud-33492738`)
- **Deploy:** GitHub Actions → FTP pro SiteGround em todo push na `main`

### Regra absoluta
`real_estate` e seu `data.json` são intocáveis. O driver de arquivo do real_estate não pode mudar.

### Como adicionar um pipeline novo
1. Publicar a(s) aba(s) da planilha Google como CSV (aba de contato primeiro)
2. Rodar no Neon (projectId `nameless-cloud-33492738`):
```sql
insert into pipelines (key, name, statuses, board_statuses, sheet_urls, supports_sync, supports_delete)
values (
  'chave_do_pipeline',
  'Nome do Pipeline',
  '["Novo","Contatado","Fechado"]',
  '["Novo","Contatado"]',           -- subset visível no board (ou null = todos)
  '["https://url_do_csv_publicado"]',
  true, true
);
```
3. (Opcional) Ajustar `display` para customizar como o card mostra os campos:
```sql
update pipelines set display = '{"title_keys":["Nome"],"subtitle_keys":["Email"]}' where key = 'chave_do_pipeline';
```
4. Chamar `action=sync` no frontend ou esperar o auto-sync (5 min). Pronto, sem código.

### Variável de ambiente necessária no SiteGround
`NEON_DATABASE_URL` — connection string pooled do Neon com `?sslmode=require`

## Regras específicas
- Salvar cada projeto como subpasta dentro de `clientes/oi-digital-media/`
- Documentar o status de cada projeto nesse CLAUDE.md conforme avança
