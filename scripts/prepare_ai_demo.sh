#!/usr/bin/env bash
# Prepare AI demo data: seed reservations, curated scenarios, train models, verify .pkl files.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
AI_DIR="$ROOT/ai"
MODELS_DIR="$AI_DIR/models"

SKIP_BULK=0
BULK_COUNT=80
APPROVED_RATIO=0.65

while [[ $# -gt 0 ]]; do
  case "$1" in
    --skip-bulk-seed) SKIP_BULK=1; shift ;;
    --count) BULK_COUNT="$2"; shift 2 ;;
    --approved-ratio) APPROVED_RATIO="$2"; shift 2 ;;
    *) echo "Unknown option: $1"; exit 1 ;;
  esac
done

cd "$AI_DIR"

echo ""
echo "========================================"
echo " CPRF AI Demo Package — Prepare"
echo "========================================"
echo ""

if ! command -v python3 >/dev/null 2>&1 && ! command -v python >/dev/null 2>&1; then
  echo "Python not found on PATH."
  exit 1
fi
PY="${PYTHON:-python3}"
command -v "$PY" >/dev/null 2>&1 || PY=python

if [[ "$SKIP_BULK" -eq 0 ]]; then
  echo "[1/4] Seeding bulk sample reservations ($BULK_COUNT)..."
  "$PY" scripts/seed_sample_reservations.py --count "$BULK_COUNT" --approved-ratio "$APPROVED_RATIO" --yes
else
  echo "[1/4] Skipping bulk seed (--skip-bulk-seed)"
fi

echo "[2/4] Seeding curated demo scenarios..."
"$PY" scripts/seed_demo_scenarios.py --yes

echo "[3/4] Training all ML models..."
TRAIN_FAILED=()
for script in \
  train_conflict_detection.py \
  train_auto_approval_risk.py \
  train_chatbot_intents.py \
  train_purpose_analysis.py \
  train_facility_recommendation.py \
  train_demand_forecasting.py
do
  echo "  -> $script"
  if ! "$PY" "scripts/$script"; then
    TRAIN_FAILED+=("$script")
    echo "  Warning: $script failed"
  fi
done

echo "[4/4] Verifying model files..."
MISSING=()
for model in \
  conflict_detection.pkl \
  conflict_detection_features.pkl \
  auto_approval_risk_model.pkl \
  auto_approval_risk_encoders.pkl \
  chatbot_intent_model.pkl \
  chatbot_intent_vectorizer.pkl \
  purpose_category_model.pkl \
  purpose_category_vectorizer.pkl \
  purpose_unclear_model.pkl \
  purpose_unclear_vectorizer.pkl \
  facility_recommendation_model.pkl \
  facility_recommendation_encoders.pkl \
  demand_forecasting_model.pkl
do
  if [[ -f "$MODELS_DIR/$model" ]]; then
    echo "  OK  $model"
  else
    MISSING+=("$model")
    echo "  --  $model (missing)"
  fi
done

echo ""
echo "Done."
if ((${#TRAIN_FAILED[@]})); then
  echo "Warning: failed train scripts: ${TRAIN_FAILED[*]}"
fi
if ((${#MISSING[@]})); then
  echo "Warning: missing models: ${MISSING[*]}"
fi
echo ""
echo "Next: /dashboard/ai-model-lab and Book a Facility demo scenarios"
echo ""
