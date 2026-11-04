"""Generate Edict's brand SVGs (seal mark, favicon, banner).

    python assets/brand/src/generate.py

Writes edict-mark.svg, favicon.svg and edict-banner.svg into assets/brand/.
Run assets/brand/src/render.py afterwards to produce the PNG/ICO files.
"""
import math
import random
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent

WAX_HI, WAX, WAX_LO, WAX_DEEP = '#C7353A', '#9E1F25', '#6E1015', '#4A0A0E'
GOLD, GOLD_LO = '#D9B45A', '#A8843A'
INK, INK_2 = '#15131D', '#221E2E'
PARCH = '#F1E6CF'


def blob(cx, cy, r, wobble, lobes, seed):
    """Closed smooth path for an irregular wax-seal edge."""
    rnd = random.Random(seed)
    n = lobes
    pts = []
    phase = [rnd.uniform(0, math.tau) for _ in range(3)]
    for i in range(n):
        a = math.tau * i / n
        rr = r * (1 + wobble * (0.55 * math.sin(3 * a + phase[0]) + 0.3 * math.sin(7 * a + phase[1])
                                + 0.25 * math.sin(11 * a + phase[2])) + rnd.uniform(-wobble, wobble) * r * 0.004)
        pts.append((cx + rr * math.cos(a), cy + rr * math.sin(a)))
    # Catmull-Rom -> cubic Bezier
    d = f'M{pts[0][0]:.1f},{pts[0][1]:.1f}'
    for i in range(n):
        p0, p1, p2, p3 = pts[i - 1], pts[i], pts[(i + 1) % n], pts[(i + 2) % n]
        c1 = (p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
        c2 = (p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
        d += f' C{c1[0]:.1f},{c1[1]:.1f} {c2[0]:.1f},{c2[1]:.1f} {p2[0]:.1f},{p2[1]:.1f}'
    return d + 'Z'


def letter_e(x, y, h):
    """A serif capital E as one path, top-left at (x, y), height h."""
    s = h / 100  # design units: 100 tall, ~70 wide
    def r(px, py, w, hh):
        return f'M{x+px*s:.1f},{y+py*s:.1f}h{w*s:.1f}v{hh*s:.1f}h{-w*s:.1f}Z'
    parts = [
        r(12, 0, 17, 100),       # stem
        r(0, 0, 66, 11),         # top arm + left serif
        r(0, 89, 70, 11),        # bottom arm + left serif
        r(12, 44, 42, 10),       # middle arm
        r(58, 0, 8, 26),         # top arm end serif
        r(62, 72, 8, 28),        # bottom arm end serif
        r(48, 36, 6, 26),        # middle serif
    ]
    return ' '.join(parts)


def crown(cx, y, w):
    """Three-point crown, base centred at cx, top at y."""
    s = w / 100
    def p(px, py):
        return f'{cx + (px - 50) * s:.1f},{y + py * s:.1f}'
    body = (f'M{p(4,58)} L{p(0,10)} L{p(27,36)} L{p(50,0)} L{p(73,36)} L{p(100,10)} L{p(96,58)} Z '
            f'M{p(4,64)} H{cx + 46 * s:.1f} V{y + 76 * s:.1f} H{cx - 46 * s:.1f} Z')
    jewels = ''.join(f'<circle cx="{cx + (jx - 50) * s:.1f}" cy="{y + jy * s:.1f}" r="{5.5 * s:.1f}"/>'
                     for jx, jy in ((0, 10), (50, 0), (100, 10)))
    return body, jewels


def seal(cx, cy, r, detail=True, uid='s'):
    """SVG group for the wax seal. detail=False gives the favicon-simple version."""
    edge = blob(cx, cy, r, 0.035, 96, seed=23)
    inner_r = r * 0.74
    defs = f'''
  <radialGradient id="{uid}wax" cx="38%" cy="32%" r="75%">
    <stop offset="0" stop-color="{WAX_HI}"/><stop offset="0.55" stop-color="{WAX}"/><stop offset="1" stop-color="{WAX_LO}"/>
  </radialGradient>
  <radialGradient id="{uid}well" cx="60%" cy="65%" r="70%">
    <stop offset="0" stop-color="{WAX}"/><stop offset="1" stop-color="{WAX_LO}"/>
  </radialGradient>'''
    g = [f'<path d="{edge}" fill="url(#{uid}wax)"/>']
    if detail:
        g.append(f'<circle cx="{cx}" cy="{cy}" r="{inner_r:.1f}" fill="url(#{uid}well)"/>')
        # embossed rim: light top-left, dark bottom-right
        g.append(f'<circle cx="{cx}" cy="{cy}" r="{inner_r:.1f}" fill="none" stroke="{WAX_DEEP}" stroke-width="{r*0.035:.1f}" transform="translate({r*0.012:.1f} {r*0.012:.1f})" opacity=".7"/>')
        g.append(f'<circle cx="{cx}" cy="{cy}" r="{inner_r:.1f}" fill="none" stroke="{WAX_HI}" stroke-width="{r*0.02:.1f}" transform="translate({-r*0.01:.1f} {-r*0.01:.1f})" opacity=".8"/>')
        # dotted ring
        dots = ''.join(f'<circle cx="{cx + r*0.86*math.cos(a):.1f}" cy="{cy + r*0.86*math.sin(a):.1f}" r="{r*0.018:.1f}"/>'
                       for a in (math.tau * i / 48 for i in range(48)))
        g.append(f'<g fill="{WAX_LO}" opacity=".85">{dots}</g>')
        eh = r * 0.62
        ex, ey = cx - eh * 0.35, cy - eh * 0.36
        cw = r * 0.44
        cbody, cjewels = crown(cx, cy - r * 0.62, cw)
        for dx, col, op in ((r*0.018, WAX_DEEP, .75), (-r*0.012, WAX_HI, .9)):
            g.append(f'<g transform="translate({dx:.1f} {dx:.1f})" fill="{col}" opacity="{op}">'
                     f'<path d="{letter_e(ex, ey + r*0.08, eh)}"/><path d="{cbody}"/>{cjewels}</g>')
        g.append(f'<g fill="{WAX}"><path d="{letter_e(ex, ey + r*0.08, eh)}"/><path d="{cbody}"/>{cjewels}</g>')
    else:
        eh = r * 0.95
        ex, ey = cx - eh * 0.35, cy - eh * 0.5
        g.append(f'<path d="{letter_e(ex + r*0.03, ey + r*0.03, eh)}" fill="{WAX_DEEP}" opacity=".8"/>')
        g.append(f'<path d="{letter_e(ex, ey, eh)}" fill="{PARCH}"/>')
    return defs, '\n  '.join(g)


def write(name, svg):
    (OUT / name).write_text(svg.strip() + '\n', encoding='utf-8')
    print('wrote', OUT / name)


def mark():
    defs, body = seal(256, 256, 236, uid='m')
    write('edict-mark.svg', f'''
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
<title>Edict</title>
<defs>{defs}</defs>
  {body}
</svg>''')


def favicon():
    defs, body = seal(32, 32, 31, detail=False, uid='f')
    write('favicon.svg', f'''
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
<defs>{defs}</defs>
  {body}
</svg>''')


def banner():
    W, H = 1280, 640
    defs, body = seal(352, 320, 196, uid='b')
    shadow = blob(352 + 10, 320 + 16, 196, 0.035, 96, seed=23)
    tx = 600
    write('edict-banner.svg', f'''
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="{W}" height="{H}">
<title>Edict: a CMS for one sovereign</title>
<defs>{defs}
  <radialGradient id="bg" cx="30%" cy="45%" r="85%">
    <stop offset="0" stop-color="{INK_2}"/><stop offset="1" stop-color="{INK}"/>
  </radialGradient>
  <linearGradient id="gold" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#EBCB7A"/><stop offset="1" stop-color="{GOLD_LO}"/>
  </linearGradient>
  <filter id="soft" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="14"/></filter>
</defs>
  <rect width="{W}" height="{H}" fill="url(#bg)"/>
  <rect x="28" y="28" width="{W-56}" height="{H-56}" fill="none" stroke="{GOLD}" stroke-opacity=".55" stroke-width="2"/>
  <rect x="38" y="38" width="{W-76}" height="{H-76}" fill="none" stroke="{GOLD}" stroke-opacity=".3" stroke-width="1"/>
  <path d="{shadow}" fill="#000" opacity=".5" filter="url(#soft)"/>
  {body}
  <g font-family="'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif">
    <text x="{tx}" y="318" font-size="176" fill="url(#gold)" letter-spacing="4">Edict</text>
    <rect x="{tx+6}" y="350" width="440" height="2" fill="{GOLD}" opacity=".7"/>
    <circle cx="{tx+226}" cy="351" r="6" fill="{INK_2}" stroke="{GOLD}" stroke-width="2"/>
    <text x="{tx+6}" y="410" font-size="34" font-style="italic" fill="{PARCH}">A CMS for one sovereign.</text>
    <text x="{tx+8}" y="466" font-size="21" fill="{GOLD}" letter-spacing="7" opacity=".9">PUBLISH · MODERATE · RULE</text>
  </g>
</svg>''')


if __name__ == '__main__':
    mark()
    favicon()
    banner()
