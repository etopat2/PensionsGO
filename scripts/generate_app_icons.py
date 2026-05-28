from __future__ import annotations

from pathlib import Path
from typing import Iterable

from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageFont


ROOT = Path(__file__).resolve().parents[1]
FRONTEND_ASSETS = ROOT / "frontend" / "assets"
PWA_ASSETS = FRONTEND_ASSETS / "pwa"

MASTER_SIZE = 1024
RADIUS = 224

MAROON = (139, 0, 0)
MAROON_DARK = (90, 0, 0)
BLUE = (0, 51, 102)
BLUE_LIGHT = (32, 81, 139)
GOLD = (212, 175, 55)
GOLD_LIGHT = (242, 210, 92)
IVORY = (247, 241, 227)
WHITE = (255, 252, 246)
INK = (21, 32, 51)


def ensure_dirs() -> None:
    PWA_ASSETS.mkdir(parents=True, exist_ok=True)
    FRONTEND_ASSETS.mkdir(parents=True, exist_ok=True)


def lerp(a: float, b: float, t: float) -> float:
    return a + (b - a) * t


def lerp_color(a: tuple[int, int, int], b: tuple[int, int, int], t: float) -> tuple[int, int, int]:
    return tuple(int(lerp(a[i], b[i], t)) for i in range(3))


def load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        Path("C:/Windows/Fonts/tahomabd.ttf" if bold else "C:/Windows/Fonts/tahoma.ttf"),
        Path("C:/Windows/Fonts/segoeuib.ttf" if bold else "C:/Windows/Fonts/segoeui.ttf"),
        Path("C:/Windows/Fonts/arialbd.ttf" if bold else "C:/Windows/Fonts/arial.ttf"),
    ]
    for candidate in candidates:
        if candidate.is_file():
            try:
                return ImageFont.truetype(str(candidate), size=size)
            except Exception:
                continue
    return ImageFont.load_default()


def draw_centered_text(
    draw: ImageDraw.ImageDraw,
    box: tuple[float, float, float, float],
    text: str,
    font: ImageFont.ImageFont | ImageFont.FreeTypeFont,
    fill: tuple[int, int, int, int] | tuple[int, int, int],
) -> tuple[float, float]:
    left, top, right, bottom = box
    bbox = draw.textbbox((0, 0), text, font=font)
    width = bbox[2] - bbox[0]
    height = bbox[3] - bbox[1]
    x = left + ((right - left) - width) / 2 - bbox[0]
    y = top + ((bottom - top) - height) / 2 - bbox[1]
    draw.text((x, y), text, font=font, fill=fill)
    return (x, y)


def rounded_mask(size: int, radius: int) -> Image.Image:
    mask = Image.new("L", (size, size), 0)
    draw = ImageDraw.Draw(mask)
    draw.rounded_rectangle((0, 0, size - 1, size - 1), radius=radius, fill=255)
    return mask


def make_background(size: int = MASTER_SIZE) -> Image.Image:
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    px = img.load()
    for y in range(size):
        for x in range(size):
            t = (x * 0.58 + y * 0.42) / (size - 1)
            base = lerp_color(MAROON, BLUE, min(max(t, 0.0), 1.0))
            depth = 0.78 + 0.22 * (1 - abs((y / size) - 0.46))
            px[x, y] = (
                int(base[0] * depth),
                int(base[1] * depth),
                int(base[2] * depth),
                255,
            )

    glow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    glow_draw = ImageDraw.Draw(glow)
    glow_draw.ellipse((size * 0.56, size * 0.05, size * 1.02, size * 0.56), fill=(*GOLD_LIGHT, 95))
    glow = glow.filter(ImageFilter.GaussianBlur(radius=size * 0.075))
    img = Image.alpha_composite(img, glow)

    lower_glow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    lower_draw = ImageDraw.Draw(lower_glow)
    lower_draw.ellipse((size * -0.08, size * 0.58, size * 0.42, size * 1.05), fill=(135, 18, 34, 86))
    lower_glow = lower_glow.filter(ImageFilter.GaussianBlur(radius=size * 0.09))
    img = Image.alpha_composite(img, lower_glow)

    vignette = Image.new("L", (size, size), 0)
    vignette_draw = ImageDraw.Draw(vignette)
    vignette_draw.ellipse((size * -0.04, size * -0.05, size * 1.04, size * 1.04), fill=255)
    vignette = ImageChops.invert(vignette.filter(ImageFilter.GaussianBlur(radius=size * 0.09)))
    vignette_rgba = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    vignette_rgba.putalpha(vignette)
    img = Image.alpha_composite(img, vignette_rgba)

    mask = rounded_mask(size, int(size * RADIUS / MASTER_SIZE))
    clipped = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    clipped.paste(img, (0, 0), mask)
    return clipped


