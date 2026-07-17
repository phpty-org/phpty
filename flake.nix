{
  description = "PhPty — find out what your terminal program actually renders";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    ghostty.url = "github:ghostty-org/ghostty";
  };

  outputs = { self, nixpkgs, ghostty }:
    let
      systems = [ "x86_64-linux" "aarch64-linux" "x86_64-darwin" "aarch64-darwin" ];
      forAllSystems = f: nixpkgs.lib.genAttrs systems (system: f system);
    in
    {
      devShells = forAllSystems (system:
        let
          pkgs = import nixpkgs { inherit system; };

          # ADR-0002: VTerm binds libghostty-vt. Ghostty's flake builds it, so the
          # Zig toolchain and the source build stay out of this repo entirely.
          # flake.lock pins the commit — libghostty-vt has no tagged release.
          libghostty-vt = ghostty.packages.${system}.libghostty-vt;

          # ADR-0001: everything except Tty requires FFI, and FFI is not in
          # nixpkgs' default PHP extension set.
          withFfi = php: php.withExtensions ({ enabled, all }: enabled ++ [ all.ffi ]);

          mkShell = php': pkgs.mkShell {
            packages = [
              php'
              php'.packages.composer
              libghostty-vt
              pkgs.pkg-config
            ];

            # The FFI binding needs to dlopen the shared object by path, and Nix
            # store paths are not guessable. Pass it in rather than searching.
            PHPTY_LIBGHOSTTY_VT = "${libghostty-vt}/lib";

            shellHook = ''
              echo "PhPty  ·  $(php -r 'echo PHP_VERSION;')  ·  FFI $(php -r 'echo extension_loaded("FFI") ? "on" : "OFF";')"
              echo "libghostty-vt: $PHPTY_LIBGHOSTTY_VT"
            '';
          };
        in
        {
          # ADR-0003: the first-milestone modules target modern PHP. nixpkgs has
          # no 7.4 or 8.0; see ADR-0008 for why that is not yet a problem.
          default = mkShell (withFfi pkgs.php84);
          php81 = mkShell (withFfi pkgs.php81);
          php82 = mkShell (withFfi pkgs.php82);
          php83 = mkShell (withFfi pkgs.php83);
          php84 = mkShell (withFfi pkgs.php84);
          php85 = mkShell (withFfi pkgs.php85);
        });
    };
}
