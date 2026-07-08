# PROGRESS — Timevault Migration & Backups

> **Propósito deste arquivo:** registro de progresso e handoff entre sessões de Claude Code.
> Se você é uma nova sessão: leia primeiro o [CLAUDE.md](CLAUDE.md) (documento mestre — arquitetura,
> requisitos de segurança e roteiro de prompts P0–P7), depois este arquivo para saber onde paramos.
> **Ao concluir qualquer fase ou decisão relevante, atualize este arquivo.**

**Última atualização:** 2026-07-08
**Fase atual:** ✅ **TODAS as fases (P0–P7) concluídas e VALIDADAS.** Roteiro do brief completo.
**Versão atual do plugin:** 0.6.0 · **Schema DB:** v2
**Git:** repositório em https://github.com/PedroAgostini/TIMEVAULT-MIGRATION.git (branch `main`)

## Como rodar os testes (P7)

- Banco de testes descartável: `timevault_tests` (MySQL do Laragon, root sem senha).
- Config: `tests/wp-tests-config.php` (aponta ABSPATH p/ `C:/laragon/www/timevault-test/` e usa a
  constante `TIMEVAULT_ENCRYPTION_KEY` + `TIMEVAULT_BACKUP_DIR` no temp).
- Rodar (PowerShell):
  ```
  $phpDir='C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64'
  $env:PATH="$phpDir;$env:PATH"; $env:WP_PHP_BINARY="$phpDir\php.exe"
  $env:WP_CORE_DIR='C:/laragon/www/timevault-test/'
  & "$phpDir\php.exe" vendor\phpunit\phpunit\phpunit
  ```
- **Importante:** `php` precisa estar no PATH (o WP test suite chama `WP_PHP_BINARY` p/ instalar).
  O wp-phpunit lê `WP_TESTS_CONFIG_FILE_PATH` como **constante** (definida em `tests/bootstrap.php`).
- Resultado atual: **57 testes, 111 asserções, 100% verdes.**

## Ambiente de teste local (Laragon) — configurado em 2026-07-07

- **Laragon** em `C:\laragon` (PHP 8.3.30, MySQL 8.4.3, Composer embutidos). Apache + MySQL rodando.
- **PHP CLI:** `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`. O `php.ini` foi criado a
  partir do template `php.ini-development` com estas extensões habilitadas: zip, sodium, openssl,
  mysqli, curl, mbstring, gd. **Atenção:** o `zip` exigiu correção manual (o template usa formato
  de linha diferente); confirme com `php -r "var_dump(class_exists('ZipArchive'));"`.
- **WP-CLI:** baixado em `C:\laragon\bin\wp-cli.phar`, com wrapper `C:\laragon\bin\wp.cmd`.
- **Site de teste:** `C:\laragon\www\timevault-test` (WordPress 6.6 pt_BR). URL:
  `http://localhost/timevault-test`. Admin: `admin` / `admin123`. Banco: `timevault_test`
  (root, sem senha, host 127.0.0.1). REST usa `?rest_route=` (permalinks plain, sem mod_rewrite).
- **Plugin:** junction `wp-content/plugins/timevault` → a pasta do projeto (edições refletem direto).
- `TIMEVAULT_ENCRYPTION_KEY` e `WP_DEBUG=true` definidas no wp-config.php do site de teste.
- **Rodar o pipeline** (Action Scheduler não roda sozinho no CLI): agendar via
  `\Timevault\Plugin::instance()->backups()->schedule('db'|'full', ...)` e processar com
  `wp.cmd action-scheduler run` (as 3 etapas encadeadas fecham em 1 pass).

### Resultado da validação de runtime (2026-07-07)

Toolchain: `composer install` OK, `php -l` limpo (29/29), **phpcs 100% limpo** (0/0).
Pipeline testado ponta a ponta num WordPress real:

- ✅ Ativação: 2 tabelas criadas, schema_version=1, sufixo aleatório do diretório, hardening
  (.htaccess/index.php/web.config) presentes.
