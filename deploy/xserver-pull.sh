#!/bin/bash
# Xserver の cron から定期実行し、GitHub の最新版を公開フォルダに反映する。
# サーバー側から GitHub へ取りに行くので、海外からのSSH接続を許可する必要はない。
#
# - 反映先 public_html/kaigo-taxi-simulator/ の中にだけ書き込む（ほかのファイルには触れない・削除もしない）
# - サーバー上の config.php と data/（設定・申込データ）は上書きしない
# - GitHub に変更がなければ何もしない
set -euo pipefail

BRANCH="claude/lucid-lamport-ptk1rt"
APP="kaigo-taxi-simulator"
DOMAIN="merci2.xbiz.jp"

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$HOME/$DOMAIN/public_html/$APP/"
LOG="$HOME/kaigo-taxi-simulator-deploy.log"

# 前回の実行が終わっていなければ今回は見送る
exec 9> "$HOME/.kaigo-taxi-simulator-deploy.lock"
flock -n 9 || exit 0

cd "$REPO_DIR"
git fetch -q origin "$BRANCH"

if [ -d "$DEST" ] && [ "$(git rev-parse HEAD)" = "$(git rev-parse FETCH_HEAD)" ]; then
  exit 0
fi

git reset -q --hard FETCH_HEAD
mkdir -p "$DEST"
rsync -rlt \
  --include='/data/.htaccess' \
  --exclude='/data/*' \
  --exclude='/config.php' \
  "$REPO_DIR/$APP/" "$DEST"

echo "$(date '+%Y-%m-%d %H:%M:%S') 反映しました $(git rev-parse --short HEAD)" >> "$LOG"
