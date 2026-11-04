import sys
from PIL import Image, ImageDraw, ImageFont
S = 3; W, H = 1700*S//2, 640*S//2
img = Image.new("RGB", (W, H), "white"); d = ImageDraw.Draw(img)
F = "/usr/share/fonts/truetype/liberation/LiberationSans-"
f = ImageFont.truetype(F+"Regular.ttf", 22*S//2); fb = ImageFont.truetype(F+"Bold.ttf", 23*S//2)
fs = ImageFont.truetype(F+"Regular.ttf", 19*S//2)
def s(v): return int(v*S/2)
def box(x, y, w, h, txt, fill, outline, font=f, color="black"):
    d.rounded_rectangle([s(x), s(y), s(x+w), s(y+h)], radius=s(8), fill=fill, outline=outline, width=s(2))
    lines = txt.split("\n"); lh = font.size + s(3); ty = s(y + h/2) - lh*len(lines)//2
    for i, l in enumerate(lines):
        tw = d.textlength(l, font=font); d.text((s(x+w/2) - tw/2, ty + i*lh), l, font=font, fill=color)
cx, cy = 850, 360
# Fliege (Bow-Tie-Fläche)
d.polygon([(s(170), s(170)), (s(cx), s(cy)), (s(170), s(550))], fill="#EAF1FB")
d.polygon([(s(1530), s(170)), (s(cx), s(cy)), (s(1530), s(550))], fill="#FDEDEA")
# Gefahr
box(cx-170, 15, 340, 70, "GEFAHR\n(z. B. Gefahrgut / Zugfahrt)", "#FFF4CC", "#B8860B", fb)
d.line([s(cx), s(80), s(cx), s(cy-70)], fill="#B8860B", width=s(3))
ys = [190, 335, 480]
for i, y in enumerate(ys):
    box(5, y-40, 165, 80, f"Ursache /\nBedrohung {i+1}", "#DCE8F7", "#2F5597")
    box(1530, y-40, 165, 80, f"Konsequenz {i+1}", "#F9D9D2", "#A23B2A")
    d.line([s(170), s(y), s(cx-80), s(cy)], fill="#2F5597", width=s(3))
    d.line([s(cx+80), s(cy), s(1530), s(y)], fill="#A23B2A", width=s(3))
    for k, fx in enumerate((0.32, 0.62)):
        x = 170 + (cx-80-170)*fx; yy = y + (cy-y)*fx
        d.rectangle([s(x-9), s(yy-20), s(x+9), s(yy+20)], fill="#2F5597")
        x2 = cx+80 + (1530-cx-80)*fx; yy2 = cy + (y-cy)*fx
        d.rectangle([s(x2-9), s(yy2-20), s(x2+9), s(yy2+20)], fill="#A23B2A")
# Top-Ereignis
d.ellipse([s(cx-85), s(cy-65), s(cx+85), s(cy+65)], fill="#C00000", outline="#7F0000", width=s(3))
for i, l in enumerate(["TOP-", "EREIGNIS"]):
    tw = d.textlength(l, font=fb); d.text((s(cx) - tw/2, s(cy-20) + i*(fb.size+s(4))), l, font=fb, fill="white")
# Eskalationsfaktor-Beispiel
d.rectangle([s(450), s(560), s(468), s(600)], fill="#2F5597")
d.text((s(480), s(568)), "präventive Barriere", font=fs, fill="#2F5597")
d.rectangle([s(1010), s(560), s(1028), s(600)], fill="#A23B2A")
d.text((s(1040), s(568)), "mitigierende Barriere", font=fs, fill="#A23B2A")
d.text((s(30), s(110)), "verhindern das Top-Ereignis  ►", font=fs, fill="#2F5597")
tx = "►  begrenzen die Folgen"; d.text((s(1670) - d.textlength(tx, font=fs), s(110)), tx, font=fs, fill="#A23B2A")
img.save(sys.argv[1])