- ✅ Backup **db**: pipeline assíncrono das 3 etapas (ações encadeadas no Action Scheduler)
  → `.zip.enc` cifrado, `is_encrypted=1`, checksum registrado, auditoria `backup_completed`.
- ✅ **Round-trip de criptografia**: checksum confere; decrypt OK; ZIP contém `database.sql`
  (com CREATE TABLE) + `manifest.json`; manifest confirma `wp_config_excluded:true` e
  `serialization:json`; work dir limpo (0 sobras).
- ✅ Backup **full**: 12,3 MB, 413 arquivos empacotados; **wp-config.php NÃO incluído**; nenhuma
  entrada com `..`; manifest presente.
- ✅ **Download via HTTP**: token HMAC emitido (capability-gated), download só com o token
  (sem header de auth) entrega o arquivo correto; **gate de checksum confere**.
- ✅ Segurança negativa: request sem auth → 401; token adulterado → 401.
- ⚠️ Basic auth de Application Password via HTTP retorna 401 no Laragon (Apache strip do header
  `Authorization`) — é config do ambiente, não do plugin; o bloqueio 401 sem auth prova o
  permission_callback. Para testar endpoints cookie/basic no futuro, adicionar
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` ao .htaccess do site.

---

## Ajustes de UX pós-P6 (2026-07-08, segunda rodada de feedback)

Pedidos do usuário implementados:
- **Excluir backups**: `DELETE /backups/{uuid}` (apaga artefato + registro, auditado) +
  `BackupRepository::delete()`; botão "Excluir" com modal de confirmação em cada backup.
- **Tema claro por padrão + alternável para escuro**: CSS reescrito com tokens de cor por tema
  (claro é o default; `.timevault-app[data-theme="dark"]` restaura o escuro). Novo token
  `--tv-amber-text` (âmbar legível como texto no claro, ~4.7:1) usado nos contextos de texto.
  **Logo inverte para branca no tema escuro** (`filter: brightness(0) invert(1)`). Toggle sol/lua
  no header, persistido em localStorage.
- **Idioma selecionável (PT-BR / EN / ES)**: dicionário i18n completo no JS, seletor no header,
  persistido. Padrão PT-BR.
- **Exportação inicia download automático**: ao clicar "Gerar exportação", agenda → faz polling do
  status → quando `completed`, emite token e inicia o download automaticamente.
- **Exportação geral por padrão**: seletor de escopo "Tudo (banco + arquivos)" (default) ou
  "Selecionar tabelas".
- **Espinha temporal paginada de 4 em 4** com ordenação (mais recentes ⇄ mais antigos) e pager.
- Testes: +1 (delete) → **60 testes verdes**. phpcs limpo; delete/overview validados em runtime.

## Ajuste pós-P6 (2026-07-08) — abas Exportar e Importar

O usuário apontou (com razão) que faltavam as abas de **Exportar** e **Importar** no dashboard.
- **Exportar**: o backend (`/exports`) já existia; faltava só a UI. Adicionada aba com seleção de
  tabelas (novo `GET /exports/tables`), incluir uploads e anonimizar.
- **Importar (migração)**: era lacuna também no backend. Adicionado:
  - `ImportManager::import_uploaded_package()` — armazena o upload no diretório protegido, valida
    como entrada hostil (checksum, decifra com a chave DESTE site, valida entradas, manifest JSON)
    e registra como backup `completed` marcado `imported`; **nunca auto-restaura**. Rejeita arquivo
    inválido sem deixar lixo no registro/disco.
  - `ImportController` — `POST /import` (multipart `package`), com `is_uploaded_file` real na borda,
    rate limiting e validação de extensão (.zip/.enc).
  - Aba de upload no dashboard (com barra de progresso via XHR e aviso sobre a chave de criptografia
    precisar ser a mesma do site de origem).
- Testes: +2 (import registra / rejeita lixo) → **59 testes verdes**. phpcs limpo. Endpoints
  validados em runtime (import registra, checksum confere, aceito pelo restore; `/exports/tables` 200).

## O que o P6 entregou (2026-07-08) — UI/UX (dashboard admin)

Dashboard admin completo seguindo o `TIMEVAULT-DESIGN-SYSTEM.md` (preto + âmbar `#ffa300` +
glassmorphism, Plus Jakarta Sans + JetBrains Mono, a "Espinha Temporal" como assinatura).

