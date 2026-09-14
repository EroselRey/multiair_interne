# Plan — Page « Scénarios Make » dans le portail interne Multiair France

Scénarios couverts : Répondeur IA, Chatbot Claire v11, Claire ADV (orchestrateur
équipements + maintenance), CSO devis.

Objectif : remplacer les Google Sheets alimentés par les scénarios Make par une page
unique `calculateurs/interne/scenarios.html` (un onglet par scénario, tableau de bord,
statistiques, historique, gestion), puis supprimer les Google Sheets.

## 1. État des lieux (analyse des blueprints Make, 14/09/2026)

### 1.1 Répondeur IA (4 scénarios actifs + 1 inactif)
Google Sheet `1pIDgoA02KAQnFp_fVg63cXwI0vATXxQ4qfkdKVIOt1s`.

| Scénario | ID | Rôle | Sheets |
|---|---|---|---|
| Répondeur IA V2 | 9582857 | Webhook VAPI fin d'appel → IA classe service/urgence/canal → WhatsApp template + fiche | addRow QUALIFICATION_EN_COURS, SAV, COMMERCIAL, FINANCE, SUIVI_WHATSAPP ; filterRows DISTRIBUTEUR, QUALIFICATION_EN_COURS ; updateCell N |
| Répondeur IA V3 — Suivi WhatsApp | 9583172 | Réponse WhatsApp du client → Claire valide/complète la fiche → transmission service | filterRows QUALIFICATION_EN_COURS ; updateCell C,D,K,N,O,P,Q ; addRow SAV/COMMERCIAL/FINANCE ; filterRows DISTRIBUTEUR |
| Aiguilleur WhatsApp v2 | 9583010 | Webhook Meta → route vers Répondeur IA ou Prime CEE | filterRows QUALIFICATION_EN_COURS + `Leads WCF` (autre Sheet, autre projet) |
| Relance 10 min | 9791097 | Toutes les 5 min : fiches « En attente » > 10 min → transmission par défaut | filterRows QUALIFICATION_EN_COURS ; addRow SAV/COMMERCIAL/FINANCE ; updateCell N |
| Répondeur IA (v1) | 9487886 | Ancienne version | inactif, à supprimer |

Colonnes QUALIFICATION_EN_COURS (A→Q) : tel_norm, service, societe, contact, marque, modele,
numero_serie, type_panne, besoin_commercial, reference_facture, resume, justification_urgence,
date, statut, derniere_reponse_ia, email, departement.
Statuts : En attente / Urgent / Transmis / Traite / Transmis (sans réponse WhatsApp).

Onglets métier : SAV (A→L), COMMERCIAL (A→J), FINANCE (A→H) : date, priorité, société,
contact, tel, [marque, modèle, n° série, type panne | besoin commercial | réf facture],
résumé, justification urgence, [n° compte distributeur].
DISTRIBUTEUR : table de référence (col D = société → commercial, n° compte, extra).

Points d'attention :
- Double relance 10 min (sleep dans V2 **et** scénario 9791097) → risque de double transmission.
- Token Meta WhatsApp en clair dans les blueprints (V2, V3).
- Recherche par téléphone sous 3 formats (33…, +33…, 0…) : à normaliser côté API.

### 1.2 Chatbot Multiair France — Claire v11 (9295374, actif)
Google Sheet `1axsxoEsXaFjexZl2UvPk0Wo8vBdYvuKt2R9vJvyHIFI`.
- addRow « Feuille 2 » à chaque message : session_id, date, message, réponse IA, page_url.
- filterRows « Routage » : catégorie → dest_to, dest_cc, libellé service (config).
- addRow « Feuille 1 » sur lead : societe, nom, prenom, email, telephone, date, besoin_resume,
  (vide), statut, marque_orientee, type_interlocuteur, departement, (vide), a_verifier,
  categorie, dest_libelle, dest_to.
- Autres sorties : mail interne, mail visiteur, SMS Brevo, réponse `{"reply": …}` au widget.
- Le log conversation est **avant** la réponse au widget : la latence de l'écriture s'ajoute
  au temps de réponse du chatbot. L'API doit répondre vite (< 200 ms).
