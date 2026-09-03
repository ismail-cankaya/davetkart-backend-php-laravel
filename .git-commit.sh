#!/usr/bin/env bash
# Faz 7 yardimci: kilitleri temizle, hepsini ekle, commit at.
# Gerekce: docs (project memory) — device_bash mount'unda git index.lock kaliyor.
set -e
cd "$(dirname "$0")"
mkdir -p .git/_stale_locks
for f in HEAD.lock index.lock; do
  [ -e ".git/$f" ] && mv ".git/$f" ".git/_stale_locks/$(date +%s%N)-$f"
done
git add -A
mkdir -p .git/_stale_locks
for f in HEAD.lock index.lock; do
  [ -e ".git/$f" ] && mv ".git/$f" ".git/_stale_locks/$(date +%s%N)-$f"
done
git commit -q -m "$1"
git log --oneline -1
