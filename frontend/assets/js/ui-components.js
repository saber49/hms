/* =====================================================================
   ui-components.js — ONE way to emit UI building blocks.

   New or changed UI uses these helpers instead of hand-written markup
   or a new per-module class. Every helper returns an HTML string (the
   codebase renders via template strings + innerHTML) and maps onto
   @material/web components where the bundle ships one:

     uiIcon(name)                      Material Symbol <span>
     uiButton(label, opts)             md-filled|outlined|text|filled-tonal-button
     uiIconButton(icon, opts)          md-icon-button (+ title / aria-label)
     uiTextField(opts)                 md-outlined-text-field (+ leading icon)
     uiSelect(opts) / uiOption()       md-outlined-select + md-select-option
     uiChip(label, opts) / uiChips()   md-assist|filter|input-chip in md-chip-set
     uiTabs(id, items, active)         md-tabs + md-primary-tab, uiBindTabs()
     uiCard(opts)                      .ui-card  (no md card exists)
     uiToolbar(start, end)             .ui-toolbar
     uiEmptyState(icon, text, opts)    .ui-empty
     uiBadge(label, tone, icon)        .ui-badge (read-only status label)
     uiActions(html, end)              .ui-actions button row
     uiPickList(id, itemsHtml) / uiPickItem(value, label)
                                       search-result picker (md-list) that
                                       replaces <select size=N>; the value
                                       lives in a hidden input #id

   Text arguments are escaped. Arguments documented as *Html are inserted
   verbatim — they must already be safe markup (e.g. other ui* output).
   `onclick`/`attrs` are raw attribute strings: never put user data in
   them without escapeHtml().
   Styles: assets/css/ui-components.css (tokens only).
===================================================================== */

function _uiEsc(v) {
    return v === null || v === undefined ? '' : escapeHtml(String(v));
}

function _uiAttrs({ id, cls, onclick, title, attrs, disabled, type } = {}) {
    return (id ? ` id="${_uiEsc(id)}"` : '')
        + (cls ? ` class="${_uiEsc(cls)}"` : '')
        + (title ? ` title="${_uiEsc(title)}" aria-label="${_uiEsc(title)}"` : '')
        + (type ? ` type="${_uiEsc(type)}"` : '')
        + (disabled ? ' disabled' : '')
        + (onclick ? ` onclick="${onclick}"` : '')
        + (attrs ? ` ${attrs}` : '');
}

/** Slotted icons (inside md-* components) are <md-icon> so the component
 *  sizes them; free-standing icons are the Material Symbols <span>. */
function uiIcon(name, { slot = '', cls = '' } = {}) {
    if (slot) return `<md-icon slot="${_uiEsc(slot)}"${cls ? ` class="${_uiEsc(cls)}"` : ''} aria-hidden="true">${_uiEsc(name)}</md-icon>`;
    return `<span class="material-symbols-outlined${cls ? ' ' + _uiEsc(cls) : ''}" aria-hidden="true">${_uiEsc(name)}</span>`;
}

/**
 * @param {{id?:string, label?:string, type?:string, value?:string|number,
 *   placeholder?:string, icon?:string, cls?:string, attrs?:string,
 *   disabled?:boolean, required?:boolean}} opts
 */
function uiTextField(opts = {}) {
    const a = (opts.label ? ` label="${_uiEsc(opts.label)}"` : '')
        + ` type="${_uiEsc(opts.type || 'text')}"`
        + (opts.value !== undefined && opts.value !== null && opts.value !== '' ? ` value="${_uiEsc(opts.value)}"` : '')
        + (opts.placeholder ? ` placeholder="${_uiEsc(opts.placeholder)}"` : '')
        + (opts.required ? ' required' : '');
    const icon = opts.icon ? uiIcon(opts.icon, { slot: 'leading-icon' }) : '';
    return `<md-outlined-text-field${_uiAttrs({ id: opts.id, cls: opts.cls, attrs: opts.attrs, disabled: opts.disabled })}${a}>${icon}</md-outlined-text-field>`;
}

function uiOption(value, label, selected = false) {
    return `<md-select-option value="${_uiEsc(value)}"${selected ? ' selected' : ''}><div slot="headline">${_uiEsc(label)}</div></md-select-option>`;
}

/** @param {{id?:string, label?:string, optionsHtml?:string, cls?:string, attrs?:string, disabled?:boolean}} opts */
function uiSelect(opts = {}) {
    return `<md-outlined-select${_uiAttrs(opts)}${opts.label ? ` label="${_uiEsc(opts.label)}"` : ''}>${opts.optionsHtml || ''}</md-outlined-select>`;
}

