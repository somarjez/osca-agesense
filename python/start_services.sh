#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# start_services.sh — Launch both OSCA Python ML services
# Usage:  bash python/start_services.sh
# ─────────────────────────────────────────────────────────────────────────────

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICES_DIR="$SCRIPT_DIR/services"
# TC-ML-02 / TC-DEP-03 (audit finding): this used to default to the STALE
# storage/app/ml_models mirror — this script never reads .env (unlike
# start_services.ps1, which does), so on any invocation where ML_MODELS_PATH
# isn't already exported in the shell environment, the services silently
# loaded from the wrong directory regardless of what .env said. python/models
# is the canonical, live directory (matches .env's own ML_MODELS_PATH=python/models
# and start_services.ps1's already-correct default).
ML_MODELS_PATH="${ML_MODELS_PATH:-$SCRIPT_DIR/models}"

export ML_MODELS_PATH

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  OSCA ML Services Launcher"
echo "  Models path: $ML_MODELS_PATH"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Install dependencies if venv doesn't exist
if [ ! -d "$SCRIPT_DIR/venv" ]; then
    echo "➤  Creating Python virtual environment…"
    python3 -m venv "$SCRIPT_DIR/venv"
    "$SCRIPT_DIR/venv/bin/pip" install -q --upgrade pip
    "$SCRIPT_DIR/venv/bin/pip" install -q -r "$SCRIPT_DIR/requirements.txt"
    echo "✅  Dependencies installed."
fi

PYTHON="$SCRIPT_DIR/venv/bin/python"

# Kill any existing services on these ports
echo "➤  Checking for existing processes on ports 5001/5002…"
fuser -k 5001/tcp 2>/dev/null || true
fuser -k 5002/tcp 2>/dev/null || true
sleep 1

# Start preprocessing service
echo "➤  Starting Preprocessing Service on port 5001…"
PREPROCESS_PORT=5001 "$PYTHON" "$SERVICES_DIR/preprocess_service.py" \
    > "$SCRIPT_DIR/../storage/logs/preprocess.log" 2>&1 &
PREPROCESS_PID=$!
echo "   PID: $PREPROCESS_PID"

sleep 1

# Start inference service
echo "➤  Starting Inference Service on port 5002…"
INFERENCE_PORT=5002 "$PYTHON" "$SERVICES_DIR/inference_service.py" \
    > "$SCRIPT_DIR/../storage/logs/inference.log" 2>&1 &
INFERENCE_PID=$!
echo "   PID: $INFERENCE_PID"

sleep 2

# Health check
echo ""
echo "➤  Health checks:"
curl -s http://127.0.0.1:5001/health | python3 -m json.tool 2>/dev/null || echo "  ⚠️  Preprocessing service not responding yet."
curl -s http://127.0.0.1:5002/health | python3 -m json.tool 2>/dev/null || echo "  ⚠️  Inference service not responding yet."

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Services started."
echo "  Preprocessor : http://127.0.0.1:5001"
echo "  Inference    : http://127.0.0.1:5002"
echo "  Logs         : storage/logs/preprocess.log"
echo "               : storage/logs/inference.log"
echo ""
echo "  To stop: kill $PREPROCESS_PID $INFERENCE_PID"
echo "  Or run : pkill -f preprocess_service && pkill -f inference_service"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
