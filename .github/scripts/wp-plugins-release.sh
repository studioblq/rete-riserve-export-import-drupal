#!/usr/bin/env bash
# Builds one zip per WordPress plugin whose version has no release yet,
# publishes it as release "<slug>-v<version>", then refreshes manifest.json
# on the "updates" release. The plugins read that manifest to offer updates.
set -euo pipefail

REPO="${GITHUB_REPOSITORY:?}"
SHA="${GITHUB_SHA:?}"
ROOT="wordpress-plugin"
SHARED="$ROOT/_shared/class-area051-github-updater.php"
MANIFEST_TAG="updates"
OUT="$(mktemp -d)"
MANIFEST="$OUT/manifest.json"

jq -n --arg generated "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --arg commit "$SHA" \
  '{generated: $generated, commit: $commit, plugins: {}}' > "$MANIFEST"

# Value of a plugin header line, e.g. header file.php "Version".
header() {
  tr -d '\r' < "$1" | grep -m1 -iE "^[[:space:]]*\*?[[:space:]]*$2:" \
    | sed -E 's/^[^:]*:[[:space:]]*//; s/[[:space:]]+$//' || true
}

# Changelog section "= <version> =" from readme.txt, if present.
changelog() {
  local readme="$1" version="$2"
  [ -f "$readme" ] || return 0
  tr -d '\r' < "$readme" | awk -v v="$version" '
    $0 ~ "^=[[:space:]]*" v "[[:space:]]*=" { on = 1; next }
    on && /^=[[:space:]]/ { exit }
    on { print }'
}

created=0
for dir in "$ROOT"/*/; do
  slug="$(basename "$dir")"
  main="$dir$slug.php"
  [[ "$slug" == _* ]] && continue
  [ -f "$main" ] || continue
  grep -q "Plugin Name:" "$main" || continue

  name="$(header "$main" "Plugin Name")"
  version="$(header "$main" "Version")"
  requires="$(header "$main" "Requires at least")"
  requires_php="$(header "$main" "Requires PHP")"
  if [ -z "$version" ]; then
    echo "::warning::$slug: header Version mancante, saltato"
    continue
  fi

  tag="$slug-v$version"
  notes="$(changelog "$dir/readme.txt" "$version")"
  [ -n "$notes" ] || notes="Versione $version"

  if gh release view "$tag" --repo "$REPO" >/dev/null 2>&1; then
    echo "$slug $version: release già presente"
  else
    # Always ship the canonical updater, even if the committed copy is stale.
    mkdir -p "$dir/lib"
    cp "$SHARED" "$dir/lib/class-area051-github-updater.php"
    (cd "$ROOT" && zip -qr "$OUT/$slug.zip" "$slug" \
      -x "*.zip" "*/debug.log" "*/.DS_Store" "*/.git*" "*/node_modules/*")
    gh release create "$tag" "$OUT/$slug.zip" \
      --repo "$REPO" --target "$SHA" --latest=false \
      --title "$name $version" --notes "$notes"
    echo "$slug $version: release creata"
    created=$((created + 1))
  fi

  released="$(gh release view "$tag" --repo "$REPO" --json publishedAt -q .publishedAt)"
  jq --arg slug "$slug" --arg name "$name" --arg version "$version" \
     --arg package "https://github.com/$REPO/releases/download/$tag/$slug.zip" \
     --arg url "https://github.com/$REPO/tree/main/$ROOT/$slug" \
     --arg requires "$requires" --arg requires_php "$requires_php" \
     --arg changelog "$notes" --arg released "$released" \
     '.plugins[$slug] = {name: $name, version: $version, package: $package, url: $url,
       requires: $requires, requires_php: $requires_php, changelog: $changelog, released: $released}' \
     "$MANIFEST" > "$MANIFEST.tmp" && mv "$MANIFEST.tmp" "$MANIFEST"
done

if ! gh release view "$MANIFEST_TAG" --repo "$REPO" >/dev/null 2>&1; then
  gh release create "$MANIFEST_TAG" --repo "$REPO" --target "$SHA" --latest=false \
    --title "Manifest aggiornamenti plugin WordPress" \
    --notes "Contiene manifest.json letto dai plugin per gli aggiornamenti automatici. Non eliminare."
fi
gh release upload "$MANIFEST_TAG" "$MANIFEST" --repo "$REPO" --clobber

echo "Release create: $created"
jq '.plugins | map_values(.version)' "$MANIFEST"