- Le lien « Voir le CRM complet » du mail interne pointe sur le Sheet → à remplacer par la page.

### 1.3 Claire ADV — Orchestrateur équipements + maintenance (9209946, actif)
Mailhook `service.clients@multiairfrance.store` → filtres anti-automatique (Postmaster, Klaviyo,
Brevo, auto-réponses, mails internes) → agent « Claire » (GPT, sortie texte, 2 outils
Knowledge : catalogue équipements/prix et plans de maintenance) → mail de réponse à
l'expéditeur + copie Cyril. Alerte mail à Cyril en cas d'échec de l'agent.
- **N'écrit dans aucun Google Sheet.** Aucun historique aujourd'hui en dehors des logs Make.
- La réponse IA contient un bloc `[ANALYSE]` (Techno, Critère, Pression, Configuration,
  Cas = DEVIS DIRECT / STANDARD / LEAD / ESCALADE, Options retenues) puis `---` puis le tag
  `[AUTO]` ou `[ESCALADE]` et le mail client. Seul le mail est envoyé ; l'analyse et le tag
  sont perdus après l'exécution.
- Pas de séparation [AUTO]/[ESCALADE] dans le flux : la réponse part au client même en escalade.
- Environ 3 exécutions/heure mais 100 % s'arrêtent au filtre (1 opération) : le trafic réel
  traité est faible (dernier traitement complet visible le 08/09).
- Pour cet onglet il faut **ajouter** un appel HTTP après l'agent (pas en remplacer un) :
  from, sujet, date, message, bloc ANALYSE parsé, tag, mail envoyé, message-id. Recommandé :
  router sur le tag pour ne pas envoyer les [ESCALADE] au client et les mettre « à valider »
  dans la page.
- Le scénario « Prix distributeur Worthington Creyssensac +CTS » (9771233) est un clone
  inactif de celui-ci avec un message de test en dur : à supprimer.

### 1.4 CSO — Analyse des devis et suivi commercial (9775498 + 9776471, actifs)
Google Sheet `1paxA-HmDmXV1HM8SgiOHcL1m7tgkRrn11xwceRoHZLI`, onglets Devis (28 col.) et Lignes (11 col.).
- 9775498 : mailhook cso@ → PDF → IA (schéma : est_un_devis, numero_offre, numero_client,
  date_offre, validite_offre, ref_demande_client, client_nom, contact_client, email_client,
  tel_client, contact_interne, montant_ht, transport, montant_ttc, lignes[]) →
  nouveau devis (addRow Devis + Lignes) ou révision (updateRow, Version+1, remplacement des lignes).
- 9776471 : lun→ven 08:00, lit Devis (Statut ∈ En attente/Relance 1/Relance 2), envoie
  relance J+3/J+7/J+15, met à jour Statut + Relances_envoyees.
- Colonnes gérées à la main : Statut (Gagne/Perdu/Sans suite), Relances_envoyees, Reponse_client
  → deviennent des champs éditables dans la page.
- Anomalie : Version « 1 » interprétée en date par Sheets ; contact_interne extrait mais non stocké.

## 2. Architecture retenue

```
Make (HTTP module, X-Api-Key) ──►  interne/api/index.php  ──►  SQLite (hors webroot)
                                            ▲
Navigateur (scenarios.html) ────────────────┘   (session PHP + mot de passe)
```

- **Backend : PHP + SQLite sur l'hébergement one.com existant** (`calculateurs/interne/api/`).
  Un seul point d'entrée `index.php`, routage par ressource, JSON in/out, clé API pour Make,
  session par mot de passe pour la page. Fichier SQLite dans un dossier protégé (`.htaccess deny`).
- **Frontend : `scenarios.html`** statique, même style que le portail interne, Chart.js (cdnjs),
  5 onglets : Vue d'ensemble, Répondeur IA, Chatbot Claire, Claire ADV, CSO devis.
