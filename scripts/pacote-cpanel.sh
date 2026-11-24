#!/bin/sh
set -eu
umask 077

if [ "$#" -ne 1 ]; then
    echo "Uso: sh scripts/pacote-cpanel.sh DIRETORIO_DE_SAIDA" >&2
    exit 2
fi

mkdir -p "$1"
output=$(cd "$1" && pwd)
root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
version=$(git -C "$root" rev-parse --short HEAD)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
archive="$output/augi-cpanel-$version.zip"
activation="$output/augi-cpanel-$version-ativacao.txt"

if [ -e "$archive" ] || [ -e "$activation" ]; then
    echo "Já existe um pacote ou código para este commit no diretório de saída." >&2
    exit 1
fi

mkdir "$work/augi"
git -C "$root" archive --format=tar HEAD > "$work/source.tar"
tar -xf "$work/source.tar" -C "$work/augi"
cd "$work/augi"
test -f public/instalar.php
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev

rm -rf .github tests scripts
rm -f bootstrap/cache/*.php
code=$(php -r 'echo bin2hex(random_bytes(32));')
printf '%s' "$code" | php -r 'echo hash("sha256", stream_get_contents(STDIN)), PHP_EOL;' > storage/app/instalador.sha256
printf '%s\n' "$code" > "$work/ativacao.txt"
cd "$work"
zip -qr "$work/pacote.zip" augi
cp "$work/pacote.zip" "$archive"
cp "$work/ativacao.txt" "$activation"
printf '%s\n%s\n' "$archive" "$activation"
