# Timevault — Plugin WordPress de Backup, Importação e Exportação — Brief de Engenharia

> **Nome do plugin:** Timevault (título: "Timevault Migration & Backups")
> **Slug/pasta:** `timevault` · **Prefixo de funções/tabelas:** `timevault_`
> **Capability:** `manage_timevault` · **Namespace PHP:** `Timevault\` · **Text domain:** `timevault`
>
> Documento mestre do projeto. Serve como guia de arquitetura, requisitos de segurança/LGPD
> e roteiro de prompts para construir o plugin no Claude Code.
>
> **Contexto de distribuição:** uso **privado/agência**. O plugin será instalado apenas em
> sites administrados pela agência ou de clientes diretos. **Não** será publicado no WordPress.org
> nem em marketplace. Isso dispensa servidor de licenças, updater customizado e conformidade com
> as guidelines do repositório oficial — e reduz a superfície de ataque (parque de sites controlado).

---

## 1. Validação da ideia

- **Executável:** sim. Backup/import/export é funcionalidade madura no WP (APIs nativas de
  filesystem, cron, REST, criptografia via libsodium/openssl).
- **Faz sentido para agência:** padronização de segurança e processo em todos os sites sob
  controle próprio; LGPD nativo no fluxo; identidade visual/UX unificada; independência de
  vendor terceiro com telemetria/comportamento desconhecido.
- **Risco principal:** plugins de backup/restore estão historicamente entre os mais explorados
  em CVEs de WordPress (zip slip, path traversal na importação, download não autenticado de
  backup, deserialização insegura, diretórios de backup públicos indexáveis). **Segurança precisa
  ser parte da arquitetura desde o primeiro commit, não uma auditoria no final.**

---

## 2. Escopo

### MVP
- Backup completo (banco + arquivos), manual e agendado
- Exportação seletiva (tabelas, posts, mídia, configurações)
- Importação/restauração (mesmo site ou migração para site novo)
- Armazenamento: local + destinos externos (Google Drive e S3/compatível cobrem ~90% do uso)
- Criptografia em repouso (AES-256-GCM)
- Log de auditoria imutável (append-only)
- Notificação por e-mail ao concluir/falhar
- Dashboard com histórico e status

### Fase 2
- Backup incremental
- Painel centralizado multi-site (visão de todos os clientes)
- Anonimização automática para exports de staging/dev
- Retenção/expiração automática de backups antigos
- Teste de restauração automatizado (sandbox) para validar integridade

---

## 3. Arquitetura em camadas

```
Admin UI (wp-admin — @wordpress/components ou Alpine.js + Tailwind)
        │
REST API layer (nonce + capability check + rate limit + permission_callback real)
        │
Core Engine
 ├─ BackupManager      (orquestra dump DB + arquivos)
 ├─ ExportManager      (seleção granular)
 ├─ ImportManager      (validação + restauração — camada mais crítica)
 ├─ EncryptionService  (sodium/openssl, chave fora do banco)
 ├─ AuditLog           (tabela própria, append-only)
 └─ PrivacyService     (anonimização, retenção, registro de tratamento)
        │
StorageAdapter (interface comum — Strategy pattern)
 ├─ LocalAdapter
 ├─ S3Adapter
 ├─ GoogleDriveAdapter
 └─ SFTPAdapter
        │