| Arquivo | Papel |
|---------|-------|
| `assets/css/timevault-admin.css` | Design system completo em CSS, escopado sob `.timevault-app` (não vaza pro wp-admin). Tokens, glass, botões, badges, Espinha Temporal, progresso, tabela, modal, toasts, estados vazios. A11y: foco âmbar 2px, `prefers-reduced-motion`, texto preto sobre âmbar. |
| `assets/js/timevault-admin.js` | App vanilla JS (sem build/CDN). Renderiza via `textContent` (anti-XSS). Carrega `/overview` + `/backups` + `/restores`; cards de status; Espinha Temporal (nó "agora" pulsando); histórico com filtros; **polling de 3s** enquanto há jobs ativos; **modal de dupla confirmação** (digitar `RESTORE` + checkbox de arquivos + nota do backup de segurança); toasts; download via token. |
| `assets/fonts/README.md` | Como ativar as fontes de marca locais (sem CDN — privacy/LGPD); cai pra stack do sistema sem elas. |
| `src/Admin/AdminMenu.php` | Enfileira os assets só na tela do plugin; localiza `TimevaultConfig` (root REST + nonce + logo); renderiza o shell do app com fallback no-JS/loading (checklist de saúde SSR). |
| `src/Rest/StatusController.php` | Novo `GET /overview` — agrega último backup, totais, espaço usado, jobs ativos, próxima manutenção, retenção. |

**Preview visual publicado** (Artifact) com o CSS real + estado populado, incluindo o modal aberto.
**Nota:** os `.woff2` da marca não estão no bundle (não os tenho); a UI usa a stack do sistema até
serem adicionados (instruções em `assets/fonts/README.md`). Validado: `/overview` retorna 200, o
shell renderiza sem fatal, assets servidos (HTTP 200), JS com sintaxe válida (node --check),
phpcs limpo, 57 testes verdes. **Falta apenas a verificação visual final no wp-admin logado** (fazer
no navegador). Versão 0.6.0.

## O que o P7 entregou (2026-07-08) — Testes PHPUnit

Suíte com `wp-phpunit` (WP test suite via Composer). **57 testes, 111 asserções, 100% verdes.**
Prioridade em import/restore (superfície mais crítica). Arquivos em `tests/`:

| Arquivo | Cobre |
|---------|-------|
| `tests/bootstrap.php` + `tests/wp-tests-config.php` + `phpunit.xml.dist` | Infra do WP test suite + banco `timevault_tests`. |
| `Restore/PathGuardTest.php` | 9 nomes maliciosos (traversal, absoluto, drive letter, backslash, NUL, fora de prefixo, vazio) + 5 legítimos + contenção de `safe_target`. |
| `Restore/SqlImporterTest.php` | Execução/contagem, `;` dentro de string não divide, **INTO OUTFILE aborta**, LOAD_FILE em dados não é falso positivo, **tabelas próprias puladas**, dump corrompido → erro. |
| `Restore/ArchiveInspectorTest.php` | **Checksum inválido**, checksum ausente, **ZIP com zip-slip recusado** (inspect + extract), manifest ausente, manifest não-JSON (serializado), pacote válido aceito. |
| `Core/EncryptionServiceTest.php` | Round-trip multi-chunk, **adulteração falha fechada** (sem plaintext parcial), **truncamento rejeitado**, string round-trip/adulteração. |
| `Storage/LocalAdapterTest.php` | store/retrieve/delete, nomes maliciosos, fonte ausente, exclui hardening, **S3 sem SDK → `timevault_sdk_missing`** (credencial/SDK indisponível). |
| `Privacy/AnonymizerTest.php` | Mascara colunas de user, **determinístico**, meta_keys conhecidas, colunas desconhecidas intactas. |
| `Core/BackupManagerTest.php` | `run_now('db')` completa com artefato válido (checksum confere, decifra, manifest ok); storage/tipo inválidos rejeitados. |
| `Core/ImportManagerTest.php` | `validate_package` happy path, backup inexistente, **artefato adulterado → checksum error**. |