def draw_frame(base: Image.Image) -> None:
    draw = ImageDraw.Draw(base)
    size = base.size[0]
    inset = int(size * 0.045)
    draw.rounded_rectangle(
        (inset, inset, size - inset, size - inset),
        radius=int(size * 0.17),
        outline=(*GOLD_LIGHT, 255),
        width=max(3, int(size * 0.014)),
    )
    inner = inset + int(size * 0.025)
    draw.rounded_rectangle(
        (inner, inner, size - inner, size - inner),
        radius=int(size * 0.145),
        outline=(255, 248, 223, 70),
        width=max(2, int(size * 0.004)),
    )


def draw_medallion(
    base: Image.Image,
    *,
    center_x: float = 0.5,
    center_y: float = 0.47,
    radius: float = 0.29,
    shadow: bool = True,
) -> None:
    size = base.size[0]
    cx = size * center_x
    cy = size * center_y
    outer_radius = size * radius
    inner_radius = outer_radius * (136 / 150)

    if shadow:
        overlay = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        draw = ImageDraw.Draw(overlay)
        shadow_box = (
            cx - outer_radius * 1.07,
            cy - outer_radius * 0.92,
            cx + outer_radius * 1.07,
            cy + outer_radius * 1.23,
        )
        draw.ellipse(shadow_box, fill=(8, 16, 26, 90))
        overlay = overlay.filter(ImageFilter.GaussianBlur(radius=size * 0.03))
        base.alpha_composite(overlay)

    draw = ImageDraw.Draw(base)
    outer = (cx - outer_radius, cy - outer_radius, cx + outer_radius, cy + outer_radius)
    inner = (cx - inner_radius, cy - inner_radius, cx + inner_radius, cy + inner_radius)
    draw.ellipse(outer, fill=(*GOLD, 255))
    draw.ellipse(inner, fill=(*WHITE, 255))
    ring_highlight = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    ring_draw = ImageDraw.Draw(ring_highlight)
    ring_draw.ellipse(
        (
            cx - outer_radius * 0.86,
            cy - outer_radius * 0.88,
            cx + outer_radius * 0.86,
            cy + outer_radius * 0.16,
        ),
        fill=(255, 248, 231, 44),
    )
    ring_highlight = ring_highlight.filter(ImageFilter.GaussianBlur(radius=size * 0.02))
    base.alpha_composite(ring_highlight)


def draw_footer_band(base: Image.Image, label: str = "UPS", y_top: float = 0.79, y_bottom: float = 0.885) -> None:
    size = base.size[0]
    band = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(band)
    draw.rounded_rectangle(
        (size * 0.19, size * y_top, size * 0.81, size * y_bottom),
        radius=size * 0.035,
        fill=(18, 34, 59, 165),
        outline=(255, 245, 225, 38),
        width=max(1, int(size * 0.003)),
    )
    font = load_font(int(size * 0.058), bold=True)
    draw_centered_text(
        draw,
        (size * 0.19, size * (y_top + 0.007), size * 0.81, size * (y_bottom - 0.007)),
        label,
        font,
        (255, 247, 230, 235),
    )
    base.alpha_composite(band)


def draw_monogram_emblem(
    base: Image.Image,
    *,
    center_x: float = 0.5,
    center_y: float = 0.47,
    radius: float = 0.29,
) -> None:
    size = base.size[0]
    cx = size * center_x
    cy = size * center_y
    outer_radius = size * radius
    shadow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    shadow_draw = ImageDraw.Draw(shadow)
    font = load_font(int(outer_radius * 0.603), bold=True)
    box = (
        cx - outer_radius * 0.552,
        cy - outer_radius * 0.614,
        cx + outer_radius * 0.552,
        cy - outer_radius * 0.041,
    )
    bbox = shadow_draw.textbbox((0, 0), "PG", font=font)
    tx = box[0] + ((box[2] - box[0]) - (bbox[2] - bbox[0])) / 2 - bbox[0]
    ty = box[1] + ((box[3] - box[1]) - (bbox[3] - bbox[1])) / 2 - bbox[1]
    shadow_offset = outer_radius * 0.017
    shadow_draw.text((tx + shadow_offset, ty + shadow_offset), "PG", font=font, fill=(18, 29, 44, 44))
    shadow = shadow.filter(ImageFilter.GaussianBlur(radius=max(1, int(outer_radius * 0.014))))
    base.alpha_composite(shadow)

    draw = ImageDraw.Draw(base)
    draw.text((tx, ty), "PG", font=font, fill=(*BLUE, 255))
    draw.text((tx, ty), "P", font=font, fill=(*MAROON_DARK, 255))

    draw.rounded_rectangle(
        (
            cx - outer_radius * 0.293,
            cy + outer_radius * 0.103,
            cx + outer_radius * 0.293,
            cy + outer_radius * 0.148,
        ),
        radius=outer_radius * 0.024,
        fill=(*GOLD_LIGHT, 255),
    )
    draw.rounded_rectangle(
        (
            cx - outer_radius * 0.197,
            cy + outer_radius * 0.234,
            cx + outer_radius * 0.197,
            cy + outer_radius * 0.269,
        ),
        radius=outer_radius * 0.017,
        fill=(*BLUE_LIGHT, 235),
    )


