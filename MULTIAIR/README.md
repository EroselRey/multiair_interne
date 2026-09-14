# MULTIAIR — tableau de bord des scénarios Make

Page interne qui remplace les Google Sheets alimentés par les scénarios Make
(Répondeur IA, Chatbot Claire, Claire ADV, CSO devis, Prime CEE).

## Déploiement (hébergement one.com, PHP + SQLite)

1. Déposer le dossier `MULTIAIR` complet dans `calculateurs/interne/` (FileZilla).
   Résultat attendu : `https://multiairfrance.store/calculateurs/interne/MULTIAIR/index.php`
2. Ouvrir `https://…/interne/MULTIAIR/check.php` : tous les contrôles doivent être « OK »
   (PHP ≥ 8, pdo_sqlite, dossier `data/` inscriptible, protection `.htaccess`).
   Si `data/` n'est pas inscriptible : clic droit sur le dossier dans FileZilla → droits 755 (ou 775).
3. Ouvrir `index.php`, se connecter (identifiant `admin`, mot de passe dans `config.php`).
4. Supprimer `check.php` une fois la vérification faite.

L'historique des Google Sheets (Répondeur IA, CRM chatbot, Suivi devis CSO) est déjà
dans `data/multiair.sqlite`. Le fichier `import/historique_….json.done` en est la source.
Pour importer plus tard d'autres données (ex. Leads WCF) : déposer un fichier `.json` dans
`import/` et ouvrir `import.php`.

## Fichiers

| Fichier | Rôle |
|---|---|
| `index.php` | page (login + 6 onglets) |
| `api.php` | API JSON pour Make et pour la page |
| `stats.php` | calcul des indicateurs |
| `lib.php` | fonctions communes (config, base, session, mail) |
| `config.php` | identifiants, clé API Make, SMTP — **privé, jamais dans Git** |
| `config.example.php` | modèle de configuration |
| `schema.sql` | structure de la base (appliquée automatiquement) |
| `check.php` | diagnostic de l'hébergement (à supprimer après) |
| `import.php` | import de fichiers JSON déposés dans `import/` |
| `assets/` | `app.js`, `style.css`, `chart.umd.js` (Chart.js 4.4.1) |
| `data/` | base SQLite, protégée par `.htaccess` |

## Mot de passe perdu

Le mot de passe est lisible dans `config.php` (FileZilla). Le lien « Mot de passe oublié »
de la page de connexion l'envoie aussi à l'adresse `recovery_email` de `config.php`
(par `mail()` PHP, ou par SMTP si la section `smtp` est renseignée).

## API pour Make

Base : `https://multiairfrance.store/calculateurs/interne/MULTIAIR/api.php?r=<route>`
Header obligatoire : `X-Api-Key: <api_key de config.php>` · corps JSON · réponse JSON `{ok: true, …}`.
Pour PATCH/DELETE depuis Make, envoyer un POST avec `"_method": "PATCH"` dans le corps.

| Route | Méthode | Remplace |
|---|---|---|
| `log` | POST `{scenario, statut, type, resume, payload}` | trace d'exécution ou d'erreur |
| `rep/fiches` | POST | addRow QUALIFICATION_EN_COURS |
| `rep/fiches/find&tel=…&statut=En attente,Urgent[&older_than_min=10]` | GET → `{trouve, fiche}` | filterRows par téléphone (formats 33…, +33…, 0… acceptés) |
| `rep/fiches/{id}` | PATCH `{statut, derniere_reponse_ia, societe, contact, email, departement, append_resume}` | updateCell N, O, C, D, P, Q, K |
| `rep/demandes` | POST `{fiche_id, service, urgence, …, source}` | addRow SAV / COMMERCIAL / FINANCE |
| `distributeurs/find&societe=…` | GET → `{trouve, commercial, compte, extra}` | filterRows DISTRIBUTEUR |
| `chat/messages` | POST `{session_id, message, reply, page_url}` | addRow Feuille 2 |
| `chat/leads` | POST (champs du bloc lead) | addRow Feuille 1 |
| `chat/routage/find&categorie=…` | GET → `{dest_to, dest_cc, dest_libelle}` | filterRows Routage (repli automatique) |
| `adv/demandes` | POST `{response, from_email, from_nom, sujet, message, message_id}` | nouveau : journal Claire ADV (le bloc [ANALYSE] et le tag sont extraits côté API) |
| `cso/devis` | POST (sortie IA + `lignes[]`, `commercial`, `fichier_source`, `message_id`, `destinataire_email`, `copies_email`) → `{action: created/updated/unchanged/ignore}` | B2:B + addRow/updateRow Devis + addRow/delete Lignes |
| `cso/devis/relances_dues` | GET → `{rows: [{…, relance_due: 1/2/3, email_relance, commercial_nom}]}` | filterRows Devis du scénario de relance |
| `cso/relances` | POST `{n_offre, numero, destinataire, cc}` | updateRow Statut + Relances_envoyees |
| `cee/leads` | POST (champs du formulaire) | addRow Leads WCF |
| `cee/leads/find&tel=…` | GET → `{trouve, lead, nb_simulations}` | filterRows Leads WCF + comptage |
| `cee/conversations` | POST `{telephone, message, reply, profil, infos, qualifie, projet, urgence, decideur, action}` | addRow Conversations WCF |
| `cee/actions` | POST `{telephone, type_profil, projet, urgence, decideur, infos, action}` | addRow Actions WCF |
| `export/<table>` | GET (session) | export CSV |

Test rapide : `GET api.php?r=ping` avec le header renvoie `{"auth":"api_key"}`.