**Nota de escopo:** o restore destrutivo completo (restore_db faz DDL que dá commit implícito e
quebraria o isolamento transacional do teste) é coberto pelo teste de runtime num site real
(documentado no P2), não pela suíte automatizada — para manter o banco de testes íntegro.

## O que o P5 entregou (2026-07-07) — PrivacyService / LGPD

### Novos componentes

| Arquivo | Papel |
|---------|-------|
| `src/Privacy/Anonymizer.php` | Pseudonimização de dados pessoais em exports staging/dev. Expõe um **transformer de linha** aplicado pelo dumper na origem (nunca reescreve SQL). Mascaramento determinístico via HMAC(`wp_salt`) por site — mesmo valor → mesmo mascarado (preserva joins/unicidade). Cobre `users`, `comments` e meta_keys conhecidas (first_name, phone, endereço…). |
| `src/Core/PrivacyService.php` | Política de retenção (get/set), varredura `apply_retention_policy` (expira por idade/quantidade com piso `min_keep`, apaga artefato e marca `expired`), e `get_processing_record` (registro de tratamento LGPD). |
| `src/Rest/PrivacyController.php` | `GET /privacy/processing-record`, `GET/POST /privacy/retention`, `POST /privacy/retention/run`. |

### Alterações em arquivos existentes

- `DatabaseDumper`: novo `set_row_transformer()` — transforma cada linha estruturada antes de serializar.
- `BackupManager`: aplica o transformer quando `options['anonymize']`; manifest ganha `privacy.anonymized`.
- `ExportManager`/`BackupsController`: opção `anonymize` no `POST /exports`.
- `Plugin`: serviço `privacy()`; hook `timevault_retention_sweep`; agenda a varredura diária **em
  `init`** (o data store do Action Scheduler só existe a partir de `init` — agendar em
  `plugins_loaded` dava "called too early" e não persistia). Fix aplicado.
- `uninstall.php`: remove options `timevault_retention`/`timevault_destinations`/`timevault_notify_email`.
- Versão 0.5.0.

### Validação de runtime do P5 (15/15)

- ✅ Anonimização: email/nome/URL/telefone **reais ausentes** do dump; valores mascarados
  presentes; determinístico; dump normal (controle) ainda contém o dado real.
- ✅ Retenção: 7 backups completos + política `max_count=2` → 5 expirados, 2 mantidos; piso respeitado.
- ✅ Registro de tratamento: personal_data, finalidades, destino local, telemetria=false, nota LGPD.
- ✅ Varredura diária agendada em `init` sem notice; phpcs 100% limpo.

## O que o P2 entregou (2026-07-07) — Import/Restore Engine (camada mais crítica)

### Novos componentes

