#!/usr/bin/env bash
#
# deploy.sh — clone repository app-runner ke folder dengan nama instance.
# Dipanggil oleh index.php:  bash deploy.sh <instance_name>
# Output gabungan stdout+stderr ditangkap oleh PHP.
#
# Exit code:
#   0 = sukses clone
#   2 = argumen instance_name tidak diberikan
#   3 = folder instance sudah ada (instance name already exist)
#
set -euo pipefail

# PHP exec() sering memakai PATH minim sehingga "git" tidak ditemukan (exit 127).
# Pastikan lokasi binary umum ada di PATH.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${PATH:-}"

# Pastikan git benar-benar terpasang.
if ! command -v git >/dev/null 2>&1; then
    echo "!! 'git' tidak ditemukan. Install git di server terlebih dahulu" >&2
    echo "   (Debian/Ubuntu: apt-get install -y git | Alpine: apk add git)." >&2
    exit 127
fi

REPO_URL="https://github.com/Barbarpotato/app-runner.git"

INSTANCE_NAME="${1:-}"
if [ -z "${INSTANCE_NAME}" ]; then
    echo "!! instance_name tidak diberikan." >&2
    exit 2
fi

# Direktori tujuan: satu level di atas folder "deploy" ini
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_PARENT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TARGET_DIR="${TARGET_PARENT}/${INSTANCE_NAME}"

echo "==> Instance     : ${INSTANCE_NAME}"
echo "==> Target dir   : ${TARGET_DIR}"
echo "==> Repo URL     : ${REPO_URL}"
echo "==> Mulai        : $(date '+%Y-%m-%d %H:%M:%S')"
echo "-----------------------------------------------"

# Kalau folder sudah ada -> tolak (instance name already exist)
if [ -e "${TARGET_DIR}" ]; then
    echo "!! Instance '${INSTANCE_NAME}' sudah ada di ${TARGET_DIR}." >&2
    exit 3
fi

echo "==> Cloning repository..."
git clone "${REPO_URL}" "${TARGET_DIR}"

echo "-----------------------------------------------"
cd "${TARGET_DIR}"
echo "==> Commit terakhir: $(git rev-parse --short HEAD) - $(git log -1 --pretty=%s)"
echo "==> Selesai (cloned) : $(date '+%Y-%m-%d %H:%M:%S')"
