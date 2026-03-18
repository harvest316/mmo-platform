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
    path = with pkgs; [ direnv nix git bash nodejs coreutils ];
    serviceConfig = {
      Type = "oneshot";
      Nice = 19;
      Environment = "HOME=/home/jason";
    };
    script = ''
      # Update wrangler
      npm update -g wrangler 2>&1 | logger -t dev-precache || true

      # Pre-warm direnv caches
      for dir in /home/jason/code/333Method /home/jason/code/2Step /home/jason/code/distributed-infra /home/jason/code/mmo-platform; do
        if [ -f "$dir/.envrc" ]; then
          cd "$dir" && direnv exec . true 2>&1 | logger -t dev-precache || true
        fi
      done
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
