# Consigne n° 5 à coller dans Claude Cowork

Colle le texte ci-dessous dans Claude Cowork et joins-lui le nouveau fichier `claire_prompt.txt`.

---

Pour l'assistante VAPI « Claire Multiair France » (db2b8de8-8dfe-4356-ac23-4710f06b4093), deux choses.
Ne touche à rien d'autre (voix, modèle, numéro, outil envoyer_demande).

**1. Créer un outil et l'ajouter à Claire**

- Type : Function (appel d'une adresse web)
- Nom : `identifier_client`
- Description : « Reconnaît l'appelant à partir du numéro de l'appel. À appeler une fois, au tout
  début de l'appel, sans argument. Renvoie ce que Multiair sait déjà de lui. »
- Paramètres : aucun obligatoire. Deux facultatifs de type texte : `telephone` et `email`.
- Adresse du serveur (Server URL) :
  `https://multiairfrance.store/calculateurs/interne/MULTIAIR/api.php?r=clients/vapi`
- En-tête (header) à ajouter : nom `X-Api-Key`, valeur = la clé que Cyril te donne
  (c'est la valeur `api_key` du fichier MULTIAIR/config.php).
- Délai (timeout) : 10 secondes.
- Pas de message à prononcer pendant l'appel de l'outil (laisse les messages « request-start »
  vides) : Claire ne doit pas annoncer qu'elle vérifie.

Ajoute ensuite cet outil à la liste des outils de Claire, à côté de `envoyer_demande`.

**2. Remplacer le System Prompt**

Remplace tout le System Prompt par le contenu exact du fichier `claire_prompt.txt` joint. Ne
reformule rien. Enregistre.

Ce qui change : une nouvelle partie « Reconnaissance de l'appelant ». Claire appelle
`identifier_client` au début de l'appel ; si la personne est connue, elle la salue par son nom et
fait confirmer ce qu'on sait déjà (société, site, matériel, e-mail) au lieu de tout redemander, et
elle signale une demande déjà en cours.

**Compte rendu attendu** : l'identifiant de l'outil créé, la liste des outils de Claire, et la
version du prompt après enregistrement.