| Arquivo | Papel |
|---------|-------|
| `src/Restore/PathGuard.php` | Defesa central contra zip-slip/path traversal. `validate_entry_name` rejeita `../`, path absoluto, drive letter, backslash, NUL, e nomes fora dos prefixos permitidos (`files/`, `uploads/`, `database.sql`, `manifest.json`). `safe_target` canonicaliza e revalida contenção no destino (defesa em profundidade). |
| `src/Restore/ArchiveInspector.php` | Verifica checksum SHA-256 **antes** de processar; decifra (autenticado); valida TODA entrada do ZIP; guarda contra zip-bomb (tamanho/razão); lê manifest como JSON (nunca unserialize). `extract_to_staging` streama cada entrada para alvo revalidado. |
| `src/Restore/SqlImporter.php` | Tokenizer de SQL que respeita strings (`\'`/`''`), backticks e comentários. Mantém versão "code" sem literais para classificação. Whitelist de statements; **aborta** em construções proibidas (`INTO OUTFILE`, `LOAD DATA`, `LOAD_FILE`, `DROP DATABASE`, `GRANT`…); executa via `$wpdb->query()` um a um (nunca eval/multi-statement). Pula tabelas próprias do Timevault e faz rewrite de prefixo só no identificador-alvo. |
| `src/Restore/RestoreRepository.php` | Persistência da tabela `timevault_restores` (schema v2). |
| `src/Restore/RateLimiter.php` | Rate limiter por usuário (transients). |
| `src/Core/ImportManager.php` | **Orquestrador.** Pipeline assíncrono de 6 etapas: `safety_backup → validate → extract → restore_db → restore_files → finalize`. Backup de segurança síncrono antes de sobrescrever; preserva options operacionais + tabelas próprias; `copy_tree` com contenção para restore de arquivos. |
| `src/Rest/RestoreController.php` | Dupla confirmação (`/restore/prepare` emite token HMAC + `/restore/confirm` exige frase `RESTORE` + token) + rate limiting; `/restores` e `/restores/{uuid}`. |

### Alterações em arquivos existentes

- `Activator`: schema **v2** — nova tabela `timevault_restores`. `maybe_upgrade` cria automaticamente.
- `BackupManager`: passos agora retornam o próximo (dirigível async **ou** síncrono); novo
  `run_now()` para o backup de segurança síncrono; exclui **todos** os dirs `timevault-*` do
  wp-content ao empacotar (evita varrer diretório de backup órfão).
- `Plugin`: serviços `restore_repository()`, `imports()`; hook `timevault_restore_step`.
- `uninstall.php`: dropa `timevault_restores`. Versão 0.4.0.

### Bugs REAIS encontrados e corrigidos durante o teste de runtime (importante!)

1. **Restore revertia o `timevault_dir_suffix`** (parte do wp_options) → o diretório de backup
   "se movia" e **orfanava todos os backups no meio do próprio restore**. Fix: `snapshot_bookkeeping`
   /`restore_bookkeeping` preservam suffix, schema_version e mantêm o plugin ativo (+ `wp_cache_flush`).
2. **Restore revertia as tabelas próprias do Timevault** (audit_log/backups/restores) → perdia a
   trilha de auditoria e o registro do backup de segurança. Fix: SqlImporter pula statements que
   miram essas tabelas (validado: 9 statements pulados = 3 tabelas × DROP/CREATE/INSERT).
3. **Falso positivo de `LOAD_FILE`**: o check de proibidos rodava no statement inteiro, batendo em
   dados. Fix: classificação roda na versão "code" (sem conteúdo de strings).
4. Ambiente: o Apache do Laragon precisava reiniciar para carregar o `zip` no PHP web (o Action
   Scheduler processa jobs via loopback HTTP ao Apache, não no CLI).

### Validação de runtime do P2 (WordPress real)

- ✅ Restore end-to-end: post apagado **volta** (mesmo ID); status `completed`.
- ✅ Backup de segurança automático criado **antes** de sobrescrever; registro sobrevive ao restore.
- ✅ Suffix/diretório de backup preservado; auditoria completa
  (`restore_scheduled → restore_safety_backup → restore_db_applied → restore_completed`).
- ✅ 12/12 testes adversariais: zip-slip, path absoluto, drive letter, backslash, NUL, fora de
  prefixo, `INTO OUTFILE` aborta, LOAD_FILE-em-dados não é falso positivo.
- ✅ 6/6 dupla confirmação: prepare emite token; frase errada → 400; token adulterado → erro;
  confirmação correta → 202.
- ✅ phpcs 100% limpo; `php -l` limpo (35 arquivos src).

## Status do roadmap

