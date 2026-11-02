"""Draw the app icons (amber tile + white flame, same colours as the theme). Run: python tools/make_icons.py"""
import math
import os

from PIL import Image, ImageDraw

AMBER = (217, 122, 34, 255)
CREAM = (251, 236, 210, 255)
WHITE = (255, 255, 255, 255)
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "assets")


def flame(cx, cy, h, w, lean=0.08, steps=240):
    """Teardrop pointing up, slightly leaning, like a flame."""
    pts = []
    for i in range(steps):
        t = 2 * math.pi * i / steps
        x = math.sin(t) * (math.sin(t / 2) ** 1.6)
        y = math.cos(t)
        x += lean * (y + 1) ** 2 * 0.5          # tip leans to one side
        pts.append((cx + x * w / 2, cy - y * h / 2))
    return pts


def icon(size, maskable=False, radius_ratio=0.22):
    s = size * 4  # draw large, then shrink for smooth edges
    img = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    if maskable:
        d.rectangle([0, 0, s, s], fill=AMBER)
        scale = 0.62
    else:
        d.rounded_rectangle([0, 0, s - 1, s - 1], radius=int(s * radius_ratio), fill=AMBER)
        scale = 0.74
    h = s * scale
    cx, cy = s / 2, s / 2 + h * 0.02
    d.polygon(flame(cx, cy, h, h * 0.78), fill=WHITE)
    d.polygon(flame(cx + h * 0.02, cy + h * 0.2, h * 0.5, h * 0.36, lean=-0.05), fill=CREAM)
    return img.resize((size, size), Image.LANCZOS)


if __name__ == "__main__":
    icon(192).save(os.path.join(OUT, "icon-192.png"))
    icon(512).save(os.path.join(OUT, "icon-512.png"))
    icon(512, maskable=True).save(os.path.join(OUT, "icon-maskable-512.png"))
    flat = Image.new("RGBA", (180, 180), AMBER)
    flat.alpha_composite(icon(180, radius_ratio=0))
    flat.convert("RGB").save(os.path.join(OUT, "icon-180.png"))
    print("icons written to", OUT)