const UI_BUTTON_TAGS = {
    filled: 'md-filled-button',
    outlined: 'md-outlined-button',
    text: 'md-text-button',
    tonal: 'md-filled-tonal-button',
};

/**
 * @param {string} label
 * @param {{variant?:'filled'|'outlined'|'text'|'tonal', icon?:string,
 *   trailingIcon?:boolean, tone?:'danger'|'success'|'warning', size?:'sm',
 *   id?:string, cls?:string, onclick?:string, title?:string, attrs?:string,
 *   disabled?:boolean, type?:'button'|'submit'|'reset'}} opts
 */
function uiButton(label, opts = {}) {
    const tag = UI_BUTTON_TAGS[opts.variant || 'filled'] || UI_BUTTON_TAGS.filled;
    const cls = [opts.tone ? `md-btn-${opts.tone}` : '', opts.size === 'sm' ? 'md-btn-sm' : '', opts.cls || '']
        .filter(Boolean).join(' ');
    const icon = opts.icon ? uiIcon(opts.icon, { slot: 'icon' }) : '';
    return `<${tag}${_uiAttrs({ ...opts, cls })}${opts.icon && opts.trailingIcon ? ' trailing-icon' : ''}>${icon}${_uiEsc(label)}</${tag}>`;
}

/**
 * @param {string} icon
 * @param {{title:string, tone?:'danger'|'success'|'primary', id?:string,
 *   cls?:string, onclick?:string, attrs?:string, disabled?:boolean}} opts
 *   `title` is required — it is the accessible name of the button.
 */
function uiIconButton(icon, opts = {}) {
    const cls = [opts.tone ? `md-icon-${opts.tone}` : '', opts.cls || ''].filter(Boolean).join(' ');
    return `<md-icon-button${_uiAttrs({ ...opts, cls })}>${uiIcon(icon)}</md-icon-button>`;
}

/**
 * kind 'assist' = action, 'filter' = toggle (selected), 'input' = removable value.
 * @param {string} label
 * @param {{kind?:'assist'|'filter'|'input', icon?:string, selected?:boolean,
 *   id?:string, cls?:string, onclick?:string, attrs?:string, disabled?:boolean}} opts
 */
function uiChip(label, opts = {}) {
    const tag = `md-${['assist', 'filter', 'input'].includes(opts.kind) ? opts.kind : 'assist'}-chip`;
    const icon = opts.icon ? uiIcon(opts.icon, { slot: 'icon' }) : '';
    return `<${tag} label="${_uiEsc(label)}"${opts.selected ? ' selected' : ''}${_uiAttrs(opts)}>${icon}</${tag}>`;
}

function uiChips(chipsHtml, { id = '', cls = '' } = {}) {
    return `<md-chip-set${_uiAttrs({ id, cls: ['ui-chips', cls].filter(Boolean).join(' ') })}>${chipsHtml}</md-chip-set>`;
}

/**
 * @param {string} id
 * @param {{key:string, label:string, icon?:string}[]} items
 * @param {string} activeKey
 */
function uiTabs(id, items, activeKey, { cls = '' } = {}) {
    const tabs = items.map(t => `<md-primary-tab data-key="${_uiEsc(t.key)}"${t.key === activeKey ? ' active' : ''}>${t.icon ? uiIcon(t.icon, { slot: 'icon' }) : ''}${_uiEsc(t.label)}</md-primary-tab>`).join('');
    return `<md-tabs${_uiAttrs({ id, cls: ['ui-tabs', cls].filter(Boolean).join(' ') })}>${tabs}</md-tabs>`;
}

/** Wire a uiTabs() bar after it is in the DOM: onChange(key). */
function uiBindTabs(id, onChange) {
    document.getElementById(id)?.addEventListener('change', e => {
        const key = e.target.activeTab?.dataset?.key;
        if (key) onChange(key);
    });
}

/**
 * @param {{title?:string, icon?:string, subtitle?:string, actionsHtml?:string,
 *   bodyHtml?:string, flush?:boolean, elevated?:boolean, id?:string, cls?:string}} opts
 */
function uiCard(opts = {}) {
    const head = (opts.title || opts.actionsHtml)
        ? `<div class="ui-card-head"><div style="flex:1 1 auto;min-width:0">${opts.title ? `<h3 class="ui-card-title">${opts.icon ? uiIcon(opts.icon) : ''}${_uiEsc(opts.title)}</h3>` : ''}${opts.subtitle ? `<div class="ui-card-sub">${_uiEsc(opts.subtitle)}</div>` : ''}</div>${opts.actionsHtml ? `<div class="ui-card-actions">${opts.actionsHtml}</div>` : ''}</div>`
        : '';
    const cls = ['ui-card', opts.elevated ? 'is-elevated' : '', opts.cls || ''].filter(Boolean).join(' ');
    return `<section${_uiAttrs({ id: opts.id, cls })}>${head}<div class="ui-card-body${opts.flush ? ' is-flush' : ''}">${opts.bodyHtml || ''}</div></section>`;
}

