# Security Review — Timevault (P4)

**Data:** 2026-07-08 · **Versão auditada:** 0.5.0 · **Escopo:** plugin inteiro (`src/`, `timevault.php`, `uninstall.php`).

Auditoria correspondente ao P4 do brief. Foco nos itens da seção 4 do CLAUDE.md: nonces +
`permission_callback` em todo endpoint, proteção do diretório de backup, exposição de segredos em
logs, validação de entrada no import, e menor privilégio nas integrações de storage.

## Resultado

**Nenhum achado crítico ou alto.** A arquitetura de segurança prevista no brief está implementada
e verificada. Abaixo, o que foi confirmado e três recomendações de endurecimento de severidade
baixa/informativa.

## Verificações que passaram

| Área | Verificação | Evidência |
|------|-------------|-----------|
| Controle de acesso | Todo endpoint REST tem `permission_callback` real; nenhum `__return_true` | `AbstractController::permission_check` (capability `manage_timevault`); grep em `src/Rest/*` mostra só `permission_check` ou `validate_token_request` |
| CSRF | Requisições por cookie exigem nonce `wp_rest` (validado pelo core antes do callback); auth por application password não usa credencial ambiente | `AbstractController` (doc); comportamento nativo do WP REST |
| Injeção de SQL | Dump via `$wpdb->prepare('%s')` em todo valor; identificadores validados contra `SHOW TABLES` + backtick-escaped; restore tokeniza e executa 1 statement por `$wpdb->query()` (sem multi-statement/eval) | `DatabaseDumper`, `SqlImporter` |
| Desserialização | Manifest e meta são JSON; nunca `unserialize`/`maybe_unserialize` | grep limpo; `ArchiveInspector::read_manifest` |
| Zip-slip / traversal | `PathGuard` rejeita `..`, absoluto, drive letter, backslash, NUL, fora de prefixo; extração streama para alvo revalidado (sem `extractTo`); backup de segurança antes de sobrescrever | `PathGuard`, `ArchiveInspector`, testes adversariais |
| Segredos em log | `AuditLog` reda chaves sensíveis; IP só como hash salgado; adapters truncam mensagens de exceção | `AuditLog::redact`/`hash_ip` |
| Criptografia em repouso | AEAD streaming autenticado (XChaCha20-Poly1305 / AES-256-GCM), chave só em `wp-config.php`; falha fechada em adulteração/truncamento | `EncryptionService`; testes de tamper/truncamento |
| Credenciais de storage | Sempre cifradas antes do banco; sem chave, recusa salvar (sem fallback plaintext); escopo mínimo (bucket+prefix / `drive.file`) | `DestinationSettings`, `S3Adapter`, `GoogleDriveAdapter` |
| Download | Nunca link público; token HMAC-SHA256 de 5 min ligado a um backup; checksum verificado antes de entregar | `DownloadController` |
| Diretório de backup | Sufixo aleatório não enumerável + `.htaccess`/`web.config`/`index.php`; suporte a fora do webroot | `Support\Paths` |
| Superglobais / LFI | Nenhum uso direto sem sanitização; autoloader de fallback imune (nomes de classe não contêm `../`) | grep; `timevault.php` |

## Recomendações de endurecimento (baixa / informativa)

### L1 — Token de download trafega na query string (baixa)
- **Arquivo:** `src/Rest/DownloadController.php:100` (`add_query_arg('token', …)`).
- **Cenário:** o token assinado vai em `?token=…` de um GET. Query strings costumam ser gravadas em
  logs de acesso do servidor/proxy e no histórico do navegador. Dentro da janela de 5 min, uma linha
  de log vazada permite rebaixar o backup.
- **Mitigações já presentes:** TTL de 5 min; backup cifrado em repouso (o vazamento entrega só o
  ciphertext, inútil sem a chave em `wp-config.php`).
- **Recomendação:** aceitar o risco documentado, ou aceitar o token via header/POST. Severidade
  baixa dado o cifrado em repouso.

### L2 — Acesso direto ao diretório de backup em Nginx sem bloco de servidor (baixa)
- **Arquivo:** `src/Support/Paths.php` (hardening cobre Apache/LiteSpeed/IIS, não Nginx).
- **Cenário:** em Nginx sem o bloco `location` documentado, os arquivos ficam serviveis por URL
  direta **se o caminho for conhecido**.
- **Mitigações já presentes:** sufixo de diretório aleatório de 16 chars + nome de arquivo com uuid
  aleatório (não enumerável); cifrado em repouso; o README documenta o bloco Nginx e a opção
  `TIMEVAULT_BACKUP_DIR` fora do webroot.
- **Recomendação:** manter a documentação; considerar exigir/checar a config no dashboard (P6).

### L3 — Redação de log é baseada em chave, não em valor (informativa)
- **Arquivo:** `src/Core/AuditLog.php:113` (`redact` casa nomes de chave sensíveis).
- **Cenário:** um segredo embutido no *valor* de uma chave não-sensível (ex.: `['msg' => '...token=abc']`)
  seria gravado. **Não ocorre no código atual** — todo contexto auditado carrega nomes/contagens.
- **Recomendação:** manter a disciplina de nunca colocar segredo em valor de contexto; opcionalmente
  adicionar uma varredura leve de padrões no futuro (com cuidado para não sobre-redigir).

## Notas de confiança (por design, não vulnerabilidades)

- **Restaurar um pacote não confiável com `restore_files=true`** grava arquivos dentro de
  `wp-content` (contido pelo `PathGuard`) — é a natureza de um restore. Mitigado por capability +
  dupla confirmação + backup de segurança automático. O pacote é validado contra traversal, mas seu
  *conteúdo* é confiado por decisão explícita do operador.

## Conclusão

Definition of Done atendido: **`/security-review` sem achados críticos ou altos.** As três
recomendações são de endurecimento incremental e não bloqueiam release.
