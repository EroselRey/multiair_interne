# Consigne n° 7 à coller dans Claude Cowork

Colle le texte ci-dessous dans Claude Cowork et joins-lui le nouveau fichier `claire_prompt.txt`.

---

Pour l'assistante VAPI « Claire Multiair France » (db2b8de8-8dfe-4356-ac23-4710f06b4093) :
remplace tout le System Prompt par le contenu exact du fichier `claire_prompt.txt` joint. Ne
reformule rien. Enregistre et publie. Ne touche à rien d'autre (outils, voix, modèle, numéro).

Ce qui change, dans la partie « Reconnaissance de l'appelant » :
- Si le client a une demande encore ouverte, Claire lui dit qu'elle est toujours ouverte, lui
  donne son statut et le jour/heure du rappel prévu (en s'excusant si ce rappel est dépassé), et
  relance l'équipe. Le résumé commence par « Relance de la demande n° X. » : la plateforme met à
  jour la demande existante au lieu d'en créer une nouvelle.
- Si la demande a été clôturée récemment et que c'est le même problème, elle est rouverte.

Compte rendu : la version du prompt après enregistrement.
