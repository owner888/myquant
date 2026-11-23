#!/usr/bin/env bash
# Cross-platform native C library builder for MyQuant (`libquant_sweep`).
# Places compiled artifacts directly into `resources/<os>-<arch>/` where
# MyPHP's `Resources` bundler and `FfiGen::resolveLibrary()` load them.
#
# Usage:
#   ./scripts/build_native.sh                 # builds for current host (and universal darwin arm64+amd64 on macOS)
#   ./scripts/build_native.sh linux-amd64     # cross-compiles via `zig cc` or target gcc when available
#   ./scripts/build_native.sh windows-amd64   # cross-compiles `quant_sweep.dll` via `zig cc` or MinGW
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/native/quant_sweep.c"

build_target() {
    local target="$1"
    local out_dir="$ROOT/resources/$target"
    mkdir -p "$out_dir"

    case "$target" in
        darwin-arm64)
            clang -O3 -ffast-math -shared -fPIC -arch arm64 "$SRC" -lm -o "$out_dir/libquant_sweep.dylib"
            echo "built $out_dir/libquant_sweep.dylib"
            ;;
        darwin-amd64)
            clang -O3 -ffast-math -shared -fPIC -arch x86_64 "$SRC" -lm -o "$out_dir/libquant_sweep.dylib"
            echo "built $out_dir/libquant_sweep.dylib"
            ;;
        linux-amd64)
            if command -v zig >/dev/null 2>&1; then
                zig cc -target x86_64-linux-gnu -O3 -shared -fPIC "$SRC" -lm -o "$out_dir/libquant_sweep.so"
            else
                ${CC:-cc} -O3 -shared -fPIC "$SRC" -lm -o "$out_dir/libquant_sweep.so"
            fi
            echo "built $out_dir/libquant_sweep.so"
            ;;
        linux-arm64)
            if command -v zig >/dev/null 2>&1; then
                zig cc -target aarch64-linux-gnu -O3 -shared -fPIC "$SRC" -lm -o "$out_dir/libquant_sweep.so"
            else
                ${CC:-cc} -O3 -shared -fPIC "$SRC" -lm -o "$out_dir/libquant_sweep.so"
            fi
            echo "built $out_dir/libquant_sweep.so"
            ;;
        windows-amd64)
            if command -v zig >/dev/null 2>&1; then
                zig cc -target x86_64-windows-gnu -O3 -shared "$SRC" -o "$out_dir/quant_sweep.dll"
            elif command -v x86_64-w64-mingw32-gcc >/dev/null 2>&1; then
                x86_64-w64-mingw32-gcc -O3 -shared "$SRC" -o "$out_dir/quant_sweep.dll"
            else
                cl /O2 /LD "$SRC" /Fe:"$out_dir/quant_sweep.dll"
            fi
            echo "built $out_dir/quant_sweep.dll"
            ;;
        *)
            echo "Unsupported target: $target" >&2
            exit 1
            ;;
    esac
}

if [[ $# -gt 0 ]]; then
    for t in "$@"; do build_target "$t"; done
    exit 0
fi

UNAME_S="$(uname -s)"
UNAME_M="$(uname -m)"
case "$UNAME_S" in
    Darwin)
        build_target darwin-arm64
        build_target darwin-amd64
        ;;
    Linux)
        [[ "$UNAME_M" == "aarch64" || "$UNAME_M" == "arm64" ]] && build_target linux-arm64 || build_target linux-amd64
        ;;
    MINGW*|MSYS*|CYGWIN*)
        build_target windows-amd64
        ;;
    *)
        echo "Unknown host OS: $UNAME_S" >&2
        exit 1
        ;;
esac
