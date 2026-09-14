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

## Reste à faire

1. Contrôler une à deux semaines que la page et les Google Sheets affichent la même chose.
2. Retirer les modules Google Sheets des scénarios en double écriture, puis archiver les Sheets.
3. Migrer `Leads WCF` (Prime CEE) une fois le Sheet partagé, et brancher l'aiguilleur dessus.
4. Supprimer le scénario 9771233 (clone inactif de Claire ADV avec un message de test en dur).
5. Sortir le jeton WhatsApp des blueprints (aujourd'hui en clair dans 4 scénarios).
