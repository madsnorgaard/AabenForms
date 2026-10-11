#!/usr/bin/env bash
#
# Trigger the merudgifter_flow (SEL §41 merudgifter) end to end from the CLI
# so it can be followed in the Workflow Modeler's Execution Replay panel.
#
# What it does:
#   1. Seeds a demo MitID session for a persona (default: freja).
#   2. Makes sure ECA replay recording is on for this run. If the modeler is
#      already listening (select the start event, click "Review flow"), it
#      leaves that job alone so the replay appears there live. Otherwise it
#      arms recording for this run only and disarms again afterwards; the run
#      is then listed in the "Review flow" execution dropdown in the modeler.
#   3. Submits an application via POST /api/webform/merudgifter/submit with the
#      session's workflow_id, exactly like the Nuxt frontend does.
#   4. Prints the step trace and the URLs to open.
#
# Usage:
#   scripts/demo-merudgifter.sh                  # freja, 2.500 kr/md -> medhold
#   scripts/demo-merudgifter.sh freja 800        # < 1.000 kr/md -> partshøring + afslag + klagefrist
#   scripts/demo-merudgifter.sh sofie 3200
#
# Personas: run `ddev drush aabenforms:seed-mitid-session list`.

set -euo pipefail

PERSONA="${1:-freja}"
AMOUNT="${2:-2500}"
BASE="${AABENFORMS_URL:-https://aabenforms.ddev.site}"
EVENT_KEY="merudgifter_flow::on_submission"

cd "$(dirname "$0")/.."

drush() { ddev drush "$@" 2>&1 | grep -v "mocks-status" || true; }

echo "==> Seeding MitID demo session for '$PERSONA'"
SEED=$(drush aabenforms:seed-mitid-session "$PERSONA")
echo "$SEED" | sed 's/^/    /'
WF=$(echo "$SEED" | grep -oE "Workflow id: \S+" | awk '{print $3}')
CPR=$(echo "$SEED" | grep -oE "CPR [0-9]{10}" | awk '{print $2}')
if [[ -z "$WF" || -z "$CPR" ]]; then
  echo "!! Could not seed a session. Is DDEV running?" >&2
  exit 1
fi

echo "==> Checking replay recording"
WAS_ARMED=$(drush state:get _eca_internal_debug_mode | tr -d '[:space:]')
MODELER_WAITING=$(drush php:eval "echo \Drupal::service('tempstore.shared')->get('eca_process_debugger')->get('testing::$EVENT_KEY') ? 'yes' : 'no';" | tr -d '[:space:]')
if [[ "$MODELER_WAITING" == "yes" ]]; then
  echo "    Modeler is listening (Review flow) - the replay will appear there live."
elif [[ "$WAS_ARMED" == "1" ]]; then
  echo "    Recording already on (left as is)."
else
  drush state:set _eca_internal_debug_mode 1 >/dev/null
  drush state:set _eca_internal_debug_test_started "$(date +%s)" >/dev/null
  echo "    Armed recording for this run."
fi

echo "==> Submitting merudgifter application (maanedlig_merudgift=$AMOUNT)"
PAYLOAD=$(cat <<JSON
{"data":{
  "workflow_id":"$WF",
  "applicant_cpr":"$CPR",
  "child_name":"Emil Nielsen",
  "child_cpr":"1503151234",
  "funktionsnedsaettelse":"Autisme. Behov for specialkost, ekstra transport til behandling og slitage på tøj.",
  "maanedlig_merudgift":$AMOUNT
}}
JSON
)
RESP=$(curl -sk -X POST -H 'Content-Type: application/json' -d "$PAYLOAD" "$BASE/api/webform/merudgifter/submit")

if [[ "$MODELER_WAITING" != "yes" && "$WAS_ARMED" != "1" ]]; then
  drush state:set _eca_internal_debug_mode 0 >/dev/null
  drush state:delete _eca_internal_debug_test_started >/dev/null
fi

RESP="$RESP" python3 - <<'PYEOF'
import os, sys, json
r = json.loads(os.environ["RESP"])
if "error" in r:
    print("!! API error:", r["error"]); sys.exit(1)
sid = r["data"]["attributes"]["sid"]
wf = r.get("workflow", {})
print("    Submission #%s  workflow status: %s  (%s steps)" % (sid, wf.get("status"), wf.get("step_count")))
for s in wf.get("steps", []):
    mark = {"completed": "ok ", "skipped": "-- ", "failed": "!! "}.get(s["status"], "?  ")
    print("    %s %-28s %s" % (mark, s["name"], s["description"]))
open("/tmp/aabenforms-last-sid", "w").write(str(sid))
PYEOF
SID=$(cat /tmp/aabenforms-last-sid 2>/dev/null || true)

echo "==> Replay history stored for $EVENT_KEY:"
drush php:eval "\$h=\Drupal::service('eca.token_browser')->getHistoryByEvent('$EVENT_KEY'); echo '    ', count(\$h), ' run(s) available in the modeler', PHP_EOL;"

cat <<EOF

Open:
  Modeler (select the start event -> Review flow -> pick the execution in the dropdown):
    $BASE/admin/config/workflow/eca/merudgifter_flow/edit
  Sagsspor for this submission:
    $BASE/admin/aabenforms/trace/$SID
  Sager:
    $BASE/admin/aabenforms/cases
EOF
