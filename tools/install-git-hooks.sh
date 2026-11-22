#!/usr/bin/env sh
# Installs the local git hook that rebuilds the plugin zips after each commit.
# Hooks live in .git/hooks and are never pushed: run this once per computer.
set -e
ROOT="$(git rev-parse --show-toplevel)"
HOOK="$ROOT/.git/hooks/post-commit"

cat > "$HOOK" <<'EOF'
#!/usr/bin/env sh
# Rebuild WordPress plugin zips whose version changed (tools/build-wp-zips.py).
ROOT="$(git rev-parse --show-toplevel)"
PY="$(command -v python || command -v python3 || command -v py)"
[ -n "$PY" ] && "$PY" "$ROOT/tools/build-wp-zips.py" || true
EOF

chmod +x "$HOOK"
echo "Hook installato: $HOOK"