function uiToolbar(startHtml = '', endHtml = '', { id = '', cls = '' } = {}) {
    return `<div${_uiAttrs({ id, cls: ['ui-toolbar', cls].filter(Boolean).join(' ') })}><div class="ui-toolbar-start">${startHtml}</div>${endHtml ? `<div class="ui-toolbar-end">${endHtml}</div>` : ''}</div>`;
}

/** @param {{title?:string, actionHtml?:string, cls?:string}} opts */
function uiEmptyState(icon, text, opts = {}) {
    return `<div class="ui-empty${opts.cls ? ' ' + _uiEsc(opts.cls) : ''}">${uiIcon(icon || 'inbox')}${opts.title ? `<p class="ui-empty-title">${_uiEsc(opts.title)}</p>` : ''}${text ? `<p class="ui-empty-text">${_uiEsc(text)}</p>` : ''}${opts.actionHtml ? `<div class="ui-empty-action">${opts.actionHtml}</div>` : ''}</div>`;
}

/** tone: 'neutral'|'info'|'success'|'warning'|'error'|'primary' */
function uiBadge(label, tone = 'neutral', icon = '') {
    const t = ['info', 'success', 'warning', 'error', 'primary'].includes(tone) ? ` is-${tone}` : '';
    return `<span class="ui-badge${t}">${icon ? uiIcon(icon) : ''}${_uiEsc(label)}</span>`;
}

function uiActions(html, end = false) {
    return `<div class="ui-actions${end ? ' is-end' : ''}">${html}</div>`;
}

/**
 * Pick list — the MD replacement for a native listbox (<select size=N>),
 * e.g. search results to choose one record from. Value is kept in a hidden
 * <input id> so existing readers (`el.value`, `[data-f]` error marking)
 * keep working. Refill with `uiPickFill(id, itemsHtml)`.
 */
