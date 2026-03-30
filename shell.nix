{ pkgs ? import <nixpkgs> {} }:

pkgs.mkShell {
  name = "mmo-platform";

  buildInputs = with pkgs; [
    nodejs_22
    nodePackages.npm
    sqlite
    gcc
    gnumake
    pkg-config
    claude-code
    git
    gh
    chromium  # For Playwright (directory-submit.js, e2e tests)
  ];

  shellHook = ''
    echo "mmo-platform — shared services"
    echo ""
    echo "Node: $(node --version) | sqlite3: $(sqlite3 --version | cut -d' ' -f1)"
    echo ""

    export PATH="$PWD/node_modules/.bin:$PATH"
    export PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

    if [ ! -d "node_modules" ]; then
      echo "Installing npm dependencies..."
      npm install
      echo ""
    fi
  '';

  NIX_LD_LIBRARY_PATH = pkgs.lib.makeLibraryPath [
    pkgs.stdenv.cc.cc
    pkgs.stdenv.cc.cc.lib
  ];

  LD_LIBRARY_PATH = pkgs.lib.makeLibraryPath [
    pkgs.stdenv.cc.cc.lib
  ];
}
