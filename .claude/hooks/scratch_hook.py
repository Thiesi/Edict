"""Claude Code hooks for Edict disk hygiene (CLAUDE.md, "Disk hygiene").

start: SessionStart. Runs scripts/clean_scratch.py --report from the main
       checkout and warns only when a tree is over the limit. Read-only.
end:   SessionEnd. If the session ended inside a worktree under
       .worktrees/, runs that worktree's scripts/clean_scratch.py, which
       deletes only ignored scratch. Never touches the main checkout.

SessionEnd is used instead of Stop on purpose: Stop fires every turn and
could delete files under a still-running background test run.
Ported from the Blacksite hook. Paths are derived from git, not hard-coded,
so the hook survives the checkout being moved or renamed.
"""
import json
import subprocess
import sys
from pathlib import Path

PY = sys.executable
HERE = Path(__file__).resolve().parents[2]


def main_checkout() -> Path:
    out = subprocess.run(['git', '-C', str(HERE), 'rev-parse', '--path-format=absolute', '--git-common-dir'],
                         capture_output=True, text=True).stdout.strip()
    return Path(out).parent if out else HERE


def start() -> None:
    script = main_checkout() / 'scripts' / 'clean_scratch.py'
    if not script.exists():
        return
    run = subprocess.run([PY, str(script), '--report'], capture_output=True, text=True, timeout=110)
    if run.returncode == 0:
        return
    over = [line.strip() for line in run.stdout.splitlines() if 'OVER' in line]
    text = ('Edict disk hygiene: worktree scratch is over the limit: ' + '; '.join(over)
            + '. Clean your own worktree with `python scripts/clean_scratch.py` and remove merged worktrees '
            '(CLAUDE.md, "Disk hygiene"). Do not delete other sessions\' worktrees.')
    print(json.dumps({'systemMessage': text,
                      'hookSpecificOutput': {'hookEventName': 'SessionStart', 'additionalContext': text}}))


def end() -> None:
    try:
        cwd = Path(json.load(sys.stdin).get('cwd') or '.').resolve()
    except (ValueError, OSError):
        return
    worktrees = (main_checkout() / '.worktrees').resolve()
    if not cwd.is_relative_to(worktrees) or cwd == worktrees:
        return
    tree = worktrees / cwd.relative_to(worktrees).parts[0]
    script = tree / 'scripts' / 'clean_scratch.py'
    if script.exists():
        subprocess.run([PY, str(script)], capture_output=True, timeout=110)


if __name__ == '__main__':
    {'start': start, 'end': end}[sys.argv[1]]()
