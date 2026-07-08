# Fontes de marca (opcional)

O dashboard usa **Plus Jakarta Sans** (interface) e **JetBrains Mono** (dados de máquina),
conforme o design system. Por privacidade/LGPD, as fontes são **locais** — nunca via CDN
(evita vazamento de IP do visitante).

Sem os arquivos abaixo, a UI cai graciosamente para a stack do sistema (`system-ui`,
`ui-monospace`) — funcional e legível, só sem a tipografia da marca.

## Para ativar as fontes da marca

1. Baixe os `.woff2` (licenças SIL Open Font / Apache 2.0, uso comercial livre):
   - Plus Jakarta Sans: pesos 400, 500, 600, 700
   - JetBrains Mono: peso 500
2. Coloque os arquivos nesta pasta (`assets/fonts/`).
3. Adicione as regras `@font-face` no topo de `assets/css/timevault-admin.css`, por exemplo:

```css
@font-face {
	font-family: "Plus Jakarta Sans";
	src: url("../fonts/PlusJakartaSans-Regular.woff2") format("woff2");
	font-weight: 400; font-display: swap;
}
/* ...repita para 500/600/700 e para JetBrains Mono 500... */
```

O `font-family` já está referenciado nos tokens `--tv-font-sans` / `--tv-font-mono`; basta
os arquivos existirem e o `@font-face` apontar para eles.