function uiPickItem(value, label, selected = false) {
    return `<md-list-item type="button" class="ui-pick-item${selected ? ' is-selected' : ''}" data-value="${_uiEsc(value)}" onclick="uiPick(this)"><div slot="headline">${_uiEsc(label)}</div>${selected ? uiIcon('check', { slot: 'end' }) : ''}</md-list-item>`;
}
function uiPickList(id, itemsHtml = '', { value = '', attrs = '', emptyText = 'Keine Treffer' } = {}) {
    return `<input type="hidden" id="${_uiEsc(id)}" value="${_uiEsc(value)}"${attrs ? ' ' + attrs : ''}><md-list class="ui-pick" data-for="${_uiEsc(id)}" data-empty="${_uiEsc(emptyText)}">${itemsHtml}</md-list>`;
}
function uiPickFill(id, itemsHtml) {
    const list = document.querySelector(`md-list.ui-pick[data-for="${CSS.escape(id)}"]`);
    const input = document.getElementById(id);
    if (!list) return;
    list.innerHTML = itemsHtml;
    if (input && ![...list.children].some(i => i.dataset.value === input.value)) input.value = '';
}
function uiPick(item) {
    const list = item.closest('md-list.ui-pick');
    const input = list && document.getElementById(list.dataset.for);
    if (!input) return;
    list.querySelectorAll('.ui-pick-item').forEach(i => {
        const on = i === item;
        i.classList.toggle('is-selected', on);
        i.querySelector('md-icon[slot="end"]')?.remove();
        if (on) i.insertAdjacentHTML('beforeend', uiIcon('check', { slot: 'end' }));
    });
    input.value = item.dataset.value;
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

// `data-max` = length limit WITHOUT the md character counter (maxlength on a
// single-line md field renders "0 / 200" under every field). Textareas may
// keep a real maxlength — there the counter is useful.
document.addEventListener('input', e => {
    const f = e.target && e.target.closest ? e.target.closest('md-outlined-text-field[data-max]') : null;
    const max = f ? parseInt(f.dataset.max, 10) : 0;
    if (max > 0 && f.value.length > max) f.value = f.value.slice(0, max);
}, true);

/**
 * File input: md button opens a hidden native <input type="file" id> (there
 * is no md file component). Readers keep using `getElementById(id).files`.
 * @param {{id:string, label?:string, accept?:string, attrs?:string, buttonLabel?:string, cls?:string}} opts
 */
function uiFilePicker(opts = {}) {
    return `<div class="ui-file${opts.cls ? ' ' + _uiEsc(opts.cls) : ''}">${opts.label ? `<div class="ui-file-label">${_uiEsc(opts.label)}</div>` : ''}<div class="ui-file-row">${uiButton(opts.buttonLabel || 'Datei wählen', { variant: 'outlined', icon: 'attach_file', onclick: 'this.nextElementSibling.click()', type: 'button' })}<input type="file" hidden id="${_uiEsc(opts.id)}"${opts.accept ? ` accept="${_uiEsc(opts.accept)}"` : ''}${opts.attrs ? ' ' + opts.attrs : ''} onchange="uiFileName(this)"><span class="ui-file-name">Keine Datei ausgewählt</span></div></div>`;
}
function uiFileName(input) {
    const n = input.nextElementSibling;
    if (n) n.textContent = input.files && input.files.length ? [...input.files].map(f => f.name).join(', ') : 'Keine Datei ausgewählt';
}

// md-radio / md-checkbox / md-switch do NOT reflect user changes to their
// `checked` / `selected` attribute (property only). Existing readers and CSS
// use `md-radio[checked]`, `md-checkbox[checked]`, `:has(md-radio[checked])`
// — keep the attribute in sync after every user change so those stay true.
document.addEventListener('change', e => {
    const t = e.target;
    if (!t || !t.tagName) return;
    if (t.tagName === 'MD-RADIO' && t.name) {
        document.querySelectorAll(`md-radio[name="${CSS.escape(t.name)}"]`).forEach(r => r.toggleAttribute('checked', !!r.checked));
    } else if (t.tagName === 'MD-CHECKBOX') {
        t.toggleAttribute('checked', !!t.checked);
    } else if (t.tagName === 'MD-SWITCH') {
        t.toggleAttribute('selected', !!t.selected);
    }
}, true);

// Segmented controls (view switchers, filter groups) are md tabs / filter
// chips marked `data-seg`. Module code keeps toggling its own state class
// (`active`, `is-on`, `on`, …) — this keeps the md `active`/`selected`
// state in line with that class, also for markup rendered later.
const UI_SEG_ON = /(^|\s)(active|is-on|on|ml-tab-active|selected|is-active)(\s|$)/;
function uiSegSync(el) {
    const on = UI_SEG_ON.test(el.className || '');
    if (el.tagName === 'MD-FILTER-CHIP') { if (el.selected !== on) el.selected = on; }
    else if (el.tagName === 'MD-PRIMARY-TAB' || el.tagName === 'MD-SECONDARY-TAB') { if (el.active !== on) el.active = on; }
}
function uiSegSyncAll(root) {
    if (root.matches && root.matches('[data-seg]')) uiSegSync(root);
    if (root.querySelectorAll) root.querySelectorAll('[data-seg]').forEach(uiSegSync);
}
new MutationObserver(muts => {
    for (const m of muts) {
        if (m.type === 'attributes') { if (m.target.hasAttribute('data-seg')) uiSegSync(m.target); }
        else m.addedNodes.forEach(n => { if (n.nodeType === 1) uiSegSyncAll(n); });
    }
}).observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
// a click on an already-active chip toggles it off internally without any
// class change — re-sync the group after the module's own handler ran
document.addEventListener('click', e => {
    const seg = e.target && e.target.closest && e.target.closest('[data-seg]');
    if (seg) setTimeout(() => uiSegSyncAll(seg.parentElement || seg), 0);
});

// A native <select> shows its first option when none is marked `selected`;
// md-outlined-select shows an empty field. Placeholder options like
// "Alle Kunden" / "— wählen —" are rendered without `selected` all over
// the code — pick the value="" option (else the first) like the browser did.
function uiSelectDefault(sel) {
    const run = () => {
        if (sel.selectedOptions && sel.selectedOptions.length) return;
        const opts = [...sel.querySelectorAll(':scope > md-select-option')];
        const o = opts.find(x => (x.getAttribute('value') ?? '') === '') || opts[0];
        if (o && !o.disabled) sel.select ? sel.select(o.value) : (o.selected = true);
    };
    Promise.resolve(sel.updateComplete).then(() => requestAnimationFrame(run));
}
new MutationObserver(muts => {
    for (const m of muts) m.addedNodes.forEach(n => {
        if (n.nodeType !== 1) return;
        if (n.tagName === 'MD-OUTLINED-SELECT') uiSelectDefault(n);
        else if (n.tagName === 'MD-SELECT-OPTION' && n.parentElement?.tagName === 'MD-OUTLINED-SELECT') uiSelectDefault(n.parentElement);
        else if (n.querySelectorAll) n.querySelectorAll('md-outlined-select').forEach(uiSelectDefault);
    });
}).observe(document.documentElement, { subtree: true, childList: true });
