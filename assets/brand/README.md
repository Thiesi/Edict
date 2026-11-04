# Edict brand assets

| File | Size | Use |
|---|---|---|
| `edict-banner.png` | 1280×640 | Top of the repo README; GitHub social preview (Settings → General → Social preview) |
| `edict-banner.svg` | vector | Banner source. Its text uses Palatino Linotype, so treat the PNG as the canonical banner |
| `edict-mark.svg` / `edict-mark-512.png` | vector / 512² | The seal: crown over a serif E. For large icons and the admin header |
| `favicon.svg` | vector | Favicon for modern browsers (`<link rel="icon" type="image/svg+xml">`); simplified seal, no crown |
| `favicon.ico` | 16/32/48 | Legacy favicon (`/favicon.ico`) |
| `apple-touch-icon.png` | 180² | iOS home screen |
| `icon-192.png` | 192² | Web manifest / Android |

Colours: wax `#9E1F25` (highlight `#C7353A`, shadow `#6E1015`), gold `#D9B45A`, ink `#15131D`, parchment `#F1E6CF`.

These are the **project** identity. The public site's design gets its own identity in the design track (concept §7), and may or may not reuse them.

## Regenerating

```
python assets/brand/src/generate.py   # SVGs (geometry and colours live here)
python assets/brand/src/render.py     # PNG/ICO via headless Edge + Pillow, temp files in the system temp dir
```
