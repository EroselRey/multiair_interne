# Décrocher directement par « Bonjour Monsieur Mortier » (réglage VAPI, facultatif)

Sans ce réglage, Claire dit d'abord son accueil habituel, puis salue l'appelant par son nom dès sa
première réponse. Avec ce réglage, elle décroche directement par :
« Bonjour Monsieur Mortier, ici Claire, de Multiair France. Que puis-je faire pour vous ? »
Un appelant inconnu garde l'accueil habituel.

À faire dans le tableau de bord VAPI, sur le numéro de téléphone de Claire (Phone Numbers) :
1. Server URL : https://multiairfrance.store/calculateurs/interne/MULTIAIR/api.php?r=clients/vapi
2. Header : X-Api-Key, avec la même clé que l'outil identifier_client.
3. Assistant : ne plus choisir d'assistante fixe sur le numéro (sinon VAPI n'interroge pas la
   plateforme). C'est la plateforme qui répond « Claire » à chaque appel.
4. Fallback Destination : un numéro qui sonne chez vous, et qui ne renvoie pas vers Claire. Il sert
   seulement si la plateforme ne répond pas.

Pour revenir en arrière : remettre l'assistante Claire sur le numéro. Rien d'autre à défaire.
