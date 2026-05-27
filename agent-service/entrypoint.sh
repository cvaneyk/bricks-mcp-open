#!/bin/bash
set -e

MODE="${ENTRYPOINT_MODE:-telegram}"

case "$MODE" in
  telegram|bot)
    echo "Starting Telegram bot..."
    exec npx tsx /app/src/main.ts
    ;;
  build)
    echo "Running single build: ${BUILD_BRIEF:-zahnarzt}"
    exec npx tsx /app/src/main.ts --build "${BUILD_BRIEF:-zahnarzt}"
    ;;
  batch)
    echo "Running overnight batch..."
    exec npx tsx /app/src/main.ts --batch
    ;;
  *)
    echo "Unknown ENTRYPOINT_MODE: $MODE"
    echo "Use: telegram, build, or batch"
    exit 1
    ;;
esac
