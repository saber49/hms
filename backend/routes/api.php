<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InteressentController;
use App\Http\Controllers\Api\ObjectController;
use App\Http\Controllers\Api\SubcontractorController;
use App\Http\Controllers\Api\WorkerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\IncomingOrderController;
use App\Http\Controllers\Api\DispositionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ControllingController;
use App\Http\Controllers\Api\ControllingDrilldownController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkerAbsenceController;
use App\Http\Controllers\Api\WorkerDocumentController;
use App\Http\Controllers\Api\WorkerMessageController;
use App\Http\Controllers\Api\CustomerDocumentController;
use App\Http\Controllers\Api\NuDocumentController;
use App\Http\Controllers\Api\PriceHistoryController;
use App\Http\Controllers\Api\LagerController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NuPortalController;
use App\Http\Controllers\Api\DownloadController;
use App\Http\Controllers\Api\WorkerAppAuthController;
use App\Http\Controllers\Api\WorkerAppFeedController;
use App\Http\Controllers\Api\WorkerAppShiftController;
use App\Http\Controllers\Api\WorkerAppZeitController;
use App\Http\Controllers\Api\WorkerAppAbsenceController;
use App\Http\Controllers\Api\WorkerAppMessageController;
use App\Http\Controllers\Api\WorkerAppNotificationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\TenantDashboardController;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\Api\PdfTemplateController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\AiAssistantController;

