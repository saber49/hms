<?php
// Token-based auth — check is done client-side.
//
// Cache-busting helper: appends ?v=<filemtime> to local assets so any
// real change automatically invalidates the long-lived browser/Cloudflare
// cache (assets are served with `Cache-Control: immutable, max-age=1y`).
if (!function_exists('asset')) {
    function asset(string $path): string {
        $path = ltrim($path, '/');
        $abs  = __DIR__ . '/' . $path;
        $v    = is_file($abs) ? filemtime($abs) : time();
        return $path . '?v=' . $v;
    }
}

// Embedded mode: the page runs inside the orders.js fullscreen dialog
// (iframe). The back arrow then closes the parent modal instead of
// navigating the iframe to index.php.
$embedded   = isset($_GET['embed']) && $_GET['embed'] !== '0';
// Headless render mode: off-screen iframe driven by app.js
// hmsWithHeadlessEditor() — the same renderer + company template produces
// the PDFs of the Auftragsbestätigung list and the Monatsabschluss.
$headless   = isset($_GET['headless']) && $_GET['headless'] !== '0';
// Dokument-Design (System → Dokument-Design, ?design=1): no text editing,
// one fixed sidebar (Vorlage / Inhalt / Textbausteine) instead of the
// Bearbeiten / Einfügen toolbar. Real documents keep the classic editor.
$designUi   = isset($_GET['design']) && $_GET['design'] !== '0' && !$headless
              && !(isset($_GET['view']) && $_GET['view'] !== '0');
