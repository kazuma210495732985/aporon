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

STATE="$HOME/.kaigo-taxi-simulator-deployed"

cd "$REPO_DIR"
git fetch -q origin "$BRANCH"
TARGET="$(git rev-parse FETCH_HEAD)"

# 最後に反映できた版と同じで、反映先も残っていれば何もしない
if [ -d "$DEST" ] && [ "$(cat "$STATE" 2> /dev/null)" = "$TARGET" ]; then
  exit 0
fi

git reset -q --hard "$TARGET"
mkdir -p "$DEST/data"
tar -C "$REPO_DIR/$APP" --exclude='./config.php' --exclude='./data' -cf - . | tar -C "$DEST" -xf -
cp "$REPO_DIR/$APP/data/.htaccess" "$DEST/data/.htaccess"

# コピーまで成功したときだけ記録する（失敗したら次回やり直す）
echo "$TARGET" > "$STATE"
echo "$(date '+%Y-%m-%d %H:%M:%S') 反映しました ${TARGET:0:7}" >> "$LOG"