| Ordem | Fase | Descrição | Status |
|-------|------|-----------|--------|
| 1 | **P0** | Scaffolding e estrutura inicial | ✅ Concluído (2026-07-07) |
| 2 | **P1** | Core Engine: BackupManager + ExportManager | ✅ Concluído (2026-07-07) |
| 3 | **P3** | Storage Adapters (S3, Google Drive — Local já existe) | ✅ Concluído (2026-07-07) |
| 4 | **P2** | Import/Restore Engine (camada mais crítica) | ✅ Concluído (2026-07-07) |
| 5 | **P5** | PrivacyService / LGPD | ✅ Concluído (2026-07-07) |
| 6 | **P7** | Testes PHPUnit (57 testes, 100% verdes) | ✅ Concluído (2026-07-08) |
| 7 | **P4** | Auditoria de segurança — ver [SECURITY-REVIEW.md](SECURITY-REVIEW.md) | ✅ Concluído (2026-07-08) |
| 8 | **P6** | UI/UX — dashboard admin (design system aplicado) | ✅ Concluído (2026-07-08) |

---

## O que o P0 entregou (2026-07-07)

Scaffolding completo: `timevault.php` (bootstrap), Composer PSR-4, Activator (tabelas via dbDelta
+ capability + hardening do diretório), Deactivator conservador, `uninstall.php` com opt-in,
AdminMenu, REST `AbstractController`/`StatusController`, AuditLog append-only, interface de
storage + LocalAdapter, JobQueue (Action Scheduler), Capabilities e Paths. Detalhes: git/README.

### Banco de dados (schema version: 1, option `timevault_schema_version`)

- `{prefix}timevault_audit_log` — append-only: event_uuid, user_id, user_login, action,
  object_type, object_id, context (JSON), ip_hash, created_at
- `{prefix}timevault_backups` — registro de metadados: backup_uuid, type, status, storage,
  file_name, size_bytes, checksum_sha256, is_encrypted, created_by, created_at,
  completed_at, expires_at, meta (JSON)

## O que o P1 entregou (2026-07-07) — Core Engine de Backup/Export

### Novos componentes

| Arquivo | Papel |
|---------|-------|
| `src/Core/EncryptionService.php` | **Completo agora.** Criptografia streaming autenticada: libsodium secretstream (XChaCha20-Poly1305) primário; fallback OpenSSL AES-256-GCM em chunks com índice+flag final como AAD, chave por arquivo via HKDF. Formato próprio com magic `TVLT`. Falha fechada (apaga saída parcial). |
| `src/Core/DatabaseDumper.php` | Dump SQL streaming: tabelas validadas contra SHOW TABLES (whitelist), identificadores backtick-escaped, TODO valor via `$wpdb->prepare('%s')`, keyset pagination, filtro por prefixo WP (bancos compartilhados), sem serialização PHP. |
| `src/Core/FilePackager.php` | ZipArchive: symlinks nunca seguidos/adicionados, `wp-config.php` excluído em qualquer nível, diretório de backup/caches podados na iteração, `manifest.json` (JSON puro) embutido. |
| `src/Core/BackupRepository.php` | Persistência do registro de backups (whitelist de colunas atualizáveis, meta JSON). |
| `src/Core/BackupManager.php` | **Orquestrador.** Pipeline assíncrono em 3 etapas via Action Scheduler: `dump_db → package → finalize`. Sem fallback síncrono. Checksum SHA-256 do artefato FINAL (pós-criptografia). Auditoria de cada transição. Notificação e-mail opcional (option `timevault_notify_email`, default desligada). |
| `src/Core/ExportManager.php` | Export seletivo (tabelas + uploads) delegando ao mesmo pipeline; validação de shape aqui + whitelist real no dumper. |
| `src/Rest/BackupsController.php` | `GET/POST /timevault/v1/backups`, `GET /backups/{uuid}`, `POST /exports`. Retorna 202 + uuid; expõe só subset seguro da row (nunca meta interna/paths). |

### Alterações em arquivos existentes