Job Queue (Action Scheduler — evita timeout em backups grandes)
```

### Decisões estruturais
- Tabelas próprias com prefixo dedicado (não sobrecarregar `wp_options`).
- PHP 8.1+, Composer com PSR-4, WordPress Coding Standards.
- Jobs longos via **Action Scheduler** (mesma lib do WooCommerce), nunca execução síncrona bloqueante.
- SDKs externos com autoload condicional (só carregam quando o destino é usado).

---

## 4. Segurança — requisitos NÃO negociáveis

- Capability própria (ex. `manage_agencybackup`) em vez de reaproveitar `manage_options` genérico.
- Nonce em toda ação admin + `permission_callback` **real** (nunca `__return_true`) em todo endpoint REST.
- Diretório de backup **fora do webroot** quando o host permitir; senão `.htaccess deny all`
  + `index.php` vazio + nomes de arquivo aleatórios não enumeráveis.
- Download só via endpoint autenticado com token assinado de curta expiração — **nunca link direto público**.
- Proteção anti **zip-slip / path traversal**: validar cada entry do ZIP antes de extrair; rejeitar
  `../` e symlinks.
- **Nunca desserializar** objetos PHP não confiáveis vindos do backup (usar JSON sempre que possível).
- Restauração de SQL via parser seguro + `$wpdb->prepare`, nunca concatenar dump cru nem `eval`.
- Excluir ou cifrar separadamente segredos de `wp-config.php` no pacote de arquivos.
- Checksum (SHA-256) de cada backup, validado **antes** de qualquer restore.
- Backups cifrados em repouso (AES-256-GCM); chave nunca em texto puro no banco
  (constante em `wp-config.php` ou secret manager).
- Credenciais de storage externo com **escopo mínimo** (bucket/pasta dedicados, nunca token de conta inteira).
- Nada de segredos em logs de auditoria ou `debug.log`.
- Rate limiting em endpoints sensíveis (especialmente restore).

---

## 5. LGPD — o que o plugin realmente entrega

> **Nota honesta:** o plugin dá *suporte técnico* à conformidade. A base legal e o papel de
> controlador/operador entre agência e cliente é questão **contratual**, não resolvida só por código.

Features que ajudam de verdade:
- **Log de auditoria** completo (quem, quando, o quê, para onde) — princípio da responsabilização (Art. 6º, VI).
- **Anonimização/pseudonimização** opcional em exports para dev/staging (mascarar e-mail, nome, telefone
  em `wp_users` e campos conhecidos).
- **Retenção configurável** com expiração automática de backups antigos.
- **Registro de destino**: se o storage for internacional, deixar explícita a região (transferência
  internacional de dados, Art. 33). Nenhum destino de terceiro como padrão obrigatório.
- **Registro de operações de tratamento** gerado automaticamente (o que o plugin faz com dados pessoais),
  útil para a agência anexar à documentação de compliance.
- **Sem telemetria oculta**: nenhuma coleta enviada para fora do site sem consentimento explícito e configurável.

---

## 6. Stack técnica

| Área | Escolha |
|------|---------|
| Runtime | PHP 8.1+ |
| Autoload | Composer PSR-4 |
| Padrão de código | WordPress Coding Standards (phpcs) |
| Jobs assíncronos | Action Scheduler |
| Compactação | `ZipArchive` nativo |
| Criptografia | libsodium (fallback openssl) |
| Storage SDKs | `aws/aws-sdk-php`, `google/apiclient` (autoload condicional) |
| UI | `@wordpress/components` **ou** Alpine.js + Tailwind |
| Testes | PHPUnit + `WP_UnitTestCase`, ambiente `wp-env`/Docker |

---

## 7. Roteiro de prompts (Claude Code)

> Use na ordem, um de cada vez, colando dentro do repositório do plugin já inicializado.
> Substitua `Cofre` e placeholders antes de rodar.

### P0 — Kickoff e scaffolding
```
Crie a estrutura inicial de um plugin WordPress privado (uso interno de agência,
não será publicado no WP.org) chamado "Timevault Migration & Backups".
Convenções: slug/pasta "timevault", namespace PHP "Timevault\", prefixo de
funções e tabelas "timevault_", capability "manage_timevault", text domain
"timevault". Requisitos:
- PHP 8.1+, PSR-4 via Composer, WordPress Coding Standards
- Separação em camadas: Admin UI, REST API, Core Engine, Storage Adapters, Job Queue
- Capability customizada dedicada (não manage_options genérico)
- Sem nenhuma chamada de rede externa por padrão (privacy by design)
- Tabelas próprias com prefixo dedicado para logs de auditoria
Monte o esqueleto de pastas, o arquivo principal do plugin, autoload e o
plugin de ativação/desativação com criação segura das tabelas.
```

### P1 — Core Engine de Backup/Export
```
Implemente o BackupManager e o ExportManager descritos na arquitetura em camadas.
Requisitos de segurança obrigatórios:
- Dump de banco via $wpdb preparado, nunca concatenação direta de SQL
- Empacotamento de arquivos via ZipArchive com checksum SHA-256 do pacote final
- Exclusão ou cifragem separada de segredos de wp-config.php
- Toda operação registrada no AuditLog (quem, quando, o quê)
- Jobs longos via Action Scheduler, nunca execução síncrona bloqueante
Explique as decisões de segurança tomadas em cada função crítica.
```

### P2 — Import/Restore Engine (camada mais crítica)
```
Implemente o ImportManager para restauração/migração de sites. Este é o componente
de maior risco do plugin — trate como superfície de ataque hostil por padrão:
- Validar TODO entry do ZIP antes de extrair (bloquear path traversal e zip slip)
- Validar checksum do pacote antes de processar
- Nunca desserializar objetos PHP não confiáveis; usar JSON quando possível
- Parser seguro de SQL na restauração, nunca eval ou execução direta de dump cru
- Dupla confirmação no fluxo (é destrutivo) e gerar um backup de segurança
  automático do estado atual antes de sobrescrever
