#!/bin/sh
# ============================================================================
# MMO Orchestrator Cutover Script
# ============================================================================
#
# IMPORTANT: Run this from the HOST TERMINAL — not from VSCode / Docker.
#            The Docker container cannot write to /etc/nixos/ and cannot
#            reach the host's systemd user session.
#
# What this does:
#   1. Stops and disables the old 333method-orchestrator timer
#   2. Copies the new NixOS module snippet into place for manual merge
#   3. Prompts you to merge the snippet into services.nix
#   4. Rebuilds NixOS with the flake
#   5. Enables and starts the new mmo-orchestrator timer
#   6. Verifies the timer is active
#
# Usage (from host terminal):
#   bash ~/code/mmo-platform/scripts/cutover-orchestrator.sh
#
# ============================================================================
# ROLLBACK (run from host terminal):
#   systemctl --user stop mmo-orchestrator.timer mmo-orchestrator.service
#   systemctl --user disable mmo-orchestrator.timer
#   # Restore the 333method-orchestrator block in /etc/nixos/services.nix
#   sudo nixos-rebuild switch --flake /etc/nixos#k7
#   systemctl --user enable --now 333method-orchestrator.timer
# ============================================================================

set -e

MMO_PLATFORM_ROOT="$HOME/code/mmo-platform"
SNIPPET="$MMO_PLATFORM_ROOT/infra/nixos/mmo-orchestrator.nix"
SERVICES_NIX="/etc/nixos/services.nix"

# ── Sanity checks ─────────────────────────────────────────────────────────────

if [ ! -f "$SNIPPET" ]; then
  echo "ERROR: snippet not found at $SNIPPET"
  echo "       Make sure you are running this from the host terminal after"
  echo "       the mmo-platform repo has been pulled."
  exit 1
fi

if [ ! -f "$SERVICES_NIX" ]; then
  echo "ERROR: $SERVICES_NIX not found — are you on the NixOS host?"
  exit 1
fi

# Warn if already running new orchestrator
if systemctl --user is-active mmo-orchestrator.timer >/dev/null 2>&1; then
  echo "WARNING: mmo-orchestrator.timer is already active."
  echo "         If you are re-running after a partial cutover, that is fine."
  echo "         Continue? [y/N]"
  read -r answer
  case "$answer" in
    y|Y) ;;
    *) echo "Aborted."; exit 0 ;;
  esac
fi

# ── Step 1: Stop and disable the old timer ────────────────────────────────────

echo ""
echo "==> Step 1: Stopping old 333method-orchestrator timer..."

if systemctl --user is-active 333method-orchestrator.timer >/dev/null 2>&1; then
  systemctl --user stop 333method-orchestrator.timer
  echo "    Stopped 333method-orchestrator.timer"
else
  echo "    333method-orchestrator.timer was not running (OK)"
fi

# Also stop any in-flight service run
if systemctl --user is-active 333method-orchestrator.service >/dev/null 2>&1; then
  echo "    Waiting for in-flight 333method-orchestrator.service to finish..."
  systemctl --user stop 333method-orchestrator.service || true
fi

if systemctl --user is-enabled 333method-orchestrator.timer >/dev/null 2>&1; then
  systemctl --user disable 333method-orchestrator.timer
  echo "    Disabled 333method-orchestrator.timer"
else
  echo "    333method-orchestrator.timer was not enabled (OK)"
fi

# ── Step 2: Show the snippet and prompt for manual merge ─────────────────────

echo ""
echo "==> Step 2: NixOS config snippet"
echo ""
echo "    The new service+timer config is at:"
echo "    $SNIPPET"
echo ""
echo "    You need to edit $SERVICES_NIX and:"
echo "    a) Find the '333method-orchestrator' service + timer block (~lines 263-283)"
echo "    b) Replace it with the contents of the snippet above"
echo "    c) Save the file"
echo ""
echo "    The snippet is also printed below for reference:"
echo "    ─────────────────────────────────────────────────"
cat "$SNIPPET"
echo "    ─────────────────────────────────────────────────"
echo ""
echo "    Open $SERVICES_NIX now and make the change."
echo "    When done, press ENTER to continue (or Ctrl-C to abort)."
read -r _unused

# ── Step 3: Rebuild NixOS ─────────────────────────────────────────────────────

echo ""
echo "==> Step 3: Rebuilding NixOS (sudo nixos-rebuild switch --flake /etc/nixos#k7)..."
echo "    This will take a minute or two..."
sudo nixos-rebuild switch --flake /etc/nixos#k7
echo "    Rebuild complete."

# ── Step 4: Enable and start the new timer ───────────────────────────────────

echo ""
echo "==> Step 4: Enabling and starting mmo-orchestrator.timer..."
systemctl --user daemon-reload
systemctl --user enable mmo-orchestrator.timer
systemctl --user start mmo-orchestrator.timer
echo "    Timer enabled and started."

# ── Step 5: Verify ───────────────────────────────────────────────────────────

echo ""
echo "==> Step 5: Verification"
echo ""

echo "--- mmo-orchestrator.timer ---"
systemctl --user status mmo-orchestrator.timer --no-pager || true

echo ""
echo "--- Next scheduled run ---"
systemctl --user list-timers mmo-orchestrator.timer --no-pager || true

echo ""
echo "--- Old 333method-orchestrator.timer (should be inactive/disabled) ---"
systemctl --user status 333method-orchestrator.timer --no-pager || true

# ── Done ──────────────────────────────────────────────────────────────────────

echo ""
echo "==> Cutover complete."
echo ""
echo "    Logs will appear at:"
echo "    ~/code/mmo-platform/logs/orchestrator-$(date +%Y-%m-%d).log"
echo ""
echo "    To watch live:"
echo "    journalctl --user -u mmo-orchestrator.service -f"
echo ""
echo "    To check after first run:"
echo "    tail -f ~/code/mmo-platform/logs/orchestrator-$(date +%Y-%m-%d).log"
echo ""
echo "    ── ROLLBACK (if something breaks) ─────────────────────────────────────"
echo "    systemctl --user stop mmo-orchestrator.timer mmo-orchestrator.service"
echo "    systemctl --user disable mmo-orchestrator.timer"
echo "    # Restore 333method-orchestrator block in $SERVICES_NIX"
echo "    sudo nixos-rebuild switch --flake /etc/nixos#k7"
echo "    systemctl --user enable --now 333method-orchestrator.timer"
echo "    ────────────────────────────────────────────────────────────────────────"
