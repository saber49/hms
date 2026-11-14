<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Http;

/**
 * Finds out how to connect a mailbox from the e-mail address alone —
 * the same strategy Thunderbird uses, so users never type server names:
 *
 *   1. OAuth providers (Google / Microsoft) by domain or MX record
 *   2. Mozilla ISPDB (autoconfig.thunderbird.net) for the domain
 *   3. The domain's own autoconfig XML
 *   4. ISPDB for the MX host's base domain (custom domains on IONOS etc.)
 *   5. Known MX → server map, then imap./mail. guesses (port probe)
 *
 * Every outbound host is checked against private/reserved ranges first —
 * the domain is user input, so this endpoint must not become an SSRF probe.
 */
class MailDiscovery
{
    private const GOOGLE_DOMAINS = ['gmail.com', 'googlemail.com'];
    private const MICROSOFT_DOMAINS = [
        'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de',
        'live.com', 'live.de', 'msn.com',
    ];

    private const GOOGLE_SERVERS = [
        'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'encryption' => 'ssl'],
        'smtp' => ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl'],
    ];

    /** MX suffix → servers, for hosters whose custom domains ISPDB misses. */
    private const MX_SERVERS = [
        'kundenserver.de' => ['imap.ionos.de', 'smtp.ionos.de'],
        'ionos.de'        => ['imap.ionos.de', 'smtp.ionos.de'],
        'ionos.com'       => ['imap.ionos.com', 'smtp.ionos.com'],
        'strato.de'       => ['imap.strato.de', 'smtp.strato.de'],
        'rzone.de'        => ['imap.strato.de', 'smtp.strato.de'],
        'hosteurope.de'   => ['imap.hosteurope.de', 'smtp.hosteurope.de'],
        'gmx.net'         => ['imap.gmx.net', 'mail.gmx.net'],
        'web.de'          => ['imap.web.de', 'smtp.web.de'],
        't-online.de'     => ['secureimap.t-online.de', 'securesmtp.t-online.de'],
    ];

    private const HINTS = [
        'gmx'     => 'Bei GMX/WEB.DE muss zuerst in den Postfach-Einstellungen „POP3/IMAP-Abruf“ aktiviert werden.',
        'web.de'  => 'Bei GMX/WEB.DE muss zuerst in den Postfach-Einstellungen „POP3/IMAP-Abruf“ aktiviert werden.',
        'yahoo'   => 'Yahoo verlangt ein App-Passwort (Kontosicherheit → App-Passwort erstellen).',
        'icloud'  => 'iCloud verlangt ein app-spezifisches Passwort (appleid.apple.com → Anmeldung und Sicherheit).',
        'me.com'  => 'iCloud verlangt ein app-spezifisches Passwort (appleid.apple.com → Anmeldung und Sicherheit).',
        'gmail'   => 'Gmail verlangt ein App-Passwort (Google-Konto → Sicherheit → App-Passwörter, 2-Faktor nötig).',
        't-online'=> 'Bei T-Online wird das separate „E-Mail-Passwort“ aus dem Kundencenter benötigt.',
    ];

    public function discover(string $email): array
    {
        $email = strtolower(trim($email));
        $domain = substr(strrchr($email, '@') ?: '', 1);
        $mx = $this->mxHosts($domain);
        $provider = $this->oauthProvider($domain, $mx);

        $out = [
            'email'    => $email,
            'provider' => $provider ?? 'imap',
            'oauth'    => $provider !== null && MailOAuth::configured($provider),
            'imap'     => null,
            'smtp'     => null,
            'hint'     => null,
        ];

        if ($provider === 'microsoft') {
            return $out;
        }
        if ($provider === 'google') {
            $servers = self::GOOGLE_SERVERS;
            $out['hint'] = self::HINTS['gmail'];
        } else {
            $servers = $this->imapServers($email, $domain, $mx);
            $out['hint'] = $this->hintFor($domain, $mx);
        }
        if ($servers) {
            $out['imap'] = $servers['imap'] + ['username' => $servers['imap']['username'] ?? $email];
            $out['smtp'] = $servers['smtp'] + ['username' => $servers['smtp']['username'] ?? $email];
        }
        return $out;
    }

