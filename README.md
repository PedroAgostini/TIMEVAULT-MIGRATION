# Timevault Migration & Backups

Plugin WordPress **privado** (uso interno de agência — não será publicado no WP.org) para backup, exportação, importação e migração de sites, com segurança e LGPD como parte da arquitetura.

> Documento mestre do projeto (arquitetura, requisitos de segurança, roteiro P0–P7): [CLAUDE.md](CLAUDE.md)

## Requisitos

- PHP 8.1+
- WordPress 6.2+
- Composer

## Setup de desenvolvimento

```bash
composer install   # autoload PSR-4 + Action Scheduler + phpcs/phpunit
composer lint      # WordPress Coding Standards
```

> O plugin funciona sem `composer install` (há um autoloader PSR-4 de fallback), mas a fila de jobs (Action Scheduler) só fica disponível com as dependências instaladas.

**Deploy:** a pasta do plugin no servidor deve se chamar `timevault` (slug oficial).

## Configuração obrigatória (wp-config.php)

```php
// Chave de criptografia dos backups (base64 de 32 bytes aleatórios).
// Gere com: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
define( 'TIMEVAULT_ENCRYPTION_KEY', '<chave>' );

// Opcional, recomendado: diretório de backups FORA do webroot.
define( 'TIMEVAULT_BACKUP_DIR', '/caminho/fora/do/webroot/timevault' );
```

A chave **nunca** é armazenada no banco. Sem `TIMEVAULT_BACKUP_DIR`, o plugin usa `wp-content/timevault-<sufixo aleatório>/` protegido por `.htaccess` (Apache/LiteSpeed), `web.config` (IIS) e `index.php` vazio.

**Nginx** não lê `.htaccess` — adicione ao server block:

```nginx
location ~ ^/wp-content/timevault- {
    deny all;
}
```

## Configuração opcional

```php
// wp-config.php não tem: notificação por e-mail é uma option do site.
// Vazia (padrão) = desligada. Defina um e-mail para receber conclusão/falha:
update_option( 'timevault_notify_email', 'ops@agencia.com.br' );
```

## API REST (`/wp-json/timevault/v1`)

Todos os endpoints exigem a capability `manage_timevault` (+ nonce `X-WP-Nonce` em auth por cookie).

| Endpoint | Método | Função |
|----------|--------|--------|
| `/status` | GET | Saúde do ambiente (chave, fila, hardening) |
| `/backups` | GET | Histórico paginado (`per_page`, `page`) |
| `/backups` | POST | Agenda backup (`type`: `full`\|`db`; `files_scope`: `wp-content`\|`full`) → 202 + uuid |
| `/backups/{uuid}` | GET | Status de um backup (polling de progresso) |
| `/exports` | POST | Agenda export seletivo (`tables[]`, `include_uploads`) → 202 + uuid |
| `/backups/{uuid}/download-token` | POST | Emite token HMAC de 5 min para download |
| `/download?token=` | GET | Baixa o backup (auth pelo token assinado; checksum verificado antes) |
| `/destinations` | GET | Destinos configurados (credenciais nunca retornadas) |
| `/destinations/{s3\|gdrive}` | POST/DELETE | Configura/remove destino externo (opt-in) |

### Destinos externos (opt-in)

Nenhum destino externo é ativado por padrão. Para usar S3 ou Google Drive:

1. Instale o SDK correspondente (autoload condicional — só onde for usar):
   `composer require aws/aws-sdk-php` **ou** `composer require google/apiclient`
2. Configure via `POST /destinations/s3` (bucket, prefix dedicado, region, credentials
   `{access_key, secret_key}`) ou `POST /destinations/gdrive` (folder_id dedicado, region,
   credentials = JSON da service account — escopo mínimo `drive.file`).
3. Credenciais são cifradas com a `TIMEVAULT_ENCRYPTION_KEY` antes de tocar o banco — sem a
   chave configurada, o plugin **recusa** salvá-las. A região de cada destino fica registrada
   no log de auditoria (LGPD Art. 33).
4. Agende backups com `"storage": "s3"` (ou `"gdrive"`) no `POST /backups`.

O pipeline roda em 3 etapas assíncronas via Action Scheduler (`dump_db → package → finalize`);
o resultado é um `timevault-{tipo}-{data}-{uuid8}.zip[.enc]` no diretório protegido, com
SHA-256 registrado no banco e manifest JSON embutido.

### Criptografia em repouso

Com `TIMEVAULT_ENCRYPTION_KEY` definida, todo backup é cifrado por streaming: **libsodium
secretstream (XChaCha20-Poly1305)** quando disponível — autenticação por chunk com proteção
nativa contra truncamento/reordenação — e fallback **OpenSSL AES-256-GCM** em chunks com AAD.
O checksum SHA-256 é calculado sobre o artefato final (pós-criptografia), permitindo validar
integridade antes de qualquer restore sem decifrar.

## Estrutura (camadas)

```
timevault.php               Bootstrap: constantes, autoload, hooks de ativação
src/
├── Plugin.php              Container de serviços + boot
├── Activation/             Activator (tabelas via dbDelta, capability,
│                           hardening do diretório) e Deactivator
├── Admin/                  AdminMenu — dashboard (placeholder até P6)
├── Rest/                   AbstractController (permission_callback real)
│                           + StatusController (GET /timevault/v1/status)
├── Core/
│   ├── AuditLog.php        Log de auditoria append-only (funcional)
│   ├── EncryptionService.php  Gestão de chave (cifras em P1)
│   ├── BackupManager.php   Contrato — implementação em P1
│   ├── ExportManager.php   Contrato — implementação em P1
│   ├── ImportManager.php   Contrato — implementação em P2 (camada mais crítica)
│   └── PrivacyService.php  Contrato — implementação em P5 (LGPD)
├── Storage/                StorageAdapterInterface (Strategy) + LocalAdapter
├── Queue/                  JobQueue — wrapper do Action Scheduler
└── Support/                Capabilities (manage_timevault) e Paths (hardening)
```

## Convenções

| Item | Valor |
|------|-------|
| Slug / pasta | `timevault` |
| Namespace PHP | `Timevault\` |
| Prefixo funções/tabelas/options | `timevault_` |
| Capability | `manage_timevault` |
| Text domain | `timevault` |
| Grupo Action Scheduler | `timevault` |
| Namespace REST | `timevault/v1` |

## Decisões de segurança já aplicadas no scaffolding

- Capability dedicada `manage_timevault` (não reaproveita `manage_options`).
- Todo endpoint REST herda de `AbstractController` com `permission_callback` real — nunca `__return_true`.
- Diretório de backup com sufixo aleatório não enumerável + arquivos de negação de acesso; suporte a diretório fora do webroot via constante.
- Log de auditoria **append-only** (a classe não expõe update/delete), com redação automática de chaves sensíveis (`password`, `token`, `secret`…) e IP pseudonimizado (hash com salt) — nunca segredos em logs.
- Chave de criptografia exclusivamente em `wp-config.php`, nunca no banco.
- `Update URI: false` no header — impede takeover por colisão de slug no WP.org.
- Desinstalação conservadora: dados só são removidos com opt-in explícito; arquivos de backup nunca são apagados automaticamente.
- Nenhuma chamada de rede externa por padrão (privacy by design); destinos externos (P3) serão sempre opt-in.

## Roadmap

P0 scaffolding ✅ → P1 backup/export → P3 storage adapters → P2 import/restore → P5 LGPD → P7 testes → P4 auditoria de segurança → P6 UI/UX. Detalhes em [CLAUDE.md](CLAUDE.md).
