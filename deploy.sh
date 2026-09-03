#!/usr/bin/env bash
#
# Telepítés a nethely tárhelyre. SSH-ról futtatandó, a projekt gyökeréből:
#
#   cd ~/szamlafolyo && ./deploy.sh
#
# Figyelem: ezen a tárhelyen az SSH alapértelmezett PHP-je 7.4, a webcímé
# viszont 8.4. A csupasz `php` tehát rossz verziót indítana, ezért a szkript
# maga keresi meg a megfelelőt. Ha tudod a pontos elérési utat, add meg:
#
#   PHP_BIN=/az/eleresi/ut/php ./deploy.sh
#
# Előfeltétel egyszer, a legelső telepítéskor:
#   - a webcím gyökérkönyvtára (docroot) a projekt `public/` mappájára mutasson
#   - a `.env` fel legyen töltve (a .env.example alapján)

set -euo pipefail

MIN_PHP="8.2"

# --- A megfelelő PHP megkeresése ---------------------------------------------
#
# Végigpróbáljuk a szolgáltatóknál szokásos neveket és útvonalakat, és az elsőt
# választjuk, ami eléri a minimumot. Ami ennél fontosabb: ha egyik sem jó, a
# szkript megáll. A 7.4-en némán elinduló telepítés a rosszabb kimenetel — ott
# a composer régi csomagverziókat old fel, az artisan fatalt dob, a cron pedig
# csendben nem csinál semmit.

php_verzio() {
    local v
    v="$("$1" -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    echo "${v:-nem futtatható}"
}

megfelelo() {
    local jelolt="$1" verzio
    [ -x "$jelolt" ] || command -v "$jelolt" >/dev/null 2>&1 || return 1
    verzio="$(php_verzio "$jelolt")"
    [ "$verzio" != "nem futtatható" ] || return 1
    [ "$(printf '%s\n%s\n' "$MIN_PHP" "$verzio" | sort -V | head -1)" = "$MIN_PHP" ]
}

php_kereses() {
    local jelolt

    # Nevek a PATH-on, a legújabbtól visszafelé.
    for jelolt in php8.4 php84 ea-php84 lsphp84 \
                  php8.3 php83 ea-php83 lsphp83 \
                  php8.2 php82 ea-php82 lsphp82; do
        if command -v "$jelolt" >/dev/null 2>&1 && megfelelo "$jelolt"; then
            command -v "$jelolt"
            return 0
        fi
    done

    # Szolgáltatónként eltérő telepítési helyek.
    for jelolt in /opt/php*/bin/php \
                  /opt/alt-php*/usr/bin/php \
                  /opt/cloudlinux/alt-php*/root/usr/bin/php \
                  /usr/local/php*/bin/php \
                  /usr/local/bin/php8* \
                  /usr/bin/php8*; do
        if [ -x "$jelolt" ] && megfelelo "$jelolt"; then
            echo "$jelolt"
            return 0
        fi
    done

    # Végül a csupasz php — ha netán már eleve jó verzióra mutat.
    if megfelelo php; then
        command -v php
        return 0
    fi

    return 1
}

if [ -n "${PHP_BIN:-}" ]; then
    if ! megfelelo "$PHP_BIN"; then
        echo "✗ A megadott PHP_BIN ($PHP_BIN) nem használható: PHP ${MIN_PHP}+ kell," >&2
        echo "  ez pedig: $(php_verzio "$PHP_BIN")." >&2
        exit 1
    fi
    PHP="$PHP_BIN"
else
    if ! PHP="$(php_kereses)"; then
        echo "✗ Nem találtam PHP ${MIN_PHP}+ értelmezőt." >&2
        echo "  Az SSH alapértelmezett php-ja: $(php_verzio php)" >&2
        echo "  Keresd meg a helyeset, majd add meg:  PHP_BIN=/eleresi/ut/php ./deploy.sh" >&2
        echo "  Segít ez a parancs:" >&2
        echo "    ls /usr/bin/php* /usr/local/bin/php* /opt/php*/bin/php 2>/dev/null" >&2
        exit 1
    fi
fi

echo "→ PHP: $PHP ($(php_verzio "$PHP"))"

# Csak megnézni, melyik PHP-t választaná — telepítés nélkül.
if [ "${1:-}" = "--check" ]; then
    echo "  (--check: a telepítés nem indul el)"
    exit 0
fi

# A composert is ezzel kell hívni: csupaszon a 7.4-et használná, és a Laravel
# post-autoload-dump szkriptje (artisan package:discover) ott elszállna.
COMPOSER_BIN="$(command -v composer || true)"
if [ -z "$COMPOSER_BIN" ]; then
    echo "✗ Nincs composer a PATH-on." >&2
    exit 1
fi

echo "→ Kód frissítése"
git pull --ff-only

echo "→ Függőségek (fejlesztői csomagok nélkül)"
"$PHP" "$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "→ Környezet ellenőrzése"
"$PHP" artisan kornyezet:ellenoriz

# Innentől az oldal karbantartási módban van. Ha a szkript bármi miatt megáll
# — hiba, Ctrl+C, megszakadt kapcsolat —, az oldal magától visszakapcsol.
# Enélkül egy félbemaradt telepítés 503-at szolgálna ki, amíg valaki észre nem
# veszi és kézzel fel nem hozza.
KARBANTARTAS=0
karbantartas_vege() {
    if [ "$KARBANTARTAS" = "1" ]; then
        echo "→ Karbantartási mód kikapcsolása"
        "$PHP" artisan up >/dev/null 2>&1 || true
    fi
}
trap karbantartas_vege EXIT INT TERM

echo "→ Karbantartási mód"
"$PHP" artisan down --render="errors::503" || true
KARBANTARTAS=1

echo "→ Adatbázis"
"$PHP" artisan migrate --force

echo "→ Gyorsítótárak"
# A gyorsítótárak abszolút útvonalakat sütnek be, ezért költöztetés után a régi
# tartalom rejtélyes hibákat okozna. Előbb takarítunk, aztán építünk.
"$PHP" artisan optimize:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache

"$PHP" artisan up
KARBANTARTAS=0

PROJEKT="$(pwd)"

cat <<CRON

✓ Kész.

Az időzített feladatokhoz ezt a három sort másold be a nethely „Időzített
folyamatok" felületére. Az elérési út a most felismert PHP-é — csupasz `php`
nem jó, mert az a 7.4-re mutat:

*/5 * * * * cd $PROJEKT && $PHP artisan email:beolvas
*/5 * * * * cd $PROJEKT && $PHP artisan dokumentum:feldolgoz --limit=5
17 3 * * * cd $PROJEKT && $PHP artisan fajl:selejtez
CRON