/*
|--------------------------------------------------------------------------
| Public routes (no auth required)
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
// Passwordless read-only login into the demo tenant (landing-page embed).
// 404s unless DEMO_TENANT_SLUG is configured for the environment.
Route::post('/auth/demo-login', [AuthController::class, 'demoLogin'])->middleware('throttle:login');
Route::post('/nu-portal/login', [NuPortalController::class, 'login'])->middleware('throttle:login');
Route::post('/kunden-portal/login', [\App\Http\Controllers\Api\Qm\KundenPortal\PortalController::class, 'login'])->middleware('throttle:login');
Route::post('/worker-app/auth/login', [WorkerAppAuthController::class, 'login'])->middleware('throttle:login');

// Self-service password reset (public, throttled — see RouteServiceProvider).
// Forgot returns a generic message regardless of email validity to avoid
// account enumeration; reset consumes a one-time token from the email link.
Route::post('/auth/forgot-password',
    [App\Http\Controllers\Api\PasswordResetController::class, 'forgot']
)->middleware('throttle:password-reset');
Route::post('/auth/reset-password',
    [App\Http\Controllers\Api\PasswordResetController::class, 'reset']
)->middleware('throttle:password-reset');

// Worker forgot/reset password — same throttle as the central flow.
Route::post('/worker-app/auth/forgot-password',
    [App\Http\Controllers\Api\WorkerPasswordResetController::class, 'forgot']
)->middleware('throttle:password-reset');
Route::post('/worker-app/auth/reset-password',
    [App\Http\Controllers\Api\WorkerPasswordResetController::class, 'reset']
)->middleware('throttle:password-reset');

// SaaS Registration (public)
Route::post('/register', [RegistrationController::class, 'register']);
Route::get('/verify-email', [RegistrationController::class, 'verifyEmail']);
Route::get('/plans', [RegistrationController::class, 'plans']);
Route::get('/check-availability', [RegistrationController::class, 'checkAvailability']);

// File serving — branding images / chat photos public, everything else only
// with a short-lived signed link from GET /api/files/link (App\Support\FileAccess).
Route::get('/serve', [DownloadController::class, 'serve'])->name('files.serve');
Route::get('/download', [DownloadController::class, 'download'])->name('files.download');

// Lead-Modul A2 — public webform. Tenant resolved by the secret key in the URL;
// GET serves the embeddable form page, POST creates Kontakt+Lead (A1+A3+Zuweisung).
Route::get('/crm-form/{key}', [\App\Http\Controllers\Api\CrmWebformController::class, 'form'])
    ->where('key', '[A-Za-z0-9]{20,60}');
Route::post('/crm-form/{key}', [\App\Http\Controllers\Api\CrmWebformController::class, 'store'])
    ->where('key', '[A-Za-z0-9]{20,60}')->middleware('throttle:crm-form');

// Auftragsbestätigung PDF via short-lived signed link (phones / app WebView
// can't send a Bearer header on navigation). Tenant, user, permission and
// Bereich scope are re-validated inside the handler.
Route::get('/oc-pdf', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'signedPdf'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('order-confirmations.signed-pdf');


// NOTE: PDF share link route /share/{slug}/{token} is registered in
// routes/web.php (not here) because PdfController::createShareLink()
// generates URLs via url('/share/...') which resolves WITHOUT the /api
// prefix that RouteServiceProvider adds to this file's routes.

/*
|--------------------------------------------------------------------------
| Authenticated routes (Sanctum + tenant middleware)
|--------------------------------------------------------------------------
*/
// Authenticated routes that must stay reachable for an UNVERIFIED user so
// they can recover (check session, log out, re-request the verification
// mail). Deliberately NOT behind EnsureEmailVerified, and they don't need
// tenant resolution (they read the central_users row / its tenant relation).
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/check', [AuthController::class, 'check']);
    Route::post('/resend-verification', [RegistrationController::class, 'resendVerification'])
        ->middleware('throttle:5,1');

    // Realtime (Reverb WebSocket): channel authorization for Echo
    // (POST socket_id + channel_name → signed pusher-style response)
    // plus the public app key the client needs to connect.
    Route::post('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
        return \Illuminate\Support\Facades\Broadcast::auth($request);
    });
    Route::get('/realtime/config', function () {
        return response()->json([
            'enabled' => config('broadcasting.default') === 'reverb'
                && !empty(config('broadcasting.connections.reverb.key')),
            'key'     => config('broadcasting.connections.reverb.key'),
        ]);
    });
});

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureEmailVerified::class, \App\Http\Middleware\ResolveTenant::class, 'tenant.boundary', 'subscription.active', 'demo.readonly'])->group(function () {

    // Auth
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    // Signed link to a stored file (tenant + permission checked in FileAccess).
    Route::get('/files/link', [DownloadController::class, 'link'])->middleware('throttle:120,1');

    // Tenant Dashboard & Settings
    // Reads: anyone with dashboard.view. Writes (settings, logo) and the
    // company documents: Inhaber only (tenant.owner) — users.manage is
    // delegable to admins, company master data is not.
    Route::middleware('permission:dashboard.view')->group(function () {
        Route::get('/dashboard', [TenantDashboardController::class, 'index']);
        Route::get('/dashboard/overview', [TenantDashboardController::class, 'overview']);
        Route::get('/dashboard/cockpit', [\App\Http\Controllers\Api\DashboardCockpitController::class, 'index']);
        Route::get('/dashboard/company', [TenantDashboardController::class, 'companyProfile']);
    });
    Route::middleware('tenant.owner')->group(function () {
        Route::put('/dashboard/settings', [TenantDashboardController::class, 'updateSettings']);
        Route::post('/dashboard/logo', [TenantDashboardController::class, 'uploadLogo']);
        Route::delete('/dashboard/logo', [TenantDashboardController::class, 'deleteLogo']);

        // Company documents (Gewerbeschein, Handelsregisterauszug, …).
        // Bytes are streamed through dedicated /view + /download endpoints
        // that re-verify tenant ownership in the DB on every request, so the
        // unauthenticated /api/serve route is bypassed for sensitive papers.
        Route::get('/dashboard/documents', [TenantDashboardController::class, 'listDocuments']);
        Route::post('/dashboard/documents', [TenantDashboardController::class, 'uploadDocument']);
        Route::delete('/dashboard/documents/{id}', [TenantDashboardController::class, 'deleteDocument'])->whereNumber('id');
        Route::get('/dashboard/documents/{id}/view',     [TenantDashboardController::class, 'viewDocument'])->whereNumber('id');
        Route::get('/dashboard/documents/{id}/download', [TenantDashboardController::class, 'downloadDocument'])->whereNumber('id');
        Route::get('/dashboard/documents/{id}/thumb',    [TenantDashboardController::class, 'thumbDocument'])->whereNumber('id');
    });

    // Onboarding = one step: the legal company data (Firma, Rechtsform,
    // Anschrift, Geschäftsführer, Register, USt-IdNr./Steuernummer). Saving it
    // completes the onboarding — Inhaber only. No skip, no demo records.
    Route::get('/onboarding/status', [OnboardingController::class, 'status']);
    Route::post('/onboarding/company-profile', [OnboardingController::class, 'saveCompanyProfile'])
        ->middleware(['permission:users.manage', 'tenant.owner', 'throttle:30,1']);

    // Tenants (super_admin only)
    Route::middleware(['super_admin'])->group(function () {
        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
        Route::put('/tenants/{tenant}/subscription', [TenantController::class, 'updateSubscription']);
        Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy']);
    });

    // AÜG-Fristen (Überlassungshöchstdauer / Equal Pay) — overview + Entleiher config
    Route::middleware('permission:stammdaten.mitarbeiter.view')->get('/aueg', [\App\Http\Controllers\Api\AuegController::class, 'index']);
    Route::middleware('permission:stammdaten.kunden.edit')->group(function () {
        Route::put('/aueg/entleiher/{kundeId}', [\App\Http\Controllers\Api\AuegController::class, 'saveEntleiher']);
        Route::delete('/aueg/entleiher/{kundeId}', [\App\Http\Controllers\Api\AuegController::class, 'deleteEntleiher']);
    });

    // Customers — gated by stammdaten.kunden.view / .edit
    Route::middleware('permission:stammdaten.kunden.view')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/bereiche', [CustomerController::class, 'bereiche']);
        Route::get('/customers/betreuer-options', [CustomerController::class, 'betreuerOptions']);
        Route::get('/customers/{id}/stats', [CustomerController::class, 'stats']);
        Route::get('/customers/{id}/delete-preview', [CustomerController::class, 'deletePreview']);
        Route::get('/customers/{id}/favorites', [CustomerController::class, 'favorites']);
        Route::get('/customers/{id}', [CustomerController::class, 'show']);
    });
    // Interessentenverwaltung — Stammdaten, gated by stammdaten.interessenten.*
    // `.view` sees only own assigned prospects; `.view_all` (Vertriebsleitung)
    // sees all + may reassign zustaendiger_mitarbeiter (enforced in controller).
    Route::middleware('permission:stammdaten.interessenten.view')->group(function () {
        Route::get('/interessenten', [InteressentController::class, 'index']);
        Route::get('/interessenten/stats', [InteressentController::class, 'stats']);
        Route::get('/interessenten/optionen', [InteressentController::class, 'optionen']);
        Route::get('/interessenten/mitarbeiter-options', [InteressentController::class, 'mitarbeiterOptions']);
        Route::get('/interessenten/kommunikation/{eintragId}/datei', [InteressentController::class, 'kommunikationDatei'])->whereNumber('eintragId');
        Route::get('/interessenten/{id}', [InteressentController::class, 'show']);
    });
    Route::middleware('permission:stammdaten.interessenten.edit')->group(function () {
        Route::post('/interessenten', [InteressentController::class, 'store']);
        Route::post('/interessenten/{id}/kommunikation', [InteressentController::class, 'kommunikationStore']);
        Route::post('/interessenten/{id}/konvertieren', [InteressentController::class, 'konvertieren']);
        Route::put('/interessenten/{id}', [InteressentController::class, 'update']);
        Route::delete('/interessenten/{id}', [InteressentController::class, 'destroy']);
    });

    // Lead-Modul (Interessentenmanagement, Spezifikation 27.10.2026) — replaces
    // the old /interessenten endpoints (data carried over by the 2026_10_28
    // tenant migration). Row-level rules (own vs. all, Sparte scope, owner
    // reassignment, admin-only delete/settings) live in App\Support\Crm.
    // Per-section rights (Benutzerverwaltung → Vertrieb): crm.leads.*,
    // crm.deals.*, crm.kontakte.*. Einstellungen/Listen/Fristen/Zuweisung/
    // Webformular = stammdaten.interessenten.admin, which only Inhaber and
    // Administratoren hold (derived in PermissionRegistry::withDerived).
    Route::prefix('crm')->middleware('permission:stammdaten.interessenten.view|stammdaten.interessenten.uebergabe')->group(function () {
        Route::get('/optionen', [\App\Http\Controllers\Api\CrmStammController::class, 'optionen']);
        Route::get('/aktivitaeten/{id}/datei', [\App\Http\Controllers\Api\CrmAktivitaetController::class, 'datei'])->whereNumber('id');
    });
    Route::prefix('crm')->middleware('permission:crm.deals.view|stammdaten.interessenten.uebergabe')->group(function () {
        Route::get('/deals', [\App\Http\Controllers\Api\CrmDealController::class, 'index']);
        Route::get('/deals/{id}', [\App\Http\Controllers\Api\CrmDealController::class, 'show'])->whereUuid('id');
        Route::get('/deals/{id}/angebot', [\App\Http\Controllers\Api\CrmDealController::class, 'angebotDownload'])->whereUuid('id');
    });
    Route::prefix('crm')->middleware('permission:crm.leads.view')->group(function () {
        Route::get('/leads', [\App\Http\Controllers\Api\CrmLeadController::class, 'index']);
        Route::get('/leads/{id}', [\App\Http\Controllers\Api\CrmLeadController::class, 'show'])->whereUuid('id');
    });
    Route::prefix('crm')->middleware('permission:crm.kontakte.view')->group(function () {
        Route::get('/firmen', [\App\Http\Controllers\Api\CrmStammController::class, 'firmen']);
        Route::get('/firmen/{id}', [\App\Http\Controllers\Api\CrmStammController::class, 'firma'])->whereUuid('id');
        Route::get('/kontakte', [\App\Http\Controllers\Api\CrmStammController::class, 'kontakte']);
        Route::get('/kontakte/{id}', [\App\Http\Controllers\Api\CrmStammController::class, 'kontakt'])->whereUuid('id');
        Route::get('/kontakte/{id}/dsgvo-export', [\App\Http\Controllers\Api\CrmWerkzeugController::class, 'kontaktExport'])->whereUuid('id');
    });
    Route::prefix('crm')->middleware('permission:stammdaten.interessenten.view')->group(function () {
        Route::post('/dubletten', [\App\Http\Controllers\Api\CrmStammController::class, 'dubletten']);
        Route::get('/einstellungen', [\App\Http\Controllers\Api\CrmSettingsController::class, 'index']);
    });
    Route::prefix('crm')->middleware(['permission:crm.kontakte.edit', 'throttle:120,1'])->group(function () {
        Route::post('/firmen', [\App\Http\Controllers\Api\CrmStammController::class, 'firmaStore']);
        Route::put('/firmen/{id}', [\App\Http\Controllers\Api\CrmStammController::class, 'firmaUpdate'])->whereUuid('id');
        Route::post('/kontakte', [\App\Http\Controllers\Api\CrmStammController::class, 'kontaktStore']);
        Route::put('/kontakte/{id}', [\App\Http\Controllers\Api\CrmStammController::class, 'kontaktUpdate'])->whereUuid('id');
        Route::put('/kontakte/{id}/lifecycle', [\App\Http\Controllers\Api\CrmStammController::class, 'lifecycle'])->whereUuid('id');
    });
    Route::prefix('crm')->middleware(['permission:crm.leads.edit', 'throttle:120,1'])->group(function () {
        Route::post('/leads', [\App\Http\Controllers\Api\CrmLeadController::class, 'store']);
        Route::put('/leads/{id}', [\App\Http\Controllers\Api\CrmLeadController::class, 'update'])->whereUuid('id');
        Route::post('/leads/{id}/qualifizieren', [\App\Http\Controllers\Api\CrmLeadController::class, 'qualifizieren'])->whereUuid('id');
        Route::post('/leads/{id}/disqualifizieren', [\App\Http\Controllers\Api\CrmLeadController::class, 'disqualifizieren'])->whereUuid('id');
    });
    Route::prefix('crm')->middleware(['permission:crm.deals.edit', 'throttle:120,1'])->group(function () {
        Route::post('/deals', [\App\Http\Controllers\Api\CrmDealController::class, 'store']);
        Route::put('/deals/{id}', [\App\Http\Controllers\Api\CrmDealController::class, 'update'])->whereUuid('id');
        Route::post('/deals/{id}/phase', [\App\Http\Controllers\Api\CrmDealController::class, 'phase'])->whereUuid('id');
        Route::post('/deals/{id}/angebot', [\App\Http\Controllers\Api\CrmDealController::class, 'angebotUpload'])->whereUuid('id');
    });
    // Activities / tasks: the controller checks the section of the target.
    Route::prefix('crm')->middleware(['permission:stammdaten.interessenten.edit', 'throttle:120,1'])->group(function () {
        Route::post('/aktivitaeten', [\App\Http\Controllers\Api\CrmAktivitaetController::class, 'store']);
        Route::post('/aufgaben', [\App\Http\Controllers\Api\CrmAktivitaetController::class, 'aufgabe']);
    });
    // Inhaber / Administrator only: settings, delete, export, merge, import.
    Route::prefix('crm')->middleware(['permission:stammdaten.interessenten.admin', 'throttle:120,1'])->group(function () {
        Route::delete('/firmen/{id}', [\App\Http\Controllers\Api\CrmStammController::class, 'firmaDestroy'])->whereUuid('id');
        Route::delete('/kontakte/{id}', [\App\Http\Controllers\Api\CrmStammController::class, 'kontaktDestroy'])->whereUuid('id');
        Route::delete('/leads/{id}', [\App\Http\Controllers\Api\CrmLeadController::class, 'destroy'])->whereUuid('id');
        Route::delete('/deals/{id}', [\App\Http\Controllers\Api\CrmDealController::class, 'destroy'])->whereUuid('id');
        Route::post('/listen', [\App\Http\Controllers\Api\CrmSettingsController::class, 'listeStore']);
        Route::put('/listen/{id}', [\App\Http\Controllers\Api\CrmSettingsController::class, 'listeUpdate'])->whereNumber('id');
        Route::put('/einstellungen', [\App\Http\Controllers\Api\CrmSettingsController::class, 'einstellungenUpdate']);
        Route::put('/zuweisung', [\App\Http\Controllers\Api\CrmSettingsController::class, 'zuweisungUpdate']);
        Route::post('/webform/rotate', [\App\Http\Controllers\Api\CrmSettingsController::class, 'webformRotate']);
    });
    // Merge / import: Vertriebsleitung or Admin (checked in the controller).
    Route::prefix('crm')->middleware(['permission:crm.kontakte.edit', 'throttle:120,1'])->group(function () {
        Route::post('/kontakte/{id}/zusammenfuehren', [\App\Http\Controllers\Api\CrmWerkzeugController::class, 'kontakteZusammenfuehren'])->whereUuid('id');
        Route::post('/firmen/{id}/zusammenfuehren', [\App\Http\Controllers\Api\CrmWerkzeugController::class, 'firmenZusammenfuehren'])->whereUuid('id');
        Route::post('/import/vorschau', [\App\Http\Controllers\Api\CrmWerkzeugController::class, 'importVorschau'])->middleware('throttle:crm-import');
        Route::post('/import/ausfuehren', [\App\Http\Controllers\Api\CrmWerkzeugController::class, 'importAusfuehren'])->middleware('throttle:crm-import');
    });

    Route::middleware('permission:stammdaten.kunden.edit')->group(function () {
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::put('/customers/{id}/favorites', [CustomerController::class, 'saveFavorites']);
        Route::put('/customers/{id}', [CustomerController::class, 'update']);
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
    });

    // Objects & Artikel — both gated by stammdaten.objekte.view / .edit
    // (Artikel are services attached to an Objekt; sharing one permission
    // pair is intentional and matches the UI where they're edited together.)
    Route::middleware('permission:stammdaten.objekte.view')->group(function () {
        Route::get('/objects', [ObjectController::class, 'index']);
        Route::get('/objects/contacts', [ObjectController::class, 'contacts']); // must precede /objects/{id}
        Route::get('/objects/arten', [ObjectController::class, 'arten']);       // must precede /objects/{id}
        Route::get('/objects/{id}', [ObjectController::class, 'show']);
        Route::get('/artikel', [\App\Http\Controllers\Api\ArtikelController::class, 'index']);
        Route::get('/artikel/{id}', [\App\Http\Controllers\Api\ArtikelController::class, 'show']);
    });
    Route::middleware('permission:stammdaten.objekte.edit')->group(function () {
        Route::post('/objects', [ObjectController::class, 'store']);
        Route::put('/objects/{id}', [ObjectController::class, 'update']);
        Route::delete('/objects/{id}', [ObjectController::class, 'destroy']);
        Route::post('/objects/{id}/apply-zuschlaege', [ObjectController::class, 'applyZuschlaegeToArtikel']);
        Route::post('/artikel', [\App\Http\Controllers\Api\ArtikelController::class, 'store']);
        Route::put('/artikel/{id}', [\App\Http\Controllers\Api\ArtikelController::class, 'update']);
        Route::delete('/artikel/{id}', [\App\Http\Controllers\Api\ArtikelController::class, 'destroy']);
    });

    // Qualitätsmanagement & Wachbuch (module routes: routes/qm/*.php)
    require __DIR__ . '/qm/office.php';

    // Checklisten & Protokolle (QM, Leistungsnachweis, Wartung, Winterdienst …) —
    // tied to Objekte, so they share the stammdaten.objekte permission pair.
    Route::middleware('permission:stammdaten.objekte.view')->group(function () {
        Route::get('/checklisten/vorlagen', [\App\Http\Controllers\Api\ChecklistenController::class, 'vorlagen']);
        Route::get('/checklisten/vorlagen/{id}', [\App\Http\Controllers\Api\ChecklistenController::class, 'vorlage'])->whereNumber('id');
        Route::get('/checklisten/vorlagen/{id}/objekte', [\App\Http\Controllers\Api\ChecklistenController::class, 'objekte'])->whereNumber('id');
        Route::get('/checklisten/protokolle', [\App\Http\Controllers\Api\ChecklistenController::class, 'protokolle']);
        Route::get('/checklisten/protokolle/{id}', [\App\Http\Controllers\Api\ChecklistenController::class, 'protokoll'])->whereNumber('id');
        Route::get('/checklisten/protokolle/{id}/pdf', [\App\Http\Controllers\Api\ChecklistenController::class, 'pdf'])->whereNumber('id');
        Route::get('/checklisten/protokolle/{id}/unterschrift/{wer}', [\App\Http\Controllers\Api\ChecklistenController::class, 'unterschrift'])->whereNumber('id')->whereIn('wer', ['kunde', 'ma']);
        Route::get('/checklisten/fotos/{id}', [\App\Http\Controllers\Api\ChecklistenController::class, 'foto'])->whereNumber('id');
    });
    Route::middleware('permission:stammdaten.objekte.edit')->group(function () {
        Route::post('/checklisten/vorlagen', [\App\Http\Controllers\Api\ChecklistenController::class, 'storeVorlage']);
        Route::post('/checklisten/vorlagen/paket', [\App\Http\Controllers\Api\ChecklistenController::class, 'paket']);
        Route::put('/checklisten/vorlagen/{id}', [\App\Http\Controllers\Api\ChecklistenController::class, 'updateVorlage'])->whereNumber('id');
        Route::delete('/checklisten/vorlagen/{id}', [\App\Http\Controllers\Api\ChecklistenController::class, 'destroyVorlage'])->whereNumber('id');
        Route::put('/checklisten/vorlagen/{id}/objekte', [\App\Http\Controllers\Api\ChecklistenController::class, 'saveObjekte'])->whereNumber('id');
        Route::delete('/checklisten/protokolle/{id}', [\App\Http\Controllers\Api\ChecklistenController::class, 'destroyProtokoll'])->whereNumber('id');
    });

    // Subcontractors — stammdaten.nu.view / .edit
    Route::middleware('permission:stammdaten.nu.view')->group(function () {
        Route::get('/subcontractors', [SubcontractorController::class, 'index']);
        Route::get('/subcontractors/workers/list', [SubcontractorController::class, 'listWorkers']);
        Route::get('/subcontractors/{id}/stats', [SubcontractorController::class, 'stats']);
        Route::get('/subcontractors/{id}/checklist', [SubcontractorController::class, 'checklist']);
        Route::get('/subcontractors/{id}/delete-preview', [SubcontractorController::class, 'deletePreview']);
        Route::get('/subcontractors/{id}', [SubcontractorController::class, 'show']);
    });
    Route::middleware('permission:stammdaten.nu.edit')->group(function () {
        Route::post('/subcontractors', [SubcontractorController::class, 'store']);
        Route::post('/subcontractors/portal', [SubcontractorController::class, 'savePortal']);
        Route::put('/subcontractors/{id}', [SubcontractorController::class, 'update']);
        Route::delete('/subcontractors/{id}', [SubcontractorController::class, 'destroy']);
    });

    // Workers — stammdaten.mitarbeiter.view / .edit
    Route::middleware('permission:stammdaten.mitarbeiter.view')->group(function () {
        Route::get('/workers',                    [WorkerController::class, 'index']);
        Route::get('/workers/stats',              [WorkerController::class, 'stats']);
        Route::get('/workers/expiring-docs',      [WorkerController::class, 'expiringDocs']);
        Route::get('/workers/export',             [WorkerController::class, 'export']);
        Route::get('/workers/{id}/checklist',     [WorkerController::class, 'checklist']);
        Route::get('/workers/{id}/activity',       [WorkerController::class, 'activity']);
        Route::get('/workers/{id}',               [WorkerController::class, 'show']);
    });
    Route::middleware('permission:stammdaten.mitarbeiter.edit')->group(function () {
        Route::post('/workers',                   [WorkerController::class, 'store']);
        Route::put('/workers/{id}',               [WorkerController::class, 'update']);
        Route::delete('/workers/{id}',            [WorkerController::class, 'destroy']);
        Route::post('/workers/reset-password',    [WorkerController::class, 'resetPassword']);
        Route::post('/workers/upload-photo',      [WorkerController::class, 'uploadPhoto']);
        Route::get('/workers/barcode/suggest',    [WorkerController::class, 'suggestBarcode']);
    });

    // Orders (= Bestellungen) — auftraege.bestellungen.view / .edit
    Route::middleware('permission:auftraege.bestellungen.view')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        // PDF generation for an order = read-only consumption of order data.
        Route::get('/pdf/order/{id}',          [PdfController::class, 'generateOrderPdf']);
        Route::get('/orders/{id}/pdfs',        [PdfController::class, 'listPdfs']);
        Route::get('/pdfs/{pdfId}/download',   [PdfController::class, 'downloadSavedPdf']);
    });
    Route::middleware('permission:auftraege.bestellungen.edit')->group(function () {
        Route::post('/orders', [OrderController::class, 'store']);
        Route::put('/orders/{id}', [OrderController::class, 'update']);
        Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
        Route::post('/orders/{id}/pdfs',       [PdfController::class, 'savePdf']);
        Route::delete('/pdfs/{pdfId}',         [PdfController::class, 'deletePdf']);
        Route::post('/pdfs/{pdfId}/share',     [PdfController::class, 'createShareLink']);
    });

    // PDF Templates & branding logo — company-wide configuration, gated
    // behind the dedicated Dokument-Design permission so it can be granted
    // to a custom role without handing over full user management. Owners /
    // admins hold it implicitly via the '*' preset.
    // Signature/stamp: the personal asset belongs to whoever signs the
    // Bestellung-PDFs, so Bestellungen editors may manage it too. The
    // controller checks "Bestellungen bearbeiten OR Dokument-Design".
    Route::post('/pdf/upload-signature',   [PdfController::class, 'uploadSignature']);
    Route::post('/pdf/upload-stamp',       [PdfController::class, 'uploadStamp']);
    Route::delete('/pdf/signature',        [PdfController::class, 'deleteSignature']);
    Route::delete('/pdf/stamp',            [PdfController::class, 'deleteStamp']);
    Route::middleware('permission:system.dokument_design.manage')->group(function () {
        Route::post('/pdf/upload-logo',        [PdfController::class, 'uploadLogo']);
        Route::post('/pdf-templates',          [PdfTemplateController::class, 'store']);
        Route::put('/pdf-templates/{id}',      [PdfTemplateController::class, 'update']);
        Route::delete('/pdf-templates/{id}',   [PdfTemplateController::class, 'destroy']);
    });
    // PDF templates listing is needed by every user that can open an order
    // (the order modal picks a template), so keep reads only auth-gated.
    Route::get('/pdf-templates',           [PdfTemplateController::class, 'index']);
    Route::get('/pdf-templates/{id}',      [PdfTemplateController::class, 'show']);

    // Company-wide editor layout templates (one per document type). Reads
    // for every renderer; writes are gated inside the controller
    // (Dokument-Design OR the edit permission of the document type).
    Route::get('/pdf/layouts',             [\App\Http\Controllers\Api\PdfLayoutController::class, 'index']);
    Route::put('/pdf/layout',              [\App\Http\Controllers\Api\PdfLayoutController::class, 'update']);
    Route::delete('/pdf/layout',           [\App\Http\Controllers\Api\PdfLayoutController::class, 'destroy']);
    Route::put('/pdf/branding',            [\App\Http\Controllers\Api\PdfLayoutController::class, 'updateBranding']);
    Route::post('/pdf/wizard-done',        [\App\Http\Controllers\Api\PdfLayoutController::class, 'wizardDone']);
    // Verlauf of the company templates (preview + restore) — gated in the controller.
    Route::get('/pdf/layout/history',      [\App\Http\Controllers\Api\PdfLayoutController::class, 'history']);
    Route::get('/pdf/layout/history/{id}', [\App\Http\Controllers\Api\PdfLayoutController::class, 'historyShow'])->where('id', '[0-9]{14}_[a-z0-9]{6}');
    Route::post('/pdf/layout/restore',     [\App\Http\Controllers\Api\PdfLayoutController::class, 'restore']);
    // PDF archive of issued documents (download / print / send) — per-type
    // view permission + Bereich scope are checked in the controller.
    Route::get('/dokument-archiv',               [\App\Http\Controllers\Api\DokumentArchivController::class, 'index']);
    Route::post('/dokument-archiv',              [\App\Http\Controllers\Api\DokumentArchivController::class, 'store'])->middleware('throttle:60,1');
    Route::get('/dokument-archiv/{id}/download', [\App\Http\Controllers\Api\DokumentArchivController::class, 'download'])->whereNumber('id');
    // Worker e-signature on documents (requested here, signed in the worker
    // app) — per-type permission + Bereich scope are checked in the controller.
    Route::get('/dokument-signaturen',            [\App\Http\Controllers\Api\DokumentSignaturController::class, 'index']);
    Route::post('/dokument-signaturen/anfragen',  [\App\Http\Controllers\Api\DokumentSignaturController::class, 'anfragen'])->middleware('throttle:30,1');
    Route::delete('/dokument-signaturen/{id}',    [\App\Http\Controllers\Api\DokumentSignaturController::class, 'withdraw'])->whereNumber('id');

    // Generic table export — the client sends the rows it already rendered
    // (data it is allowed to see), we just pack them into an .xlsx workbook.
    Route::post('/export/xlsx', [ExportController::class, 'xlsxTable']);

    // Schichtbörse (shift exchange) — reads behind dispo.view, every change
    // of the plan (publish / approve / reject / withdraw) behind dispo.edit.
    Route::middleware('permission:auftraege.dispo.view')->group(function () {
        Route::get('/schichtboerse',                         [\App\Http\Controllers\Api\SchichtboerseController::class, 'index']);
        Route::get('/schichtboerse/count',                   [\App\Http\Controllers\Api\SchichtboerseController::class, 'count']);
        Route::get('/schichtboerse/einsatz/{id}',            [\App\Http\Controllers\Api\SchichtboerseController::class, 'einsatz']);
    });
    Route::middleware('permission:auftraege.dispo.edit')->group(function () {
        Route::post('/schichtboerse/einsatz/{id}/veroeffentlichen', [\App\Http\Controllers\Api\SchichtboerseController::class, 'publish']);
        Route::post('/schichtboerse/angebote/{id}/zurueckziehen',   [\App\Http\Controllers\Api\SchichtboerseController::class, 'withdraw'])->whereNumber('id');
        Route::post('/schichtboerse/anfragen/{id}/genehmigen',      [\App\Http\Controllers\Api\SchichtboerseController::class, 'approve'])->whereNumber('id');
        Route::post('/schichtboerse/anfragen/{id}/ablehnen',        [\App\Http\Controllers\Api\SchichtboerseController::class, 'reject'])->whereNumber('id');
    });

    // Tasks (Einsätze) + Disposition — both belong to auftraege.dispo.*
    Route::middleware('permission:auftraege.dispo.view')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/{id}', [TaskController::class, 'show']);
        Route::get('/tasks/{id}/briefing-pdf', [TaskController::class, 'briefingPdf']);
        Route::get('/disposition/planner', [DispositionController::class, 'planner']);
        Route::get('/disposition/search', [DispositionController::class, 'search']);
        Route::get('/disposition/active-workers', [DispositionController::class, 'activeWorkers']);
    });
    // Zeiterfassung (Monatsabschluss) — its own permission pair; the page is
    // gated on the same keys in app.js (PAGE_PERMS / PAGE_EDIT_PERMS).
    Route::middleware('permission:auftraege.monatsabschluss.view')->group(function () {
        Route::get('/disposition/completed-workers', [DispositionController::class, 'completedWorkers']);
    });
    Route::middleware('permission:auftraege.monatsabschluss.edit')->group(function () {
        Route::post('/disposition/zeit-freigabe', [DispositionController::class, 'zeitFreigabe']);
        Route::post('/disposition/kunden-freigabe', [DispositionController::class, 'kundenFreigabe']);
        Route::post('/disposition/zeiten-speichern', [TaskController::class, 'saveZeiten']);
    });
    Route::middleware('permission:auftraege.dispo.edit')->group(function () {
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::put('/tasks/{id}', [TaskController::class, 'update']);
        Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);
        Route::post('/tasks/confirm', [TaskController::class, 'confirm']);
        Route::post('/disposition/save-assignment', [DispositionController::class, 'saveAssignment']);
        Route::post('/disposition/bulk-mark-planned', [DispositionController::class, 'bulkMarkPlanned']);
        Route::post('/disposition/bulk-confirm', [DispositionController::class, 'bulkConfirm']);
    });

    // Incoming Orders (Bestelleingang) — auftraege.eingang.view / .edit
    Route::middleware('permission:auftraege.eingang.view')->group(function () {
        Route::get('/incoming-orders', [IncomingOrderController::class, 'index']);
        Route::get('/incoming-orders/{id}', [IncomingOrderController::class, 'show']);
    });
    Route::middleware('permission:auftraege.eingang.edit')->group(function () {
        Route::post('/incoming-orders', [IncomingOrderController::class, 'store']);
        Route::put('/incoming-orders/{id}', [IncomingOrderController::class, 'update']);
        Route::delete('/incoming-orders/{id}', [IncomingOrderController::class, 'destroy']);
    });

    // Order Confirmations (Auftragsbestätigungen) — the commercial PDF sent
    // to the customer once an order is assembled in Bestelleingang.
    // auftraege.bestaetigung.view / .edit (sent-flag is the only mutation).
    Route::middleware('permission:auftraege.bestaetigung.view')->group(function () {
        Route::get('/order-confirmations', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'index']);
        Route::get('/order-confirmations/{id}', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'show']);
        Route::get('/order-confirmations/{id}/pdf', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'pdf']);
        Route::get('/order-confirmations/{id}/pdf-link', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'pdfLink']);
        // Same signed link, but for a PDF the editor already rendered
        // client-side (company layout applied) — stored for 10 minutes.
        Route::post('/order-confirmations/{id}/pdf-link', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'pdfLinkUpload']);
        // Archived (sent) versions + choosable "Bearbeiter".
        Route::get('/order-confirmations/{id}/archive', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'archivePdf']);
        Route::get('/order-confirmation-bearbeiter', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'bearbeiterOptions']);
    });
    Route::middleware('permission:auftraege.bestaetigung.edit')->group(function () {
        Route::post('/order-confirmations/{id}/sent', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'markSent']);
        Route::delete('/order-confirmations/{id}/sent', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'unmarkSent']);
        Route::put('/order-confirmations/{id}/bearbeiter', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'setBearbeiter']);
        Route::put('/order-confirmations/{id}/bemerkung', [\App\Http\Controllers\Api\OrderConfirmationController::class, 'setBemerkung']);
    });

    // Reports (legacy — kept for backward compatibility) → controlling.view
    Route::middleware('permission:controlling.view')->group(function () {
        Route::get('/reports', [ReportController::class, 'index']);
    });

    // Controlling & Analytics (new module)
    // .view = browsing dashboards & breakdowns.
    // .export = pulling raw CSV out of the system or persisting snapshots
    //   (snapshots are a long-term export of a point-in-time dataset).
    Route::prefix('controlling')->middleware('permission:controlling.view')->group(function () {
        // Combined endpoint — returns ALL dashboard widgets in one request.
        // Use this from the frontend so a single page load = 1 HTTP request
        // instead of 10 parallel ones (avoids running into the API throttle
        // limit when the user mashes F5 a few times).
        Route::get('/dashboard',                 [ControllingController::class, 'dashboard']);
        Route::get('/kpi',                       [ControllingController::class, 'kpi']);
        Route::get('/trend',                     [ControllingController::class, 'trend']);
        Route::get('/breakdown/customers',       [ControllingController::class, 'breakdownCustomers']);
        Route::get('/breakdown/objects',         [ControllingController::class, 'breakdownObjects']);
        Route::get('/breakdown/subcontractors',  [ControllingController::class, 'breakdownSubcontractors']);
        Route::get('/breakdown/bereich',         [ControllingController::class, 'breakdownBereich']);
        Route::get('/breakdown/schichtart',      [ControllingController::class, 'breakdownSchichtart']);
        Route::get('/breakdown/artikel',         [ControllingController::class, 'breakdownArtikel']);
        Route::get('/breakdown/mitarbeiter',     [ControllingController::class, 'breakdownMitarbeiter']);
        // Per-worker analysis (hours, times, places, profit/loss) + filter options
        Route::get('/mitarbeiter-options',       [ControllingController::class, 'mitarbeiterOptions']);
        Route::get('/mitarbeiter/{dienstNr}',    [ControllingController::class, 'mitarbeiterAnalyse']);
        // Drill-down: everything about ONE Kunde/Objekt/NU/Artikel/Bereich/Schichtart/Mitarbeiter
        Route::get('/drilldown/{dim}/{id}',      [ControllingDrilldownController::class, 'show']);
        Route::get('/heatmap',                   [ControllingController::class, 'heatmap']);
        Route::get('/forecast',                  [ControllingController::class, 'forecast']);
        Route::get('/details',                   [ControllingController::class, 'details']);
        Route::get('/snapshots',                 [ControllingController::class, 'listSnapshots']);
        Route::get('/snapshots/{id}',            [ControllingController::class, 'getSnapshot']);
        // Controlling-Cockpit: stufenweise Deckungsbeitragsrechnung + Objektcontrolling.
        Route::get('/cockpit',                   [\App\Http\Controllers\Api\ControllingCockpitController::class, 'cockpit']);
        Route::get('/settings',                  [\App\Http\Controllers\Api\ControllingCockpitController::class, 'settings']);
        Route::get('/fixkosten',                 [\App\Http\Controllers\Api\ControllingCockpitController::class, 'fixkostenIndex']);
    });
    // Cost settings + Fixkosten decide what every report shows as result → Inhaber only.
    Route::prefix('controlling')->middleware(['permission:controlling.view', 'tenant.owner', 'throttle:30,1'])->group(function () {
        Route::put('/settings',                  [\App\Http\Controllers\Api\ControllingCockpitController::class, 'saveSettings']);
        Route::post('/fixkosten',                [\App\Http\Controllers\Api\ControllingCockpitController::class, 'fixkostenStore']);
        Route::put('/fixkosten/{id}',            [\App\Http\Controllers\Api\ControllingCockpitController::class, 'fixkostenUpdate'])->whereNumber('id');
        Route::post('/fixkosten/{id}/aendern',   [\App\Http\Controllers\Api\ControllingCockpitController::class, 'fixkostenAendern'])->whereNumber('id');
        Route::delete('/fixkosten/{id}',         [\App\Http\Controllers\Api\ControllingCockpitController::class, 'fixkostenDestroy'])->whereNumber('id');
    });
    Route::prefix('controlling')->middleware('permission:controlling.export')->group(function () {
        Route::get('/export.csv',                [ControllingController::class, 'exportCsv']);
        Route::get('/export.xlsx',               [ControllingController::class, 'exportXlsx']);
        Route::post('/snapshots',                [ControllingController::class, 'saveSnapshot']);
        Route::delete('/snapshots/{id}',         [ControllingController::class, 'deleteSnapshot']);
    });

    // AI assistant — floating chat widget. Stays inside the auth+tenant
    // group so every request is bound to the caller's tenant connection
    // BEFORE we hand off to the LLM tool layer. Extra 20/min throttle
    // matches the per-minute cap in config/ai.php to make the limit
    // explicit at the routing layer too.
    Route::post('/ai/chat', [AiAssistantController::class, 'chat'])
        ->middleware('throttle:30,1');

    // Thumbs-up/down rating on an AI reply — quality telemetry only,
    // never blocks the chat flow. Loose throttle: one rating per reply.
    Route::post('/ai/feedback', [\App\Http\Controllers\Api\AiFeedbackController::class, 'store'])
        ->middleware('throttle:30,1');

    // AI PDF-template generator. Reproduces an uploaded company design as
    // an HTML template (with fixed placeholders) used for the order PDF.
    // Company-wide branding → gated by the dedicated Dokument-Design permission.
    Route::middleware('permission:system.dokument_design.manage')->group(function () {
        Route::post('/ai/generate-template', [\App\Http\Controllers\Api\AiTemplateController::class, 'generate'])
            ->middleware('throttle:6,1');
        Route::post('/ai/template', [\App\Http\Controllers\Api\AiTemplateController::class, 'save']);
        Route::delete('/ai/template', [\App\Http\Controllers\Api\AiTemplateController::class, 'clear']);
    });

    // AI write-action confirmation flow. When the LLM proposes a write
    // (create_worker, update_kunde, ...) the chat endpoint returns a
    // requires_confirmation payload with an action_id. The UI then hits
    // these endpoints. Tighter throttle on confirm: someone spam-clicking
    // "Confirm" must not bypass the per-minute write budget.
    Route::get(   '/ai/actions/{actionId}',         [\App\Http\Controllers\Api\AiActionsController::class, 'show']);
    Route::post(  '/ai/actions/{actionId}/confirm', [\App\Http\Controllers\Api\AiActionsController::class, 'confirm'])
        ->middleware('throttle:10,1');
    Route::delete('/ai/actions/{actionId}',         [\App\Http\Controllers\Api\AiActionsController::class, 'cancel']);

    // AI file-upload pipeline. Accepts a PDF / image, extracts text,
    // asks the LLM to fill the JSON shape for the requested write tool,
    // and registers a pending action — same downstream as a chat-driven
    // write so the UI confirmation modal handles both flows identically.
    // Tight throttle: file uploads are an order of magnitude pricier
    // than a normal chat turn (extraction + structured-output LLM call).
    Route::post('/ai/extract', [\App\Http\Controllers\Api\AiUploadController::class, 'extract'])
        ->middleware('throttle:10,1');

    // AI export download — backed by a temporary signed URL (15 min)
    // produced by the export_einsaetze_csv tool. The route handler
    // double-checks the path is inside the caller's tenant folder so a
    // leaked link from another tenant is rejected even if the signature
    // is technically valid (defense in depth).
    Route::get('/ai/export/download', [\App\Http\Controllers\Api\AiExportController::class, 'download'])
        ->middleware('signed')
        ->name('ai.export.download');


    // Users (tenant-level) — RBAC
    // /me + /permissions are usable by anyone authenticated (no gating)
    Route::get('/users/me',           [UserController::class, 'me']);
    Route::get('/users/permissions',  [UserController::class, 'permissions']);

    // Reads: gated by `users.manage` but NOT throttled. Throttling reads
    // caused a flaky UX where rapid F5 refreshes hit the limit and the
    // user list returned `{"message":"Too Many Attempts."}` instead of an
    // array, so the page silently rendered empty (looked like the new user
    // disappeared at random).
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users',           [UserController::class, 'index']);
        Route::get('/users/{id}',      [UserController::class, 'show'])->whereNumber('id');
    });

    // Mutations: same permission gate PLUS a 30/min throttle. This is the
    // anti-abuse boundary — no legitimate admin workflow needs to create,
    // edit, or delete users faster than once every 2 seconds on average.
    Route::middleware(['permission:users.manage', 'throttle:30,1'])->group(function () {
        Route::post('/users',          [UserController::class, 'store']);
        Route::put('/users/{id}',      [UserController::class, 'update'])->whereNumber('id');
        Route::delete('/users/{id}',   [UserController::class, 'destroy'])->whereNumber('id');
    });

    // Custom roles (tenant-defined, reusable permission bundles).
    // Reads share the users.manage gate; writes add the same 30/min throttle.
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/roles',           [\App\Http\Controllers\Api\RoleController::class, 'index']);
    });
    Route::middleware(['permission:users.manage', 'throttle:30,1'])->group(function () {
        Route::post('/roles',          [\App\Http\Controllers\Api\RoleController::class, 'store']);
        Route::put('/roles/{id}',      [\App\Http\Controllers\Api\RoleController::class, 'update'])->whereNumber('id');
        Route::delete('/roles/{id}',   [\App\Http\Controllers\Api\RoleController::class, 'destroy'])->whereNumber('id');
    });

    // Bereich catalog (Hauptbereiche + Unterbereiche): readable by every
    // authenticated office user — pickers need it and restricted users get
    // scope-filtered results. Writes are gated inside the controller to
    // admins/owners + any stammdaten.*.edit holder.
    Route::get('/bereich-catalog',          [\App\Http\Controllers\Api\BereichCatalogController::class, 'index']);
    Route::get('/sicherheitsleistungen',    [\App\Http\Controllers\Api\BereichCatalogController::class, 'leistungen']);
    Route::post('/bereich-catalog',         [\App\Http\Controllers\Api\BereichCatalogController::class, 'store']);
    Route::put('/bereich-catalog/{id}',     [\App\Http\Controllers\Api\BereichCatalogController::class, 'update'])->whereNumber('id');
    Route::delete('/bereich-catalog/{id}',  [\App\Http\Controllers\Api\BereichCatalogController::class, 'destroy'])->whereNumber('id');
    Route::post('/bereich-catalog/{id}/paket', [\App\Http\Controllers\Api\BereichCatalogController::class, 'paket'])->whereNumber('id');

    // Qualifikationen lists per Hauptbereich (read via /bereich-catalog).
    Route::post('/bereich-qualifikationen',         [\App\Http\Controllers\Api\BereichQualifikationController::class, 'store']);
    Route::put('/bereich-qualifikationen/{id}',     [\App\Http\Controllers\Api\BereichQualifikationController::class, 'update'])->whereNumber('id');
    Route::delete('/bereich-qualifikationen/{id}',  [\App\Http\Controllers\Api\BereichQualifikationController::class, 'destroy'])->whereNumber('id');

    // Notifications (in-app, per-tenant) — surface unread counts for the
    // sidebar badge + a dropdown list of all notifications.
    Route::get('/notifications',                   [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count',      [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read',        [NotificationController::class, 'markRead'])->whereNumber('id');
    Route::post('/notifications/read-all',         [NotificationController::class, 'markAllRead']);
    // Personal per-user notification toggles (Konto & Firma → Benachrichtigungen)
    Route::get('/notifications/preferences',       [NotificationController::class, 'preferences']);
    Route::put('/notifications/preferences',       [NotificationController::class, 'savePreferences']);
    // Device push tokens for this user (admin logged into the native app).
    Route::post('/notifications/push/subscribe',   [NotificationController::class, 'pushSubscribe']);
    Route::post('/notifications/push/unsubscribe', [NotificationController::class, 'pushUnsubscribe']);

    // Worker Absences — tied to the Mitarbeiter permission pair. Approve
    // and reject are also write actions (they change the absence status).
    Route::middleware('permission:stammdaten.mitarbeiter.view')->group(function () {
        Route::get('/worker-absences/pending-counts', [WorkerAbsenceController::class, 'pendingCounts']);
        Route::get('/worker-absences/requests', [WorkerAbsenceController::class, 'requests']);
        Route::get('/worker-absences/history', [WorkerAbsenceController::class, 'history']);
        Route::get('/worker-absences/calendar', [WorkerAbsenceController::class, 'calendar']);
        Route::get('/worker-absences', [WorkerAbsenceController::class, 'index']);
    });
    Route::middleware('permission:stammdaten.mitarbeiter.edit')->group(function () {
        Route::post('/worker-absences', [WorkerAbsenceController::class, 'store']);
        Route::put('/worker-absences/{id}', [WorkerAbsenceController::class, 'update']);
        Route::delete('/worker-absences/{id}', [WorkerAbsenceController::class, 'destroy']);
        Route::post('/worker-absences/{id}/approve', [WorkerAbsenceController::class, 'approve'])->whereNumber('id');
        Route::post('/worker-absences/{id}/reject',  [WorkerAbsenceController::class, 'reject'])->whereNumber('id');
    });

    // Worker Documents — same Mitarbeiter pair. Note these endpoints use
    // POST for delete/update (legacy form-data style), so we gate them as
    // mutations even when they look like reads.
    Route::middleware('permission:stammdaten.mitarbeiter.view')->group(function () {
        Route::get('/worker-documents', [WorkerDocumentController::class, 'index']);
        Route::get('/worker-documents/{id}/thumb', [WorkerDocumentController::class, 'thumb'])->whereNumber('id');
    });
    Route::middleware('permission:stammdaten.mitarbeiter.edit')->group(function () {
        Route::post('/worker-documents/upload', [WorkerDocumentController::class, 'upload']);
        Route::post('/worker-documents/update', [WorkerDocumentController::class, 'update']);
        Route::post('/worker-documents/delete', [WorkerDocumentController::class, 'destroy']);
    });

    // Worker document renewals — expiring docs are re-requested from the
    // worker (email + app), uploaded as PDF and reviewed by HR before the
    // replacement goes live. See WorkerDocRenewalController.
    Route::middleware('permission:stammdaten.mitarbeiter.view')->group(function () {
        Route::get('/worker-doc-renewals', [\App\Http\Controllers\Api\WorkerDocRenewalController::class, 'index']);
        Route::get('/worker-doc-renewals/{id}/file', [\App\Http\Controllers\Api\WorkerDocRenewalController::class, 'file'])->whereNumber('id');
    });
    Route::middleware('permission:stammdaten.mitarbeiter.edit')->group(function () {
        Route::post('/worker-doc-renewals/request', [\App\Http\Controllers\Api\WorkerDocRenewalController::class, 'requestRenewal']);
        Route::post('/worker-doc-renewals/{id}/approve', [\App\Http\Controllers\Api\WorkerDocRenewalController::class, 'approve'])->whereNumber('id');
        Route::post('/worker-doc-renewals/{id}/reject', [\App\Http\Controllers\Api\WorkerDocRenewalController::class, 'reject'])->whereNumber('id');
        Route::post('/worker-doc-renewals/{id}/resend', [\App\Http\Controllers\Api\WorkerDocRenewalController::class, 'resend'])->whereNumber('id');
        Route::get('/worker-doc-renewals/{id}/log',     [\App\Http\Controllers\Api\WorkerDocRenewalController::class, 'log'])->whereNumber('id');
    });

    // Worker Messages — auftraege.nachrichten.view / .send
    Route::middleware('permission:auftraege.nachrichten.view')->group(function () {
        Route::get('/worker-messages/inbox', [WorkerMessageController::class, 'inbox']);
        Route::get('/worker-messages/thread', [WorkerMessageController::class, 'thread']);
        Route::get('/worker-messages/unread', [WorkerMessageController::class, 'unread']);
    });
    Route::middleware('permission:auftraege.nachrichten.send')->group(function () {
        Route::get('/worker-messages/workers', [WorkerMessageController::class, 'workers']);
        Route::post('/worker-messages/reply', [WorkerMessageController::class, 'reply']);
        Route::post('/worker-messages/send-document', [WorkerMessageController::class, 'sendDocument']);

        // Office → worker WebRTC voice calls (signaling only; media is P2P
        // or via the self-hosted coturn relay on this server).
        Route::post('/calls', [\App\Http\Controllers\Api\CallController::class, 'start']);
        Route::get('/calls/ice-servers', [\App\Http\Controllers\Api\CallController::class, 'iceServers']);
        Route::get('/calls/{id}', [\App\Http\Controllers\Api\CallController::class, 'show']);
        Route::post('/calls/{id}/offer', [\App\Http\Controllers\Api\CallController::class, 'offer']);
        Route::post('/calls/{id}/ice', [\App\Http\Controllers\Api\CallController::class, 'ice']);
        Route::post('/calls/{id}/cancel', [\App\Http\Controllers\Api\CallController::class, 'cancel']);
        Route::post('/calls/{id}/end', [\App\Http\Controllers\Api\CallController::class, 'end']);
    });


    // Customer Documents — gated by the Kunden pair.
    Route::middleware('permission:stammdaten.kunden.view')->group(function () {
        Route::get('/customer-documents', [CustomerDocumentController::class, 'index']);
        Route::get('/customer-documents/{id}/thumb', [CustomerDocumentController::class, 'thumb'])->whereNumber('id');
    });
    Route::middleware('permission:stammdaten.kunden.edit')->group(function () {
        Route::post('/customer-documents/upload', [CustomerDocumentController::class, 'upload']);
        Route::post('/customer-documents/delete', [CustomerDocumentController::class, 'destroy']); // legacy
        Route::delete('/customer-documents/{id}', [CustomerDocumentController::class, 'destroy']);
    });

    // NU Documents — gated by the NU pair.
    Route::middleware('permission:stammdaten.nu.view')->group(function () {
        Route::get('/nu-documents', [NuDocumentController::class, 'index']);
        Route::get('/nu-documents/expiring', [NuDocumentController::class, 'expiring']);
        Route::get('/nu-documents/{id}/thumb', [NuDocumentController::class, 'thumb'])->whereNumber('id');
    });
    Route::middleware('permission:stammdaten.nu.edit')->group(function () {
        Route::post('/nu-documents/upload', [NuDocumentController::class, 'upload']);
        Route::post('/nu-documents/delete', [NuDocumentController::class, 'destroy']); // legacy
        Route::delete('/nu-documents/{id}', [NuDocumentController::class, 'destroy']);
    });

    // Price History — reads behind preise.view. Writing a new price record
    // is part of editing an article, so it shares stammdaten.objekte.edit
    // (the registry has no dedicated preise.edit key, by design).
    Route::middleware('permission:stammdaten.preise.view')->group(function () {
        Route::get('/price-history',                          [PriceHistoryController::class, 'index']);
        Route::get('/price-history/stats',                    [PriceHistoryController::class, 'stats']);
        Route::get('/price-history/article/{artikel_id}',     [PriceHistoryController::class, 'articleHistory']);
    });
    Route::middleware('permission:stammdaten.objekte.edit')->group(function () {
        Route::post('/price-history',                         [PriceHistoryController::class, 'store']);
    });

    // Lager & Ausrüstung — warehouse/equipment + issue/return log.
    // Issuing to a worker writes, so it sits behind lager.edit; the worker
    // picker itself only reads names (needed even for view+issue roles).
    Route::middleware('permission:stammdaten.lager.view')->group(function () {
        Route::get('/lager',                          [LagerController::class, 'index']);
        Route::get('/lager/stats',                    [LagerController::class, 'stats']);
        Route::get('/lager/kategorien',               [LagerController::class, 'kategorien']);
        Route::get('/lager/ausgaben',                 [LagerController::class, 'ausgaben']);
        Route::get('/lager/mitarbeiter-options',      [LagerController::class, 'mitarbeiterOptions']);
        Route::get('/lager/{id}',                     [LagerController::class, 'show'])->whereNumber('id');
        // Invoice / signed return protocol — private disk, authenticated only.
        Route::get('/lager/{id}/rechnung',            [LagerController::class, 'rechnungFile'])->whereNumber('id');
        Route::get('/lager/ausgaben/{id}/protokoll',  [LagerController::class, 'protokollFile'])->whereNumber('id');
    });
    Route::middleware('permission:stammdaten.lager.edit')->group(function () {
        Route::post('/lager',                         [LagerController::class, 'store']);
        Route::post('/lager/{id}/foto',               [LagerController::class, 'fotoUpload'])->whereNumber('id');
        Route::delete('/lager/{id}/foto',             [LagerController::class, 'fotoDelete'])->whereNumber('id');
        Route::post('/lager/{id}/rechnung',           [LagerController::class, 'rechnungUpload'])->whereNumber('id');
        Route::delete('/lager/{id}/rechnung',         [LagerController::class, 'rechnungDelete'])->whereNumber('id');
        Route::put('/lager/{id}',                     [LagerController::class, 'update'])->whereNumber('id');
        Route::delete('/lager/{id}',                  [LagerController::class, 'destroy'])->whereNumber('id');
        Route::post('/lager/kategorien',              [LagerController::class, 'kategorieStore']);
        Route::put('/lager/kategorien/{id}',          [LagerController::class, 'kategorieUpdate'])->whereNumber('id');
        Route::delete('/lager/kategorien/{id}',       [LagerController::class, 'kategorieDestroy'])->whereNumber('id');
        Route::post('/lager/{id}/ausgabe',            [LagerController::class, 'ausgabe'])->whereNumber('id');
        Route::post('/lager/ausgaben/{id}/rueckgabe', [LagerController::class, 'rueckgabe'])->whereNumber('id');
    });

    // Aufgaben (tasks) — every office user may assign and receive tasks;
    // visibility/ownership is enforced inside AufgabenController.
    Route::get('/aufgaben',                        [\App\Http\Controllers\Api\AufgabenController::class, 'index']);
    Route::get('/aufgaben/counts',                 [\App\Http\Controllers\Api\AufgabenController::class, 'counts']);
    Route::get('/aufgaben/options',                [\App\Http\Controllers\Api\AufgabenController::class, 'options']);
    Route::get('/aufgaben/{id}',                   [\App\Http\Controllers\Api\AufgabenController::class, 'show'])->whereNumber('id');
    Route::post('/aufgaben',                       [\App\Http\Controllers\Api\AufgabenController::class, 'store'])->middleware('throttle:60,1');
    Route::put('/aufgaben/empfaenger/{id}',        [\App\Http\Controllers\Api\AufgabenController::class, 'updateStatus'])->whereNumber('id');
    Route::delete('/aufgaben/{id}',                [\App\Http\Controllers\Api\AufgabenController::class, 'destroy'])->whereNumber('id');

    // Chat Messages
    Route::get('/messages/conversations', [MessageController::class, 'conversations']);
    Route::get('/messages/messages', [MessageController::class, 'messages']);
    Route::post('/messages/send', [MessageController::class, 'send']);
    Route::post('/messages/create', [MessageController::class, 'create']);
    Route::post('/messages/add-members', [MessageController::class, 'addMembers']);
    Route::get('/messages/unread', [MessageController::class, 'unread']);
    Route::post('/messages/delete', [MessageController::class, 'deleteConversation']);
    Route::post('/messages/upload-image', [MessageController::class, 'uploadImage']);
    Route::get('/messages/users', [MessageController::class, 'users']);

    // Disposition — NU positions (subcontractor lineup for shifts)
    Route::middleware('permission:auftraege.dispo.view')->group(function () {
        Route::get('/disposition/nu-positions', [DispositionController::class, 'nuPositions']);
    });
    Route::middleware('permission:auftraege.dispo.edit')->group(function () {
        Route::post('/disposition/save-nu-positions', [DispositionController::class, 'saveNuPositions']);
        Route::post('/disposition/remove-nu-worker', [DispositionController::class, 'removeNuWorker']);
    });

});

