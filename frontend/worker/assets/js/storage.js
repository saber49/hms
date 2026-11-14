/* ============================================
   Clood-X Mitarbeiter — Secure Session Storage
   --------------------------------------------
   Stores the auth token (and the small worker profile) in the device's
   hardware-backed secure store:
     - iOS:     Keychain
     - Android: Keystore-encrypted SharedPreferences
   via the `capacitor-secure-storage-plugin` native plugin, accessed through
   the Capacitor proxy (window.Capacitor.Plugins.SecureStoragePlugin) so it
   works in the non-bundled WebView.

   On the web/PWA (or if the native plugin is unavailable for any reason) it
   falls back transparently to localStorage, so the app NEVER breaks — the
   secure-at-rest benefit simply activates once the native build ships.

   The token is cached in memory after init() so the rest of the app can keep
   reading it synchronously (wGetToken) without awaiting on every request.
   ============================================ */
(function () {
    const TOKEN_KEY = 'w_token';
    const USER_KEY  = 'w_user';

    let _token = null;
    let _user  = {};
    let _ready = false;

    const isNative = (typeof window.Capacitor !== 'undefined'
        && typeof window.Capacitor.isNativePlatform === 'function'
        && window.Capacitor.isNativePlatform());

    // Native secure-storage plugin proxy, if the plugin is installed/synced.
    function ss() {
        try {
            return (window.Capacitor && window.Capacitor.Plugins)
                ? window.Capacitor.Plugins.SecureStoragePlugin
                : null;
        } catch (e) { return null; }
    }

    async function ssGet(key) {
        const p = ss();
        if (!p) return null;
        try {
            const res = await p.get({ key });
            return (res && typeof res.value !== 'undefined') ? res.value : null;
        } catch (e) {
            // Plugin throws when the key does not exist — treat as null.
            return null;
        }
    }

    async function ssSet(key, value) {
        const p = ss();
        if (!p) return false;
        try { await p.set({ key, value: String(value) }); return true; }
        catch (e) { return false; }
    }

    async function ssRemove(key) {
        const p = ss();
        if (!p) return;
        try { await p.remove({ key }); } catch (e) { /* already gone */ }
    }

    function parseUser(raw) {
        try { return raw ? JSON.parse(raw) : {}; } catch (e) { return {}; }
    }

    // Load the session into memory. Must be awaited before the app reads the
    // token. On native, also migrates any legacy token left in localStorage by
    // a previous (pre-secure-storage) version, then wipes the plaintext copy.
    async function init() {
        if (_ready) return;

        if (isNative && ss()) {
            _token = await ssGet(TOKEN_KEY);
            _user  = parseUser(await ssGet(USER_KEY));

            if (!_token) {
                const legacyToken = localStorage.getItem(TOKEN_KEY);
                if (legacyToken) {
                    const legacyUser = localStorage.getItem(USER_KEY) || '{}';
                    await ssSet(TOKEN_KEY, legacyToken);
                    await ssSet(USER_KEY, legacyUser);
                    _token = legacyToken;
                    _user  = parseUser(legacyUser);
                }
            }
            // Always clear any plaintext copies on a secure-capable device.
            localStorage.removeItem(TOKEN_KEY);
            localStorage.removeItem(USER_KEY);
        } else {
            _token = localStorage.getItem(TOKEN_KEY);
            _user  = parseUser(localStorage.getItem(USER_KEY));
        }

        localStorage.removeItem('w_tenant_slug'); // legacy cleanup
        _ready = true;
    }

    function getToken() { return _token; }
    function getUser()  { return _user || {}; }

    async function setSession(token, user) {
        _token = token;
        _user  = user || {};
        if (isNative && (await ssSet(TOKEN_KEY, token))) {
            await ssSet(USER_KEY, JSON.stringify(_user));
        } else {
            localStorage.setItem(TOKEN_KEY, token);
            localStorage.setItem(USER_KEY, JSON.stringify(_user));
        }
    }

    async function setUser(user) {
        _user = user || {};
        if (isNative && ss()) {
            await ssSet(USER_KEY, JSON.stringify(_user));
        } else {
            localStorage.setItem(USER_KEY, JSON.stringify(_user));
        }
    }

    // Clears the in-memory copy synchronously (so callers can redirect
    // immediately) and removes the persisted copy best-effort.
    function clear() {
        _token = null;
        _user  = {};
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
        ssRemove(TOKEN_KEY);
        ssRemove(USER_KEY);
    }

    window.WStore = { init, getToken, getUser, setSession, setUser, clear, isNative: () => isNative };
})();
