# PostgreSQL backup + wal2json audit log (DR-104)
#
# USAGE: Import from /etc/nixos/configuration.nix alongside postgresql.nix
#
# Provides:
#   - pg-backup: 6-hourly pg_dump (gzipped)
#   - pg-backup-verify: weekly restore verification
#   - pg-audit-consumer: streams WAL changes to JSONL files
#   - pg-audit-compress: hourly zstd compression of completed audit logs
#   - pg-audit-prune: daily deletion of audit logs > 90 days
#
{ config, pkgs, ... }:
{
  # ── pg_dump backup (every 6 hours) ──────────────────────────────────────────
  systemd.services.pg-backup = {
    description = "PostgreSQL backup (pg_dump → gzip)";
    after = [ "postgresql.service" ];
    wants = [ "postgresql.service" ];
    path = [ pkgs.postgresql_16 pkgs.gzip pkgs.coreutils ];
    serviceConfig = {
      Type = "oneshot";
      User = "jason";
      ExecStart = pkgs.writeShellScript "pg-backup" ''
        set -euo pipefail
        BACKUP_DIR="/run/media/jason/store/backups/pg"
        mkdir -p "$BACKUP_DIR"
        FILENAME="$BACKUP_DIR/mmo-$(date +%Y-%m-%d_%H%M).sql.gz"
        pg_dump mmo | gzip > "$FILENAME"
        echo "Backup complete: $FILENAME ($(du -h "$FILENAME" | cut -f1))"
        # Prune backups older than 7 days
        find "$BACKUP_DIR" -name 'mmo-*.sql.gz' -mtime +7 -delete
      '';
    };
  };

  systemd.timers.pg-backup = {
    wantedBy = [ "timers.target" ];
    timerConfig = {
      OnCalendar = "*-*-* 00,06,12,18:00:00";
      Persistent = true;
    };
  };

  # ── Weekly restore verification ─────────────────────────────────────────────
  systemd.services.pg-backup-verify = {
    description = "PostgreSQL backup restore verification";
    after = [ "postgresql.service" ];
    wants = [ "postgresql.service" ];
    path = [ pkgs.postgresql_16 pkgs.gzip pkgs.coreutils ];
    serviceConfig = {
      Type = "oneshot";
      User = "jason";
      ExecStart = pkgs.writeShellScript "pg-backup-verify" ''
        set -euo pipefail
        BACKUP_DIR="/run/media/jason/store/backups/pg"
        LATEST=$(ls -t "$BACKUP_DIR"/mmo-*.sql.gz 2>/dev/null | head -1)
        if [ -z "$LATEST" ]; then
          echo "ERROR: No backup files found in $BACKUP_DIR"
          exit 1
        fi

        echo "Verifying backup: $LATEST"
        dropdb --if-exists mmo_verify_tmp 2>/dev/null || true
        createdb mmo_verify_tmp

        zcat "$LATEST" | psql -q mmo_verify_tmp

        ROW_COUNT=$(psql -t -c "SELECT count(*) FROM m333.sites" mmo_verify_tmp | tr -d ' ')
        echo "Backup verification passed: $ROW_COUNT sites in m333.sites"

        dropdb mmo_verify_tmp
      '';
    };
  };

  systemd.timers.pg-backup-verify = {
    wantedBy = [ "timers.target" ];
    timerConfig = {
      OnCalendar = "Sun *-*-* 03:00:00";
      Persistent = true;
    };
  };

  # ── wal2json audit log consumer ─────────────────────────────────────────────
  systemd.services.pg-audit-consumer = {
    description = "PostgreSQL WAL audit log consumer (wal2json)";
    after = [ "postgresql.service" ];
    wants = [ "postgresql.service" ];
    path = [ pkgs.postgresql_16 pkgs.coreutils ];
    serviceConfig = {
      Type = "simple";
      User = "jason";
      Restart = "always";
      RestartSec = "30";
      ExecStart = pkgs.writeShellScript "pg-audit-consumer" ''
        set -euo pipefail
        AUDIT_DIR="/var/lib/pg-audit"
        mkdir -p "$AUDIT_DIR"

        # Create replication slot if it doesn't exist
        psql -d mmo -c "SELECT pg_create_logical_replication_slot('audit_slot', 'wal2json')" 2>/dev/null || true

        DATE=$(date -u +%Y-%m-%d)
        HOUR=$(date -u +%H)
        OUTFILE="$AUDIT_DIR/''${DATE}_''${HOUR}.jsonl"

        exec pg_recvlogical \
          --slot=audit_slot \
          --plugin=wal2json \
          -d mmo \
          --start \
          --no-loop \
          -o format-version=2 \
          -o include-timestamp=true \
          -o include-lsn=true \
          -o add-tables='m333.sites,m333.messages,m333.keywords,m333.purchases,ops.cron_jobs,ops.pipeline_control' \
          -o filter-columns='m333.sites.html_dom,m333.sites.key_pages_html,m333.sites.evidence_json,m333.sites.evidence_pass1_json,m333.sites.evidence_pass2_json,m333.sites.perf_json,m333.sites.form_fill_data' \
          -f "$OUTFILE" \
          --status-interval=10
      '';
    };
  };

  # ── Compress completed audit log files (hourly) ────────────────────────────
  systemd.services.pg-audit-compress = {
    description = "Compress completed audit log files";
    path = [ pkgs.zstd pkgs.findutils pkgs.coreutils ];
    serviceConfig = {
      Type = "oneshot";
      User = "jason";
      ExecStart = pkgs.writeShellScript "pg-audit-compress" ''
        set -euo pipefail
        AUDIT_DIR="/var/lib/pg-audit"
        CURRENT_HOUR=$(date -u +%Y-%m-%d_%H)
        # Compress all .jsonl files except the current hour (still being written)
        find "$AUDIT_DIR" -name '*.jsonl' -not -name "''${CURRENT_HOUR}.jsonl" \
          -exec zstd --rm -q -19 {} \; 2>/dev/null || true
      '';
    };
  };

  systemd.timers.pg-audit-compress = {
    wantedBy = [ "timers.target" ];
    timerConfig = {
      OnCalendar = "*:05:00";
      Persistent = true;
    };
  };

  # ── Prune old audit logs (daily, >90 days) ─────────────────────────────────
  systemd.services.pg-audit-prune = {
    description = "Prune old audit log files (>90 days)";
    path = [ pkgs.findutils pkgs.coreutils ];
    serviceConfig = {
      Type = "oneshot";
      User = "jason";
      ExecStart = pkgs.writeShellScript "pg-audit-prune" ''
        find /var/lib/pg-audit -name '*.zst' -mtime +90 -delete 2>/dev/null || true
      '';
    };
  };

  systemd.timers.pg-audit-prune = {
    wantedBy = [ "timers.target" ];
    timerConfig = {
      OnCalendar = "*-*-* 04:30:00";
      Persistent = true;
    };
  };

  # ── Directories ─────────────────────────────────────────────────────────────
  systemd.tmpfiles.rules = [
    "d /run/media/jason/store/backups/pg 0750 jason users -"
    "d /run/media/jason/store/backups/pg/wal 0750 jason users -"
    "d /var/lib/pg-audit 0700 jason users -"
  ];
}
