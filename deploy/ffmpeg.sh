#!/usr/bin/env bash
# Install a static ffmpeg + ffprobe build for Laravel Cloud.
#
# Wired in via Laravel Cloud → Settings → Build Commands. Runs every deploy
# in the builder container; the resulting files persist at /var/www/html/bin/ffmpeg/
# in the runtime container.
#
# Path at runtime:
#   /var/www/html/bin/ffmpeg/ffmpeg
#   /var/www/html/bin/ffmpeg/ffprobe
#
# Idempotent: skips download if the binary is already present (e.g. when
# Cloud reuses a cached builder layer).
set -euo pipefail

INSTALL_DIR="${HOME}/bin/ffmpeg"
FFMPEG_BIN="${INSTALL_DIR}/ffmpeg"
ARCH="$(uname -m)"

case "${ARCH}" in
    aarch64|arm64) RELEASE_URL="https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-arm64-static.tar.xz" ;;
    x86_64|amd64)  RELEASE_URL="https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz" ;;
    *) echo "[ffmpeg.sh] Unsupported arch: ${ARCH}" >&2; exit 1 ;;
esac

if [[ -x "${FFMPEG_BIN}" ]]; then
    echo "[ffmpeg.sh] ffmpeg already installed at ${FFMPEG_BIN} — skipping download"
    "${FFMPEG_BIN}" -version | head -1
    exit 0
fi

echo "[ffmpeg.sh] Installing static ffmpeg build for ${ARCH} into ${INSTALL_DIR}"
mkdir -p "${INSTALL_DIR}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

curl --silent --show-error --fail --location \
    --output "${TMP_DIR}/ffmpeg.tar.xz" \
    "${RELEASE_URL}"

tar -xf "${TMP_DIR}/ffmpeg.tar.xz" -C "${TMP_DIR}"

# Tarball extracts to ffmpeg-<version>-<arch>-static/
SRC_DIR="$(find "${TMP_DIR}" -maxdepth 1 -type d -name 'ffmpeg-*-static' | head -1)"
if [[ -z "${SRC_DIR}" ]]; then
    echo "[ffmpeg.sh] Could not locate extracted ffmpeg directory" >&2
    exit 1
fi

cp "${SRC_DIR}/ffmpeg"  "${INSTALL_DIR}/ffmpeg"
cp "${SRC_DIR}/ffprobe" "${INSTALL_DIR}/ffprobe"
chmod +x "${INSTALL_DIR}/ffmpeg" "${INSTALL_DIR}/ffprobe"

echo "[ffmpeg.sh] Installed:"
"${INSTALL_DIR}/ffmpeg"  -version | head -1
"${INSTALL_DIR}/ffprobe" -version | head -1
