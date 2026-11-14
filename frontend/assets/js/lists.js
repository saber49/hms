/* ============================================
   HMS Gruppe — Listen & Konfiguration Module
   Backend: api/lists.php
   ============================================ */

async function renderLists() {
    const content = document.getElementById('content');
    const data = await apiGet(API.lists);
    const items = Array.isArray(data) ? data : [];

    const grouped = {};
    items.forEach(i => {
        if (!grouped[i.kategorie]) grouped[i.kategorie] = [];
        grouped[i.kategorie].push(i);
    });

    content.innerHTML = `
        <div class="toolbar">
            <div class="toolbar-left"></div>
            <md-filled-button  onclick="openListModal()"><i slot="icon" class="material-symbols-outlined">add</i> Neuer Eintrag</md-filled-button>
        </div>
        ${Object.keys(grouped).map(cat => `
            <div class="card mb-2">
                <div class="card-header"><h3>${cat}</h3></div>
                <div class="card-body no-pad">
                    <table>
                        <thead><tr><th>Wert</th><th>Beschreibung</th><th>Aktiv</th><th>Sortierung</th><th>Aktionen</th></tr></thead>
                        <tbody>
                            ${grouped[cat].map(l => `<tr>
                                <td><strong>${l.wert}</strong></td>
                                <td>${l.beschreibung || '–'}</td>
                                <td>${l.aktiv == 1 ? '<span class="status status-ja">Ja</span>' : '<span class="status status-nein">Nein</span>'}</td>
                                <td>${l.sortierung}</td>
                                <td>
                                    <div class="btn-group">
                                        <md-icon-button  onclick="openListModal(${l.id})" title="Bearbeiten"><i class="material-symbols-outlined">edit</i></md-icon-button>
                                        <md-icon-button  class="md-icon-danger" onclick="deleteListItem(${l.id})" title="Löschen"><i class="material-symbols-outlined">delete</i></md-icon-button>
                                    </div>
                                </td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `).join('')}
        ${Object.keys(grouped).length === 0 ? '<div class="empty-state"><i class="material-symbols-outlined">list</i><p>Keine Listeneinträge</p></div>' : ''}
    `;
}

async function openListModal(id = null) {
    let item = {};
    if (id) {
        const all = await apiGet(API.lists);
        item = all.find(l => l.id == id) || {};
    }
    const body = `
        <div class="form-grid">
            <div class="form-group"><label>Kategorie *</label><input type="text" class="form-control" id="f_list_kat" value="${item.kategorie || ''}" placeholder="z.B. modell"></div>
            <div class="form-group"><label>Wert *</label><input type="text" class="form-control" id="f_list_wert" value="${item.wert || ''}"></div>
            <div class="form-group"><label>Beschreibung</label><input type="text" class="form-control" id="f_list_beschr" value="${item.beschreibung || ''}"></div>
            <div class="form-group"><label>Sortierung</label><input type="number" class="form-control" id="f_list_sort" value="${item.sortierung || 0}"></div>
            <div class="form-group"><label>Aktiv</label><select class="form-control" id="f_list_aktiv"><option value="1" ${item.aktiv != 0 ? 'selected' : ''}>Ja</option><option value="0" ${item.aktiv == 0 ? 'selected' : ''}>Nein</option></select></div>
        </div>
    `;
    const footer = `
        <md-outlined-button  onclick="closeModal()">Abbrechen</md-outlined-button>
        <md-filled-button  onclick="saveListItem(${id || 'null'})">${id ? 'Aktualisieren' : 'Erstellen'}</md-filled-button>
    `;
    openModal(id ? 'Eintrag bearbeiten' : 'Neuer Listeneintrag', body, footer);
}

async function saveListItem(id) {
    const data = {
        kategorie: document.getElementById('f_list_kat').value,
        wert: document.getElementById('f_list_wert').value,
        beschreibung: document.getElementById('f_list_beschr').value || null,
        sortierung: parseInt(document.getElementById('f_list_sort').value) || 0,
        aktiv: document.getElementById('f_list_aktiv').value
    };
    if (!data.kategorie || !data.wert) { showToast('Kategorie und Wert erforderlich', 'error'); return; }
    if (id) data.id = id;

    const result = id ? await apiPut(API.lists + '/' + id, data) : await apiPost(API.lists, data);
    if (result.success) {
        closeModal();
        showToast('Gespeichert');
        renderLists();
    } else {
        showToast(result.error || 'Fehler', 'error');
    }
}

async function deleteListItem(id) {
    if (!confirm('Eintrag löschen?')) return;
    const result = await apiDelete(API.lists, { id: id });
    if (result.success) { showToast('Gelöscht'); renderLists(); }
}
