# Edict: working rules for agents

## Disk hygiene (rules, not tips)

These rules exist because of an incident in the Blacksite repo, where millions of leftover test files piled up on the dev PC (see Thiesi/Blacksite PR #152, AGENTS.md section 5).

1. **No per-run scratch directories inside the repo.** Test runners and build tools either use the system temp dir or exactly one fixed in-repo path (`.scratch/`, gitignored), which is reused and overwritten on every run. Never use unique-per-run paths such as `--basetemp=.test-tmp/<random>`.
2. **Test temp dirs are cleaned automatically.** Fixtures create temp dirs via the framework's managed temp API with retention for failed runs only, and delete them on success.
3. **One worktree per active task**, removed (`git worktree remove`) once its PR is merged. Each worktree's dependencies (`node_modules`, `vendor/`, `.venv`) go away with it.
4. **Clean scratch before going idle:** remove `.scratch/` and tool caches in the worktree you worked in. Never touch `.git`.
5. **Pre-flight guard:** the test suite refuses to start if gitignored scratch in the checkout exceeds 50,000 files. Keep that guard in place.

## Product rules that affect storage (enforced in code)

- Uploaded originals are stored content-addressed in a fan-out tree (`media/ab/cd/<sha256>`), never in one flat directory.
- Derived variants (thumbnails, transcodes) are deterministic from (hash, preset), each tracked by a DB row, so orphans can be swept with a query instead of a tree walk.
- Sessions and caches live in the DB or in memory, never one file per item. Any file cache has a TTL sweeper and a hard cap.
- Media jobs write to a per-job temp dir that is removed in `finally`. A periodic sweep removes anything stale left by crashed jobs.
- Hard caps on total media bytes and file count fail loudly before a write.
