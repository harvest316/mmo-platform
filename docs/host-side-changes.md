# Host-Side Changes (Apply from NixOS host terminal)

These changes need to be applied from the NixOS host terminal, not from inside the Docker container.

**Generated:** 2026-03-18 by Agency Agents consolidated plan

---

## T0.6 — Add `--init` to Docker container (HIGH PRIORITY)

Fixes zombie process accumulation. One-line change, zero rebuild needed.

**File:** `/etc/nixos/home.nix` (around line 519, in the `DOCKER_ARGS` array)

```bash
# Find the DOCKER_ARGS array and add --init:
DOCKER_ARGS=(
    --rm
    --init          # <-- ADD THIS LINE (Docker's built-in tini as PID 1)
    --name "codium-sandbox-$$"
    # ... rest of args
)
```

**Verify:** After restarting the container, check PID 1:
```bash
docker exec codium-sandbox-$$ ps -p 1 -o comm=
# Should show "tini" instead of "codium"
```

---

## T2.13 — Narrow ~/.local/share mount (MEDIUM PRIORITY)

Current mount exposes GNOME keyring files. Narrow to only needed subdirectories.

**File:** `/etc/nixos/home.nix` (in the volume mounts section)

Replace:
```bash
--volume "$HOME/.local/share:$HOME/.local/share:rw"
```

With individual mounts:
```bash
--volume "$HOME/.local/share/claude:$HOME/.local/share/claude:rw"
--volume "$HOME/.local/share/vscodium:$HOME/.local/share/vscodium:rw"
--volume "$HOME/.local/share/codium-sandbox:$HOME/.local/share/codium-sandbox:rw"
```

**Note:** You may need to add other subdirectories if something breaks. Check what's actually accessed:
```bash
# Before changing, see what the container accesses:
docker exec codium-sandbox-$$ find ~/.local/share -maxdepth 1 -type d
```

---

## T2.14 — Switch to SSH agent socket forwarding (MEDIUM PRIORITY)

Prevents private SSH keys from being directly mounted into the container.

**File:** `/etc/nixos/home.nix` (in the volume mounts section)

Replace:
```bash
--volume "$HOME/.ssh:$HOME/.ssh:ro"
```

With:
```bash
--volume "$SSH_AUTH_SOCK:/tmp/ssh-agent.sock:ro"
--env "SSH_AUTH_SOCK=/tmp/ssh-agent.sock"
```

**Prerequisite:** `ssh-agent` must be running on the host (it likely already is).

**Verify:** Inside container:
```bash
ssh-add -l   # Should list your keys
git ls-remote git@github.com:your/repo.git   # Should work
```

---

## Apply all changes

```bash
# Edit home.nix
sudo nano /etc/nixos/home.nix

# Rebuild NixOS
sudo nixos-rebuild switch --flake /etc/nixos#k7

# Restart container (close and reopen VSCodium, or just restart Docker)
```
