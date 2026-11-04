"""Remove a checkout's local scratch, or report scratch across all worktrees.

    python scripts/clean_scratch.py            # clean the checkout/worktree this script is in
    python scripts/clean_scratch.py --report   # file counts for the main checkout and every worktree

Cleaning deletes only ignored, reproducible scratch (see SCRATCH_DIRS and
NESTED_SCRATCH). It never touches .git, .worktrees, dependency dirs
(node_modules, vendor, .venv) or tracked files.
The report never deletes anything. It flags trees over the limit, plus
worktrees with no commits of their own (likely merged), so their owners
can `git worktree remove` them.

Ported from Thiesi/Blacksite (PR #152). See CLAUDE.md, "Disk hygiene".
"""
from __future__ import annotations

import argparse
import os
import shutil
import stat
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_LIMIT = int(os.environ.get('EDICT_SCRATCH_LIMIT', '50000'))

# Top-level scratch dirs, removed wholesale.
SCRATCH_DIRS = ('.scratch', '.pytest_cache', '.phpunit.cache', '.php-cs-fixer.cache', 'coverage')
# Scratch dir names removed wherever they appear (outside SKIP).
NESTED_SCRATCH = ('__pycache__',)
SKIP = {'.git', '.venv', '.worktrees', 'node_modules', 'vendor'}


def count_files(path: Path, cap: int) -> int:
    """Count files under path, stopping early once cap is exceeded."""
    total, stack = 0, [path]
    while stack:
        try:
            with os.scandir(stack.pop()) as it:
                for entry in it:
                    if entry.is_dir(follow_symlinks=False):
                        stack.append(Path(entry.path))
                    else:
                        total += 1
                        if total > cap:
                            return total
        except OSError:
            continue
    return total


def scratch_paths(root: Path) -> list[Path]:
    found = [root / name for name in SCRATCH_DIRS if (root / name).exists()]
    for top, dirs, _ in os.walk(root):
        dirs[:] = [d for d in dirs if d not in SKIP and not (Path(top) == root and d in SCRATCH_DIRS)]
        for d in list(dirs):
            if d in NESTED_SCRATCH or d.endswith('.egg-info'):
                found.append(Path(top) / d)
                dirs.remove(d)
    return found


def remove(path: Path) -> list[str]:
    failed: list[str] = []

    def retry(function, name, _error):
        try:
            os.chmod(name, stat.S_IWRITE)
            function(name)
        except OSError:
            failed.append(name)
    if path.is_dir():
        shutil.rmtree(path, onexc=retry)
    else:
        retry(os.remove, str(path), None)
    return failed


def clean(root: Path) -> int:
    failed: list[str] = []
    for path in scratch_paths(root):
        failed += remove(path)
    left = sum(count_files(root / name, 10**9) for name in SCRATCH_DIRS if (root / name).is_dir())
    print(f'{root}: scratch removed; {left} files left, {len(failed)} paths could not be deleted.')
    for name in failed[:20]:
        print(f'  could not delete: {name}')
    return 1 if failed else 0


def count_tree(tree: Path) -> int:
    """Files in a checkout, leaving out SKIP dirs (.git, deps, nested worktrees)."""
    total = 0
    for entry in os.scandir(tree):
        if entry.name in SKIP:
            continue
        total += count_files(Path(entry.path), 10**9) if entry.is_dir(follow_symlinks=False) else 1
    return total


def git(root: Path, *args: str) -> str:
    return subprocess.run(['git', '-C', str(root), *args], capture_output=True, text=True).stdout.strip()


def main_checkout(root: Path) -> Path:
    return Path(git(root, 'rev-parse', '--path-format=absolute', '--git-common-dir')).parent


def report(root: Path, limit: int) -> int:
    common = main_checkout(root)
    trees = [common] + sorted(p for p in (common / '.worktrees').glob('*') if p.is_dir())
    registered = {Path(line.split(' ', 1)[1]).resolve()
                  for line in git(common, 'worktree', 'list', '--porcelain').splitlines()
                  if line.startswith('worktree ')}
    flagged = 0
    for tree in trees:
        total = count_tree(tree)
        notes = []
        if total > limit:
            notes.append(f'OVER {limit:,}')
        if tree != common and tree.resolve() not in registered:
            notes.append('not a registered worktree')
        elif tree != common and git(tree, 'rev-parse', 'HEAD') and \
                subprocess.run(['git', '-C', str(tree), 'merge-base', '--is-ancestor', 'HEAD', 'origin/main'],
                               capture_output=True).returncode == 0 \
                and not git(tree, 'status', '--porcelain'):
            notes.append('no commits of its own: remove it if its PR has merged and no session is using it')
        flagged += any(note.startswith('OVER') for note in notes)
        print(f'{total:>9,}  {tree.name if tree != common else "(main checkout)"}  {"; ".join(notes)}')
    return 1 if flagged else 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--report', action='store_true', help='report every worktree; delete nothing')
    parser.add_argument('--limit', type=int, default=DEFAULT_LIMIT, help='files per tree before it is flagged')
    args = parser.parse_args(argv)
    return report(ROOT, args.limit) if args.report else clean(ROOT)


if __name__ == '__main__':
    sys.exit(main())
