# Timevault — Design System

> Sistema visual do plugin **Timevault Migration & Backups**.
> Direção: **preto + âmbar `#ffa300` + glassmorphism**.
> Tipografia: **Plus Jakarta Sans** (interface) + **JetBrains Mono** (dados de máquina).
>
> Documento irmão de `PLUGIN-BACKUP-BRIEF.md`. Use como fonte única de tokens ao construir a UI (prompt P6).

---

## 1. Conceito

O âmbar (`#ffa300`) não é só o acento da marca — é a metáfora do produto. Âmbar é a **resina fóssil
que preserva vida intacta por milhões de anos**: exatamente o que o Timevault faz com o site do cliente.
Toda a linguagem visual parte daí:

- **Âmbar = preservação.** A cor sinaliza o que está salvo, ativo e íntegro. Usada com restrição, como luz.
- **Glass = estratos de tempo.** Painéis de vidro fosco empilhados como camadas geológicas — cada backup
  é uma camada preservada. O blur precisa de luz por trás (ver §5): sobre preto puro o glass some.
- **Assinatura: a Espinha Temporal.** Uma linha do tempo vertical de *restore points*, nós brilhando em
  âmbar. É o elemento memorável e encoda informação real (cronologia dos backups) — não é decoração.

**Personalidade:** instrumento de precisão. Calmo, técnico, confiável. Manipula dados críticos — a UI
deve transmitir controle, não entusiasmo. Menos brilho, mais engenharia.

---

## 2. Princípios

1. **Âmbar é luz, não tinta.** Reserve para o que importa: estado ativo, foco, CTA primário, o nó "agora"
   da timeline. Se tudo é âmbar, nada é.
2. **Preto é profundidade, não vazio.** Use a escala de pretos para criar hierarquia por elevação, não só
   por bordas.
3. **Glass precisa de luz por trás.** Nunca aplique glass sobre `#000` chapado. Sempre haja um glow âmbar
   ambiente por trás (§5.1).
4. **Dados são mono.** Timestamps, tamanhos, checksums, IDs e contagens usam JetBrains Mono. Isso comunica
   "valor preciso de máquina" e alinha colunas numéricas.
5. **Acessibilidade é piso, não meta.** Contraste AA, foco visível, `prefers-reduced-motion` respeitado.

---

## 3. Cores — tokens

Cole como CSS custom properties. Prefixo `--tv-` (Timevault). Todos os valores pensados para tema escuro.

```css
:root {
  /* ── Base / pretos (do mais profundo ao elevado) ───────────── */
  --tv-ink-900: #060607;   /* fundo mais profundo da app */
  --tv-ink-800: #0a0a0b;   /* fundo base da tela */
  --tv-ink-700: #111113;   /* superfície sólida sob o glass */
  --tv-ink-600: #17171a;   /* cards sólidos, inputs */
  --tv-ink-500: #202024;   /* hover de superfícies sólidas */

  /* ── Âmbar (marca) ─────────────────────────────────────────── */
  --tv-amber-300: #ffce80; /* âmbar suave — texto de destaque sobre glass */
  --tv-amber-400: #ffb733; /* hover / estado claro */
  --tv-amber-500: #ffa300; /* ★ primária da marca */
  --tv-amber-600: #cc8200; /* pressed / borda ativa mais escura */
  --tv-amber-700: #7a4e00; /* glow/halo profundo, sombras coloridas */

  /* ── Texto ─────────────────────────────────────────────────── */
  --tv-text-strong:  #f7f7f5; /* títulos, alto contraste (~18:1 s/ ink-800) */
  --tv-text-body:    #d4d4d2; /* corpo padrão */
  --tv-text-muted:   #9a9aa2; /* legendas, metadados (AA em ≥16px) */
  --tv-text-faint:   #64646c; /* placeholders, desabilitado */
  --tv-text-on-amber:#0a0a0b; /* SEMPRE preto sobre âmbar (ver §9) */

  /* ── Semânticos (estado) ───────────────────────────────────── */
  --tv-success: #34d399;   /* backup concluído/íntegro */
  --tv-warning: #ffa300;   /* atenção = a própria marca */
  --tv-danger:  #f4485f;   /* restore/exclusão destrutiva */
  --tv-info:    #56b0ff;   /* neutro informativo */

  /* ── Vidro (glassmorphism) — ver §5 para receitas ──────────── */
  --tv-glass-fill:      rgba(255, 255, 255, 0.05);
  --tv-glass-fill-strong: rgba(255, 255, 255, 0.08);
  --tv-glass-border:    rgba(255, 255, 255, 0.10);
  --tv-glass-border-amber: rgba(255, 163, 0, 0.28);
  --tv-glass-highlight: rgba(255, 255, 255, 0.14); /* brilho superior 1px */
  --tv-glass-blur:      16px;
  --tv-glass-blur-lg:   28px;

  /* ── Halo/glow âmbar ambiente ──────────────────────────────── */
  --tv-glow-amber: 0 0 40px rgba(255, 163, 0, 0.25);
  --tv-glow-amber-soft: 0 0 80px rgba(255, 163, 0, 0.12);
}
```

