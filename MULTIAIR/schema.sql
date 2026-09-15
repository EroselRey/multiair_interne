-- MULTIAIR — schéma SQLite (tableau de bord des scénarios Make)
PRAGMA journal_mode = DELETE;

CREATE TABLE IF NOT EXISTS parametres (
  cle TEXT PRIMARY KEY,
  valeur TEXT
);

CREATE TABLE IF NOT EXISTS executions_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scenario TEXT NOT NULL,                 -- repondeur | chatbot | adv | cso | cee
  date TEXT NOT NULL,
  statut TEXT NOT NULL DEFAULT 'ok',      -- ok | erreur
  type_evenement TEXT,
  resume TEXT,
  payload TEXT
);
CREATE INDEX IF NOT EXISTS idx_log_scenario_date ON executions_log(scenario, date);

-- ---------------------------------------------------------------- Répondeur IA
CREATE TABLE IF NOT EXISTS rep_fiches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tel_norm TEXT,
  service TEXT,                           -- technique | commercial | finance
  societe TEXT, contact TEXT, marque TEXT, modele TEXT, numero_serie TEXT,
  type_panne TEXT, besoin_commercial TEXT, reference_facture TEXT,
  resume TEXT, justification_urgence TEXT,
  statut TEXT NOT NULL DEFAULT 'En attente',
  derniere_reponse_ia TEXT,
  email TEXT, departement TEXT,
  canal TEXT, urgence INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT,
  transmis_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_rep_fiches_tel ON rep_fiches(tel_norm, statut);

CREATE TABLE IF NOT EXISTS rep_demandes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fiche_id INTEGER,
  service TEXT NOT NULL,                  -- SAV | COMMERCIAL | FINANCE
  priorite TEXT NOT NULL DEFAULT 'Normal',-- Normal | URGENT
  societe TEXT, contact TEXT, tel TEXT, marque TEXT, modele TEXT, numero_serie TEXT,
  type_panne TEXT, besoin_commercial TEXT, reference_facture TEXT,
  resume TEXT, justification_urgence TEXT,
  compte_distributeur TEXT, commercial TEXT, email TEXT,
  source TEXT,                            -- vapi_direct | whatsapp_qualifie | sans_reponse_10min | import
  statut TEXT NOT NULL DEFAULT 'a_traiter', -- a_traiter | en_cours | traite
  traite_par TEXT, traite_at TEXT, commentaire TEXT,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rep_demandes_date ON rep_demandes(created_at);

-- Conversation complète entre le client et Claire (WhatsApp entrant/sortant,
-- résumé de l'appel VAPI). Une ligne par échange, rattachée à la fiche d'appel.
CREATE TABLE IF NOT EXISTS rep_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT NOT NULL,
  fiche_id INTEGER,
  tel_norm TEXT,
  canal TEXT,                             -- whatsapp | appel | sms
  message TEXT,                           -- ce que le client a envoyé
  reponse TEXT,                           -- ce que Claire a répondu
  source TEXT                             -- scénario Make à l'origine
);
CREATE INDEX IF NOT EXISTS idx_rep_messages_tel ON rep_messages(tel_norm, date);
CREATE INDEX IF NOT EXISTS idx_rep_messages_fiche ON rep_messages(fiche_id, date);

CREATE TABLE IF NOT EXISTS distributeurs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  marque TEXT, vendeur TEXT, compte TEXT, raison_sociale TEXT,
  nom TEXT, prenom TEXT, email TEXT, telephone TEXT
);
CREATE INDEX IF NOT EXISTS idx_distributeurs_rs ON distributeurs(raison_sociale);

-- ---------------------------------------------------------------- Chatbot Claire
CREATE TABLE IF NOT EXISTS chat_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT, date TEXT NOT NULL,
  message TEXT, reponse TEXT, page_url TEXT
);
CREATE INDEX IF NOT EXISTS idx_chat_messages_session ON chat_messages(session_id, date);