    public static function hostAllowed(?string $host): bool
    {
        if (!$host || !preg_match('/^(?=.{1,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/i', $host)) return false;
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips) return false;
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    private function mxHosts(string $domain): array
    {
        if ($domain === '') return [];
        $records = @dns_get_record($domain, DNS_MX) ?: [];
        usort($records, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
        return array_values(array_filter(array_map(fn ($r) => strtolower(rtrim($r['target'] ?? '', '.')), $records)));
    }

    private function oauthProvider(string $domain, array $mx): ?string
    {
        if (in_array($domain, self::GOOGLE_DOMAINS, true)) return 'google';
        if (in_array($domain, self::MICROSOFT_DOMAINS, true)) return 'microsoft';
        foreach ($mx as $h) {
            if (str_ends_with($h, '.google.com') || str_ends_with($h, '.googlemail.com')) return 'google';
            if (str_ends_with($h, '.protection.outlook.com') || str_ends_with($h, '.outlook.com')) return 'microsoft';
        }
        return null;
    }

    private function imapServers(string $email, string $domain, array $mx): ?array
    {
        $candidates = ['https://autoconfig.thunderbird.net/v1.1/' . rawurlencode($domain)];
        if (self::hostAllowed('autoconfig.' . $domain)) {
            $candidates[] = 'https://autoconfig.' . $domain . '/mail/config-v1.1.xml?emailaddress=' . rawurlencode($email);
        }
        if (self::hostAllowed($domain)) {
            $candidates[] = 'https://' . $domain . '/.well-known/autoconfig/mail/config-v1.1.xml';
        }
        $mxBase = $mx ? $this->baseDomain($mx[0]) : null;
        if ($mxBase && $mxBase !== $domain) {
            $candidates[] = 'https://autoconfig.thunderbird.net/v1.1/' . rawurlencode($mxBase);
        }

        foreach ($candidates as $url) {
            $servers = $this->fetchAutoconfig($url, $email);
            if ($servers) return $servers;
        }

        foreach ($mx as $h) {
            foreach (self::MX_SERVERS as $suffix => $pair) {
                if ($h === $suffix || str_ends_with($h, '.' . $suffix)) {
                    return [
                        'imap' => ['host' => $pair[0], 'port' => 993, 'encryption' => 'ssl'],
                        'smtp' => ['host' => $pair[1], 'port' => 465, 'encryption' => 'ssl'],
                    ];
                }
            }
        }

        return $this->guess($domain);
    }

    private function fetchAutoconfig(string $url, string $email): ?array
    {
        try {
            $res = Http::timeout(4)->connectTimeout(3)->withOptions(['allow_redirects' => false])->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (!$res->successful() || !str_contains($res->body(), 'emailProvider')) return null;

        $xml = @simplexml_load_string($res->body(), 'SimpleXMLElement', LIBXML_NONET);
        if (!$xml || !isset($xml->emailProvider)) return null;

        $pick = function ($nodes, string $type) use ($email) {
            $best = null;
            foreach ($nodes as $s) {
                if ((string) $s['type'] !== $type) continue;
                $c = $this->serverFrom($s, $email);
                if ($c && (!$best || ($c['encryption'] === 'ssl' && $best['encryption'] !== 'ssl'))) $best = $c;
            }
            return $best;
        };
        $imap = $pick($xml->emailProvider->incomingServer, 'imap');
        $smtp = $pick($xml->emailProvider->outgoingServer, 'smtp');
        return ($imap && $smtp) ? ['imap' => $imap, 'smtp' => $smtp] : null;
    }

    private function serverFrom(\SimpleXMLElement $s, string $email): ?array
    {
        $enc = match (strtoupper((string) $s->socketType)) {
            'SSL'      => 'ssl',
            'STARTTLS' => 'starttls',
            default    => null,
        };
        $host = strtolower(str_replace('%EMAILDOMAIN%', substr(strrchr($email, '@'), 1), (string) $s->hostname));
        $port = (int) $s->port;
        if (!$enc || !$port || !self::hostAllowed($host)) return null;
        $user = str_replace(
            ['%EMAILADDRESS%', '%EMAILLOCALPART%', '%EMAILDOMAIN%'],
            [$email, strstr($email, '@', true), substr(strrchr($email, '@'), 1)],
            (string) $s->username ?: '%EMAILADDRESS%'
        );
        return ['host' => $host, 'port' => $port, 'encryption' => $enc, 'username' => $user];
    }

    private function guess(string $domain): ?array
    {
        foreach (['imap.', 'mail.'] as $p) {
            $imap = $p . $domain;
            if (!self::hostAllowed($imap) || !$this->portOpen($imap, 993)) continue;
            foreach (['smtp.' . $domain, 'mail.' . $domain] as $smtp) {
                if (!self::hostAllowed($smtp)) continue;
                if ($this->portOpen($smtp, 465)) {
                    return ['imap' => ['host' => $imap, 'port' => 993, 'encryption' => 'ssl'],
                            'smtp' => ['host' => $smtp, 'port' => 465, 'encryption' => 'ssl']];
                }
                if ($this->portOpen($smtp, 587)) {
                    return ['imap' => ['host' => $imap, 'port' => 993, 'encryption' => 'ssl'],
                            'smtp' => ['host' => $smtp, 'port' => 587, 'encryption' => 'starttls']];
                }
            }
        }
        return null;
    }

    private function portOpen(string $host, int $port): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 3);
        if (!$fp) return false;
        fclose($fp);
        return true;
    }

    private function baseDomain(string $host): string
    {
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) return $host;
        $take = in_array($parts[$n - 2], ['co', 'com', 'net', 'org', 'ac', 'gv'], true) ? 3 : 2;
        return implode('.', array_slice($parts, -$take));
    }

    private function hintFor(string $domain, array $mx): ?string
    {
        $hay = $domain . ' ' . implode(' ', $mx);
        foreach (self::HINTS as $needle => $hint) {
            if (str_contains($hay, $needle)) return $hint;
        }
        return null;
    }
}