/*
|--------------------------------------------------------------------------
| NU Portal routes (custom token auth via nu.auth middleware)
|--------------------------------------------------------------------------
*/
Route::middleware(['nu.auth', 'tenant.boundary'])->prefix('nu-portal')->group(function () {
    Route::get('/shifts', [NuPortalController::class, 'shifts']);
    Route::get('/config', [NuPortalController::class, 'config']);
    Route::get('/workers', [NuPortalController::class, 'workers']);
    Route::post('/workers', [NuPortalController::class, 'storeWorker']);
    Route::post('/workers/{id}', [NuPortalController::class, 'updateWorker']);
    Route::post('/workers/{id}/photo', [NuPortalController::class, 'uploadPhoto']);
    Route::post('/workers/{id}/delete-photo', [NuPortalController::class, 'deletePhoto']);
    Route::post('/workers/{id}/delete', [NuPortalController::class, 'deleteWorker']);
    Route::get('/positions', [NuPortalController::class, 'positions']);
    Route::post('/assign-worker', [NuPortalController::class, 'assignWorker']);
    Route::post('/unassign-worker', [NuPortalController::class, 'unassignWorker']);
    Route::post('/accept-shift', [NuPortalController::class, 'acceptShift']);
    Route::post('/reject-shift', [NuPortalController::class, 'rejectShift']);
    Route::get('/completed-shifts', [NuPortalController::class, 'completedShifts']);
});