def make_centered_emblem_layer(
    *,
    size: int = MASTER_SIZE,
    center_x: float = 0.5,
    center_y: float = 0.47,
    radius: float = 0.29,
) -> tuple[Image.Image, int, int]:
    emblem = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw_monogram_emblem(emblem, center_x=center_x, center_y=center_y, radius=radius)

    bbox = alpha_bbox(emblem)
    if bbox:
        target_cx = size * center_x
        target_cy = size * center_y
        current_cx = (bbox[0] + bbox[2]) / 2
        current_cy = (bbox[1] + bbox[3]) / 2
        offset_x = int(round(target_cx - current_cx))
        offset_y = int(round(target_cy - current_cy))
    else:
        offset_x = 0
        offset_y = 0

    centered = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    centered.alpha_composite(emblem, (offset_x, offset_y))
    return centered, offset_x, offset_y


def make_brand_icon() -> Image.Image:
    base = make_background(MASTER_SIZE)
    draw_frame(base)
    draw_medallion(base)
    centered_emblem, _, _ = make_centered_emblem_layer()
    base.alpha_composite(centered_emblem)
    draw_footer_band(base, "UPS")
    return base


def make_favicon_icon() -> Image.Image:
    base = Image.new("RGBA", (MASTER_SIZE, MASTER_SIZE), (0, 0, 0, 0))
    draw_medallion(base, center_y=0.5, radius=0.36, shadow=False)
    centered_emblem, _, _ = make_centered_emblem_layer(center_y=0.5, radius=0.36)
    base.alpha_composite(centered_emblem)
    return base


def save_png(image: Image.Image, path: Path, size: int) -> None:
    target = image.resize((size, size), Image.Resampling.LANCZOS)
    target.save(path, format="PNG", optimize=True)


def save_ico(image: Image.Image, path: Path, sizes: Iterable[int]) -> None:
    resized = image.resize((256, 256), Image.Resampling.LANCZOS)
    resized.save(path, format="ICO", sizes=[(s, s) for s in sizes])


def alpha_bbox(image: Image.Image, threshold: int = 16) -> tuple[int, int, int, int] | None:
    alpha = image.getchannel("A")
    px = alpha.load()
    width, height = image.size
    min_x = width
    min_y = height
    max_x = -1
    max_y = -1

    for y in range(height):
        for x in range(width):
            if px[x, y] < threshold:
                continue
            if x < min_x:
                min_x = x
            if y < min_y:
                min_y = y
            if x > max_x:
                max_x = x
            if y > max_y:
                max_y = y

    if max_x < min_x or max_y < min_y:
        return None

    return (min_x, min_y, max_x + 1, max_y + 1)