### Uso rápido
| Papel | Token |
|-------|-------|
| Fundo da tela | `--tv-ink-800` |
| Card de vidro | receita `.tv-glass` (§5.2) |
| Card sólido | `--tv-ink-600` |
| CTA primário (fundo) | `--tv-amber-500`, texto `--tv-text-on-amber` |
| Texto padrão | `--tv-text-body` |
| Timestamp / tamanho | `--tv-text-muted` em mono |
| Ação destrutiva | `--tv-danger` |

---

## 4. Tipografia

```css
:root {
  --tv-font-sans: "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
  --tv-font-mono: "JetBrains Mono", ui-monospace, "SF Mono", "Cascadia Code", monospace;
}
```

- **Plus Jakarta Sans** — toda a interface: títulos, corpo, botões, labels. Legível, humanista, moderna.
- **JetBrains Mono** — **apenas dados de máquina**: datas/horas, tamanhos (`482.7 MB`), checksums,
  IDs de job, contadores. Alinha colunas e comunica precisão.

Carregar via `@font-face` local (privacy by design — **não** usar Google Fonts CDN, evita vazamento de IP
do visitante; alinhado à LGPD). Inclua os `.woff2` no bundle do plugin.

### Escala de tipo
| Token | Tamanho / linha | Peso | Uso |
|-------|-----------------|------|-----|
| `--tv-display` | 32px / 1.15 | 700 | Título de página, número-herói (ex. "12 backups") |
| `--tv-h1` | 24px / 1.25 | 700 | Título de seção |
| `--tv-h2` | 19px / 1.3 | 600 | Título de card |
| `--tv-h3` | 16px / 1.4 | 600 | Subtítulo |
| `--tv-body` | 14px / 1.55 | 400 | Corpo padrão (base do wp-admin) |
| `--tv-body-sm` | 13px / 1.5 | 400 | Texto secundário |
| `--tv-caption` | 12px / 1.4 | 500 | Legendas, eyebrows (uppercase, `letter-spacing: .08em`) |
| `--tv-mono` | 13px / 1.5 | 500 | Dados de máquina (JetBrains Mono) |

```css
:root {
  --tv-display: 700 32px/1.15 var(--tv-font-sans);
  --tv-h1: 700 24px/1.25 var(--tv-font-sans);
  --tv-h2: 600 19px/1.3 var(--tv-font-sans);
  --tv-h3: 600 16px/1.4 var(--tv-font-sans);
  --tv-body: 400 14px/1.55 var(--tv-font-sans);
  --tv-caption: 500 12px/1.4 var(--tv-font-sans);
}
.tv-eyebrow { font: var(--tv-caption); text-transform: uppercase; letter-spacing: .08em; color: var(--tv-amber-500); }
.tv-data    { font-family: var(--tv-font-mono); font-size: 13px; font-weight: 500; font-variant-numeric: tabular-nums; }
```

---

## 5. Glassmorphism — receitas

### 5.1 Regra de ouro: luz por trás do vidro
Glass fosco só funciona se houver luminância por trás para o blur capturar. Sobre preto puro ele
desaparece. Coloque **glows âmbar ambientes** no fundo da app, atrás dos painéis:

```css
.tv-app-bg {
  background-color: var(--tv-ink-800);
  background-image:
    radial-gradient(60% 50% at 15% 0%, rgba(255,163,0,0.14), transparent 70%),
    radial-gradient(50% 40% at 100% 100%, rgba(255,163,0,0.08), transparent 70%);
  background-attachment: fixed;
}
```

### 5.2 Painel de vidro padrão
```css
.tv-glass {
  background: var(--tv-glass-fill);
  backdrop-filter: blur(var(--tv-glass-blur)) saturate(1.2);
  -webkit-backdrop-filter: blur(var(--tv-glass-blur)) saturate(1.2);
  border: 1px solid var(--tv-glass-border);
  border-radius: var(--tv-radius-lg);
  box-shadow:
    inset 0 1px 0 var(--tv-glass-highlight),          /* brilho superior */
    0 8px 32px rgba(0, 0, 0, 0.45);                    /* profundidade */
  position: relative;
}
```

### 5.3 Painel de vidro "ativo" (borda âmbar + halo)
Para o card em foco, job em andamento, ou o restore point selecionado:
```css
.tv-glass--active {
  border-color: var(--tv-glass-border-amber);
  box-shadow:
    inset 0 1px 0 var(--tv-glass-highlight),
    0 8px 32px rgba(0,0,0,0.45),
    var(--tv-glow-amber);
}
```

### 5.4 Fallback (sem `backdrop-filter`)
```css
@supports not (backdrop-filter: blur(1px)) {
  .tv-glass { background: var(--tv-ink-600); border-color: rgba(255,255,255,0.08); }
}
```

> **Cuidado de performance/legibilidade:** limite o número de camadas com `backdrop-filter` visíveis ao
> mesmo tempo (custa GPU). Não empilhe glass sobre glass sem uma superfície sólida intermediária, ou o
> texto perde contraste.

---

## 6. Forma — raio, espaçamento, elevação

```css
:root {
  /* Raio */
  --tv-radius-sm: 8px;
  --tv-radius-md: 12px;
  --tv-radius-lg: 18px;   /* padrão dos painéis de vidro */
  --tv-radius-xl: 24px;
  --tv-radius-pill: 999px;

  /* Espaçamento (base 4) */
  --tv-space-1: 4px;  --tv-space-2: 8px;  --tv-space-3: 12px;
  --tv-space-4: 16px; --tv-space-5: 24px; --tv-space-6: 32px;
  --tv-space-7: 48px; --tv-space-8: 64px;

  /* Elevação (sombras neutras — profundidade) */
  --tv-shadow-sm: 0 2px 8px rgba(0,0,0,0.35);
  --tv-shadow-md: 0 8px 32px rgba(0,0,0,0.45);
  --tv-shadow-lg: 0 20px 60px rgba(0,0,0,0.55);
}
```

Hierarquia por elevação: `ink-800` (fundo) → `ink-700` → glass → `glass--active`. Não use borda âmbar
para separar seções neutras; reserve âmbar para estado.

---

## 7. Movimento

```css
:root {
  --tv-ease: cubic-bezier(0.22, 1, 0.36, 1);   /* saída suave, "assenta" */
  --tv-dur-fast: 140ms;
  --tv-dur: 220ms;
  --tv-dur-slow: 420ms;
}
@media (prefers-reduced-motion: reduce) {
  * { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
```

Momentos de movimento (contidos, com propósito):
- **Pulso do nó "agora"** na Espinha Temporal — halo âmbar respirando lentamente (só 1 nó).
- **Progresso de job** — barra âmbar com deslocamento sutil, comunica atividade viva.
- **Hover de card** — elevação +2px e borda clareando, `--tv-dur-fast`.
- **Confirmação destrutiva** — sem animação festiva; transição sóbria. Peso, não brilho.

---

## 8. Componentes

