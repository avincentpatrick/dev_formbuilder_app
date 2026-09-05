# @meridian/design-system

The single shared design system for Meridian. **One system, every page** — the builder,
dashboard, submissions inbox, settings, and the public form runtime all consume these tokens
and components. No page invents its own styling (see `docs/ux/design-system-reference.md`).

## What's here

- **Tokens** — `tokens/*.json` (blueprint palette: primitives + a semantic layer), built by
  `build-tokens.mjs` (Style Dictionary) into:
  - `dist/tokens.css` — `--mds-*` custom properties on `:root`
  - `dist/tokens.ts` — a typed `mdsToken` map for TS consumers
- **Theme + personalization** — `src/theme/theme-overrides.css` re-points the *semantic* layer for
  `data-theme-mode="dark"` and `data-accent="teal"` (components never change). "system" = the
  absence of `data-theme-mode`.
- **Components** — `src/components/**`: **39 components**, each with a Storybook story (42 story files).
- **Accessibility** — `@storybook/test-runner` + axe runs on every story; any violation fails CI
  (WCAG 2.2 AA, merge-blocking).

## Scripts

```bash
npm run build:tokens        # regenerate dist/tokens.css + dist/tokens.ts
npm run storybook           # local Storybook on :6006
npm run build-storybook     # build tokens + static Storybook
npm run test-storybook      # axe every story (requires a running/built Storybook)
```

Run them from the repository root through these aliases. ⛔ **Every package script has one,
and this table is the whole mapping** — it used to name three of the four in a sentence and
silently drop `storybook`, the one with no alias, which is how a preview nobody could start went
unnoticed for as long as it did.

| Package script | Root alias | What it is for |
|---|---|---|
| `build:tokens` | `ds:tokens` | regenerate `dist/tokens.{css,ts}` after editing `tokens/*.json` |
| `storybook` | `ds:storybook` | **local preview** on `:6006`, published by the `node` service |
| `build-storybook` | `ds:storybook:build` | build tokens + the static Storybook that CI scans |
| `test-storybook` | `ds:test` | axe every story; needs a server already up, so CI passes `--url` |

`ds:install` is a fifth alias with no package-script counterpart — it is `npm install` in this
directory, and the root `README.md` makes it the mandatory first bootstrap step.

⚠️ **From the host, run them through the container**, e.g.
`docker compose exec node npm run ds:storybook`, then open `http://localhost:6006`.

⛔ **AND `ds:install` MUST HAVE BEEN RUN *INSIDE THE CONTAINER*, OR `ds:storybook` DIES ON A MISSING
PRESET — measured, not predicted (M72).** A Windows-host `npm install` here uses `--no-bin-links`
(root `README.md` says why: a nested esbuild-bin file lock), which leaves a **split tree**: the
`storybook` CLI hoisted to the root `node_modules` and `@storybook/vue3-vite` only in this package's.
The root CLI then resolves presets from the root tree and fails with
`Cannot find module '@storybook/vue3-vite/preset'` — a message that names a package which *is*
installed, one directory away. `docker compose exec node npm run ds:install` produces a coherent
Linux install and the preview then answers on the first try.

⚠️ **Nothing gates this and nothing can.** It is a property of a developer's `node_modules`, which is
gitignored — not of any artefact in the repository. `tests/Feature/Docs/DocumentedCommandDriftTest.php`
proves the port is published and the alias exists; whether *your* tree can start the server is what
this paragraph is for.