- **Supabase : non nécessaire.** Volume < 100 lignes/jour, un seul utilisateur, tout reste chez
  l'hébergeur. Bascule vers Supabase uniquement si : (a) `pdo_sqlite` indisponible sur one.com,
  (b) besoin de plusieurs utilisateurs avec comptes distincts, (c) souhait d'une API hors
  hébergeur. Le modèle de données ci-dessous se transpose tel quel en Postgres.

Prérequis à vérifier avant de coder (5 min) : déposer un `phpinfo.php` dans `interne/` et
confirmer PHP ≥ 8.0 et `pdo_sqlite` ; confirmer que `.htaccess` est honoré.

## 3. Modèle de données (SQLite)

Tables transverses
- `executions_log` : id, scenario (repondeur|chatbot|wcf|cso), date, statut (ok|erreur),
  type_evenement, resume, payload_json — historique brut par scénario, alimente les stats
  et l'onglet « Historique » de chaque scénario.
- `parametres` : cle, valeur (ex. délai relance, destinataires alertes).

Répondeur IA
- `rep_fiches` (= QUALIFICATION_EN_COURS) : id, tel_norm, service, societe, contact, marque,
  modele, numero_serie, type_panne, besoin_commercial, reference_facture, resume,
  justification_urgence, statut, derniere_reponse_ia, email, departement, canal, urgence,
  created_at, updated_at, transmis_at.
- `rep_demandes` (= SAV + COMMERCIAL + FINANCE unifiés) : id, fiche_id, service, priorite,
  societe, contact, tel, marque, modele, numero_serie, type_panne, besoin_commercial,
  reference_facture, resume, justification_urgence, compte_distributeur, commercial, source
  (vapi_direct|whatsapp_qualifie|sans_reponse_10min), created_at.
- `distributeurs` (= DISTRIBUTEUR) : id, societe, commercial, compte, extra — éditable dans la page.
- `SUIVI_WHATSAPP` : abandonné (legacy, remplacé par `rep_fiches.statut`).
- `Leads WCF` (Prime CEE, autre projet) : reste dans son Sheet pour l'instant ; l'aiguilleur
  garde ce module. Migration possible dans un 2e temps.

Chatbot Claire
- `chat_messages` : id, session_id, date, message, reponse, page_url.
- `chat_leads` : id, session_id, societe, nom, prenom, email, telephone, date, besoin_resume,
  statut, marque_orientee, type_interlocuteur, departement, a_verifier, categorie,
  dest_libelle, dest_to, suivi (nouveau|contacte|converti|perdu), commentaire.
- `chat_routage` : categorie, dest_to, dest_cc, libelle — éditable dans la page.

Claire ADV
- `adv_demandes` : id, date, message_id, from_email, from_nom, sujet, message, famille
  (equipements|maintenance), cas (DEVIS DIRECT|STANDARD|LEAD|ESCALADE), techno, critere,
  pression, configuration, options_retenues, tag (AUTO|ESCALADE|ERREUR), reponse_ia,
  mail_envoye, envoye_at, statut_suivi (envoye|a_valider|valide|traite), traite_par.

CSO devis
- `cso_devis` : mêmes 28 champs que l'onglet Devis (clé unique n_offre) + id + contact_interne.
- `cso_lignes` : id, devis_id, n_offre, version, poste, reference, designation, quantite,
  prix_unitaire, prix_total_ht, pays_origine, code_douanier, date_traitement.
- `cso_relances` : id, devis_id, numero (1|2|3), date_envoi, destinataire — trace des relances.

## 4. API (contrat pour Make)

Toutes les routes sous `interne/api/index.php?r=…`, header `X-Api-Key`, JSON.

