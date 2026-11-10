"""Musique de la vidéo Multiair : 120 BPM, Am–F–C–G, synthèse numpy + bruitages calés sur l'animation."""
import json
import sys
import wave

import numpy as np

SR = 44100
BPM = 120
BEAT = 60 / BPM
BAR = 4 * BEAT
cues = json.load(open(sys.argv[1]))
DUREE = cues['duree']
N = int(DUREE * SR) + SR
rng = np.random.default_rng(7)


def mtof(m):
    return 440.0 * 2 ** ((m - 69) / 12)


def zeros():
    return np.zeros((2, N))


def place(bus, sig, t, gain=1.0, pan=0.0):
    """Ajoute un signal mono (ou stéréo) à l'instant t, avec panoramique -1..1."""
    i = int(t * SR)
    if i >= N:
        return
    if sig.ndim == 1:
        l, r = np.cos((pan + 1) * np.pi / 4), np.sin((pan + 1) * np.pi / 4)
        sig = np.vstack([sig * l * 1.414, sig * r * 1.414])
    n = min(sig.shape[1], N - i)
    bus[:, i:i + n] += sig[:, :n] * gain


def tt(d):
    return np.arange(int(d * SR)) / SR


def fft_filtre(x, lo=None, hi=None, ordre=2):
    X = np.fft.rfft(x, axis=-1)
    f = np.fft.rfftfreq(x.shape[-1], 1 / SR)
    g = np.ones_like(f)
    if hi:
        g *= 1 / np.sqrt(1 + (f / hi) ** (2 * ordre))
    if lo:
        g *= 1 / np.sqrt(1 + (lo / np.maximum(f, 1e-3)) ** (2 * ordre))
    return np.fft.irfft(X * g, n=x.shape[-1], axis=-1)


def bruit(d):
    return rng.standard_normal(int(d * SR))


# ------------------------------------------------------------------ instruments
def saw_add(f, d, nh=12, detune=(0, -0.08, 0.08)):
    t = tt(d)
    out = np.zeros_like(t)
    for dt in detune:
        ff = f * 2 ** (dt / 12)
        for h in range(1, nh + 1):
            if ff * h > 9000:
                break
            out += np.sin(2 * np.pi * ff * h * t + h * 1.7 + dt * 9) / h
    return out / len(detune)


def env_adsr(d, a=0.01, dec=0.1, s=0.7, r=0.1):
    n = int(d * SR)
    t = np.arange(n) / SR
    e = np.where(t < a, t / max(a, 1e-4), s + (1 - s) * np.exp(-(t - a) / max(dec, 1e-4)))
    rel = int(r * SR)
    if rel and rel < n:
        e[-rel:] *= np.linspace(1, 0, rel)
    return e


def pad(m, d):
    return saw_add(mtof(m), d, nh=10) * env_adsr(d, a=0.5, dec=0.8, s=0.85, r=0.7)


def pluck(m, d=0.42):
    t = tt(d)
    f = mtof(m)
    idx = 3.2 * np.exp(-t / 0.06)
    s = np.sin(2 * np.pi * f * t + idx * np.sin(2 * np.pi * 2 * f * t))
    return s * np.exp(-t / 0.2) * np.minimum(1, t / 0.002)


def bell(f, d=0.9, tau=0.35):
    t = tt(d)
    s = np.sin(2 * np.pi * f * t) + 0.35 * np.sin(2 * np.pi * f * 2.76 * t) * np.exp(-t / 0.12) + 0.2 * np.sin(2 * np.pi * f * 5.4 * t) * np.exp(-t / 0.05)
    return s * np.exp(-t / tau) * np.minimum(1, t / 0.003)


def basse(m, d=0.24):
    t = tt(d)
    f = mtof(m)
    s = np.sin(2 * np.pi * f * t) + 0.35 * np.sin(2 * np.pi * 2 * f * t) + 0.12 * np.sin(2 * np.pi * 3 * f * t)
    return np.tanh(1.6 * s) * env_adsr(d, a=0.004, dec=0.12, s=0.6, r=0.05)


def lead(m, d):
    t = tt(d + 0.25)
    f = mtof(m)
    vib = 1 + 0.004 * np.sin(2 * np.pi * 5.5 * t) * np.minimum(1, t / 0.3)
    ph = 2 * np.pi * f * np.cumsum(vib) / SR
    s = np.sin(ph) + 0.45 * np.sin(2 * ph) + 0.2 * np.sin(3 * ph) + 0.08 * np.sin(5 * ph)
    return s * env_adsr(d + 0.25, a=0.02, dec=0.25, s=0.65, r=0.25)


