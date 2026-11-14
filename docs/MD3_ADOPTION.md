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

## Post-review fixes (2026-11-14, `?v=` series `20261114md3e`)

Regression sweep found + fixed:
- `order_form.js` emitted `<md-checkbox>`/`<md-circular-progress>` (6 sites) into order_form.php which has **no bundle** → reverted to native inputs (readers already used `input[...]` + `.checked`).
- `messages.js` chat-user-item onclick did `querySelector('input').checked` on an md-checkbox → `null` TypeError; now toggles the md-checkbox and syncs `.selected`.
- `verify-email.php` `setState()` received FA names → literal text in the ligature element → now real Material names.
- `kunden_portal/qm-*.js` rendered `<i class="fas fa-*">` (19 icons) with no FA on the page → converted to `material-symbols-outlined` (incl. `KP_WB_ART_ICON` map).
- `_tagViewerActions` (viewer-mode gating) missed md buttons/icons → candidates now include `md-*-button`, icons matched by ligature name (`_MUTATION_ICON_NAME_RE`), primary check covers `MD-FILLED-BUTTON`.
- `.crm-erg-pill:has(input:checked)` → added `:has(md-radio[checked])`.
- `reset-/change-password.php` icon-positioning CSS targeted `i.fa-lock/fa-envelope` → now `.input-wrapper > i.material-symbols-outlined` (direct child — keeps `.toggle` eye icon unaffected).
- Leftover `fa-` in index.php context: `app.js` fav star, `systemConfirm({icon:'fa-calendar-times'})` ×2 in 03-dispo-detail, `fa-handshake` in 09-dispo-nu.
- `systemConfirm` colorMap hexes → MD3 ext/error tokens.
- `.crm-actions`/`.int-detail-actions`: no layout rule → md buttons glued together (native `<button>` clusters used to get whitespace) → flex+gap:8px.
- `landing.html` `window`/`apple` ligatures: `apple` isn't a Material Symbols name (renders literal text) and `window` draws a generic frame — replaced with inline brand SVGs (6 sites).

## Known pending / unresolved

- **`u-sec-head` layout bug (RESOLVED 2026-11-14 — true root cause found)**: users.css line 220 had an **unclosed `var(` paren** inside `linear-gradient` (`.role-chip.r-owner`) — the CSS parser swallowed **every rule from ~line 220 to EOF** (~290 lines): all `.u-head`/`.u-sec`/`.u-sec-head`/`.u-grid` dialog rules, `.role-chip.r-*` colors, `.manage-lock`, etc. That's why the dialog rendered raw while cards (styled by rules before 220) still worked, and why NO amount of `?v=` bumping or extra fix rules could ever apply — the file itself was malformed since the repo import. Fixed by closing the paren; verified headless: cssRules 39 → 144, `.u-sec-head` computes `display:flex`. The earlier "stale-cache" hypothesis was partially wrong — cache was real for OTHER assets (notifications.js `fa-bell`→`notifications` in `1992ba3` with stale `?v=20261019au1` = the missing bell; rule still holds: **ANY asset edit without a `?v=` bump is effectively never deployed**), but `u-sec-head` was a parse error, not a cache miss. users.css `?v=` → `20261114md3h`. Repo-wide paren-balance scan: no other CSS file affected.
- **Deferred**: `system-confirm-overlay`/`wp-overlay`/`planner-nu-select-overlay`/`chat-panel`/`nu-modal-overlay` custom dialogs → md-dialog (per-site wiring); `.form-control`→md in standalone PHP pages (register.php, kunden_login, nu_login have no bundle — add it or restyle native); `lists.js` appears unreferenced (dead file? — `checklisten.js` too, but its DB tables exist → in-progress feature, kept).
- **Done — dead CSS pass 2** (~320 lines, `md3h`): `.btn-icon`/`.btn-icon-danger` (style.css ×2 blocks, theme-unified), `.radio-label` + `.input-with-icon`/`.field-icon` + `.doc-actions .btn-icon` + `.data-table-card .btn-group`/`.btn-icon*` + `.modal.modal-wide .data-table-card .btn-icon` + `.zuschlag-toggle-slider` (customers.css), `.modal-overlay` all 4 rules (style.css), `.modal.be-context`/`.modal-overlay:has(.modal.be-context)` block (incoming_orders.css — replaced by `md-dialog.be-context` rules at ~1316), `.wk-doc-actions .btn-icon`/`.btn-sm` (workers.css → `md-icon-button` flex rule), `.ph-tl-actions`/`.btn-icon` (price_history.css). Verified: zero markup refs across all js/php/html; `.btn`, `.btn-group`, `.btn-danger`, `.zuschlag-toggle`, `.modal`, `.nu-modal-overlay`, planner rules kept (live).
- **Never convert**: file inputs (no md component), data tables, planner, PDF designer, calendars (no MD equivalent), marketing Tailwind pages (own design system), Bereich 12-color palette (domain data — use `bereichColor*` helpers in app.js).
- Watch for more `height`-on-`.form-control` host rules and `querySelector('input')`-style lookups — same breakage pattern may lurk in untested modules.
