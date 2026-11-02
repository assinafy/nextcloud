#!/bin/sh
# Builds build/assinafy-<version>.tar.gz from the committed HEAD with one top-level assinafy/ directory.
# When the App Store key and certificate exist, the code is signed (appinfo/signature.json) inside a
# throwaway Nextcloud container and the archive signature for the release upload is written to <archive>.sig.
set -eu

app=assinafy
version="$(git show HEAD:appinfo/info.xml | sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p')"
if [ -z "$version" ]; then
	echo "appinfo/info.xml has no version" >&2
	exit 1
fi
key="${ASSINAFY_SIGNING_KEY:-$HOME/.nextcloud/certificates/$app.key}"
certificate="${ASSINAFY_SIGNING_CERTIFICATE:-$HOME/.nextcloud/certificates/$app.crt}"
archive="build/$app-$version.tar.gz"

mkdir -p build
rm -f "$archive" "$archive.sig"
git archive --format=tar --prefix="$app/" --output="build/$app-$version.tar" HEAD

if [ ! -f "$key" ] || [ ! -f "$certificate" ]; then
	gzip -f "build/$app-$version.tar"
	echo "$archive"
	echo "UNSIGNED: $certificate or $key is missing; the App Store rejects unsigned releases." >&2
	exit 0
fi

docker run --rm \
	-v "$PWD/build:/build" \
	-v "$key:/keys/app.key:ro" \
	-v "$certificate:/keys/app.crt:ro" \
	-e APP="$app" -e VERSION="$version" \
	--entrypoint sh nextcloud:31-apache -c '
		set -eu
		mkdir -p /tmp/keys /tmp/stage
		install -o www-data -m 600 /keys/app.key /tmp/keys/app.key
		install -o www-data -m 644 /keys/app.crt /tmp/keys/app.crt
		tar -xf "/build/$APP-$VERSION.tar" -C /tmp/stage
		chown -R www-data /usr/src/nextcloud /tmp/stage
		su -s /bin/sh www-data -c "php /usr/src/nextcloud/occ integrity:sign-app --path=/tmp/stage/$APP --privateKey=/tmp/keys/app.key --certificate=/tmp/keys/app.crt"
		tar -C /tmp/stage --owner=0 --group=0 --numeric-owner -czf "/build/$APP-$VERSION.tar.gz" "$APP"
	'
rm -f "build/$app-$version.tar"
openssl dgst -sha512 -sign "$key" "$archive" | openssl base64 > "$archive.sig"
echo "$archive"
echo "Release signature: $archive.sig"