def kick(g=1.0):
    t = tt(0.5)
    f = 46 + 110 * np.exp(-t / 0.035)
    ph = 2 * np.pi * np.cumsum(f) / SR
    s = np.sin(ph) * np.exp(-t / 0.3)
    s[:150] += bruit(150 / SR) * 0.25 * np.linspace(1, 0, 150)
    return np.tanh(1.5 * s) * g


def clap():
    d = 0.35
    t = tt(d)
    n = fft_filtre(bruit(d), lo=900, hi=6500)
    e = np.exp(-t / 0.11)
    for k in (0.0, 0.012, 0.024):
        e += 0.6 * np.exp(-np.maximum(t - k, 0) / 0.01) * (t >= k)
    return n * e * 0.5 + 0.3 * np.sin(2 * np.pi * 190 * t) * np.exp(-t / 0.05)


def hat(open_=False):
    d = 0.25 if open_ else 0.08
    t = tt(d)
    n = fft_filtre(bruit(d), lo=7000)
    return n * np.exp(-t / (0.09 if open_ else 0.022))


def crash(d=2.2):
    t = tt(d)
    return fft_filtre(bruit(d), lo=3500, hi=14000) * np.exp(-t / 0.8)


def riser(d=2.0):
    t = tt(d)
    n = bruit(d)
    lo = fft_filtre(n, hi=1200)
    hi_ = fft_filtre(n, lo=2500)
    w = (t / d) ** 2
    sweep = np.sin(2 * np.pi * np.cumsum(220 + 1300 * (t / d) ** 2) / SR) * 0.25
    return ((1 - w) * lo * 0.6 + w * hi_ + sweep * w) * (t / d) ** 1.6


def whoosh(d=0.6, big=False):
    t = tt(d)
    n = fft_filtre(bruit(d), lo=300 if big else 600, hi=5000 if big else 7000)
    e = np.sin(np.pi * np.clip(t / d, 0, 1)) ** (1.5 if big else 2)
    return n * e


def stereo_pan_sweep(sig):
    n = len(sig)
    p = np.linspace(-0.8, 0.8, n)
    return np.vstack([sig * np.cos((p + 1) * np.pi / 4) * 1.414, sig * np.sin((p + 1) * np.pi / 4) * 1.414])


def impact():
    t = tt(1.6)
    f = 38 + 60 * np.exp(-t / 0.08)
    s = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t / 0.7)
    return np.tanh(1.3 * s) + 0.25 * np.concatenate([crash(1.6)[:len(t)]])


def chord_stab(notes, d=0.45):
    t = tt(d)
    s = sum(saw_add(mtof(m), d, nh=14) for m in notes)
    return fft_filtre(s * np.exp(-t / 0.18), hi=2600) * 0.5


# ------------------------------------------------------------------ arrangement
CH = {'Am': (45, [57, 60, 64, 69]), 'F': (41, [57, 60, 65, 69]), 'C': (48, [55, 60, 64, 67]), 'G': (43, [55, 59, 62, 67])}
CYCLE = ['Am', 'F', 'C', 'G']
NB = int(np.ceil(DUREE / BAR))


def accord(b):
    return {37: 'F', 38: 'G', 39: 'C', 40: 'C'}.get(b, CYCLE[b % 4])


def dans(b, *plages):
    return any(a <= b <= z for a, z in plages)


