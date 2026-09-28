# Consigne à coller dans Claude Cowork (sur le PC de Cyril)

Colle tout le texte ci-dessous dans Claude Cowork, puis joins-lui le fichier `claire_prompt.txt`.

---

Tu vas modifier l'assistante vocale « Claire » dans VAPI. Je suis connecté à VAPI dans mon navigateur.

Assistante : https://dashboard.vapi.ai/assistants/c4c9886f-0ef4-4645-b22f-da390e5bda51

**Étape 1 — Sauvegarde, avant de toucher à quoi que ce soit.**
Copie le prompt système actuel (System Prompt) et la définition actuelle de l'outil `envoyer_demande`
(tous ses paramètres) dans un fichier texte sur mon bureau : `claire_sauvegarde_AAAA-MM-JJ.txt`.
Ne passe à l'étape 2 qu'une fois ce fichier enregistré.

**Étape 2 — Remplacer le prompt système.**
Remplace tout le System Prompt par le contenu exact du fichier `claire_prompt.txt` joint, sans
reformuler ni corriger. Enregistre (Publish / Save).

**Étape 3 — Compléter l'outil `envoyer_demande`.**
Ouvre l'outil `envoyer_demande` (onglet Tools). Ne supprime et ne renomme aucun paramètre existant.
Ajoute ces trois paramètres, tous facultatifs (non « required ») :

| Nom | Type | Valeurs permises | Description à saisir |
|---|---|---|---|
| `code_postal` | string | — | Code postal à 5 chiffres du site où se trouve la machine (service technique). Sans espace. |
| `nature` | string | `commande_pieces`, `devis_pieces`, `commande_equipement`, `devis_equipement`, `autre` | Service commercial uniquement : ce que veut le client. |
| `type_equipement` | string | `piston`, `autre` | Service technique, marque ABAC uniquement : compresseur à piston ou autre. |

Puis, sur les paramètres existants :
- `marque` : s'il a une liste de valeurs permises (enum), ajoute `ovity` et `fitec`. Sinon, ne change rien.
- `urgence` : remplace sa description par « "true" uniquement pour une panne technique qui arrête la
  production. Toujours "false" pour les services commercial et finance. »
- `type_interlocuteur` : s'il a une liste de valeurs permises, vérifie qu'elle contient
  `distributeur`, `utilisateur_final`, `installateur`, `particulier` ; ajoute celles qui manquent.

Enregistre l'outil.

**Étape 4 — Ne touche à rien d'autre.**
Pas de changement de voix, de modèle, de numéro de téléphone, de serveur (Server URL) ni d'autres
outils.

**Étape 5 — Compte rendu.**
Dis-moi :
1. où est le fichier de sauvegarde ;
2. la liste complète des paramètres de `envoyer_demande` après modification (nom, type, valeurs permises) ;
3. si quelque chose n'a pas pu être fait, et pourquoi.

Copie ce compte rendu dans la conversation Claude Code pour que la suite (Make) soit branchée sur ces
nouveaux champs.
