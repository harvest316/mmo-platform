#!/bin/sh
# Run from a host terminal: bash claude-continue.sh

while true; do
  WID=$(nix run nixpkgs#xdotool -- search --onlyvisible --name 'VSCodium' | head -1)
  if [ -n "$WID" ]; then
    nix run nixpkgs#xdotool -- windowfocus "$WID"
    sleep 0.5
    nix run nixpkgs#xdotool -- type --clearmodifiers $'continue\n'
  else
    echo "VSCodium window not found, skipping"
  fi
  sleep 3300
done
