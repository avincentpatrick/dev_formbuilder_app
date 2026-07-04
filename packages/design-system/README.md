# @meridian/design-system

The single shared design system for Meridian. **One system, every page** — the builder,
dashboard, submissions inbox, settings, and the public form runtime all consume these tokens
and components. No page invents its own styling (see `docs/ux/design-system-reference.md`).

## What's here (Phase 0 seed)

- **Tokens** — `tokens/*.json` (blueprint palette: primitives + a semantic layer), built by
  `build-tokens.mjs` (Style Dictionary) into:
  - `dist/tokens.css` — `--mds-*` custom properties on `:root`
  - `dist/tokens.ts` — a typed `mdsToken` map for TS consumers
- **Theme + personalization** — `src/theme/theme-overrides.css` re-points the *semantic* layer for
  `data-theme-mode="dark"` and `data-accent="teal"` (components never change). "system" = the
  absence of `data-theme-mode`.
- **Components** — `src/components/**` (Phase 0: `Button`). Each has a Storybook story.
- **Accessibility** — `@storybook/test-runner` + axe runs on every story; any violation fails CI
  (WCAG 2.2 AA, merge-blocking).

## Scripts

```bash
npm run build:tokens        # regenerate dist/tokens.css + dist/tokens.ts
npm run storybook           # local Storybook on :6006
npm run build-storybook     # build tokens + static Storybook
npm run test-storybook      # axe every story (requires a running/built Storybook)
```

Run these from the repo root as `npm run ds:tokens`, `npm run ds:storybook:build`, `npm run ds:test`.