PAD, ARP, BAS, LEAD, DRM, FX = zeros(), zeros(), zeros(), zeros(), zeros(), zeros()
kicks = []
for b in range(NB):
    t0 = b * BAR
    nom = accord(b)
    root, notes = CH[nom]
    # nappe (sauf le temps fort, presque silencieux)
    for m in notes:
        place(PAD, pad(m, BAR + 0.7), t0, 0.09 if b != 32 else 0.035, pan=(m % 5 - 2) * 0.15)
    # arpège en doubles croches
    if dans(b, (2, 31), (33, 38)):
        motif = [0, 1, 2, 3, 2, 1, 2, 3, 0, 1, 2, 3, 2, 3, 1, 2]
        for k, i in enumerate(motif):
            if b == 2 and k % 2:
                continue
            vel = 0.8 if k % 4 == 0 else 0.55
            place(ARP, pluck(notes[i] + 12), t0 + k * BEAT / 4, 0.16 * vel, pan=0.35 if k % 2 else -0.35)
    # basse en croches
    if dans(b, (3, 31), (33, 37)):
        for k in range(8):
            if b in (3, 4, 7) and k % 2:
                continue
            m = root + (12 if k in (3, 7) else 0)
            place(BAS, basse(m, 0.22), t0 + k * BEAT / 2, 0.26 if k % 2 == 0 else 0.2)
    # batterie
    if dans(b, (3, 8), (38, 38)):
        pattern_k = [0, 2]
    elif dans(b, (9, 31), (33, 37)):
        pattern_k = [0, 1, 2, 3]
    else:
        pattern_k = []
    for k in pattern_k:
        kicks.append(t0 + k * BEAT)
        place(DRM, kick(), t0 + k * BEAT, 0.45)
    if dans(b, (13, 31), (33, 37)):
        for k in (1, 3):
            place(DRM, clap(), t0 + k * BEAT, 0.3, pan=0.05)
    if dans(b, (3, 31), (33, 37)):
        seize = dans(b, (18, 26), (33, 36))
        for k in range(16 if seize else 8):
            dt = k * BEAT / (4 if seize else 2)
            off = (k % 2 == 1) if not seize else (k % 4 == 2)
            place(DRM, hat(open_=off and not seize), t0 + dt, (0.12 if off else 0.06) * (1 if not seize else 0.9), pan=0.25)
    # mélodie
    if dans(b, (18, 26), (33, 36)):
        ph = {'Am': [(76, 0, 1), (74, 1, .5), (72, 1.5, .5), (74, 2, 1), (76, 3, 1)],
              'F': [(81, 0, 1.5), (79, 1.5, .5), (76, 2, 1), (72, 3, 1)],
              'C': [(79, 0, 1), (76, 1, 1), (74, 2, .5), (72, 2.5, .5), (74, 3, 1)],
              'G': [(71, 0, 1), (74, 1, 1), (79, 2, 2)]}[nom]
        for m, deb, d in ph:
            place(LEAD, lead(m, d * BEAT * 0.92), t0 + deb * BEAT, 0.075, pan=0.1)

# roulement avant le temps fort (62–64) et montées
for k in range(8):
    place(DRM, clap(), 63.0 + k * BEAT / 8, 0.1 + 0.03 * k)
for t, d in ((34.0, 2.0), (62.0, 2.0), (12.0, 2.0)):
    place(FX, stereo_pan_sweep(riser(d) * 0.22), t)
for b in (3, 7, 13, 18, 23, 27, 33, 37):
    place(DRM, crash(), b * BAR, 0.16)
# temps fort : accords plaqués sur les mots
for t in (64.5, 65.0, 65.5):
    place(DRM, kick(1.1), t, 0.7)
    place(PAD, chord_stab([57, 60, 64, 69]), t, 0.5)
    place(DRM, crash(1.0), t, 0.12)
place(PAD, chord_stab([53, 57, 60, 65], 1.2), 66.0, 0.55)
place(PAD, chord_stab([48, 55, 60, 64, 67], 2.5), 80.0 - 2.0, 0.0)

