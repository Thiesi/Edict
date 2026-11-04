"""Render Edict's brand SVGs to PNG/ICO using headless Microsoft Edge + Pillow.

    python assets/brand/src/render.py

Writes edict-banner.png (1280x640, README + GitHub social preview),
edict-mark-512.png, apple-touch-icon.png (180), icon-192.png and favicon.ico
(16/32/48) into assets/brand/. Temp files go to the system temp dir.
"""
import shutil
import subprocess
import tempfile
from pathlib import Path

from PIL import Image

OUT = Path(__file__).resolve().parent.parent
EDGE = r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'


def shot(svg: Path, w: int, h: int, dest: Path, transparent=True) -> None:
    with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp:
        html = Path(tmp) / 'page.html'
        bg = 'transparent' if transparent else '#000'
        html.write_text(f'<!doctype html><html><body style="margin:0;background:{bg}">'
                        f'<img src="{svg.as_uri()}" width="{w}" height="{h}" style="display:block"></body></html>',
                        encoding='utf-8')
        png = Path(tmp) / 'shot.png'
        subprocess.run([EDGE, '--headless=new', '--disable-gpu', '--hide-scrollbars', f'--user-data-dir={tmp}/profile',
                        '--default-background-color=00000000', f'--window-size={w},{h}',
                        f'--screenshot={png}', html.as_uri()], check=True, capture_output=True, timeout=60)
        shutil.copy(png, dest)
    print('wrote', dest)


def main() -> None:
    shot(OUT / 'edict-banner.svg', 1280, 640, OUT / 'edict-banner.png', transparent=False)
    big = OUT / 'edict-mark-512.png'
    shot(OUT / 'edict-mark.svg', 512, 512, big)
    mark = Image.open(big).convert('RGBA')
    for size, name in ((180, 'apple-touch-icon.png'), (192, 'icon-192.png')):
        mark.resize((size, size), Image.LANCZOS).save(OUT / name, optimize=True)
        print('wrote', OUT / name)
    fav = OUT / 'favicon-256.png'
    shot(OUT / 'favicon.svg', 256, 256, fav)
    Image.open(fav).convert('RGBA').save(OUT / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
    fav.unlink()
    print('wrote', OUT / 'favicon.ico')


if __name__ == '__main__':
    main()
