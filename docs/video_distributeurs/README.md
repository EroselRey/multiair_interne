# Vidéo « Vos demandes » pour les distributeurs

Vidéo de présentation (1920 × 1080, 30 images/s, 82 s, avec musique).
Elle montre comment Multiair traite une demande, sans montrer la plateforme.

Le déroulé :

1. **Ouverture** : « Chaque demande compte ».
2. **Contact** : téléphone, e-mail, chat, WhatsApp.
3. **Répondeur IA** : le 09 72 16 39 35, disponible 24 h/24. L'appelant est reconnu, avec ses machines et sa demande en cours. L'assistante relance l'équipe.
4. **Questions** : les bonnes questions sont posées, et la fiche se remplit.
5. **Orientation** : la demande part vers Commerce, SAV ou Finance.
6. **Prise en charge** : par WhatsApp, même sur la route.
7. **Suivi** : le client reçoit un e-mail, un WhatsApp ou un appel.
8. **Temps fort** : « Commerce. SAV. Finance. », puis les bénéfices.
9. **Fin** : « Organisés pour vous répondre plus vite », avec le numéro.

L'exemple est fictif : Ateliers Durand, demandes n° 1239 et 1284. Aucun nom ni numéro de l'équipe n'apparaît.

## Fichiers

| Fichier | Rôle |
|---|---|
| `index.html` | L'animation (GSAP 3.12.5). `window.seekTo(t)` affiche l'image à l'instant t. |
| `sfx_dump.js` | Exporte les bruitages calés sur l'image (`sfx.json`). |
| `gen_music.py` | Musique (120 BPM, Am–F–C–G) et bruitages, synthétisés avec numpy. Produit `musique.wav`. |
| `render.js` | Capture les 2 460 images avec Playwright et les assemble avec la musique (ffmpeg, H.264 + AAC). |
| `preview.js` | Images fixes à des instants donnés : `node preview.js 5 12.8 40`. |

## Refaire la vidéo

Il faut Node, Playwright avec Chromium, ffmpeg, Python 3 et numpy, ainsi que la police Inter installée sur la machine.

```bash
npm install gsap@3.12.5        # index.html charge node_modules/gsap/dist/…
node sfx_dump.js               # → sfx.json
python3 gen_music.py sfx.json musique.wav
node render.js                 # → Multiair_demandes_distributeurs.mp4
```

## Modifier

- **Textes, exemple, numéro** : directement dans le HTML des scènes de `index.html`.
- **Timing** : dans le montage (`// ===== S…`). Les scènes placées après le répondeur IA sont décalées de 12 s (`OFF = 12`).
- **Musique** : après un changement de timing, relancer `sfx_dump.js` puis `gen_music.py`.
