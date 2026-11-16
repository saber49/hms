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
