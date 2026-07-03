# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

**Pop** is a newly initialized repository. As of this writing it contains no source code, build system, or tooling — only this file and a `README.md` with the project title. There is no established language, framework, or architecture yet.

## Current Structure

```
Pop/
├── README.md   # Project title only
└── CLAUDE.md   # This file
```

## Development Workflow

- The default branch is `main`.
- Feature work happens on branches (e.g. `claude/<topic>`) and is merged via pull requests.
- There are no build, test, or lint commands yet — none are configured.

## Guidance for AI Assistants

Because the project is a blank slate:

1. **Don't assume a stack.** No language, package manager, or framework has been chosen. Ask the user (or infer from their request) before scaffolding anything.
2. **Establish conventions as code is added.** When the first real code lands, update this file with:
   - Build, test, and lint commands
   - Directory layout and architecture notes
   - Any project-specific conventions (naming, formatting, commit style)
3. **Keep this file current.** Update it whenever tooling, structure, or workflows change so it stays an accurate map of the codebase.