# ------------------------------------------------------------------ bruitages calés sur l'image
def sfx_son(k):
    if k == 'impact':
        return impact() * 0.6
    if k == 'rise':
        return riser(1.2) * 0.5
    if k == 'riser':
        return riser(0.9) * 0.6
    if k == 'whoosh':
        return stereo_pan_sweep(whoosh(0.6) * 0.5)
    if k == 'swoosh':
        return stereo_pan_sweep(whoosh(0.9, big=True) * 0.7)
    if k == 'swish':
        return stereo_pan_sweep(whoosh(0.35) * 0.4)
    if k == 'pop':
        t = tt(0.09)
        return np.sin(2 * np.pi * np.cumsum(900 * np.exp(-t / 0.03) + 280) / SR) * np.exp(-t / 0.03) * 0.5
    if k == 'ping':
        return bell(1567.98, 1.0, 0.4) * 0.35 + bell(2349.3, 1.0, 0.25) * 0.15
    if k == 'chime':
        s = np.zeros(int(1.6 * SR))
        for i, f in enumerate((1046.5, 1318.5, 1568.0, 2093.0)):
            b = bell(f, 1.2, 0.45) * 0.25
            j = int(i * 0.07 * SR)
            s[j:j + len(b)] += b[:len(s) - j]
        return s
    if k == 'success':
        s = np.zeros(int(1.4 * SR))
        for i, f in enumerate((1046.5, 1318.5, 1568.0, 2093.0)):
            b = bell(f, 1.0, 0.35) * 0.25
            j = int(i * 0.05 * SR)
            s[j:j + len(b)] += b[:len(s) - j]
        return s
    if k == 'notif':
        s = np.zeros(int(0.9 * SR))
        for i, f in enumerate((1318.5, 1760.0)):
            b = bell(f, 0.7, 0.22) * 0.3
            j = int(i * 0.1 * SR)
            s[j:j + len(b)] += b[:len(s) - j]
        return s
    if k in ('blip', 'blip2'):
        t = tt(0.12)
        f = 880 if k == 'blip' else 660
        return np.sin(2 * np.pi * f * t) * np.exp(-t / 0.04) * np.minimum(1, t / 0.004) * 0.35
    if k == 'tick':
        t = tt(0.05)
        return np.sin(2 * np.pi * 2200 * t) * np.exp(-t / 0.012) * 0.3
    if k == 'tap':
        t = tt(0.06)
        return (np.sin(2 * np.pi * 1300 * t) * np.exp(-t / 0.012) + fft_filtre(bruit(0.06), lo=2000) * np.exp(-t / 0.004)) * 0.35
    if k == 'alert':
        s = np.zeros(int(0.5 * SR))
        for i, f in enumerate((987.8, 784.0)):
            t = tt(0.16)
            b = np.sin(2 * np.pi * f * t) * np.exp(-t / 0.08) * 0.3
            j = int(i * 0.13 * SR)
            s[j:j + len(b)] += b
        return s
    if k == 'ring':
        t = tt(0.9)
        alt = (np.floor(t * 24) % 2)
        f = np.where(alt == 0, 1320.0, 1660.0)
        gate = ((t % 0.45) < 0.36).astype(float)
        return np.sin(2 * np.pi * np.cumsum(f) / SR) * gate * np.minimum(1, t / 0.01) * 0.12
    if k == 'pickup':
        t = tt(0.15)
        return (np.sin(2 * np.pi * 160 * t) * np.exp(-t / 0.05) + fft_filtre(bruit(0.15), lo=1500) * np.exp(-t / 0.01)) * 0.3
    if k == 'hit':
        return impact()[: int(0.9 * SR)] * 0.5
    if k == 'shine':
        t = tt(0.8)
        f = 2600 + 3200 * t / 0.8
        return np.sin(2 * np.pi * np.cumsum(f) / SR) * np.sin(np.pi * t / 0.8) ** 2 * 0.08
    return None


for c in cues['sfx']:
    s = sfx_son(c['k'])
    if s is not None:
        place(FX, s, c['t'], 0.9 * c.get('v', 1), pan=float(rng.uniform(-0.25, 0.25)) if s.ndim == 1 else 0)

# ------------------------------------------------------------------ mixage
# compression rythmique : la nappe, la basse et l'arpège respirent avec la grosse caisse
env = np.zeros(N)
tk = np.arange(int(0.35 * SR)) / SR
forme = np.exp(-tk / 0.12)
for t in kicks:
    i = int(t * SR)
    n = min(len(forme), N - i)
    env[i:i + n] = np.maximum(env[i:i + n], forme[:n])
duck = 1 - 0.45 * env
PAD = fft_filtre(PAD, hi=2400) * duck
BAS = fft_filtre(BAS, hi=1800) * (1 - 0.3 * env)
ARP = ARP * (1 - 0.3 * env)

# réverbération (réponse impulsionnelle synthétique)
dr = 1.9
ti = np.arange(int(dr * SR)) / SR
ir = np.vstack([rng.standard_normal(len(ti)), rng.standard_normal(len(ti))]) * np.exp(-ti / 0.55)
ir = fft_filtre(ir, lo=200, hi=6000)
ir /= np.sqrt((ir ** 2).sum(axis=1, keepdims=True))


def reverb(x, wet):
    L = x.shape[1] + ir.shape[1]
    nfft = 1 << (L - 1).bit_length()
    y = np.fft.irfft(np.fft.rfft(x, nfft) * np.fft.rfft(ir, nfft), nfft)[:, :x.shape[1]]
    return x + wet * y


musique = PAD + ARP + BAS + LEAD + DRM
musique = reverb(PAD + ARP + LEAD, 0.25) + BAS + DRM
fx = reverb(FX, 0.18)
mix = fft_filtre(musique * 0.9 + fx * 0.85, lo=32)
# fondu d'entrée et de sortie (l'image s'éteint de 80,7 à 82 s)
t = np.arange(N) / SR
mix *= np.clip(t / 0.05, 0, 1)
mix *= np.clip((DUREE - t) / 1.6, 0, 1) ** 1.5
mix = mix[:, :int(DUREE * SR)]
pic = np.max(np.abs(mix))
mix = np.tanh(1.15 * mix / pic) / np.tanh(1.15) * 0.93
pcm = (mix.T * 32767).astype(np.int16)
with wave.open(sys.argv[2], 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    w.writeframes(pcm.tobytes())
print('ok', DUREE, 's', 'pic', round(float(pic), 3), 'kicks', len(kicks))
