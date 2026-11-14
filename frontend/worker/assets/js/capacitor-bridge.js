/* ============================================
   Capacitor Native Bridge
   Handles native plugin integration
   ============================================ */

document.addEventListener('DOMContentLoaded', async () => {
    // Initialize Capacitor plugins if available
    if (typeof Capacitor !== 'undefined' && Capacitor.isNativePlatform()) {
        initStatusBar();
        initKeyboard();
        initSplashScreen();
        initBackButton();
        initEdgeToEdge();

        // Re-apply native icon/scrim colour whenever the theme attribute
        // flips or the page resurfaces — covers WebView state restores
        // where no reload (and no DOMContentLoaded) ever happens.
        try {
            new MutationObserver(syncSystemBarIcons).observe(
                document.documentElement,
                { attributes: true, attributeFilter: ['data-theme'] });
        } catch (e) {}
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) syncSystemBarIcons();
        });
        window.addEventListener('pageshow', syncSystemBarIcons);
        window.addEventListener('focus', syncSystemBarIcons);
    }
});

// ============================================
// EDGE-TO-EDGE (Android) — pull native system-bar insets into the CSS vars
// --safe-top / --safe-bottom. iOS fills env(safe-area-inset-*) natively; the
// Android WebView doesn't, so MainActivity pushes them (and we pull once here
// to cover the case where the push ran before the document existed).
// ============================================
async function initEdgeToEdge() {
    try {
        if (Capacitor.getPlatform() !== 'android') return;
        const DV = Capacitor.Plugins && Capacitor.Plugins.DocumentViewer;
        if (!DV || !DV.getSafeAreaInsets) return;
        const r = await DV.getSafeAreaInsets();
        const s = document.documentElement.style;
        if (r && typeof r.top === 'number') s.setProperty('--safe-top', r.top + 'px');
        if (r && typeof r.bottom === 'number') s.setProperty('--safe-bottom', r.bottom + 'px');
    } catch (e) { /* plugin not available — vars keep their env() fallback */ }
    syncSystemBarIcons();
}

// Dark icons on light theme, light icons on dark theme. Called on boot and
// from toggleTheme() whenever the palette flips.
async function syncSystemBarIcons() {
    try {
        if (typeof Capacitor === 'undefined' || Capacitor.getPlatform() !== 'android') return;
        const DV = Capacitor.Plugins && Capacitor.Plugins.DocumentViewer;
        if (!DV || !DV.setSystemBarStyle) return;
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        await DV.setSystemBarStyle({ darkIcons: !dark });
    } catch (e) { /* best-effort */ }
}
window.wSyncSystemBarIcons = syncSystemBarIcons;

// ============================================
// OPEN DOCUMENT (native viewer / browser)
// ============================================
async function openDocument(url, title) {
    if (typeof Capacitor !== 'undefined' && Capacitor.isNativePlatform()) {
        try {
            const { Plugins } = Capacitor;
            if (Plugins && Plugins.DocumentViewer) {
                await Plugins.DocumentViewer.open({ url: url, title: title || 'Stundennachweis' });
                return;
            }
        } catch (e) {
            // Plugin not available, fall through
        }
    }
    // Fallback: open in new tab (web browser)
    window.open(url, '_blank');
}

// ============================================
// STATUS BAR
// ============================================
async function initStatusBar() {
    try {
        const { StatusBar, Style } = await import('@capacitor/status-bar');
        if (Capacitor.getPlatform() === 'android') {
            // Edge-to-edge: bars stay transparent, the WebView draws behind
            // them; icon colour follows the app theme via syncSystemBarIcons.
            await StatusBar.setOverlaysWebView({ overlay: true });
            await StatusBar.setBackgroundColor({ color: '#00000000' });
        } else {
            await StatusBar.setStyle({ style: Style.Dark });
            await StatusBar.setBackgroundColor({ color: '#000000' });
            try { await StatusBar.setOverlaysWebView({ overlay: false }); } catch (e) {}
        }
    } catch (e) { /* plugin not available */ }
}