def write_brand_svg(path: Path) -> None:
    _, offset_x, offset_y = make_centered_emblem_layer()
    svg_dx = offset_x * 512 / MASTER_SIZE
    svg_dy = offset_y * 512 / MASTER_SIZE

    svg = f"""<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#8B0000"/>
      <stop offset="72%" stop-color="#003366"/>
      <stop offset="100%" stop-color="#0b2747"/>
    </linearGradient>
    <radialGradient id="glow" cx="72%" cy="22%" r="46%">
      <stop offset="0%" stop-color="#f2d25c" stop-opacity="0.55"/>
      <stop offset="100%" stop-color="#f2d25c" stop-opacity="0"/>
    </radialGradient>
    <filter id="softShadow" x="-30%" y="-30%" width="160%" height="160%">
      <feDropShadow dx="0" dy="9" stdDeviation="12" flood-color="#0d1826" flood-opacity="0.25"/>
    </filter>
  </defs>
  <rect width="512" height="512" rx="112" fill="url(#bg)"/>
  <rect x="24" y="24" width="464" height="464" rx="95" fill="none" stroke="#f2d25c" stroke-width="14"/>
  <rect x="44" y="44" width="424" height="424" rx="82" fill="none" stroke="#fff8df" stroke-opacity="0.28" stroke-width="4"/>
  <circle cx="256" cy="238" r="150" fill="#d4af37" filter="url(#softShadow)"/>
  <circle cx="256" cy="238" r="136" fill="#fffcf6"/>
  <circle cx="256" cy="238" r="150" fill="url(#glow)"/>
  <g transform="translate({svg_dx:.2f} {svg_dy:.2f})">
    <text x="256" y="235" text-anchor="middle" fill="#12223b" font-family="Tahoma, Segoe UI, sans-serif" font-size="94" font-weight="700" letter-spacing="1" opacity="0.2">PG</text>
    <text x="256" y="235" text-anchor="middle" fill="#003366" font-family="Tahoma, Segoe UI, sans-serif" font-size="94" font-weight="700" letter-spacing="1">PG</text>
    <text x="232" y="235" text-anchor="middle" fill="#5a0000" font-family="Tahoma, Segoe UI, sans-serif" font-size="94" font-weight="700">P</text>
    <rect x="213" y="257" width="86" height="9" rx="4.5" fill="#f2d25c"/>
    <rect x="227" y="282" width="58" height="7" rx="3.5" fill="#20518b" opacity="0.9"/>
  </g>
  <rect x="96" y="404" width="320" height="54" rx="18" fill="#12223b" fill-opacity="0.72" stroke="#fff5e1" stroke-opacity="0.22" stroke-width="1.5"/>
  <text x="256" y="441" text-anchor="middle" fill="#fff7e6" font-family="Tahoma, Segoe UI, sans-serif" font-size="28" font-weight="700" letter-spacing="3">UPS</text>
</svg>
"""
    path.write_text(svg, encoding="utf-8")


def main() -> None:
    ensure_dirs()
    brand = make_brand_icon()
    favicon_mark = make_favicon_icon()

    variants_dir = PWA_ASSETS / "variants"
    official_dir = variants_dir / "official"
    product_dir = variants_dir / "product"
    minimal_dir = variants_dir / "minimal"
    for variant_dir in (official_dir, product_dir, minimal_dir):
        variant_dir.mkdir(parents=True, exist_ok=True)

    # Primary/default assignments:
    # - Official: general branding assets
    # - Product: installed app / PWA assets
    # - Minimal: tiny favicon handling
    save_png(brand, FRONTEND_ASSETS / "logo.png", 512)
    save_png(brand, ROOT / "favicon.png", 512)

    save_png(brand, PWA_ASSETS / "icon-512.png", 512)
    save_png(brand, PWA_ASSETS / "icon-192.png", 192)
    save_png(brand, PWA_ASSETS / "apple-touch-icon.png", 180)

    save_ico(favicon_mark, FRONTEND_ASSETS / "favicon.ico", [16, 24, 32, 48, 64, 128, 256])
    save_ico(favicon_mark, ROOT / "favicon.ico", [16, 24, 32, 48, 64, 128, 256])

    save_png(brand, official_dir / "icon-512.png", 512)
    save_png(brand, official_dir / "icon-192.png", 192)
    save_png(brand, product_dir / "icon-512.png", 512)
    save_png(brand, product_dir / "icon-192.png", 192)
    save_png(favicon_mark, minimal_dir / "icon-512.png", 512)
    save_png(favicon_mark, minimal_dir / "icon-192.png", 192)

    write_brand_svg(PWA_ASSETS / "icon.svg")
    write_brand_svg(product_dir / "icon.svg")

    print("Generated app icons:")
    print(f"- official logo: {FRONTEND_ASSETS / 'logo.png'}")
    print(f"- official master png: {ROOT / 'favicon.png'}")
    print(f"- product PWA icon 512: {PWA_ASSETS / 'icon-512.png'}")
    print(f"- product PWA icon 192: {PWA_ASSETS / 'icon-192.png'}")
    print(f"- product apple touch icon: {PWA_ASSETS / 'apple-touch-icon.png'}")
    print(f"- product SVG icon: {PWA_ASSETS / 'icon.svg'}")
    print(f"- minimal favicon: {FRONTEND_ASSETS / 'favicon.ico'}")
    print(f"- minimal root favicon: {ROOT / 'favicon.ico'}")
    print(f"- variant previews: {variants_dir}")


if __name__ == "__main__":
    main()