- `src/Support/Paths.php`: + `working_dir()`, `ensure_working_dir()`, `delete_tree()` (este só
  deleta DENTRO do diretório de backup — recusa qualquer path fora, e nunca o próprio diretório).
- `src/Plugin.php`: novos serviços (`backup_repository()`, `backups()`, `exports()`), registro do
  hook `timevault_backup_step` e do BackupsController.
- `src/Admin/AdminMenu.php`: dashboard lista os 10 backups mais recentes.
- `timevault.php`: versão 0.2.0.

### Decisões técnicas do P1 (não regredir!)

- **Pipeline por etapas** (cada step é uma action separada) para evitar timeout; steps replayed
  de pipelines já `completed/failed` são ignorados (guarda de status).
- **Checksum calculado sobre o artefato final armazenado** (depois da criptografia) — permite
  validar integridade antes do restore sem decifrar.
- **Cifra primária é XChaCha20-Poly1305 (secretstream)**, não AES-GCM, quando libsodium existe:
  streaming autenticado nativo com proteção contra truncamento/reordenação. AES-256-GCM é o
  fallback OpenSSL (com AAD manual índice+final). Documentado como desvio consciente do brief.
- **Backup sem chave configurada é permitido mas auditado** (`backup_unencrypted`) — a release
  exige criptografia; decidir antes do release se vira erro duro.
- Dump filtra por `$wpdb->base_prefix` por padrão (option `all_tables` para incluir tudo).
- Artefato final: `timevault-{tipo}-{Ymd-His}-{uuid8}.zip[.enc]` no adapter local.
- Manifest JSON registra `wp_config_excluded: true`, prefixo do banco (para migração no P2),
  contagens de tabelas/linhas/arquivos.

## O que o P3 entregou (2026-07-07) — Storage Adapters + Download seguro

### Novos componentes

| Arquivo | Papel |
|---------|-------|
| `src/Storage/SafeName.php` | Validação de nome de arquivo compartilhada por todos os adapters (anti path-traversal na borda). |
| `src/Storage/DestinationSettings.php` | Config de destinos externos (option `timevault_destinations`, autoload off). Credenciais **sempre** cifradas via EncryptionService — sem chave configurada, **recusa** salvar (nunca plaintext). Habilitar exige credenciais já armazenadas (opt-in duplo). Toda mudança auditada com região (LGPD Art. 33). |
| `src/Storage/S3Adapter.php` | S3/compatível (MinIO, R2 via endpoint). SDK condicional (`composer require aws/aws-sdk-php` — está em `suggest`, não em `require`). Operações confinadas a bucket+prefix; chaves de objeto validadas contra o prefix configurado. |
| `src/Storage/GoogleDriveAdapter.php` | Drive via service account, escopo **`drive.file` apenas** (menor privilégio: só arquivos criados pelo plugin). Upload resumable em chunks de 8 MiB. Pasta dedicada (`folder_id`). SDK condicional (`google/apiclient` em `suggest`). |
| `src/Rest/DestinationsController.php` | `GET /destinations`, `POST/DELETE /destinations/{s3\|gdrive}`. Credenciais são **write-only**: aceitas no POST, nunca retornadas. |
| `src/Rest/DownloadController.php` | **Fecha item do checklist.** `POST /backups/{uuid}/download-token` (capability) emite token HMAC (wp_salt) com TTL de 5 min vinculado a um backup; `GET /download?token=` valida assinatura em tempo constante + expiração, **verifica o checksum SHA-256 antes de entregar**, audita emissão e download. Nunca link público direto. |

### Alterações em arquivos existentes

- `EncryptionService`: + `encrypt_string()`/`decrypt_string()` (secretbox sodium / AES-256-GCM)
  para credenciais em repouso.
- `BackupManager`: recebe registry de adapters; `options['storage']` selecionado no agendamento e
  validado contra os adapters registrados; `file_name` agora guarda o nome amigável e
  `meta.remote_id` o identificador remoto (key S3 / file id Drive); auditoria registra
  `storage_region` na conclusão.
