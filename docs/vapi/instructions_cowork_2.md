# Consigne n° 2 à coller dans Claude Cowork

Colle le texte ci-dessous dans Claude Cowork et joins-lui le nouveau fichier `claire_prompt.txt`
(il remplace celui d'hier).

---

Merci pour ton compte rendu. Deux corrections sur la même assistante VAPI
(db2b8de8-8dfe-4356-ac23-4710f06b4093, « Claire Multiair France »).

**Étape 1 — Ajouter un paramètre à l'outil `envoyer_demande`.**
Ne supprime et ne renomme rien. Ajoute :

| Nom | Type | Valeurs permises | Obligatoire | Description à saisir |
|---|---|---|---|---|
| `type_interlocuteur` | string | `distributeur`, `utilisateur_final`, `installateur`, `particulier` | non | Qui appelle : un distributeur qui revend nos marques, ou un client direct (utilisateur final, installateur, particulier). Décide qui rappelle en SAV. |

Enregistre l'outil.

**Étape 2 — Remplacer le prompt système.**
Remplace tout le System Prompt par le contenu exact du fichier `claire_prompt.txt` joint (nouvelle
version, alignée sur les champs réels de l'outil : le nom de la société va dans `distributeur`, les
marques s'écrivent comme dans la liste de l'outil, la marque tierce et les informations manquantes
vont dans `resume`). Ne reformule rien. Enregistre.

**Étape 3 — Ne touche à rien d'autre.**

**Étape 4 — Compte rendu.**
Confirme la liste complète des paramètres de `envoyer_demande` et la version du prompt.