// The classic free-form editor was removed (2026-11-04): every real document
// opens in the viewer (body.viewer-mode) — download, print, Verlauf,
// signature/stamp and per-PDF column switches only.
$viewerOnly = !$designUi && !$headless;
// Safe-area insets (px) handed over by orders.js when the page runs inside
// the app-shell iframe: the iframe covers the whole edge-to-edge WebView,
// so the topbar must clear the status bar / Dynamic Island itself.
$safeTop    = min(120, max(0, (int)($_GET['sat'] ?? 0)));
$safeBottom = min(120, max(0, (int)($_GET['sab'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $viewerOnly ? 'PDF Ansicht' : 'PDF Editor' ?> — Auftragsbestätigung</title>
    <link rel="stylesheet" href="/assets/css/roboto.css?v=20261119ms1">
    <link rel="stylesheet" href="/assets/css/material-symbols.css?v=20261119ms1">
    <!-- Same theme bootstrap as index.php: light default, dark only when the
         user explicitly chose it (hms_theme). Set before stylesheets. -->
    <script>
    (function () {
        var t = localStorage.getItem('hms_theme');
        document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
    })();
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
    <link rel="stylesheet" href="<?= asset('assets/css/md3-tokens.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/order_form.css') ?>">
<?php if ($safeTop || $safeBottom): ?>
    <style>
    /* App-shell iframe: pad the sticky topbar by the status-bar inset (its
       gradient keeps filling the bar area) and leave room above the home
       indicator so the last page / the toolbar buttons stay reachable. */
    :root { --safe-top: <?= $safeTop ?>px; --safe-bottom: <?= $safeBottom ?>px; }
    .topbar { height: auto; min-height: calc(var(--topbar-h) + var(--safe-top)); padding-top: var(--safe-top); }
    .format-bar.visible { top: calc(var(--topbar-h) + var(--safe-top) + 12px); }
    .editor-layout, body.viewer-mode .editor-layout { min-height: calc(100vh - var(--topbar-h) - var(--safe-top)); }
    .canvas, body.viewer-mode .canvas { padding-bottom: calc(24px + var(--safe-bottom)); }
    #toastHost { bottom: calc(20px + var(--safe-bottom)); }
    
.material-symbols-outlined.ms-spin, .ms-spin { animation: msSpin 1s linear infinite; }
@keyframes msSpin { to { transform: rotate(360deg); } }
</style>
<?php endif; ?>
    <link rel="icon" href="/favicon.ico?v=2" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
</head>
<body class="<?= trim(($viewerOnly ? 'viewer-mode ' : '') . ($headless ? 'headless ' : '') . ($designUi ? 'dd-mode' : '')) ?>">
    <!-- ════════════════════════════════════════════════ TOPBAR ════════════════════════════════════════════════ -->
<?php if ($designUi): ?>
    <header class="topbar">
        <div class="topbar-left">
            <a href="index.php" class="tb-back" title="Zurück" onclick="return window.closeEditor ? window.closeEditor() : true"><span class="material-symbols-outlined">arrow_back</span></a>
            <div class="tb-brand">
                <span class="material-symbols-outlined">palette</span>
                <div>
                    <div class="tb-title">Dokumentvorlagen</div>
                    <div class="tb-subtitle" id="docMeta">Wird geladen…</div>
                </div>
            </div>
        </div>
        <div class="topbar-right">
            <button class="tb-btn dd-sb-toggle" onclick="ddToggleSidebar()" title="Anpassen">
                <span class="material-symbols-outlined">tune</span> <span>Anpassen</span>
            </button>
            <!-- Template changes are saved automatically (ddAutosaveTick) — the
                 sample document itself is never downloaded or filed here. -->
            <span class="dd-save-state" id="ddSaveState" data-state="saved"><span class="material-symbols-outlined">cloud_done</span> <span class="dd-ss-text">Gespeichert</span></span>
        </div>
    </header>
<?php else: ?>
    <header class="topbar">
        <div class="topbar-left">
            <a href="index.php" class="tb-back" title="Zurück" onclick="return window.closeEditor ? window.closeEditor() : true"><i class="material-symbols-outlined">arrow_back</i></a>
            <div class="tb-brand">
                <i class="material-symbols-outlined">receipt_long</i>
                <div>
                    <div class="tb-title">Auftragsbestätigung</div>
                    <div class="tb-subtitle" id="docMeta">Wird geladen…</div>
                </div>
            </div>
        </div>

        <div class="topbar-right">
            <!-- Simple viewer only: column switches for THIS PDF (not the template) -->
            <div class="tb-btn-wrap" id="pdfColsWrap" style="display:none">
                <button class="tb-btn tb-secondary" id="btnPdfCols" onclick="togglePdfColsPanel(event)" title="Spalten für dieses PDF">
                    <i class="material-symbols-outlined">tune</i> <span>Einstellungen</span>
                </button>
                <div class="pdfcols-panel" id="pdfColsPanel">
                    <div class="pdfcols-title">Nur für dieses PDF</div>
                    <label class="sb-toggle" id="pcZuschRow"><input type="checkbox" id="pcZusch" onchange="setPdfZusch(this.checked)"> Zuschlag-Spalten</label>
                    <div class="sb-subtitle">Tabellenspalten</div>
                    <div id="pcCols"></div>
                    <div class="pdfcols-hint">Gilt für den Download dieses PDFs. Die Firmenvorlage bleibt unverändert.</div>
                </div>
            </div>
            <button class="tb-btn tb-secondary" id="btnAnonymize" onclick="toggleAnonymize()" title="Mitarbeiternamen ausblenden">
                <i class="material-symbols-outlined">person_off</i> <span>Mitarbeiter</span>
            </button>
            <button class="tb-btn tb-secondary" id="btnAnonymizeNu" onclick="toggleAnonymizeNu()" title="NU-Zeile ausblenden">
                <i class="material-symbols-outlined">apartment</i> <span>NU-Firma</span>
            </button>
            <button class="tb-btn tb-secondary" id="btnSignature" onclick="toggleDocAsset('signature')" title="Unterschrift auf das Dokument setzen">
                <i class="material-symbols-outlined">signature</i> <span>Unterschrift</span>
            </button>
            <button class="tb-btn tb-secondary" id="btnStamp" onclick="toggleDocAsset('stamp')" title="Firmenstempel auf das Dokument setzen">
                <i class="material-symbols-outlined">approval</i> <span>Stempel</span>
            </button>
            <button class="tb-btn tb-secondary" id="btnSigRequest" onclick="openSigRequest()" title="Mitarbeiter in der App unterschreiben lassen" style="display:none">
                <i class="material-symbols-outlined">edit_document</i> <span>MA-Unterschrift</span><span class="tb-badge" id="sigReqBadge" hidden></span>
            </button>
            <button class="tb-btn tb-secondary" id="btnArchiv" onclick="openArchiv()" title="Verlauf: archivierte PDF-Versionen" style="display:none">
                <i class="material-symbols-outlined">history</i> <span>Verlauf</span>
            </button>
            <button class="tb-btn tb-primary" onclick="downloadPdfClient()" title="PDF herunterladen">
                <i class="material-symbols-outlined">download</i> <span>Download</span>
            </button>
            <button class="tb-btn tb-ghost" onclick="printDoc()" title="Drucken (exakt wie das PDF)">
                <i class="material-symbols-outlined">print</i>
            </button>
        </div>
    </header>
<?php endif; ?>

    <!-- ════════════════════════════════════════════════ LAYOUT ════════════════════════════════════════════════ -->
    <div class="editor-layout">
<?php if ($designUi): ?>
        <!-- DOKUMENT-DESIGN SIDEBAR (always open; bottom sheet on phones) -->
        <div class="dd-sb-scrim" onclick="ddToggleSidebar(false)"></div>
        <aside class="sidebar dd-sidebar" id="sidebar">
            <div class="dd-sheet-handle" onclick="ddToggleSidebar(false)"><span></span></div>
            <nav class="dd-tabs" role="tablist">
                <button type="button" class="dd-tab active" data-tab="vorlage" onclick="ddTab('vorlage')"><span class="material-symbols-outlined">dashboard_customize</span>Vorlage</button>
                <button type="button" class="dd-tab" data-tab="inhalt" onclick="ddTab('inhalt')"><span class="material-symbols-outlined">checklist</span>Inhalt</button>
                <button type="button" class="dd-tab" data-tab="texte" onclick="ddTab('texte')"><span class="material-symbols-outlined">article</span>Textbausteine</button>
            </nav>

            <!-- ── Vorlage ── -->
            <div class="dd-panel active" data-panel="vorlage">
                <div class="sb-section">
                    <div class="sb-section-title">Standard-Vorlagen</div>
                    <div class="dd-tpls" id="ddTemplates"></div>
                </div>
                <div class="sb-section">
                    <div class="sb-section-title">Farben &amp; Schrift</div>
                    <div class="dd-row2">
                        <div class="sb-field"><label>Primärfarbe</label><input type="color" id="settingPrimary" value="#1a2744" oninput="setPrimary(this.value)"></div>
                        <div class="sb-field"><label>Akzentfarbe</label><input type="color" id="settingAccent" value="#3b82f6" oninput="setAccent(this.value)"></div>
                    </div>
                    <div class="sb-field">
                        <label>Schriftart</label>
                        <select id="settingFont" onchange="setFontFamily(this.value)">
                            <option value="'Segoe UI', system-ui, sans-serif">Segoe UI</option>
                            <option value="'Helvetica Neue', Arial, sans-serif">Helvetica</option>
                            <option value="Arial, sans-serif">Arial</option>
                            <option value="Georgia, serif">Georgia</option>
                            <option value="'Times New Roman', serif">Times New Roman</option>
                            <option value="'Courier New', monospace">Courier New</option>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label>Schriftgröße</label>
                        <select id="settingSize" onchange="setFontSize(this.value)">
                            <option value="small">Klein</option>
                            <option value="medium" selected>Normal</option>
                            <option value="large">Groß</option>
                        </select>
                    </div>
                </div>
                <div class="sb-section">
                    <div class="sb-section-title">Firmenlogo</div>
                    <div class="logo-upload" onclick="document.getElementById('logoInput').click()">
                        <div id="logoPreview"><span class="material-symbols-outlined">cloud_upload</span><div>Logo hochladen</div></div>
                    </div>
                    <input type="file" id="logoInput" accept="image/*" hidden onchange="handleLogoUpload(this)">
                    <button type="button" id="btnRemoveLogo" class="sb-action danger" style="display:none; margin-top:8px;" onclick="removeLogo()">
                        <span class="material-symbols-outlined">visibility_off</span> Logo ausblenden
                    </button>
                    <button type="button" id="btnShowLogo" class="sb-action" style="display:none; margin-top:8px;" onclick="showLogo()">
                        <span class="material-symbols-outlined">visibility</span> Logo anzeigen
                    </button>
                    <div class="sb-field" style="margin-top:12px;">
                        <label>Logo-Position</label>
                        <select id="settingLogoPos" onchange="setLogoPosition(this.value)">
                            <option value="left">Oben links (über Firmenname)</option>
                            <option value="center">Oben mittig</option>
                            <option value="right">Oben rechts</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── Inhalt ── -->
            <div class="dd-panel" data-panel="inhalt">
                <div class="sb-section">
                    <div class="sb-section-title">Unterschrift &amp; Stempel</div>
                    <label class="dd-switch-row">
                        <span class="material-symbols-outlined dd-sr-icon">signature</span>
                        <span class="dd-sr-text">Unterschrift<small id="ddSigHint">Auf dem Dokument verschieben, drehen &amp; skalieren</small></span>
                        <span class="dd-switch"><input type="checkbox" id="btnSignature" onchange="toggleDocAsset('signature')"><span></span></span>
                    </label>
                    <label class="dd-switch-row">
                        <span class="material-symbols-outlined dd-sr-icon">approval</span>
                        <span class="dd-sr-text">Firmenstempel<small id="ddStampHint">Auf dem Dokument verschieben, drehen &amp; skalieren</small></span>
                        <span class="dd-switch"><input type="checkbox" id="btnStamp" onchange="toggleDocAsset('stamp')"><span></span></span>
                    </label>
                </div>
                <div class="sb-section">
                    <div class="sb-section-title">Sichtbarkeit</div>
                    <label class="sb-toggle"><input type="checkbox" id="showHeaderRule" checked onchange="toggleSection('headerRule', this.checked)"> Kopflinie</label>
                    <label class="sb-toggle"><input type="checkbox" id="showSummary" checked onchange="toggleSection('summarySection', this.checked)"> Zusammenfassung</label>
                    <label class="sb-toggle"><input type="checkbox" id="showSignature" checked onchange="toggleSection('signatureSection', this.checked)"> Unterschriftenfeld</label>
                    <label class="sb-toggle"><input type="checkbox" id="showFooter" checked onchange="toggleSection('footerSection', this.checked)"> Fußzeile (Impressum)</label>
                    <label class="sb-toggle"><input type="checkbox" id="showNu" checked onchange="toggleSection('nuRow', this.checked)"> NU-Zeile</label>
                    <label class="sb-toggle"><input type="checkbox" id="showZuschColumn" checked onchange="toggleZuschColumns(this.checked)"> Zuschlag-Spalten</label>
                </div>
                <div class="sb-section">
                    <div class="sb-section-title">Tabellenspalten</div>
                    <div id="colToggles"></div>
                </div>
                <div class="sb-section">
                    <div class="sb-section-title">Zurücksetzen</div>
                    <button type="button" class="sb-action danger" onclick="resetToDefault()">
                        <span class="material-symbols-outlined">restart_alt</span> Auf Standard zurücksetzen
                    </button>
                </div>
            </div>

            <!-- ── Textbausteine ── -->
            <div class="dd-panel" data-panel="texte">
                <div class="dd-seg" role="tablist">
                    <button type="button" class="active" data-seg="pdf" onclick="ddSeg('pdf')"><span class="material-symbols-outlined">picture_as_pdf</span>Im PDF</button>
                    <button type="button" data-seg="mail" onclick="ddSeg('mail')"><span class="material-symbols-outlined">mail</span>E-Mail</button>
                </div>
                <div class="dd-segpanel active" data-segpanel="pdf">
                    <p class="dd-hint">Feste Texte, die auf jedem Dokument dieses Typs gedruckt werden. Platzhalter werden beim Erstellen automatisch ersetzt.</p>
                    <div id="ddPdfBausteine"></div>
                    <div class="sb-section">
                        <div class="sb-section-title">Fußnote</div>
                        <div class="dd-bs-sub" style="margin-bottom:8px">Kleiner Hinweis am Ende des Dokuments</div>
                        <textarea id="extraNote" class="dd-input" rows="3" maxlength="2000" placeholder="z. B. Bitte geben Sie bei Rückfragen die Auftrags-Nr. an." oninput="updateExtraNote()"></textarea>
                    </div>
                </div>
                <div class="dd-segpanel" data-segpanel="mail">
                    <div id="ddMail"></div>
                </div>
            </div>
        </aside>
<?php endif; ?>

        <!-- MAIN CANVAS -->
        <main class="canvas">
            <div class="page" id="orderForm">
                <div class="loading"><i class="material-symbols-outlined ms-spin">progress_activity</i> Lade Auftragsdaten…</div>
            </div>
        </main>

    </div>

    <!-- ════════════════════════════════════════════════ SAVED PDFS MODAL ════════════════════════════════════════════════ -->
    <div class="modal" id="pdfsModal">
        <div class="modal-backdrop" onclick="closePdfsModal()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="modal-title">Verlauf · archivierte PDFs</div>
                    <div class="modal-subtitle" id="pdfsSubtitle"></div>
                </div>
                <button class="modal-close" onclick="closePdfsModal()"><i class="material-symbols-outlined">close</i></button>
            </div>
            <div class="modal-body">
                <div id="pdfsList"><div class="loading"><i class="material-symbols-outlined ms-spin">progress_activity</i> Lade…</div></div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════ MITARBEITER-UNTERSCHRIFT PER APP ════════════════════════════════════════════════ -->
    <div class="modal" id="sigReqModal">
        <div class="modal-backdrop" onclick="closeSigRequest()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="modal-title">Unterschrift der Mitarbeiter per App</div>
                    <div class="modal-subtitle" id="sigReqSubtitle"></div>
                </div>
                <button class="modal-close" onclick="closeSigRequest()"><i class="material-symbols-outlined">close</i></button>
            </div>
            <div class="modal-body">
                <p class="sigreq-info">Jeder Mitarbeiter erhält in der App eine Mitteilung mit <b>seinen eigenen</b> Einsätzen (Tage, Zeiten, Stunden) — nicht das Dokument. Nach seiner Unterschrift erscheint sie in seinen Zeilen der Spalte „Unterschrift“.</p>
                <div id="sigReqList"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" onclick="closeSigRequest()">Schließen</button>
                <button class="btn btn-primary" id="sigReqSend" onclick="sendSigRequests()" disabled><i class="material-symbols-outlined">send</i> <span>Zur Unterschrift senden</span></button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════ FIRST-RUN WIZARD ════════════════════════════════════════════════ -->
    <div class="wizard-overlay" id="pdfWizard">
        <div class="wizard">
            <div class="wizard-head">
                <div class="wizard-steps" id="wizardDots">
                    <span class="wz-dot active"></span><span class="wz-dot"></span><span class="wz-dot"></span>
                </div>
                <button class="wizard-skip" onclick="wizardSkip()">Überspringen</button>
            </div>

            <!-- STEP 1 — Farbe & Schrift -->
            <div class="wizard-step active" data-step="1">
                <div class="wz-icon"><i class="material-symbols-outlined">palette</i></div>
                <h2>Willkommen! Wählen Sie Ihr Design</h2>
                <p class="wz-sub">Farbe und Schrift werden auf alle Ihre PDF-Dokumente angewendet. Sie können das später jederzeit in den Einstellungen ändern.</p>

                <div class="wz-field">
                    <label>Farbschema</label>
                    <div class="wz-palettes" id="wzPalettes"></div>
                </div>
                <div class="wz-row">
                    <div class="wz-field"><label>Primärfarbe</label><input type="color" id="wzPrimary"></div>
                    <div class="wz-field"><label>Akzentfarbe</label><input type="color" id="wzAccent"></div>
                </div>
                <div class="wz-field">
                    <label>Schriftart</label>
                    <select id="wzFont">
                        <option value="'Segoe UI', system-ui, sans-serif">Segoe UI</option>
                        <option value="'Helvetica Neue', Arial, sans-serif">Helvetica</option>
                        <option value="Arial, sans-serif">Arial</option>
                        <option value="Georgia, serif">Georgia</option>
                        <option value="'Times New Roman', serif">Times New Roman</option>
                        <option value="'Courier New', monospace">Courier New</option>
                    </select>
                </div>
                <div class="wz-preview" id="wzPreview">
                    <div class="wz-prev-title">LEISTUNGSNACHWEIS</div>
                    <div class="wz-prev-bar"></div>
                    <div class="wz-prev-text">So sieht der Kopf Ihres Dokuments aus.</div>
                    <div class="wz-prev-row"><b>Monat:</b> <span class="wz-prev-hl">Juni 2026</span></div>
                </div>
            </div>

            <!-- STEP 2 — Logo -->
            <div class="wizard-step" data-step="2">
                <div class="wz-icon"><i class="material-symbols-outlined">image</i></div>
                <h2>Ihr Firmenlogo</h2>
                <p class="wz-sub">Optional — erscheint im Kopf jedes Dokuments. Diesen Schritt können Sie überspringen.</p>
                <div class="wz-logo-upload" id="wzLogoPreview" onclick="document.getElementById('wzLogoInput').click()">
                    <i class="material-symbols-outlined">cloud_upload</i><div>Logo hochladen (optional)</div>
                </div>
                <input type="file" id="wzLogoInput" accept="image/*" hidden onchange="wizardHandleLogo(this)">
                <div class="wz-field" style="margin-top:14px;">
                    <label>Logo-Position</label>
                    <select id="wzLogoPos">
                        <option value="left">Oben links (über Firmenname)</option>
                        <option value="center">Oben mittig</option>
                        <option value="right">Oben rechts</option>
                    </select>
                </div>
            </div>

            <!-- STEP 3 — Fertig -->
            <div class="wizard-step" data-step="3">
                <div class="wz-icon"><i class="material-symbols-outlined">rocket_launch</i></div>
                <h2>Alles bereit!</h2>
                <p class="wz-sub">So nutzen Sie den Editor:</p>
                <ul class="wz-tips">
<?php if ($designUi): ?>
                    <li><i class="material-symbols-outlined">palette</i><span><b>Vorlage</b> — Klassisch, Modern oder DIN 5008, dazu Farben, Schrift &amp; Logo.</span></li>
                    <li><i class="material-symbols-outlined">visibility</i><span><b>Inhalt</b> — Unterschrift, Stempel, Abschnitte und Tabellenspalten.</span></li>
                    <li><i class="material-symbols-outlined">sticky_note_2</i><span><b>Textbausteine</b> — feste Texte im PDF und die E-Mail-Vorlage.</span></li>
                    <li><i class="material-symbols-outlined">save</i><span><b>Automatisch gespeichert</b> — jede Änderung gilt sofort für alle Dokumente dieses Typs.</span></li>
<?php endif; ?>
                </ul>
            </div>

            <div class="wizard-foot">
                <button class="wz-btn ghost" id="wzBack" onclick="wizardBack()" style="visibility:hidden"><i class="material-symbols-outlined">arrow_back</i> Zurück</button>
                <button class="wz-btn primary" id="wzNext" onclick="wizardNext()">Weiter <i class="material-symbols-outlined">arrow_forward</i></button>
            </div>
        </div>
    </div>

    <!-- TOAST -->
    <div id="toastHost"></div>

    <script>window.__VIEWER_ONLY__ = <?= $viewerOnly ? 'true' : 'false' ?>; window.__EMBEDDED__ = <?= $embedded ? 'true' : 'false' ?>; window.__HEADLESS__ = <?= $headless ? 'true' : 'false' ?>; window.__DESIGN_UI__ = <?= $designUi ? 'true' : 'false' ?>;</script>
    <script src="<?= asset('assets/js/order_form_elements.js') ?>"></script>
    <script src="<?= asset('assets/js/order_form.js') ?>"></script>
    <script>
    /* Font Awesome → Material Symbols shim.
       Rewrites every <i class="fas fa-x"> to the equivalent Material Symbols
       ligature so the page uses the same icon font as the rest of the app.
       A MutationObserver also catches icons injected later by JS. */
    (function () {
        var MAP = {
            'fa-align-center':'format_align_center','fa-align-left':'format_align_left','fa-align-right':'format_align_right',
            'fa-arrow-down':'arrow_downward','fa-arrow-left':'arrow_back','fa-arrow-right':'arrow_forward','fa-arrow-up':'arrow_upward',
            'fa-arrows-alt':'open_with','fa-redo':'redo',
            'fa-bold':'format_bold','fa-check':'check','fa-check-circle':'check_circle','fa-chevron-down':'expand_more',
            'fa-cloud-upload-alt':'cloud_upload','fa-copy':'content_copy','fa-cube':'deployed_code','fa-download':'download',
            'fa-envelope':'mail','fa-eraser':'ink_eraser','fa-exclamation-circle':'error','fa-exclamation-triangle':'warning',
            'fa-external-link-alt':'open_in_new','fa-eye':'visibility','fa-eye-slash':'visibility_off',
            'fa-file-invoice':'receipt_long','fa-file-pdf':'picture_as_pdf','fa-folder-open':'folder_open',
            'fa-font':'text_fields','fa-history':'history','fa-grip-lines-vertical':'drag_indicator','fa-image':'image','fa-info-circle':'info',
            'fa-italic':'format_italic','fa-minus':'remove','fa-palette':'palette','fa-pen':'edit','fa-plus':'add',
            'fa-print':'print','fa-rocket':'rocket_launch','fa-save':'save','fa-share-alt':'share','fa-sliders-h':'tune',
            'fa-spinner':'progress_activity','fa-square':'crop_square','fa-sticky-note':'sticky_note_2','fa-table':'table',
            'fa-times':'close','fa-trash':'delete','fa-undo':'undo','fa-underline':'format_underlined',
            'fa-user-secret':'person_off','fa-wand-magic-sparkles':'auto_fix_high','fa-whatsapp':'chat'
        };
        function convert(el) {
            var cls = (el.className || '').toString();
            var icon = null, spin = false;
            cls.split(/\s+/).forEach(function (c) {
                if (c === 'fa-spin' || c === 'fa-pulse') spin = true;
                else if (MAP[c]) icon = MAP[c];
            });
            if (!icon) return;
            el.className = 'material-symbols-outlined' + (spin ? ' ms-spin' : '');
            el.textContent = icon;
        }
        function scan(root) {
            if (root.querySelectorAll) root.querySelectorAll('i.fas, i.far, i.fab, i.fa').forEach(convert);
            if (root.matches && root.matches('i.fas, i.far, i.fab, i.fa')) convert(root);
        }
        function init() {
            scan(document);
            new MutationObserver(function (muts) {
                muts.forEach(function (m) {
                    if (m.addedNodes) m.addedNodes.forEach(function (n) {
                        if (n.nodeType === 1) scan(n);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
        else init();
    })();
    </script>
</body>
</html>
