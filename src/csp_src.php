<?php
/**
 * LetaDial — Content Security Policy (CSP)
 * Krok 2 planu naprawy CSP unsafe-inline — patrz PLAN_NAPRAWY_CSP.md
 * Krok 6 (ta wersja): dodano sendEnforcingHeader() — prawdziwy, WYMUSZAJĄCY
 * nagłówek Content-Security-Policy, generowany PHP-em zamiast nginx.
 *
 * Faza obecna: Report-Only + Enforcing, RÓWNOLEGLE. Report-Only nic nie
 * blokuje, tylko raportuje (patrz api/csp_report_api.php) — zostaje wysyłany
 * jako canary, decyzja czy zdjąć go na stałe to osobny, wciąż otwarty
 * Krok 7 (patrz plan, sekcja "Otwarte pytania" #2 - ta zmiana go NIE
 * rozstrzyga). Enforcing NAPRAWDĘ blokuje: każdy inline `<script>` bez
 * pasującego nonce, każdy `<style>` bez pasującego nonce (przez
 * style-src-elem), każdy `onXXX="..."` jako atrybut HTML, każdy
 * `eval()`/`new Function()` - wszystko to, czego Krok 1 inwentaryzacja i
 * Kroki 3-5 miały się pozbyć albo oznaczyć nonce'm. CELOWO nie blokuje
 * atrybutu `style="..."` (np. `style="display:none"`) - patrz komentarz
 * przy policy() niżej po angielsku, sekcja "BUGFIX", po co ten wyjątek
 * i dlaczego jest bezpieczny.
 *
 * WAŻNE — nginx (obie maszyny, LetaDial.LetaLab.eu i dashboard.andrzejl.eu):
 * stara linia `add_header Content-Security-Policy "... 'unsafe-inline' ...";`
 * MUSI zostać usunięta/zakomentowana w configu nginx, ZANIM (albo w tym samym
 * kroku co) ten kod trafi na serwer. Gdyby oba nagłówki (nginx + ten, PHP)
 * były wysłane jednocześnie na tej samej odpowiedzi, przeglądarka nie "łączy
 * się permisywnie" — każda dyrektywa jest przecięciem (intersection) wszystkich
 * dostarczonych polityk, więc efektywna polityka i tak byłaby tą OSTRZEJSZĄ
 * (czyli w praktyce tą z tego pliku, bez unsafe-inline) — ale to oznacza też,
 * że SAMO przywrócenie starej linii nginx PRZY WCIĄŻ AKTYWNYM tym kodzie NIE
 * jest pełnym rollbackiem (patrz CSP_ENFORCE niżej i PLAN_NAPRAWY_CSP.md,
 * Krok 6, sekcja deploymentu/rollbacku).
 *
 * Awaryjny wyłącznik (rollback bez gita, bez restartu nginx/php-fpm):
 *   Dodanie w config.php (NIE w tym pliku, NIGDY w repo):
 *       define('CSP_ENFORCE', false);
 *   wyłącza WYŁĄCZNIE sendEnforcingHeader() (Report-Only leci dalej bez zmian)
 *   od następnego żądania — jeden zapis pliku, zero przeładowania usług.
 *   Brak tej stałej w config.php = enforcing WŁĄCZONY (domyślnie bezpieczne
 *   "działa od razu po wdrożeniu tych plików", zgodnie z opisem Kroku 6).
 *
 * Nonce:
 *   - 16 losowych bajtów (128 bit) → 32 znaki hex, jeden na HTTP request.
 *   - Cache'owany w statycznej właściwości klasy WYŁĄCZNIE na czas
 *     pojedynczego requestu — PHP resetuje cały stan klas między
 *     requestami w tym stacku (mod_php/php-fpm, brak persistent workerów
 *     typu Swoole/RoadRunner), więc nie ma ryzyka wycieku nonce'u między
 *     różnymi żądaniami czy użytkownikami.
 *   - self::nonce() musi zostać wywołane przed jakimkolwiek outputem HTML
 *     (patrz sendReportOnlyHeader()/sendEnforcingHeader() poniżej) — TEN SAM
 *     nonce trafia do OBU nagłówków HTTP i do atrybutu nonce="..." w
 *     znacznikach <script>/<style> (Kroki 3 i 5b) — muszą być identyczne we
 *     wszystkich miejscach, inaczej przeglądarka odrzuci dany inline blok.
 *     Gwarantowane przez policy() niżej: obie metody sendXxxHeader() wołają
 *     TĘ SAMĄ policy(), więc nie da się, żeby dyrektywy (albo nonce) w obu
 *     nagłówkach kiedykolwiek się rozjechały.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class CSP
{
    private static ?string $nonce = null;

    /**
     * Nonce dla bieżącego requestu. Generowany raz, przy pierwszym
     * wywołaniu, i cache'owany w pamięci na czas tego samego requestu —
     * każde kolejne wywołanie w tym samym requeście (oba nagłówki, każdy
     * <script>/<style> na stronie) dostaje TĘ SAMĄ wartość.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = bin2hex(random_bytes(16));
        }
        return self::$nonce;
    }

    /**
     * Treść polityki (bez nazwy nagłówka) dla bieżącego nonce'u requestu.
     * Krok 6: wydzielone z sendReportOnlyHeader() do wspólnej metody, żeby
     * sendReportOnlyHeader() i sendEnforcingHeader() fizycznie NIE MOGŁY
     * rozjechać się dyrektywami — jedna zmiana tutaj, zawsze widoczna w obu
     * nagłówkach jednocześnie.
     *
     * ---------------------------------------------------------------------
     * BUGFIX (found during pre-deploy test pass, before Krok 6 went live):
     * style-src must NOT carry the nonce alone, or every inline style="..."
     * attribute in the app breaks.
     *
     * Root cause: a nonce-source only authorizes an ELEMENT that carries a
     * matching nonce="..." attribute (i.e. our own <style nonce="..."> tags
     * from Krok 5b). It can never authorize an inline style="" ATTRIBUTE on
     * an arbitrary element (there is no way to put a nonce "on" an
     * attribute value). Per the CSP Level 3 algorithm, once a source list
     * contains ANY nonce-source, 'unsafe-inline' in that SAME list is
     * ignored for every browser that understands nonces (i.e. effectively
     * all of them) - so simply appending 'unsafe-inline' next to the nonce
     * in one shared style-src line would NOT fix this; it has to live in a
     * separate directive that has no nonce in it. Confirmed against the
     * spec algorithm and against MDN's style-src / style-src-elem docs;
     * see PLAN_NAPRAWY_CSP.md, "Sesja 11" for the full derivation and the
     * reproduction script.
     *
     * Fix: split into style-src-elem (governs <style> elements and
     * <link rel=stylesheet>, keeps the strict nonce-only policy) and a
     * general style-src (used as the fallback for style-src-attr, which we
     * do not set explicitly - so it governs the style="" attribute case,
     * with 'unsafe-inline' and NO nonce in that particular list, so it is
     * not negated). Net effect: injected/unnonced <style> blocks are still
     * blocked exactly as before; script-src is completely untouched (still
     * nonce-only, still the main XSS protection); only the style ATTRIBUTE
     * case changes, from "silently broken" to "allowed", which is what the
     * whole rest of the codebase already assumes (238 style="..." uses in
     * pages/*.php plus dynamically-built ones in assets/js/app.js).
     *
     * Browser support note: style-src-elem/style-src-attr only reached
     * Baseline (i.e. broadly interoperable across current browser
     * versions) in December 2025. Very old browsers that predate this will
     * simply not look for style-src-elem at all and will fall back to the
     * single general style-src line for BOTH elements and attributes - in
     * that one legacy case only, an injected <style> block would also be
     * allowed via 'unsafe-inline' (style protection degrades gracefully to
     * pre-CSP-plan behaviour). script-src protection is never affected by
     * any of this, on any browser, old or new.
     * ---------------------------------------------------------------------
     *
     * SEC-106 (11.08.2026): added object-src 'none', base-uri 'self',
     * form-action 'self', frame-ancestors 'self'. None of these four are
     * covered by default-src — each has its own spec-defined fallback that
     * is effectively unrestricted when the directive is absent entirely, it
     * is NOT inherited from default-src the way script-src/style-src/img-src
     * are. Without them explicitly set, an attacker who got even one
     * injection point past every other layer could still: load a legacy
     * <object>/<embed> plugin (object-src), splice in a <base href="..."> to
     * silently rewrite every relative URL on the page including this app's
     * own nonce'd <script src="/assets/js/app.js"> (base-uri), redirect a
     * legitimate <form> submission to an attacker origin (form-action), or
     * have the whole app framed by a hostile site for clickjacking
     * (frame-ancestors — the modern, CSP-native equivalent of the
     * X-Frame-Options: SAMEORIGIN header nginx already sends; kept
     * alongside it rather than instead of it, since header support is not
     * perfectly identical across older clients). Verified via a full-project
     * grep before adding these: no <object>/<embed>/<applet> anywhere, no
     * <form action="..."> pointing off-origin, no legitimate <iframe> use —
     * all four are pure hardening with no behaviour change for this app.
     */
    private static function policy(): string
    {
        $n = self::nonce();

        return
            "default-src 'self'; " .
            "script-src 'self' 'nonce-{$n}'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "style-src-elem 'self' 'nonce-{$n}'; " .
            "style-src-attr 'unsafe-inline'; " .
            "img-src 'self' https: data: blob:; " .
            "object-src 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'; " .
            "frame-ancestors 'self'; " .
            "report-uri /api/csp-report";
    }

    /**
     * Wysyła nagłówek Content-Security-Policy-Report-Only dla bieżącego
     * requestu. Wywołać raz, na starcie routera (index.php), przed
     * jakimkolwiek outputem — analogicznie do pre-warmu CSRF::token()
     * w login_page.php / forgot_password_page.php / reset_password_page.php / setup_account_page.php.
     * Z definicji CSP: ten nagłówek NIC nie blokuje, tylko raportuje.
     *
     * headers_sent() guard: w normalnym przepływie (wywołanie zaraz po
     * require_once w index.php, przed jakimkolwiek echo/HTML) nigdy nie
     * powinno być true — zabezpieczenie czysto defensywne, żeby ewentualny
     * błąd w kolejności wywołań w przyszłości dał cichy no-op zamiast
     * ostrzeżenia PHP "headers already sent" wylatującego na stronę.
     */
    public static function sendReportOnlyHeader(): void
    {
        if (headers_sent()) {
            return;
        }

        header("Content-Security-Policy-Report-Only: " . self::policy());
    }

    /**
     * Krok 6 — nagłówek WYMUSZAJĄCY. Ta sama treść polityki co
     * sendReportOnlyHeader() (patrz policy() wyżej — jedno źródło prawdy),
     * pod prawdziwą nazwą nagłówka. Wywołać RAZEM z sendReportOnlyHeader(),
     * w tym samym miejscu w index.php, przed jakimkolwiek outputem.
     *
     * Wyłącznik awaryjny: jeśli w config.php zdefiniowane
     * `CSP_ENFORCE === false`, ta metoda jest no-opem (Report-Only leci
     * dalej normalnie, sendReportOnlyHeader() jest osobnym wywołaniem i nie
     * jest tym w żaden sposób dotknięte). Domyślnie (stała niezdefiniowana)
     * — enforcing WŁĄCZONY. Patrz też komentarz na górze pliku (sekcja
     * nginx) — usunięcie starej linii `add_header Content-Security-Policy`
     * w configu nginx obu serwerów jest częścią tego samego kroku wdrożenia,
     * nie tylko wgranie tego pliku.
     */
    public static function sendEnforcingHeader(): void
    {
        if (headers_sent()) {
            return;
        }
        if (defined('CSP_ENFORCE') && CSP_ENFORCE === false) {
            return;
        }

        header("Content-Security-Policy: " . self::policy());
    }
}
