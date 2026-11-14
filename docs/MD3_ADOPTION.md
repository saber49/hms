# MD3 Adoption & Frontend Handoff

State as of 2026-11-14 (staging branch, commit ~c149f1c). Read `AGENTS.md` first — server/deploy conventions live there.

## Deploy workflow

- Monorepo `saber49/hms`: `backend/`→/var/www/hms-backend, `frontend/`→/var/www/hms-frontend.
- Flow: work on `staging` branch → `bash deploy/deploy.sh staging` → verify on https://staging.clood-x.com → merge to `main` → deploy `main` for prod.
- `deploy/deploy.sh` fetches + checks out `origin/<branch>` in server mirror `/var/www/hms-repo`, rsyncs to web roots. Frontend-only deploys skip backend restart. Rollback = deploy an older SHA.
- `?v=` cache-bust params must be bumped for every changed asset: direct `<script>/<link>` tags in `index.php`, AND the lazy module map `PAGE_MODULE_SCRIPTS` in `assets/js/app.js` (crm/objects/lists/reports/controlling/etc.), AND standalone pages (`nu_portal.php` etc.). Current series: `20261114md3d`.
- Some files use **CRLF** (incoming_orders.js, subcontractors.js, users.js + several css) — always edit byte-preserving (`newline=''` in Python). A botched codemod once normalized them; restored from git.

## MD3 foundation

- `assets/css/md3-tokens.css` — official-style MD3 palettes (seed `#0B57D0`), light+dark roles, typescale/shape/elevation/motion, extended roles `--md-ext-color-{success,warning,info}{,-container,on-container}`.
- `assets/css/theme-unified.css` — unifies module accent palettes behind MD3 roles.
- `assets/css/style.css` — legacy-var bridge (`--primary`,`--accent`,`--gray-*`,`--shadow`,`--radius-*`) → MD3 tokens; shared chrome (buttons, fields, dialogs, tables).
- `assets/js/material-web.bundle.js` — vendored @material/web bundle. **NEVER run codemods over it** (a checkbox codemod once corrupted it; restored via `git checkout HEAD --`). Loads only in `index.php` + `nu_portal.php` — standalone pages without it cannot render `md-*` elements.
- Material Symbols font loaded via Google Fonts link (added to all auth/marketing pages; `display=block`).

## Completed work

1. **Dead CSS** ~980 lines removed (verified: no markup refs + no dynamic prefixes). Keep dynamic families: `is-*`, `qm-art-*`, `doc-baustein-*`, `planner-*`, `crm-*`, `ma-*`, `schichtart-*`, `be-*`.
2. **Colors** ~2043 hardcoded hex/rgba → `--md-sys-color-*`/`--md-ext-color-*`, context-aware (text/bg/border/shadow × hue × lightness); translucency → `color-mix()`; white text preserved on colored fills; gradients fixed where endpoints collapsed. Same pass run on `<style>` blocks + `style=""` attrs in JS and PHP. Chart.js/jsPDF/canvas colors were NOT touched — they need runtime resolution (`getComputedStyle`), never `var()`/`color-mix()`.
3. **Shape/elevation** ~648 radii → `corner-*` scale; shadows → `elevation-level*` (semantic colored glows kept).
4. **Safe merge** ~1234 lines — adjacent identical rules only, never across `@media`.
5. **Shared chrome → MD spec**: scrim black 32%, outlined cards, 2px primary focus, state-layer hover.
6. **Buttons** 76 sites `btn-*` → `<md-filled|outlined|text-button>` + `slot="icon"`; semantic variants `md-btn-danger|success|warning` classes in style.css.
7. **Icons** ~550 Font Awesome → Material Symbols (JS + PHP + HTML, incl. 97 in landing.html); `fa-spinner` → `md-circular-progress`/`.ms-spin`; dynamic icon maps fixed (`call.js` mic, `order_form.js` status icons, `verify-email.php`, `kunden_portal` folders); `order_form.php` keeps its runtime shim. `fa-subset.css` links removed where dead. `order_form.php`+`register.php` keep native checkboxes (no bundle).
8. **Fields** 111 `input.form-control`+`textarea` → `md-outlined-text-field` (class kept so `.form-control` selectors still match); 18 `<select>`+options → `md-outlined-select`/`md-select-option`; skipped: file/checkbox/radio/hidden/dynamic-type.
9. **`#modalOverlay` → real `<md-dialog>`**: `openModal`→`.show()`, `closeModal`→`.close()` (open-attr fallback), `close` event wired for cleanup, modal-guard + scroll-lock selectors → `md-dialog#modalOverlay[open]`, crm check → `.open`. Inner `.modal` keeps sizing contract (700px/xl/lg/max); `#modalOverlay::part(container)` uncapped.
10. **Checkboxes/radios/switch** 45 sites → `md-checkbox`/`md-radio`; `.cl-switch` custom toggle → `md-switch` (`selected` prop).

