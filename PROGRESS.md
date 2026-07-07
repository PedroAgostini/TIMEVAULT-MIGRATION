# PROGRESS — Timevault Migration & Backups

> **Propósito deste arquivo:** registro de progresso e handoff entre sessões de Claude Code.
> Se você é uma nova sessão: leia primeiro o [CLAUDE.md](CLAUDE.md) (documento mestre — arquitetura,
> requisitos de segurança e roteiro de prompts P0–P7), depois este arquivo para saber onde paramos.
> **Ao concluir qualquer fase ou decisão relevante, atualize este arquivo.**

**Última atualização:** 2026-07-07
**Fase atual:** ✅ P0 + P1 concluídos → **próximo passo: P3 (Storage Adapters S3/Google Drive)**
**Versão atual do plugin:** 0.2.0

---

## Status do roadmap

Ordem de execução recomendada (definida no CLAUDE.md, seção 8):

| Ordem | Fase | Descrição | Status |
|-------|------|-----------|--------|
| 1 | **P0** | Scaffolding e estrutura inicial | ✅ Concluído (2026-07-07) |
| 2 | **P1** | Core Engine: BackupManager + ExportManager | ✅ Concluído (2026-07-07) |
| 3 | **P3** | Storage Adapters (S3, Google Drive — Local já existe) | ⬜ Próximo |
| 4 | **P2** | Import/Restore Engine (camada mais crítica) | ⬜ Pendente |
| 5 | **P5** | PrivacyService / LGPD | ⬜ Pendente |
| 6 | **P7** | Testes PHPUnit (rodar continuamente a partir daqui) | ⬜ Pendente |
| 7 | **P4** | Auditoria de segurança (`/security-review`) antes de release | ⬜ Pendente |
| 8 | **P6** | UI/UX (aguarda estrutura de design do usuário) | ⬜ Bloqueado — depende de design tokens |

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

## Pendências e avisos para a próxima sessão

1. **Ambiente local sem PHP/Composer** (Windows, verificado em 2026-07-07): `php -l` e
   `composer install` ainda não foram executados. **Nenhum código foi validado em runtime** —
   antes do P3, subir num WordPress real ou `wp-env`/Docker, rodar `composer install`, ativar,
   disparar `POST /wp-json/timevault/v1/backups` e acompanhar o pipeline.
2. **Git não inicializado.** Recomendado `git init` + commit antes de continuar (usuário ainda
   não autorizou — perguntar/confirmar).
3. **P6 (UI/UX) bloqueado:** aguarda o usuário fornecer a estrutura de design.
4. **Download de backup ainda não existe** (endpoint autenticado com token assinado de curta
   expiração — requisito do checklist). Implementar junto com P3 ou P2.
5. **Retenção/expiração** (`expires_at` já existe na tabela) — implementação fica no P5.
6. **Nada de telemetria/chamada externa** sem opt-in — princípio inegociável do projeto.

## Como retomar (nova sessão)

1. Ler [CLAUDE.md](CLAUDE.md) — seções 3 (arquitetura), 4 (segurança) e 7 (prompts P0–P7).
2. Ler este arquivo para o estado atual.
3. Executar a próxima fase pendente do roadmap acima (agora: **P3**, prompt na seção 7 do CLAUDE.md),
   respeitando as convenções e decisões de segurança listadas.
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
