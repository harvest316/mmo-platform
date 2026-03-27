# PostgreSQL 16 for mmo-platform (DR-099: SQLite → PostgreSQL migration)
#
# USAGE: Add to /etc/nixos/services.nix or import from configuration.nix
#
# After rebuilding:
#   sudo nixos-rebuild switch --flake /etc/nixos#k7
#
# Then create schemas:
#   psql -d mmo -f ~/code/mmo-platform/db/pg-init-schemas.sql
#
{ config, pkgs, ... }:
{
  # ============================================================================
  # POSTGRESQL 16
  # ============================================================================

  services.postgresql = {
    enable = true;
    package = pkgs.postgresql_16;
    extraPlugins = ps: [ ps.wal2json ];

    settings = {
      # -- Connections --
      max_connections = 100;

      # -- Memory (k7: 32GB RAM) --
      shared_buffers = "4GB";
      effective_cache_size = "12GB";
      work_mem = "16MB";
      maintenance_work_mem = "128MB";

      # -- WAL & Checkpoints --
      wal_level = "logical";
      max_replication_slots = 4;
      max_wal_senders = 4;
      max_wal_size = "2GB";
      checkpoint_completion_target = 0.9;
      max_slot_wal_keep_size = "2GB";

      # -- Archiving --
      archive_mode = "on";
      archive_command = "test ! -f /run/media/jason/store/backups/pg/wal/%f && cp %p /run/media/jason/store/backups/pg/wal/%f";

      # -- Autovacuum --
      autovacuum_max_workers = 4;

      # -- Performance (SSD) --
      random_page_cost = 1.1;

      # -- Security --
      password_encryption = "scram-sha-256";

      # -- Timezone --
      timezone = "UTC";

      # -- Logging --
      log_min_duration_statement = 500;
      log_lock_waits = true;
      deadlock_timeout = "1s";
      log_parameter_max_length = 64;
      log_parameter_max_length_on_error = 0;

      # -- Extensions --
      shared_preload_libraries = "pg_stat_statements,wal2json";
    };

    ensureDatabases = [ "mmo" ];
    ensureUsers = [{
      name = "jason";
      ensureDBOwnership = true;
    }];

    authentication = pkgs.lib.mkOverride 10 ''
      # TYPE  DATABASE  USER   METHOD
      local   all       all    peer
      host    mmo       jason  127.0.0.1/32  scram-sha-256
      host    mmo       jason  ::1/128       scram-sha-256
    '';
  };

  # -- Backup directories --
  systemd.tmpfiles.rules = [
    "d /run/media/jason/store/backups/pg 0750 jason users -"
    "d /run/media/jason/store/backups/pg/wal 0750 jason users -"
    "d /var/lib/pg-audit 0700 jason users -"
  ];
}
