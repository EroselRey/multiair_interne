# Bascule des scénarios Make vers l'API MULTIAIR

État au 30/09/2026. Base API : `https://multiairfrance.store/calculateurs/interne/MULTIAIR/api.php?r=…`
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

## Retrait des Google Sheets (30/09/2026)

Plus aucun scénario actif n'écrit ni ne lit de Google Sheet, à une exception près (voir plus bas).
La plateforme MULTIAIR est désormais la seule source.

| Scénario | ID | Modules retirés | Remplacement |
|---|---|---|---|
| Chatbot Claire v12 | 9295374 | 7 (journal des leads), 12 (journal des conversations) | `chat/leads`, `chat/messages` (déjà en place) |
| CSO analyse des devis | 9775498 | 4 (lecture colonne B) et toute la branche Sheets (router 6 : ajout, mise à jour, lignes, suppression des anciennes lignes) | `cso/devis`, qui gère déjà nouveau devis et révision |
| Prime CEE — WCF A | 9324836 | 4 et 6 (ajout `Leads WCF`) | `cee/leads` ; les filtres « Téléphone fourni » / « Pas de téléphone » passent sur les modules 20 et 21 |
| Prime CEE — WCF B | 9339470 | 7 et 15 (lecture `Leads WCF`), 16 (comptage), 10, 12, 13, 14 (ajouts Conversations/Actions WCF) | 1 appel `cee/leads/find` (module 7) ; filtre « Lead connu sur la plateforme » sur l'agent ; nombre de simulations = `nb_simulations` ; filtres de profil déplacés sur 23, 24, 25 |
| Aiguilleur WhatsApp | 9583010 | 50 (lecture `Leads WCF`), 51 (agrégateur) | 1 appel `cee/leads/find` (module 50) ; mêmes règles de tri (fiche VAPI la plus récente contre lead CEE le plus récent) |

Reprise de l'historique `Leads WCF` : le Sheet ne contenait que 2 lignes (tests du 28/07 et du
02/09). Elles ont été reprises sur la plateforme par un scénario ponctuel, supprimé ensuite.
Aucun formulaire CEE n'avait été reçu depuis l'ajout de l'appel `cee/leads` (14/09), d'où la table
vide avant la reprise.

**Exception conservée : Jeu Mauguière (9356263).** Ce scénario n'a pas d'équivalent sur la
plateforme : son Sheet est son unique stockage, ce n'est pas un doublon. Il reste tel quel.

Les Sheets peuvent être archivés (ne pas les supprimer tout de suite : ils gardent l'historique
d'avant la bascule).

## Relances CSO : envoi par petits lots (05/10/2026, correctif 34)

one.com bloque les envois en rafale : le lundi 28/09 et le lundi 05/10, le scénario 9776471 s'est
arrêté en erreur après 60 mails envoyés en moins d'une minute, et les relances suivantes sont
restées en attente jusqu'au lendemain.

- `cso/devis/relances_dues` ne renvoie plus qu'un lot (les plus anciennes d'abord). Taille du lot :
  paramètre `cso_relances_par_passage` (15 par défaut), ou `&limit=…` dans l'URL (0 = sans limite).
  La réponse indique `nb` (lot envoyé) et `total` (toutes les relances dues).