### 8.1 Botões
```css
.tv-btn { font: 600 14px/1 var(--tv-font-sans); padding: 10px 18px; border-radius: var(--tv-radius-md);
  border: 1px solid transparent; cursor: pointer; transition: all var(--tv-dur-fast) var(--tv-ease); }

/* Primário — a única superfície âmbar cheia da tela */
.tv-btn--primary { background: var(--tv-amber-500); color: var(--tv-text-on-amber); }
.tv-btn--primary:hover { background: var(--tv-amber-400); box-shadow: var(--tv-glow-amber); }
.tv-btn--primary:active { background: var(--tv-amber-600); }

/* Secundário — vidro */
.tv-btn--ghost { background: var(--tv-glass-fill); border-color: var(--tv-glass-border);
  color: var(--tv-text-strong); backdrop-filter: blur(8px); }
.tv-btn--ghost:hover { border-color: var(--tv-glass-border-amber); color: var(--tv-amber-300); }

/* Destrutivo — nunca âmbar; vermelho reservado a restore/exclusão */
.tv-btn--danger { background: transparent; border-color: var(--tv-danger); color: var(--tv-danger); }
.tv-btn--danger:hover { background: var(--tv-danger); color: #fff; }

.tv-btn:focus-visible { outline: 2px solid var(--tv-amber-500); outline-offset: 2px; }
```
> Um CTA primário por vista. Se houver dois botões âmbar cheios competindo, o segundo vira `--ghost`.

### 8.2 Card de status (glass)
Estrutura: eyebrow (mono/caption) → número-herói (display) → label → metadado (mono). Ex.: "Último backup".

### 8.3 Espinha Temporal (assinatura)
Linha vertical de *restore points*. Cada nó = um backup. O nó mais recente pulsa em âmbar; passados são
`ink` com anel `--tv-glass-border`. Ao lado de cada nó: data (mono), tamanho (mono), destino (badge),
status (badge). A linha encoda cronologia real — ordem importa, então numeração/tempo é justificado aqui.

```
│
◉  agora ── 07 jul 2026 · 14:32 · 482.7 MB · [S3] · ✓ íntegro   ← pulsa âmbar
│
○  06 jul 2026 · 03:00 · 479.1 MB · [Local] · ✓
│
○  05 jul 2026 · 03:00 · 477.8 MB · [Drive] · ✓
│
```

### 8.4 Barra de progresso (jobs longos)
Trilha `ink-700`, preenchimento âmbar com brilho. Rótulo em mono: `312 / 540 arquivos · 58%`.
Atualiza via polling REST (não travar a UI). Estado de erro troca preenchimento para `--tv-danger`.

### 8.5 Badges de status
```css
.tv-badge { font: 500 12px/1 var(--tv-font-sans); padding: 4px 10px; border-radius: var(--tv-radius-pill);
  border: 1px solid; }
.tv-badge--ok    { color: var(--tv-success); border-color: rgba(52,211,153,.4); background: rgba(52,211,153,.1); }
.tv-badge--warn  { color: var(--tv-amber-500); border-color: rgba(255,163,0,.4); background: rgba(255,163,0,.1); }
.tv-badge--danger{ color: var(--tv-danger); border-color: rgba(244,72,95,.4); background: rgba(244,72,95,.1); }
.tv-badge--dest  { color: var(--tv-text-muted); border-color: var(--tv-glass-border); background: transparent; } /* destino: S3/Drive/Local */
```

### 8.6 Tabelas (histórico)
Cabeçalho em `--tv-caption` uppercase âmbar. Colunas de dados em mono, `tabular-nums`, alinhadas à direita.
Linhas com hover `--tv-ink-500`. Zebra sutil via `rgba(255,255,255,0.02)`.

### 8.7 Modal de confirmação destrutiva (restore/exclusão)
O momento mais crítico da UX. **Dupla confirmação** (brief §4).
- Painel `glass--active` centralizado, overlay `rgba(0,0,0,0.6)` com blur leve.
- Título direto: "Restaurar este backup vai substituir o site atual."
- Explica a consequência + informa que um **backup de segurança automático** será criado antes.
- Campo de confirmação por digitação (ex.: digitar o nome do site) para ações irreversíveis.
- Botão de ação em `--tv-btn--danger`; cancelar em `--ghost` e como ação padrão do teclado (Esc).
- Sem animação celebratória. Sobriedade.

