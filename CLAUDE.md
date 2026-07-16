# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Status

This is a greenfield repository named "Pop". As of the last update to this file, it contains only:

- `README.md` — a single-line title (`# Pop`).
- `CLAUDE.md` — this guidance file.

There is no source code, build tooling, test suite, or CI configuration yet.

## Current State

- No package manager, build system, or language toolchain has been set up.
- There are no build, lint, or test commands to run.
- There is no established architecture or directory structure.
- The default branch is `main`.

## Git Workflow

- The default branch is `main`; feature work happens on dedicated branches.
- Push branches with `git push -u origin <branch-name>`.
- Open a pull request against `main` for review before merging.

## Guidance for Future Sessions

- When the first code is added, establish the project's language, tooling, and
  structure based on the user's requirements rather than assumptions. Do not
  scaffold a stack speculatively.
- Once a language and package manager are chosen, record the concrete
  build / lint / test commands here so future sessions can run them without
  rediscovery.
- As an architecture and directory layout emerge, document them in this file
  (key directories, entry points, and how the pieces fit together).
- Keep this file in sync with the actual state of the repository — update it
  whenever tooling, structure, or conventions change.