/*
|--------------------------------------------------------------------------
| Kundenportal routes (read-only, custom token auth via kunden.auth)
|--------------------------------------------------------------------------
*/
Route::middleware(['kunden.auth', 'tenant.boundary', 'throttle:120,1'])->prefix('kunden-portal')->group(function () {
    require __DIR__ . '/qm/portal.php';
});

// Objekt-Terminal of the QM module (own token auth) + signed PDF links.
require __DIR__ . '/qm/terminal.php';
require __DIR__ . '/qm/public.php';

/*
|--------------------------------------------------------------------------
| Worker App routes (custom token auth via worker.auth + tenant middleware)
|--------------------------------------------------------------------------
*/
Route::middleware(['worker.auth', 'tenant.boundary'])->prefix('worker-app')->group(function () {
    Route::post('/auth/change-password', [WorkerAppAuthController::class, 'changePassword']);
    Route::post('/auth/logout', [WorkerAppAuthController::class, 'logout']);
    Route::get('/feed', [WorkerAppFeedController::class, 'index']);
    Route::get('/shifts', [WorkerAppShiftController::class, 'index']);
    Route::get('/zeit', [WorkerAppZeitController::class, 'index']);
    Route::get('/zeit/zeiten', [WorkerAppZeitController::class, 'zeiten']);
    Route::post('/zeit/clock-in', [WorkerAppZeitController::class, 'clockIn']);
    Route::post('/zeit/clock-out', [WorkerAppZeitController::class, 'clockOut']);
    Route::post('/zeit/pause-start', [WorkerAppZeitController::class, 'pauseStart']);
    Route::post('/zeit/pause-end', [WorkerAppZeitController::class, 'pauseEnd']);
    Route::post('/zeit/extend', [WorkerAppZeitController::class, 'extend']);
    Route::get('/absences', [WorkerAppAbsenceController::class, 'index']);
    Route::post('/absences', [WorkerAppAbsenceController::class, 'store']);
    Route::put('/absences', [WorkerAppAbsenceController::class, 'update']);
    Route::delete('/absences', [WorkerAppAbsenceController::class, 'destroy']);
    Route::get('/messages', [WorkerAppMessageController::class, 'index']);
    Route::get('/messages/unread-count', [WorkerAppMessageController::class, 'unreadCount']);
    Route::post('/messages', [WorkerAppMessageController::class, 'store']);
    Route::get('/messages/photo-context', [\App\Http\Controllers\Api\WorkerAppPhotoController::class, 'context']);
    Route::post('/messages/photo', [\App\Http\Controllers\Api\WorkerAppPhotoController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/schichtboerse', [\App\Http\Controllers\Api\WorkerAppSchichtboerseController::class, 'index']);
    Route::post('/schichtboerse/angebote/{id}/anfragen', [\App\Http\Controllers\Api\WorkerAppSchichtboerseController::class, 'request'])->whereNumber('id')->middleware('throttle:30,1');
    Route::delete('/schichtboerse/angebote/{id}/anfragen', [\App\Http\Controllers\Api\WorkerAppSchichtboerseController::class, 'cancelRequest'])->whereNumber('id');
    Route::post('/schichtboerse/abgeben', [\App\Http\Controllers\Api\WorkerAppSchichtboerseController::class, 'handOver'])->middleware('throttle:20,1');
    Route::post('/schichtboerse/angebote/{id}/zurueckziehen', [\App\Http\Controllers\Api\WorkerAppSchichtboerseController::class, 'withdrawHandOver'])->whereNumber('id');
    // Qualitätsmanagement: Wachbuch, Dienstanweisungen, Meine PIN
    require __DIR__ . '/qm/worker.php';
    Route::get('/checklisten', [\App\Http\Controllers\Api\WorkerAppChecklistenController::class, 'index']);
    Route::post('/checklisten', [\App\Http\Controllers\Api\WorkerAppChecklistenController::class, 'store'])->middleware('throttle:30,1');

    // Worker-facing notification feed (approve/reject responses, etc.)
    Route::get('/notifications',                   [WorkerAppNotificationController::class, 'index']);
    Route::get('/notifications/unread-count',      [WorkerAppNotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read',        [WorkerAppNotificationController::class, 'markRead'])->whereNumber('id');
    Route::post('/notifications/read-all',         [WorkerAppNotificationController::class, 'markAllRead']);
    // E-signature requests from the office (own rows of a document)
    Route::get('/signaturen',                      [\App\Http\Controllers\Api\WorkerAppSignaturController::class, 'index']);
    Route::get('/signaturen/{id}',                 [\App\Http\Controllers\Api\WorkerAppSignaturController::class, 'show'])->whereNumber('id');
    Route::post('/signaturen/{id}/unterschreiben', [\App\Http\Controllers\Api\WorkerAppSignaturController::class, 'sign'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/signaturen/{id}/ablehnen',       [\App\Http\Controllers\Api\WorkerAppSignaturController::class, 'reject'])->whereNumber('id')->middleware('throttle:20,1');

    // Document renewal requests — list mine + upload replacement PDF.
    Route::get('/doc-requests', [\App\Http\Controllers\Api\WorkerAppDocumentController::class, 'index']);
    Route::post('/doc-requests/{id}/upload', [\App\Http\Controllers\Api\WorkerAppDocumentController::class, 'upload'])->whereNumber('id');

    // Web-Push (VAPID) subscriptions — real push even when the app is closed.
    Route::get('/push/vapid-key', [\App\Http\Controllers\Api\WorkerAppPushController::class, 'vapidKey']);
    Route::post('/push/subscribe', [\App\Http\Controllers\Api\WorkerAppPushController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [\App\Http\Controllers\Api\WorkerAppPushController::class, 'unsubscribe']);

    // WebRTC voice calls — worker side (answer/reject/ICE/end) + the
    // pusher-style auth that lets the app join private-worker.{tenant}.{id}.
    Route::post('/broadcasting/auth', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'broadcastAuth']);
    Route::get('/realtime/config', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'realtimeConfig']);
    Route::get('/calls/pending', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'pending']);
    Route::get('/calls/ice-servers', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'iceServers']);
    Route::get('/calls/{id}', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'show']);
    Route::post('/calls/{id}/answer', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'answer']);
    Route::post('/calls/{id}/reject', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'reject']);
    Route::post('/calls/{id}/ice', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'ice']);
    Route::post('/calls/{id}/end', [\App\Http\Controllers\Api\WorkerAppCallController::class, 'end']);
});

// Document view — outside middleware (uses token query param for browser tab access)
Route::get('/worker-app/messages/document/{id}', [WorkerAppMessageController::class, 'document']);