## JS↔MD compatibility rules (verified in bundle source)

- md fields support: `.value`, `valueAsNumber/Date`, `min/max/step`, `pattern`, `required`, `disabled`, `readOnly`, `placeholder`, `autocomplete`, `inputMode`, `select()`, `selectionStart/End`, `setSelectionRange`, `setRangeText`, `stepUp/stepDown`, `showPicker()`, `checkValidity/reportValidity`, `form`/`name` (form-associated → `form.elements`, FormData, reset, `<label for>` all work).
- **`:checked` never matches custom elements** → selectors must use `[checked]` (md-checkbox/radio reflect attr); md-switch uses `selected`.
- **`blur`/`focus` don't cross shadow boundary** → use `focusout`/`focusin` (composed). `input`/`change`/key events ARE composed → `oninput=` etc. work.
- `document.activeElement` inside an md field reports the HOST (tag `MD-OUTLINED-TEXT-FIELD`) — tagName guards must include MD tags (done in order_form_elements.js).
- `querySelector('input')` misses md fields — fixed sites use `'input, md-outlined-text-field'`; `.options`/`selectedIndex`/`selectedOptions` exist on md-outlined-select.
- Guard CSS in style.css + nu_portal.css: `md-outlined-*[class*="form-control"]{padding:0!important;border:none!important;background:transparent!important;box-shadow:none!important;min-height:0!important;height:auto!important;--md-outlined-*-top-space:6px;--md-outlined-*-bottom-space:6px}` — legacy `.form-control` site rules (e.g. `height:40px`) target the host and clip the inner field without this.

## Known pending / unresolved

- **`u-sec-head` layout bug (UNRESOLVED)**: in "Benutzer bearbeiten" dialog, section-head `<strong>`/`<small>` render on one line. DevTools showed `div.u-sec-head` matching only wildcard/UA rules — `.u-sec-head{display:flex}` from users.css absent despite file served fine, rules top-level (not inside @media), bytes clean, other users.css rules working on the page. Defensive fix `.u-sec-head > div{display:flex;flex-direction:column}` added at users.css:435 — needs user verification (could have been a stale-cache capture). If it persists: check whether users.css applies inside the dialog at all (Network tab + Sources search); fallback = move dialog styles into style.css or inline them.
- **Deferred**: `system-confirm-overlay`/`wp-overlay`/`planner-nu-select-overlay`/`chat-panel`/`nu-modal-overlay` custom dialogs → md-dialog (per-site wiring); dead `.btn*`/`.modal-overlay` CSS cleanup (~100-200 lines); `.form-control`→md in standalone PHP pages (register.php, kunden_login, nu_login have no bundle — add it or restyle native); `lists.js` appears unreferenced (dead file?).
- **Never convert**: file inputs (no md component), data tables, planner, PDF designer, calendars (no MD equivalent), marketing Tailwind pages (own design system), Bereich 12-color palette (domain data — use `bereichColor*` helpers in app.js).
- Watch for more `height`-on-`.form-control` host rules and `querySelector('input')`-style lookups — same breakage pattern may lurk in untested modules.