- `LocalAdapter`: + `local_path()` (download local sem duplicar arquivo grande); `safe_name`
  delega ao SafeName.
- `Plugin`: `destination_settings()`; `storage_adapters()` monta adapters externos **somente**
  dos destinos habilitados; registra os dois novos controllers.
- `BackupsController`: parâmetro `storage` no POST /backups.
- `composer.json`: SDKs em `suggest` (autoload condicional — instalar só onde usar).
- Versão 0.3.0.

### Decisões do P3 (não regredir!)

- SDKs externos ficam em `suggest`, não `require` — sites sem S3/Drive não carregam nada extra;
  adapters retornam `WP_Error timevault_sdk_missing` com instrução clara se a classe não existir.
- Credencial sem chave de criptografia configurada = erro (`timevault_credentials_need_key`);
  jamais armazenar plaintext como fallback.
- Token de download: HMAC-SHA256 com `wp_salt('auth')`, payload `uuid|expiração`, comparação
  com `hash_equals`. NÃO é single-use (hardening futuro anotado nas pendências).
- Download verifica o checksum do artefato **antes** de entregar (gate de integridade).

## Pendências e avisos para a próxima sessão

1. **Ambiente local configurado em 2026-07-07**: Laragon instalado via winget (`C:\laragon`,
   PHP 8.3.30 + MySQL + Composer embutidos; extensões zip/sodium/openssl/mysqli habilitadas no
   php.ini criado a partir do template). `composer install` executado (vendor/ com Action
   Scheduler 3.9.3), `php -l` limpo nos 29 arquivos e **phpcs 100% limpo**. **Falta ainda o
   teste em WordPress real**: criar site no Laragon, linkar o plugin em wp-content/plugins/timevault,
   ativar, configurar TIMEVAULT_ENCRYPTION_KEY e disparar POST /backups.
2. **Token de download não é single-use** (TTL 5 min + capability na emissão + auditoria).
   Hardening futuro: registrar jti consumido para invalidar reuso dentro do TTL.
3. **SFTPAdapter** consta na arquitetura do CLAUDE.md mas não no prompt P3 — fica como fase futura.
4. **Retenção/expiração** (`expires_at` já existe na tabela) — implementação fica no P5.
5. **P6 desbloqueado**: o usuário adicionou `TIMEVAULT-DESIGN-SYSTEM.md` na raiz — usar como
   fonte de tokens/componentes quando chegar a fase de UI.
6. **Nada de telemetria/chamada externa** sem opt-in — princípio inegociável do projeto.

## Como retomar (nova sessão)

1. Ler [CLAUDE.md](CLAUDE.md) — seções 3 (arquitetura), 4 (segurança) e 7 (prompts P0–P7).
2. Ler este arquivo para o estado atual.
3. Executar a próxima fase pendente do roadmap acima (agora: **P2 — Import/Restore**, prompt na
   seção 7 do CLAUDE.md), respeitando as convenções e decisões de segurança listadas.
4. Ao terminar a fase: marcar o status na tabela, registrar o que foi entregue, novas decisões
   e pendências, e atualizar a data no topo.

## Convenções fixas (usar em todo código novo)

| Item | Valor |
|------|-------|
| Slug / pasta de deploy | `timevault` |
| Namespace PHP | `Timevault\` (PSR-4 → `src/`) |
| Prefixo funções/tabelas/options | `timevault_` |
| Capability | `manage_timevault` (`Support\Capabilities::MANAGE`) |
| Text domain | `timevault` |
| Namespace REST | `timevault/v1` |
| Grupo Action Scheduler | `timevault` (`Queue\JobQueue::GROUP`) |
| Hook do pipeline | `timevault_backup_step` (`Core\BackupManager::STEP_HOOK`) |
| Códigos WP_Error | prefixo `timevault_` |
| Estilo | WPCS (tabs, snake_case, `array()`), `declare(strict_types=1)` |
