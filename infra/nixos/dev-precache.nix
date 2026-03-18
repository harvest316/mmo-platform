# Dev environment pre-warming — paste into services.nix above the "333Method" section
#
# What it does:
#   1. Updates wrangler globally (silences the "new version" nag)
#   2. Pre-warms direnv/nix-shell caches for all project dirs
#
# Runs 2min after boot, then every 30min.
# nix-direnv (already enabled) caches the evaluated env as a GC root,
# so after this timer runs, cd-ing into any project dir is instant.

  # ============================================================================
  # DEV ENVIRONMENT PRE-WARMING
  # ============================================================================

  systemd.user.services.dev-precache = {
    description = "Pre-warm direnv/nix-shell caches and update dev tools";
    path = with pkgs; [ direnv nix git bash nodejs coreutils util-linux ];
    serviceConfig = {
      Type = "oneshot";
      Nice = 19;
      Environment = "HOME=/home/jason";
      StandardOutput = "journal";
      StandardError = "journal";
    };
    script = ''
      echo "dev-precache: starting"

      # Update wrangler
      if command -v npm >/dev/null 2>&1; then
        echo "dev-precache: updating wrangler..."
        npm update -g wrangler 2>&1 || echo "dev-precache: wrangler update failed (non-fatal)"
      else
        echo "dev-precache: npm not found in PATH"
      fi

      # Pre-warm direnv caches
      # Touch the manual-reload trigger so nix_direnv_manual_reload
      # forces a full Nix re-evaluation (otherwise it just sources
      # the existing cache and exits in <1s)
      for dir in /home/jason/code/333Method /home/jason/code/2Step /home/jason/code/distributed-infra /home/jason/code/mmo-platform; do
        if [ -f "$dir/.envrc" ]; then
          echo "dev-precache: warming $dir..."
          mkdir -p "$dir/.direnv"
          touch "$dir/.direnv/.manual-reload-trigger"
          (cd "$dir" && direnv exec . true 2>&1) || echo "dev-precache: FAILED $dir"
        fi
      done

      echo "dev-precache: done"
    '';
  };

  systemd.user.timers.dev-precache = {
    wantedBy = [ "timers.target" ];
    timerConfig = {
      OnBootSec = "2min";
      OnUnitActiveSec = "30min";
      Persistent = true;
    };
  };
