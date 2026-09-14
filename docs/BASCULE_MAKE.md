# Bascule des scénarios Make vers l'API MULTIAIR

État au 14/09/2026. Base API : `https://multiairfrance.store/calculateurs/interne/MULTIAIR/api.php?r=…`
(header `X-Api-Key`). Chaque blueprint d'origine a été sauvegardé avant modification.

## Modes de bascule

- **Double écriture** : les modules Google Sheets restent en place, les appels API sont ajoutés
  à côté. Aucun risque de perte : on compare les deux pendant la période de contrôle.
- **Bascule directe** : les modules Google Sheets sont remplacés par des appels API. Retenu quand
  le scénario lit ses propres données (le Sheet devient inutilisable en parallèle).

## Scénarios

| Scénario | ID | Mode | Ce qui change |
|---|---|---|---|
| Chatbot Claire v11 | 9295374 | double écriture | log conversation et lead envoyés aussi à l'API ; mail interne, accusé de réception et SMS conditionnés à `nouveau = true` (fin des envois en double) ; lien du mail interne vers MULTIAIR |
| CSO analyse des devis | 9775498 | double écriture | lignes converties en JSON puis 1 appel `cso/devis` remplaçant à terme 7 modules Sheets |
| CSO relances | 9776471 | directe | source = `cso/devis/relances_dues` ; chaque envoi trace `cso/relances` |
| Claire ADV | 9209946 | ajout | journalisation `adv/demandes` ; router AUTO (client) / ESCALADE (validation humaine) ; échec agent tracé en ERREUR |
| Répondeur IA V2 | 9582857 | directe | fiches et demandes via API ; lookup distributeur via API ; **attente 10 min supprimée** (assurée par le scénario de relance) |
| Répondeur IA V3 | 9583172 | directe | recherche de fiche, mises à jour et demandes via API ; 6 `updateCell` remplacés par 1 appel |
| Aiguilleur WhatsApp | 9583010 | partielle | fiche VAPI via API ; `Leads WCF` reste sur Sheets tant que ce Sheet n'est pas partagé |
| Relance 10 min | 9791097 | directe | source = `rep/fiches/find?older_than_min=10` |
| Prime CEE — WCF A | 9324836 | double écriture | lead envoyé aussi à `cee/leads` |
| Prime CEE — WCF B | 9339470 | double écriture | conversation et actions envoyées aussi à `cee/conversations` et `cee/actions` |

## Destinataires des mails

Tous les mails de transmission lisent désormais la table de routage de la page MULTIAIR
(onglets Répondeur IA, Chatbot Claire, Claire ADV). Repli automatique sur
cyril.mortier@airwco.com si la clé est inconnue. Plus aucune adresse en dur à modifier dans Make.

## Point de vigilance immédiat — relances CSO

Les 18 devis importés du Google Sheet sont tous au statut « En attente » avec une date de
relance 1 déjà dépassée (12/09 ou 14/09). Au prochain passage du scénario de relances
(mardi 15/09 à 08:00), **18 mails de relance partiront aux clients réels**, en copie de leur
commercial. Ce comportement existait déjà avant la bascule : l'ancien scénario lisait les mêmes
dates dans le Sheet et aurait envoyé les mêmes mails. Trois options avant demain matin :

1. Laisser partir, si ces relances sont effectivement dues.
2. Repousser les dates ou passer certains devis en « Gagne » / « Perdu » / « Sans suite »
   directement dans l'onglet CSO de la page (le scénario ignore ces trois statuts).
3. Mettre le scénario 9776471 en pause dans Make le temps de trier.

## Incidents du 14/09 et corrections

**Table `routage` absente en production.** La page affichait « no such table: routage » et l'API
répondait en erreur sur les leads du chatbot. La base déposée était restée dans sa version
initiale : la mise à niveau se déclenchait sur la date du fichier `schema.sql`, qui change selon
le mode de transfert FTP. `ma_migrate()` vérifie désormais réellement les tables et colonnes
présentes, crée ce qui manque et reprend l'ancienne table `chat_routage`. Les contrôles
correspondants ont été ajoutés à `check.php`.

**Adresses de routage invalides dans le chatbot.** Les deux premiers leads du 14/09 ont écrit
la valeur `14` dans les colonnes « Service destinataire » et « Envoyé à » du Sheet, et les mails
internes ont échoué (« Invalid email address in parameter to / cc »). En cause, l'expression
`get(14; "1")` des modules 14 et 16 qui renvoyait le nombre 14 au lieu du contenu de la ligne
de routage. Ce défaut datait des modifications du 10/09 et n'avait jamais été déclenché faute
de lead depuis. Correction : les modules 14 et 16 sont supprimés, le routage vient maintenant
de la réponse de `chat/leads`, avec repli sur cyril.mortier@airwco.com si l'API ne répond pas.
Le filtre « Lead exploitable » a été déplacé sur l'appel API.

## Comportement en cas de panne de l'API

Les modules HTTP sont réglés sur « ne pas traiter les codes d'erreur comme des erreurs ».
Conséquence : si l'API répond 404, 401 ou 500 (mauvaise URL, mauvaise clé, bug), le scénario
continue normalement — les Google Sheets sont écrits et les mails partent comme avant, seule
la remontée vers MULTIAIR est perdue. C'est le mode de défaillance le plus probable et il est
sans danger. En revanche, si le serveur est totalement injoignable (panne d'hébergement,
délai de 40 s dépassé), le gestionnaire « Ignore » arrête le traitement de ce message : la
ligne concernée ne sera écrite ni dans MULTIAIR ni dans le Sheet. Risque faible, à surveiller
pendant la période de double écriture en comparant les deux sources.

## Reste à faire

1. Contrôler une à deux semaines que la page et les Google Sheets affichent la même chose.
2. Retirer les modules Google Sheets des scénarios en double écriture, puis archiver les Sheets.
3. Migrer `Leads WCF` (Prime CEE) une fois le Sheet partagé, et brancher l'aiguilleur dessus.
4. Supprimer le scénario 9771233 (clone inactif de Claire ADV avec un message de test en dur).
5. Sortir le jeton WhatsApp des blueprints (aujourd'hui en clair dans 4 scénarios).
