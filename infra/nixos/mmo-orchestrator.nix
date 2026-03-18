# Unified MMO Platform Orchestrator — paste into services.nix
#
# Replaces: 333method-orchestrator.service + 333method-orchestrator.timer
#
# What it does:
#   Runs scripts/orchestrator.sh --loop every 10 minutes.
#   Handles both 333Method and 2Step Claude batch processing under one shared
#   Claude Max usage pool and one conservation mode.
#
# To apply:
#   1. Copy this snippet into /etc/nixos/services.nix, replacing the
#      333method-orchestrator service+timer block (lines ~263-283)
#   2. sudo nixos-rebuild switch --flake /etc/nixos#k7
#   3. systemctl --user status mmo-orchestrator.timer
#
# Rollback:
#   Restore the original 333method-orchestrator block in services.nix and rebuild.

  # ============================================================================
  # MMO PLATFORM UNIFIED ORCHESTRATOR
  # ============================================================================

  systemd.user.services."mmo-orchestrator" = {
    description = "MMO Platform Unified Orchestrator (333Method + 2Step)";
    after = [ "network.target" ];
    # path supplies binaries the POSIX sh script needs beyond its own nix-store glob logic
    path = with pkgs; [
      nodejs_22   # node — required by gate helpers and store wrappers
      sqlite      # sqlite3 — may be called by helper scripts
      git         # git — used by monitoring/overseer helpers
      coreutils   # date, mktemp, wc, etc.
      util-linux  # ionice, renice (used by chromium-nice on host)
    ];
    serviceConfig = {
      Type = "oneshot";
      Nice = 10;
      WorkingDirectory = "/home/jason/code/mmo-platform";
      ExecStart = "/bin/sh /home/jason/code/mmo-platform/scripts/orchestrator.sh --loop";
      # Load env files in order: 333Method base → 333Method secrets → 2Step overrides
      # Each EnvironmentFile line is processed in order; later values win on collision.
      EnvironmentFile = [
        "/home/jason/code/333Method/.env"
        "/home/jason/code/333Method/.env.secrets"
        "/home/jason/code/2Step/.env"
      ];
      Environment = [
        "HOME=/home/jason"
      ];
      # Give the --loop run time to drain queues before systemd kills it
      TimeoutStartSec = "55min";
      StandardOutput = "journal";
      StandardError = "journal";
    };
  };

  systemd.user.timers."mmo-orchestrator" = {
    wantedBy = [ "timers.target" ];
    timerConfig = {
      # Fire at :00, :10, :20, :30, :40, :50 past each hour
      OnCalendar = "*:0/10";
      # Run on next opportunity if the system was off at the scheduled time
      Persistent = true;
      Unit = "mmo-orchestrator.service";
    };
  };
