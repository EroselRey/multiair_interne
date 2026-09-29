# Consigne n° 4 à coller dans Claude Cowork

Colle le texte ci-dessous dans Claude Cowork et joins-lui le nouveau fichier `claire_prompt.txt`.

---

Merci pour le diagnostic, il était exact. Voici le prompt durci en conséquence, pour l'assistante VAPI
« Claire Multiair France » (db2b8de8-8dfe-4356-ac23-4710f06b4093).

Remplace tout le System Prompt par le contenu exact du fichier `claire_prompt.txt` joint. Ne reformule
rien. Enregistre. Ne touche à rien d'autre (outil, voix, modèle, numéro).

Ce qui change par rapport à la v8 :
- une liste de contrôle obligatoire avant d'appeler `envoyer_demande`, même en urgence :
  email demandé ; en technique, code postal répété chiffre par chiffre et mis dans `code_postal`,
  question distributeur / utilisateur posée à chaque fois ;
- Claire ne donne jamais le portable ni l'e-mail d'un collaborateur Multiair ;
- elle ne parle plus de « technicien » : ce sont des responsables techniques.

Compte rendu : la version du prompt après enregistrement.