CREATE TABLE IF NOT EXISTS chat_leads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT,
  societe TEXT, nom TEXT, prenom TEXT, email TEXT, telephone TEXT,
  date TEXT NOT NULL,
  besoin_resume TEXT, produits_proposes TEXT,
  statut TEXT, marque_orientee TEXT, type_interlocuteur TEXT, departement TEXT,
  a_verifier TEXT, categorie TEXT, dest_libelle TEXT, dest_to TEXT,
  suivi TEXT NOT NULL DEFAULT 'nouveau',  -- nouveau | contacte | converti | perdu
  commentaire TEXT,
  updated_at TEXT,
  nb_mises_a_jour INTEGER NOT NULL DEFAULT 0
);

-- Table de routage commune (chatbot : catégorie ; repondeur : service ; adv : cas / famille / tag)
CREATE TABLE IF NOT EXISTS routage (
  scenario TEXT NOT NULL,                 -- chatbot | repondeur | adv
  cle TEXT NOT NULL,
  dest_to TEXT, dest_cc TEXT, libelle TEXT,
  PRIMARY KEY (scenario, cle)
);

-- ---------------------------------------------------------------- Claire ADV
CREATE TABLE IF NOT EXISTS adv_demandes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date TEXT NOT NULL,
  message_id TEXT,
  from_email TEXT, from_nom TEXT, sujet TEXT, message TEXT,
  famille TEXT,                           -- equipements | maintenance
  cas TEXT,                               -- DEVIS DIRECT | STANDARD | LEAD | ESCALADE
  techno TEXT, critere TEXT, pression TEXT, configuration TEXT, options_retenues TEXT,
  tag TEXT,                               -- AUTO | ESCALADE | DRAFT | ERREUR
  analyse_brute TEXT, reponse_ia TEXT, mail_envoye TEXT, envoye_at TEXT,
  statut_suivi TEXT NOT NULL DEFAULT 'envoye', -- envoye | a_valider | valide | traite
  traite_par TEXT, traite_at TEXT, commentaire TEXT
);
CREATE INDEX IF NOT EXISTS idx_adv_date ON adv_demandes(date);

-- ---------------------------------------------------------------- CSO devis
CREATE TABLE IF NOT EXISTS cso_devis (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  date_traitement TEXT NOT NULL,
  n_offre TEXT NOT NULL UNIQUE,
  n_client TEXT, client TEXT, contact_client TEXT, email_client TEXT, tel_client TEXT,
  commercial TEXT, contact_interne TEXT,
  date_offre TEXT, validite_offre TEXT, ref_demande_client TEXT,
  montant_ht REAL, transport REAL, montant_ttc REAL,
  nb_lignes INTEGER, controle_coherence TEXT,
  statut TEXT NOT NULL DEFAULT 'En attente', -- En attente | Relance 1 | Relance 2 | Relance 3 | Gagne | Perdu | Sans suite
  relance_1_j3 TEXT, relance_2_j7 TEXT, relance_3_j15 TEXT,
  relances_envoyees INTEGER NOT NULL DEFAULT 0,
  reponse_client TEXT,
  fichier_source TEXT, message_id TEXT, destinataire_email TEXT, copies_email TEXT,
  empreinte TEXT, version INTEGER NOT NULL DEFAULT 1,
  commentaire TEXT,
  updated_at TEXT
);

CREATE TABLE IF NOT EXISTS cso_lignes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  devis_id INTEGER NOT NULL,
  n_offre TEXT NOT NULL,
  version INTEGER NOT NULL DEFAULT 1,
  poste INTEGER, reference TEXT, designation TEXT,
  quantite REAL, prix_unitaire REAL, prix_total_ht REAL,
  pays_origine TEXT, code_douanier TEXT,
  date_traitement TEXT
);
CREATE INDEX IF NOT EXISTS idx_cso_lignes_devis ON cso_lignes(devis_id, version);