- Dans Make, le scénario passe toutes les 30 minutes de 8 h à 11 h, du lundi au vendredi
  (7 passages, soit jusqu'à 105 relances par jour). Un passage sans relance due coûte 1 crédit.

## Boîte service clients : chaque e-mail devient une demande (05/10/2026, correctifs 36 et 37)

Avant : le scénario Claire ADV (9209946) ne savait traiter que les demandes de prix d'équipements et
de maintenance. Un e-mail SAV (ex. « Demande de dépannage urgente » de Veolia, 05/10) partait en
ESCALADE à l'adresse de validation ADV, hors du circuit des demandes : le SAV (Julien Jardin) n'était
pas prévenu.

Maintenant, côté plateforme (`adv/demandes` POST, `ma_email_demande`) :
- l'e-mail reste journalisé comme avant (onglet « Boîte service clients »), Claire ADV continue de
  répondre aux demandes de prix ;
- il devient aussi une demande numérotée, canal « e-mail » : service deviné (panne → SAV, facture → compta,
  candidature → RH, prix/matériel → commerce), téléphone, code postal du site, marque, urgence et société
  lus dans l'e-mail, puis routage par les règles habituelles ;
- l'équipe concernée est prévenue (e-mail par le scénario « Suivi client », SMS pour le SAV), le client
  reçoit son n° et son lien de suivi ;
- une réponse à un de nos e-mails de suivi (« demande n° 63 ») relance la demande existante au lieu d'en créer une ;
- e-mails internes et e-mails écartés par les filtres (RECU) : pas de demande ;
- dans le détail d'un e-mail reçu avant le correctif : aperçu du routage (service, destinataires, règle) et
  deux boutons « Créer et prévenir l'équipe et le client » / « … l'équipe seulement » (correctif 37) ;
- les demandes de pièces (filtres, kits, clapets, entretien…) partent en « devis pièces » et non « devis équipement ».
- interrupteur : paramètre `adv_demandes_auto` (1 par défaut, 0 pour couper).

Scénario Make 9209946 mis à jour le 05/10 (prompt de Claire ADV inchangé, vérifié) :
1. Module 20 `adv/demandes` : `pieces_jointes`, `equipe_par_make` = 1, `ticket_dans_reponse` = 1.
2. Module 30 : e-mail à l'équipe de la demande (`equipe_to`, `equipe_cc`, `equipe_objet`, `equipe_html`), répondre à
   l'expéditeur, **pièces jointes de l'e-mail d'origine**. En cas d'erreur (pièces trop lourdes), module 31 : même
   e-mail sans pièces jointes avec un avertissement.
3. Modules 33-34 : SMS SAV par Brevo (`equipe_sms`, `equipe_sms_texte`).
4. Module 5 (réponse automatique de Claire) : corps = `20.data.mail`, qui se termine par le n° de demande et le lien de suivi.
5. Module 21 (escalade ADV) : seulement si la demande est commerciale (validation d'une proposition de prix).

Pièces jointes gardées sur la plateforme (correctif 38, scénario 9209946 du 05/10) :
- table `rep_pieces`, fichiers dans `data/pieces/` (dossier fermé au web), servis par `api.php?r=rep/demandes/{id}/pieces/{pid}`
  (connexion) ou `demande.php?t=…&piece={pid}` (lien de l'équipe) ; lecture partielle gérée (vidéos sur iPhone) ;
- Make (modules 36-37) envoie chaque pièce jointe de l'e-mail à `rep/demandes/{id}/pieces` (multipart, champ `fichier`),
  sauf les petites images de signature ; l'e-mail à l'équipe garde aussi les pièces jointes ;
- l'équipe peut ajouter photos, vidéos ou documents depuis la plateforme et depuis le lien reçu par e-mail / SMS ;
- limite : paramètre `pieces_max_mo` (250 Mo) et limites PHP du serveur (`upload_max_filesize`, `post_max_size`) ;
  un fichier refusé reste listé avec la raison, pour aller le chercher dans l'e-mail d'origine.
- Service « RH » ajouté dans la fiche d'une personne (Équipe et accès).
- Correctif 39 : « RH » devient un service de demande à part entière (candidatures, recrutement : chat, e-mail,
  téléphone), avec l'accès « Service RH » (ne voit que les demandes RH) et le filtre RH dans les listes.
  Les demandes de recrutement déjà classées « à orienter » passent en RH au premier chargement.

Reclassement des e-mails déjà reçus (05/10) :
- demandes créées : Veolia n° 86 (SAV urgent, Julien Jardin, client prévenu), ETE FLOEX n° 87, Richardson n° 88,
  Vasconcelos n° 89, TEC-ASI n° 90, Valorys n° 91 (équipe prévenue, client non prévenu) ;
- rattachés à la demande déjà ouverte par le chatbot : candidature → n° 85, Rubix → n° 80, Quickparts → n° 78 ;
- laissés tels quels : e-mails internes (tests, réaction Teams), Joozeo et TG Filter (fournisseurs).

## Reste à faire

1. Supprimer le scénario 9771233 (clone inactif de Claire ADV avec un message de test en dur).
2. Sortir le jeton WhatsApp des blueprints (aujourd'hui en clair dans plusieurs scénarios).