// ============================================
// KEYBOARD
// ============================================
async function initKeyboard() {
    // Android: MainActivity owns keyboard state. It measures the IME and the
    // WebView natively and pushes --keyboard-height + .keyboard-open, so no
    // JS-side estimate can race it (Samsung shrinks the WebView late, which
    // made the input float far above the keyboard).
    if (typeof Capacitor !== 'undefined' && Capacitor.getPlatform() === 'android') return;
    // iOS: the WKWebView is NOT resized when the keyboard opens (Capacitor's
    // "native" mode leaves the frame alone), so the keyboard simply covers the
    // bottom of the page. The plugin tells us the keyboard height — turn it
    // into --keyboard-height + .keyboard-open (the same contract the Android
    // MainActivity feeds) so the chat bar lifts above it. NB: the plugin is
    // reached through Capacitor.Plugins — a bare `import('@capacitor/keyboard')`
    // can never resolve in this un-bundled web app.
    const Keyboard = window.Capacitor && Capacitor.Plugins ? Capacitor.Plugins.Keyboard : null;
    const vv = window.visualViewport;
    let pluginKb = 0;
    let baseH = window.innerHeight;
    const apply = () => {
        // Part of the keyboard the layout viewport already gave up (0 on iOS
        // today, but never double-lift if a future OS resizes the WebView).
        const resized = Math.max(0, baseH - window.innerHeight);
        let kb = pluginKb > 0 ? Math.max(0, pluginKb - resized) : 0;
        if (!pluginKb && vv) kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
        document.body.style.setProperty('--keyboard-height', kb + 'px');
        document.body.classList.toggle('keyboard-open', pluginKb > 0 || kb > 0);
        if (pluginKb > 0) window.scrollTo(0, 0);
    };
    if (Keyboard && Keyboard.addListener) {
        const show = (info) => { pluginKb = (info && info.keyboardHeight) || 0; apply(); };
        const hide = () => { pluginKb = 0; apply(); };
        Keyboard.addListener('keyboardWillShow', show);
        Keyboard.addListener('keyboardDidShow', show);
        Keyboard.addListener('keyboardWillHide', hide);
        Keyboard.addListener('keyboardDidHide', hide);
    }
    window.addEventListener('resize', () => { if (!pluginKb) baseH = Math.max(baseH, window.innerHeight); apply(); });
    if (vv) {
        vv.addEventListener('resize', apply);
        vv.addEventListener('scroll', apply);
    }
    apply();
}

// ============================================
// SPLASH SCREEN
// ============================================
async function initSplashScreen() {
    try {
        const { SplashScreen } = await import('@capacitor/splash-screen');
        await SplashScreen.hide();
    } catch (e) { /* plugin not available */ }
}

// ============================================
// BACK BUTTON (Android)
// ============================================
async function initBackButton() {
    try {
        const { App } = await import('@capacitor/app');
        App.addListener('backButton', ({ canGoBack }) => {
            // An open signature sheet / notification drawer closes first.
            if (typeof window.closeSignaturSheet === 'function' && window.closeSignaturSheet()) return;
            if (typeof window.closeNotifDrawer === 'function' && window.closeNotifDrawer()) return;
            if (typeof currentTab !== 'undefined' && currentTab !== 'feed') {
                switchTab('feed');
            } else {
                App.minimizeApp();
            }
        });
    } catch (e) { /* plugin not available */ }
}

// Called by initAdminMode() right before the WebView navigates to the
// web system (clood-x.com/index.php). With no JS listener registered,
// Capacitor falls back to native WebView history for the BACK key.
window.wReleaseBackButton = async function () {
    try {
        const { App } = await import('@capacitor/app');
        await App.removeAllListeners();
    } catch (e) { /* plugin not available */ }
};
