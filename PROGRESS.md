# PROGRESS — Timevault Migration & Backups

> **Propósito deste arquivo:** registro de progresso e handoff entre sessões de Claude Code.
> Se você é uma nova sessão: leia primeiro o [CLAUDE.md](CLAUDE.md) (documento mestre — arquitetura,
> requisitos de segurança e roteiro de prompts P0–P7), depois este arquivo para saber onde paramos.
> **Ao concluir qualquer fase ou decisão relevante, atualize este arquivo.**

**Última atualização:** 2026-07-07
**Fase atual:** ✅ P0 + P1 + P3 concluídos e **VALIDADOS EM RUNTIME** → **próximo passo: P2 (Import/Restore — camada mais crítica)**
**Versão atual do plugin:** 0.3.0
**Git:** repositório em https://github.com/PedroAgostini/TIMEVAULT-MIGRATION.git (branch `main`)

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

## Status do roadmap

Ordem de execução recomendada (definida no CLAUDE.md, seção 8):

| Ordem | Fase | Descrição | Status |
|-------|------|-----------|--------|
| 1 | **P0** | Scaffolding e estrutura inicial | ✅ Concluído (2026-07-07) |
| 2 | **P1** | Core Engine: BackupManager + ExportManager | ✅ Concluído (2026-07-07) |
| 3 | **P3** | Storage Adapters (S3, Google Drive — Local já existe) | ✅ Concluído (2026-07-07) |
| 4 | **P2** | Import/Restore Engine (camada mais crítica) | ⬜ Próximo |
| 5 | **P5** | PrivacyService / LGPD | ⬜ Pendente |
| 6 | **P7** | Testes PHPUnit (rodar continuamente a partir daqui) | ⬜ Pendente |
| 7 | **P4** | Auditoria de segurança (`/security-review`) antes de release | ⬜ Pendente |
| 8 | **P6** | UI/UX | ⬜ Desbloqueado — usuário forneceu [TIMEVAULT-DESIGN-SYSTEM.md](TIMEVAULT-DESIGN-SYSTEM.md) |

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