### 8.8 Toasts / notificações
Canto inferior direito, `tv-glass`, borda semântica à esquerda (4px). Auto-dismiss em sucesso;
persistente em erro (com ação de detalhe). Voz de interface, não pessoal (§10).

### 8.9 Estados vazios
Convite à ação, não decoração. Ex. (sem backups): título "Nenhum backup ainda." +
"Crie o primeiro para preservar o estado atual do site." + CTA primário "Criar backup agora".

---

## 9. Acessibilidade — regras rígidas

- **Nunca texto branco sobre âmbar.** `#fff` sobre `#ffa300` dá ~2:1 (reprova). Âmbar carrega **texto
  preto** (`--tv-text-on-amber`), contraste ~10:1. Isso vale para botões, badges preenchidos, chips.
- **Âmbar como texto sobre preto:** `#ffa300` sobre `--tv-ink-800` ≈ 10.5:1 — ótimo para links/destaques.
- **Foco sempre visível:** `outline: 2px solid var(--tv-amber-500); outline-offset: 2px` em todo
  interativo. Nunca `outline: none` sem substituto.
- **Contraste de corpo:** `--tv-text-body` sobre `--tv-ink-800` ≈ 12:1. `--tv-text-muted` só em ≥14px.
- **Glass e contraste:** garanta contraste do texto contra a cor *efetiva* do glass sobre o fundo, não
  contra o fill translúcido isolado. Em dúvida, aumente `--tv-glass-fill` ou use superfície sólida.
- **Alvo de toque:** mínimo 40×40px em controles.
- **Movimento:** respeitar `prefers-reduced-motion` (já em §7). Pulso e progresso animado desligam.
- **Não comunicar só por cor:** status sempre tem ícone/rótulo além da cor (daltonismo).

---

## 10. Voz da interface (copy)

Alinhado ao brief e às house rules da agência.
- **Ativa e literal:** o botão diz o que acontece. "Criar backup", não "Enviar". O botão "Restaurar"
  gera o toast "Restaurado".
- **Sentence case**, sem filler, sem exclamação em telas de sistema.
- **Nomes pelo que o usuário controla:** "Destino de armazenamento", não "storage adapter". "Agendamento",
  não "cron job".
- **Erros dirigem, não se desculpam:** o que houve + como resolver. "Não foi possível enviar ao S3: a
  credencial expirou. Atualize as chaves em Destinos."
- **Vazio convida à ação** (§8.9).
- **PT-BR** como padrão da UI (público da agência), com i18n via text domain `timevault` para futura tradução.

---

## 11. Integração com o wp-admin

- **Escopar todo o CSS** sob um wrapper (ex. `.timevault-app`) para não vazar estilos para o wp-admin
  nem sofrer override do core. Resetar o essencial dentro do wrapper.
- O tema escuro do plugin é **próprio**, independente do esquema de cores do admin do usuário.
- Respeitar `admin_body_class` e largura responsiva do conteúdo do wp-admin (colapso do menu lateral).
- Fontes **locais** (`.woff2` no bundle) — sem CDN externo (privacy/LGPD).
- Ícones: SVG inline no bundle (sem chamadas externas).

---

## 12. Resumo dos tokens (cola rápida)

```
Cor       preto → âmbar; âmbar = luz/estado, preto = profundidade
Fundo     --tv-ink-800 + glows âmbar radiais (.tv-app-bg)
Vidro     .tv-glass / .tv-glass--active (blur 16–28px, borda 1px)
Texto     strong #f7f7f5 · body #d4d4d2 · muted #9a9aa2 · on-amber #0a0a0b
Marca     --tv-amber-500 #ffa300 (hover 400, pressed 600)
Fonte     Plus Jakarta Sans (UI) + JetBrains Mono (dados)
Raio      painéis 18px · botões 12px · pills 999px
Assinatura Espinha Temporal (timeline vertical de restore points)
A11y      texto preto sobre âmbar; foco âmbar 2px; AA sempre
```