CREATE TABLE IF NOT EXISTS cso_relances (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  devis_id INTEGER NOT NULL,
  n_offre TEXT,
  numero INTEGER NOT NULL,                -- 1 | 2 | 3
  date_envoi TEXT NOT NULL,
  destinataire TEXT, cc TEXT
);

-- ---------------------------------------------------------------- Prime CEE (WCF)
CREATE TABLE IF NOT EXISTS cee_leads (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  telephone_intl TEXT,
  date TEXT NOT NULL,
  prenom TEXT, nom TEXT, societe TEXT, email TEXT, telephone_brut TEXT,
  message_initial TEXT, profil TEXT,
  statut TEXT,                            -- WhatsApp accueil envoye | Sans tel - relance email manuelle
  page_url TEXT,
  eco_an REAL, eco_5ans REAL, cee_min REAL, cee_max REAL, roi_avec_cee TEXT,
  regime TEXT, usage TEXT, nb_compresseurs TEXT, solutions TEXT, co2_tonnes REAL,
  nb_simulations INTEGER,
  suivi TEXT NOT NULL DEFAULT 'nouveau',  -- nouveau | qualifie | rappel_planifie | converti | perdu
  commentaire TEXT
);
CREATE INDEX IF NOT EXISTS idx_cee_leads_tel ON cee_leads(telephone_intl);

CREATE TABLE IF NOT EXISTS cee_conversations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lead_id INTEGER,
  telephone TEXT, date TEXT NOT NULL,
  message TEXT, reponse TEXT,
  profil TEXT, infos TEXT, qualifie TEXT,
  projet TEXT, urgence TEXT, decideur TEXT, action TEXT
);
CREATE INDEX IF NOT EXISTS idx_cee_conv_tel ON cee_conversations(telephone, date);

CREATE TABLE IF NOT EXISTS cee_actions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lead_id INTEGER,
  date TEXT NOT NULL,
  type_profil TEXT, societe TEXT, telephone TEXT,
  detail TEXT, projet TEXT, urgence TEXT, decideur TEXT, infos TEXT,
  action TEXT,
  fait INTEGER NOT NULL DEFAULT 0, fait_par TEXT, fait_at TEXT
);

INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('routage_fallback_email', 'cyril.mortier@airwco.com');
INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('routage_fallback_libelle', 'Non classe');
INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('cso_boite', 'cso@multiairfrance.store');
INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('chat_lead_fenetre_min', '60');
-- Routage par défaut Répondeur IA (service pressenti -> destinataires du mail)
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('repondeur', 'technique', 'cyril.mortier@airwco.com', '', 'SAV / intervention technique');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('repondeur', 'commercial', 'cyril.mortier@airwco.com', '', 'Demande commerciale');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('repondeur', 'finance', 'cyril.mortier@airwco.com', '', 'Finance / comptabilite');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('repondeur', 'autre', 'cyril.mortier@airwco.com', '', 'Service non reconnu');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('repondeur', 'aiguilleur', 'cyril.mortier@airwco.com', '', 'WhatsApp non identifie');
-- Routage par défaut Claire ADV (cas detecte -> destinataires en copie / a valider)
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('adv', 'DEVIS DIRECT', 'cyril.mortier@airwco.com', '', 'Equipement - devis direct');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('adv', 'STANDARD', 'cyril.mortier@airwco.com', '', 'Equipement - reponse standard');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('adv', 'LEAD', 'cyril.mortier@airwco.com', '', 'Equipement - lead (>= 75 ch)');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('adv', 'MAINTENANCE', 'cyril.mortier@airwco.com', '', 'Plan de maintenance');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('adv', 'ESCALADE', 'cyril.mortier@airwco.com', '', 'Escalade - a valider par un humain');
INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES ('adv', 'ERREUR', 'cyril.mortier@airwco.com', '', 'Echec de traitement');