| Route | Méthode | Remplace |
|---|---|---|
| `log` | POST | — (nouveau : trace exécution/erreur, tout scénario) |
| `rep/fiches` | POST | addRow QUALIFICATION_EN_COURS |
| `rep/fiches?tel=…&statut=En attente,Urgent` | GET | filterRows (3 formats de tel gérés côté API) |
| `rep/fiches/{id}` | PATCH | updateCell C,D,K,N,O,P,Q |
| `rep/fiches?statut=En attente&older_than=10` | GET | filterRows relance 10 min |
| `rep/demandes` | POST | addRow SAV / COMMERCIAL / FINANCE |
| `distributeurs?societe=…` | GET | filterRows DISTRIBUTEUR |
| `chat/messages` | POST | addRow Feuille 2 |
| `chat/leads` | POST | addRow Feuille 1 |
| `chat/routage?categorie=…` | GET | filterRows Routage |
| `adv/demandes` | POST | — (nouveau, après l'agent) |
| `adv/demandes/{id}` | PATCH | — (validation manuelle des escalades) |
| `cso/devis?n_offre=…` | GET | makeAPICall B2:B + filterRows Devis |
| `cso/devis` | POST (upsert + lignes) | addRow Devis + Lignes, updateRow, batchUpdate delete |
| `cso/devis?relance_due=1` | GET | filterRows Devis (Statut ∈ …) |
| `cso/devis/{id}` | PATCH | updateRow Statut / Relances_envoyees |

Le POST `cso/devis` fait tout le traitement nouveau/révision/empreinte/version côté API :
7 modules Sheets remplacés par 1 appel HTTP.

## 5. Page `scenarios.html`

Commun à chaque onglet : 4 à 6 cartes KPI, 1 graphique (volume/jour sur 30 j), tableau
filtrable (recherche, statut, période), panneau de détail au clic, export CSV, journal des
exécutions (succès/erreurs) du scénario, bouton « ouvrir dans Make ».

- Vue d'ensemble : état des 4 scénarios (dernière exécution, erreurs 7 j, volume 7 j).
- Répondeur IA : KPI appels / en attente / urgents / transmis / taux de réponse WhatsApp ;
  tableau fiches avec changement de statut ; sous-onglets SAV / Commercial / Finance ;
  gestion de la table Distributeurs.
- Chatbot Claire : KPI conversations / leads / taux de conversion / répartition catégories ;
  liste des conversations (fil complet par session) ; leads avec suivi commercial ;
  gestion de la table de routage.
- Claire ADV : KPI mails traités / famille équipements vs maintenance / AUTO vs ESCALADE /
  cas DEVIS DIRECT-STANDARD-LEAD / erreurs ; liste des demandes avec analyse et réponse IA ;
  file « à valider » pour les escalades ; marquage « traité ».
- CSO devis : KPI devis du mois / montant HT / en attente / relancés / gagnés / perdus /
  taux de transformation ; tableau devis (statut éditable, réponse client, relances) ;
  détail = lignes du devis + historique versions + relances envoyées.

## 6. Phasage

| Phase | Contenu | Livrable |
|---|---|---|
| 0 | Vérif hébergement (PHP, pdo_sqlite, .htaccess), choix mot de passe page | go/no-go PHP vs Supabase |
| 1 | API PHP + schéma SQLite + script d'import CSV des Sheets existants | `interne/api/` |
| 2 | Page `scenarios.html` (5 onglets) branchée sur l'API, données importées | page en ligne |
| 3 | Bascule Chatbot Claire (3 modules → 3 HTTP) en double écriture Sheets + API | scénario 9295374 |
| 4 | Bascule CSO (9775498 + 9776471) | 2 scénarios |
| 5 | Bascule Répondeur IA (V2, V3, Aiguilleur, Relance 10 min) + suppression du sleep de V2 | 4 scénarios |
| 6 | Claire ADV : ajout du log API après l'agent, router AUTO/ESCALADE, suppression du clone 9771233 | scénario 9209946 |
| 7 | 1 à 2 semaines en double écriture, contrôle, puis retrait des modules Sheets et archivage des Sheets | fin |

Les modifications de scénarios se font via l'API Make (blueprints), module par module,
avec sauvegarde du blueprint avant chaque changement.

## 7. Questions à valider

1. Mot de passe unique sur la page (session PHP) ou aucune protection comme les autres pages `interne/` ?
2. Import de l'historique des Sheets (oui recommandé) : export CSV à me fournir ou accès Google Drive.
3. La table `Leads WCF` (Prime CEE) reste dans Google Sheets pour l'instant ?
4. Nom de la page : `scenarios.html` (proposé).