- Rate limiting e log de auditoria de toda tentativa de restauração
Ao final, liste explicitamente quais vetores de ataque conhecidos de plugins de
restore (CVEs históricos de zip slip, path traversal, insecure deserialization)
esse código mitiga e como.
```

### P3 — Storage Adapters
```
Implemente os adapters de armazenamento (Local, S3, Google Drive) atrás da
interface StorageAdapter (Strategy pattern). Requisitos:
- Credenciais armazenadas cifradas, nunca em texto puro no banco
- Escopo mínimo de permissão nas credenciais (bucket/pasta dedicados)
- Nenhum destino externo ativado por padrão — sempre opt-in explícito do usuário
- Registrar no AuditLog e no PrivacyService a região/localização de cada destino
  usado, para rastreabilidade de transferência internacional de dados (LGPD Art. 33)
```

### P4 — Auditoria de segurança dedicada
```
Faça uma revisão de segurança completa do plugin construído até agora, focando em:
nonces e permission_callback em todos os endpoints REST/admin-post, proteção do
diretório de backup contra acesso direto, exposição de segredos em logs,
validação de entrada em todo import, e princípio do menor privilégio nas
integrações de storage. Reporte cada achado com arquivo, linha e cenário de
exploração concreto.
```
> Corresponde ao skill `/security-review` — pode rodar direto quando o código existir.

### P5 — Camada LGPD/Privacidade
```
Implemente o PrivacyService: anonimização opcional de dados pessoais (email,
nome, telefone) em exports marcados como "staging/dev", política de retenção
configurável com expiração automática de backups, e geração de um registro
leve de operações de tratamento (o que o plugin faz com dados pessoais,
para onde vão, por quanto tempo ficam retidos). Não implemente nenhuma
telemetria ou coleta enviada para fora do site sem consentimento explícito
e configurável pelo usuário.
```

### P6 — UI/UX e Design System
```
[COLAR AQUI a estrutura de design: tokens de cor, tipografia, componentes, espaçamento]

Usando essa estrutura de design, construa o dashboard admin do plugin:
tela de status (último backup, próximo agendado, uso de espaço), histórico
com filtros, indicador de progresso para jobs longos via polling REST,
estados vazios bem desenhados, e fluxo de confirmação destrutiva para
restauração (dupla confirmação clara). Siga WCAG AA de acessibilidade.
```
> Depende da estrutura de design que você vai fornecer. Deixar por último.

### P7 — Testes
```
Escreva testes PHPUnit/WP_UnitTestCase para BackupManager, ImportManager e
StorageAdapters, incluindo casos adversariais: ZIP malicioso com path traversal,
SQL dump corrompido, checksum inválido, credencial de storage revogada durante
upload. Priorize testes de import/restore por serem a superfície mais crítica.
```

---

## 8. Ordem de execução recomendada

1. **P0** — scaffolding
2. **P1** — backup/export (base do valor)
3. **P3** — storage adapters (para ter onde guardar)
4. **P2** — import/restore (mais crítico; construir com cuidado)
5. **P5** — LGPD/privacidade
6. **P7** — testes (rodar continuamente, não só no fim)
7. **P4** — auditoria de segurança (`/security-review`) antes de cada release
8. **P6** — UI/UX (quando a estrutura de design estiver pronta)

---

## 9. Checklist de release (Definition of Done)

- [ ] Todos os endpoints REST com `permission_callback` real + nonce
- [ ] Diretório de backup protegido contra acesso direto (testado via URL)
- [ ] Download apenas via token assinado de curta expiração
- [ ] Import validado contra zip-slip/path traversal (teste adversarial passa)
- [ ] Nenhuma desserialização de objeto PHP não confiável
- [ ] Backups cifrados em repouso; chave fora do banco
- [ ] Credenciais de storage cifradas e com escopo mínimo
- [ ] Nenhum segredo em logs
- [ ] Backup de segurança automático antes de todo restore
- [ ] Log de auditoria append-only funcionando
- [ ] Retenção/expiração automática configurável
- [ ] Sem telemetria oculta; toda saída externa é opt-in
- [ ] `/security-review` sem achados críticos ou altos
- [ ] Testes PHPUnit passando, incluindo casos adversariais
- [ ] UI em conformidade WCAG AA
```
