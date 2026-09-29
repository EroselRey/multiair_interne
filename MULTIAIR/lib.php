<?php
// MULTIAIR — fonctions communes (config, base, session, utilitaires)
declare(strict_types=1);

// Version du code déployé — visible dans api.php?r=ping, dans check.php et dans la page.
const MA_VERSION = '2026-09-29k';

function ma_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "config.php manquant : copier config.example.php en config.php et le compléter.";
            exit;
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'Europe/Paris');
    }
    return $cfg;
}

function ma_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = ma_config();
        $path = $cfg['db_path'];
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fresh = !is_file($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        ma_migrate($pdo, $fresh);
    }
    return $pdo;
}

/**
 * Met la base à niveau. On ne se fie pas à un numéro de version (la date du fichier change
 * selon le mode de transfert FTP) : on regarde réellement ce qui manque dans la base.
 */
function ma_migrate(PDO $pdo, bool $fresh): void
{
    // Tables et colonnes attendues par le code. Toute absence déclenche la mise à niveau.
    $tables = ['parametres', 'executions_log', 'rep_fiches', 'rep_demandes', 'rep_messages', 'distributeurs',
        'rep_contacts', 'rep_regles', 'rep_evenements', 'rep_clients', 'rep_equipements',
        'chat_messages', 'chat_leads', 'routage', 'adv_demandes', 'cso_devis', 'cso_lignes',
        'cso_relances', 'cee_leads', 'cee_conversations', 'cee_actions'];
    $colonnes = [
        'chat_leads' => ['updated_at' => 'TEXT', 'nb_mises_a_jour' => 'INTEGER NOT NULL DEFAULT 0', 'demande_id' => 'INTEGER',
            // Ce que le chatbot recueille comme Claire au téléphone (SAV, commande, facture, relance).
            'nature' => 'TEXT', 'reference' => 'TEXT', 'marque_materiel' => 'TEXT', 'modele' => 'TEXT', 'numero_serie' => 'TEXT',
            'code_postal' => 'TEXT', 'urgence' => 'TEXT', 'justification_urgence' => 'TEXT', 'demande_existante' => 'TEXT',
            'preference_contact' => 'TEXT', 'page_url' => 'TEXT', 'page_title' => 'TEXT'],
        'adv_demandes' => ['commentaire' => 'TEXT'],
        'cso_devis' => ['commentaire' => 'TEXT', 'contact_interne' => 'TEXT'],
        'cee_leads' => ['commentaire' => 'TEXT'],
        'rep_demandes' => ['commentaire' => 'TEXT', 'email' => 'TEXT', 'departement' => 'TEXT',
            // Routage par marque / type de client / urgence (moteur ma_rep_router)
            'code_postal' => 'TEXT', 'marque_norm' => 'TEXT', 'type_client' => 'TEXT', 'type_equipement' => 'TEXT',
            'regle_id' => 'INTEGER', 'regle_libelle' => 'TEXT', 'destinataires' => 'TEXT',
            'dest_to' => 'TEXT', 'dest_cc' => 'TEXT', 'dest_sms' => 'TEXT', 'pris_at' => 'TEXT', 'nature' => 'TEXT',
            'type_interlocuteur' => 'TEXT', 'traite_par' => 'TEXT',
            // Suivi : un lien secret pour l'équipe, un autre pour le client (sa demande uniquement).
            'jeton_interne' => 'TEXT', 'jeton_client' => 'TEXT', 'pris_par' => 'TEXT', 'rappel_prevu' => 'TEXT',
            // Par où la demande est arrivée : telephone | whatsapp | chat | email.
            'canal' => 'TEXT', 'client_id' => 'INTEGER', 'equipement_id' => 'INTEGER',
            // Le client rappelle pour la même demande : relances comptées, demande liée si nouvelle.
            'nb_relances' => 'INTEGER NOT NULL DEFAULT 0', 'derniere_relance' => 'TEXT', 'demande_liee_id' => 'INTEGER'],
        // Ce que Claire a recueilli au téléphone : la fiche le garde pour la demande créée après WhatsApp.
        'rep_fiches' => ['code_postal' => 'TEXT', 'type_interlocuteur' => 'TEXT', 'nature' => 'TEXT', 'type_equipement' => 'TEXT'],
        // L'annuaire est aussi l'équipe de la plateforme : fonction, service, accès et mot de passe.
        // Champs corrigés à la main sur la fiche client : les demandes suivantes ne les écrasent plus.
        'rep_clients' => ['champs_corriges' => 'TEXT', 'civilite' => 'TEXT'],
        'rep_contacts' => ['membres' => 'TEXT', 'fonction' => 'TEXT', 'service' => 'TEXT', 'acces' => 'TEXT',
            'mdp_hash' => 'TEXT', 'mdp_jeton' => 'TEXT', 'mdp_jeton_exp' => 'TEXT', 'derniere_connexion' => 'TEXT'],
        'rep_regles' => ['natures' => 'TEXT'],
    ];

    $present = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    $manquantes = array_diff($tables, $present);

    // Le schéma n'utilise que des CREATE ... IF NOT EXISTS : le rejouer est sans risque.
    if ($fresh || $manquantes) {
        $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
        $present = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    }

    // Colonnes ajoutées après la première mise en service : CREATE IF NOT EXISTS ne les pose pas.
    foreach ($colonnes as $table => $defs) {
        if (!in_array($table, $present, true)) {
            continue;
        }
        $existantes = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($defs as $col => $type) {
            if (!in_array($col, $existantes, true)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
            }
        }
    }

    // Reprise de l'ancienne table de routage du chatbot vers la table commune.
    if (in_array('chat_routage', $present, true) && in_array('routage', $present, true)) {
        $pdo->exec("INSERT OR IGNORE INTO routage(scenario, cle, dest_to, dest_cc, libelle)
                    SELECT 'chatbot', lower(categorie), dest_to, COALESCE(dest_cc, ''), libelle
                    FROM chat_routage WHERE categorie IS NOT NULL AND categorie != ''");
    }

    // Toute demande a ses deux liens de suivi, y compris celles d'avant la fonctionnalité.
    if (in_array('rep_demandes', $present, true)) {
        $pdo->exec("UPDATE rep_demandes SET jeton_interne = lower(hex(randomblob(16))) WHERE jeton_interne IS NULL OR jeton_interne = ''");
        $pdo->exec("UPDATE rep_demandes SET jeton_client = lower(hex(randomblob(16))) WHERE jeton_client IS NULL OR jeton_client = ''");
        $pdo->exec("UPDATE rep_demandes SET canal = CASE WHEN source IN ('chatbot', 'chat') THEN 'chat' WHEN source IN ('email', 'adv') THEN 'email'
            WHEN source = 'whatsapp' THEN 'whatsapp' ELSE 'telephone' END WHERE canal IS NULL OR canal = ''");
    }
    $pdo->exec("INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('rep_standard_tel', '01 34 32 95 00')");
    $pdo->exec("INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('cso_boite', 'cso@multiairfrance.store')");
    $pdo->exec("INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('domaines_internes',
        'airwco.com,multiairfrance.fr,multiairfrance.store,abacfrance.fr')");
    // Les leads importés du Google Sheet n'étaient pas regroupés : on le fait une
    // seule fois, automatiquement, au premier chargement après le dépôt.
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'dedup_initial'");
    $st->execute();
    if (!$st->fetchColumn()) {
        $r = ma_chat_dedup($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('dedup_initial', ?)")
            ->execute([ma_now() . ' : ' . $r['fusionnes'] . ' fusionné(s), ' . $r['restants'] . ' restant(s)']);
        $pdo->prepare('INSERT INTO executions_log(date, scenario, statut, type_evenement, resume, payload) VALUES (?,?,?,?,?,?)')
            ->execute([ma_now(), 'chatbot', 'ok', 'dedup_automatique',
                $r['fusionnes'] . ' doublon(s) regroupé(s) automatiquement, ' . $r['restants'] . ' lead(s) restant(s)',
                json_encode($r, JSON_UNESCAPED_UNICODE)]);
    }
    // Un mail arrivé dans la boîte CSO sans devis correspondant a longtemps été
    // journalisé en « erreur » alors que c'est du trafic normal (mails internes,
    // réponses arrivées avant l'enregistrement du devis). On corrige l'étiquette
    // des lignes déjà écrites : rien n'est supprimé, le journal reste complet.
    $pdo->exec("UPDATE executions_log SET statut = 'info'
        WHERE type_evenement = 'reponse_non_rattachee' AND statut = 'erreur'");

    // Répondeur : annuaire et règles pré-remplis une seule fois, d'après le schéma
    // de routage SAV / finance. Ensuite, tout se modifie depuis la page.
    $pdo->exec("INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('rep_repli_email', 'cyril.mortier@airwco.com')");
    $pdo->exec("INSERT OR IGNORE INTO parametres(cle, valeur) VALUES ('rep_cc_urgence', '')");
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'rep_routage_initial'");
    $st->execute();
    if (!$st->fetchColumn()) {
        ma_rep_routage_initial($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('rep_routage_initial', ?)")->execute([ma_now()]);
    }
    // Passage des règles libres (correctifs 17-18) aux tableaux par service : on relit ce que les
    // règles faisaient, on le réécrit sous forme de tableaux. Rien ne change pour les appels.
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'rep_routage_grilles'");
    $st->execute();
    if (!$st->fetchColumn()) {
        $pdo->exec("UPDATE rep_contacts SET role = 'finance' WHERE role = 'compta'");
        $pdo->exec("UPDATE rep_regles SET cible_role = 'finance' WHERE cible_role = 'compta'");
        // « Commercial (à définir) » n'était qu'un renvoi vers l'adresse de repli : le tableau Commerce le dit mieux.
        $pdo->exec("DELETE FROM rep_contacts WHERE role = 'commercial' AND nom = 'Commercial (à définir)'");
        ma_rep_grilles_enregistrer($pdo, ma_rep_grilles_lire($pdo));
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('rep_routage_grilles', ?)")->execute([ma_now()]);
    }

    // L'équipe de la plateforme (accès, responsables de service) : posée une seule fois.
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'equipe_initiale'");
    $st->execute();
    if (!$st->fetchColumn() && in_array('rep_contacts', $present, true)) {
        ma_equipe_initiale($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('equipe_initiale', ?)")->execute([ma_now()]);
    }

    // Base clients : constituée une seule fois à partir de toutes les demandes, puis tenue à jour à chaque demande.
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'clients_constitues'");
    $st->execute();
    $clientsAFaire = !$st->fetchColumn();

    // Les leads du chat d'avant le circuit commun deviennent des demandes, une seule fois, sans rien envoyer.
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'chat_leads_repris'");
    $st->execute();
    if (!$st->fetchColumn() && in_array('chat_leads', $present, true)) {
        $n = ma_chat_reprise($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('chat_leads_repris', ?)")->execute([ma_now() . ' : ' . $n . ' lead(s)']);
    }

    // Parc machines : constitué une seule fois à partir des demandes déjà rattachées.
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'parc_constitue'");
    $st->execute();
    if (!$st->fetchColumn() && !$clientsAFaire && in_array('rep_equipements', $present, true)) {
        foreach ($pdo->query('SELECT id FROM rep_demandes WHERE client_id IS NOT NULL ORDER BY created_at, id')->fetchAll(PDO::FETCH_COLUMN) as $did) {
            ma_equipement_rattacher($pdo, (int) $did);
        }
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('parc_constitue', ?)")->execute([ma_now()]);
    }
    // Civilités : « Monsieur Mortier » noté dans une demande donne la civilité de la fiche (une seule fois).
    $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'civilites_reprises'");
    $st->execute();
    if (!$st->fetchColumn() && !$clientsAFaire && in_array('rep_clients', $present, true)) {
        foreach ($pdo->query('SELECT derniere_demande_id FROM rep_clients WHERE derniere_demande_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $did) {
            ma_client_rattacher($pdo, (int) $did);
        }
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('civilites_reprises', ?)")->execute([ma_now()]);
    }
    if ($clientsAFaire && in_array('rep_demandes', $present, true)) {
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('civilites_reprises', ?)")->execute([ma_now()]);
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('parc_constitue', ?)")->execute([ma_now()]);
        $n = ma_clients_reprise($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('clients_constitues', ?)")->execute([ma_now() . ' : ' . $n . ' demande(s) rattachée(s)']);
    }

    $pdo->prepare("INSERT OR REPLACE INTO parametres(cle, valeur) VALUES ('schema_version', ?)")
        ->execute([date('Y-m-d H:i:s')]);
}

function ma_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = ma_config();
    $days = (int) ($cfg['session_days'] ?? 30);
    session_set_cookie_params([
        'lifetime' => $days * 86400,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('MULTIAIRSESS');
    session_start();
}

function ma_is_logged(): bool
{
    ma_session_start();
    return !empty($_SESSION['ma_auth']);
}

function ma_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Normalise une date reçue de Make ou d'un import vers 'Y-m-d H:i:s'. */
function ma_date(?string $v, bool $withTime = true): ?string
{
    if ($v === null) {
        return null;
    }
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    // Sheets : 1899-12-31 = valeur numérique interprétée en date -> ignorer
    if (str_starts_with($v, '1899-12-3')) {
        return null;
    }
    $formats = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
        'd.m.Y', DATE_ATOM, 'Y-m-d\TH:i:s.v\Z', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s',
    ];
    foreach ($formats as $f) {
        $d = DateTime::createFromFormat($f, $v);
        if ($d instanceof DateTime && $d->format($f) === $v) {
            if (in_array($f, ['Y-m-d', 'd/m/Y', 'd.m.Y'], true)) {
                $d->setTime(0, 0, 0);
            }
            if (str_contains($f, '\Z')) {
                $d->setTimezone(new DateTimeZone(ma_config()['timezone'] ?? 'Europe/Paris'));
            }
            return $withTime ? $d->format('Y-m-d H:i:s') : $d->format('Y-m-d');
        }
    }
    $ts = strtotime($v);
    if ($ts !== false) {
        return $withTime ? date('Y-m-d H:i:s', $ts) : date('Y-m-d', $ts);
    }
    return $v;
}

/** Numéro de téléphone normalisé au format international sans + (ex : 33612345678). */
function ma_tel(?string $v): ?string
{
    if ($v === null) {
        return null;
    }
    $t = preg_replace('/[\s\.\-\(\)]/', '', (string) $v);
    if ($t === '' || $t === null) {
        return null;
    }
    $t = preg_replace('/^\+/', '', $t);
    $t = preg_replace('/^00/', '', $t);
    if (preg_match('/^0\d{9}$/', $t)) {
        $t = '33' . substr($t, 1);
    } elseif (preg_match('/^[1-9]\d{8}$/', $t)) {
        // 9 chiffres sans le 0 initial (ex : 699044092) -> France
        $t = '33' . $t;
    } elseif (preg_match('/^330\d{9}$/', $t)) {
        // 33 0 6 ... (double préfixe) -> retirer le 0
        $t = '33' . substr($t, 3);
    } elseif (preg_match('/^33033\d{9}$/', $t)) {
        $t = substr($t, 3);
    }
    return $t;
}

function ma_float($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v)) {
        return (float) $v;
    }
    $s = str_replace([' ', "\u{a0}", '€', 'EUR'], '', (string) $v);
    // "1 892,04" -> 1892.04 ; "1,892.04" -> 1892.04
    if (preg_match('/,\d{1,2}$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

function ma_int($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_bool($v)) {
        return $v ? 1 : 0;
    }
    if (is_numeric($v)) {
        return (int) $v;
    }
    $s = strtolower(trim((string) $v));
    if (in_array($s, ['true', 'oui', 'yes', 'urgent'], true)) {
        return 1;
    }
    if (in_array($s, ['false', 'non', 'no', 'normal'], true)) {
        return 0;
    }
    // "1899-12-31 00:00" (Sheets) -> 1
    if (str_starts_with($s, '1899-12-3')) {
        return 1;
    }
    return (int) $s;
}

function ma_bool($v): bool
{
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string) $v));
    return in_array($s, ['1', 'true', 'oui', 'yes', 'urgent'], true);
}

function ma_str($v): ?string
{
    if ($v === null) {
        return null;
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    $s = trim((string) $v);
    return $s === '' ? null : $s;
}

/** Envoi d'un mail (SMTP si configuré, sinon mail()). Retourne true/false. */
function ma_send_mail(string $to, string $subject, string $body): bool
{
    $cfg = ma_config();
    $from = $cfg['mail_from'] ?? 'no-reply@multiairfrance.store';
    $smtp = $cfg['smtp'] ?? [];
    if (!empty($smtp['host'])) {
        return ma_send_smtp($smtp, $from, $to, $subject, $body);
    }
    $headers = "From: $from\r\nReply-To: $from\r\nContent-Type: text/plain; charset=utf-8\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

/** Client SMTP minimal (AUTH LOGIN, STARTTLS ou SSL). */
function ma_send_smtp(array $s, string $from, string $to, string $subject, string $body): bool
{
    $host = $s['host'];
    $port = (int) ($s['port'] ?? 587);
    $secure = $s['secure'] ?? 'tls';
    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$fp) {
        return false;
    }
    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $out;
    };
    $cmd = function ($c) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        return $read();
    };
    $read();
    $cmd('EHLO multiairfrance.store');
    if ($secure === 'tls') {
        $r = $cmd('STARTTLS');
        if (!str_starts_with($r, '220')) {
            fclose($fp);
            return false;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $cmd('EHLO multiairfrance.store');
    }
    if (!empty($s['user'])) {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($s['user']));
        $r = $cmd(base64_encode($s['pass'] ?? ''));
        if (!str_starts_with($r, '235')) {
            fclose($fp);
            return false;
        }
    }
    $cmd('MAIL FROM:<' . $from . '>');
    $cmd('RCPT TO:<' . $to . '>');
    $r = $cmd('DATA');
    if (!str_starts_with($r, '354')) {
        fclose($fp);
        return false;
    }
    $msg = "From: $from\r\nTo: $to\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "Date: " . date(DATE_RFC2822) . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
        . str_replace("\n.", "\n..", $body) . "\r\n.";
    $r = $cmd($msg);
    $cmd('QUIT');
    fclose($fp);
    return str_starts_with($r, '250');
}

/**
 * Crée un lead chatbot ou met à jour le lead équivalent créé dans la fenêtre de regroupement
 * (même session, ou même société + nom, ou même email, ou même téléphone, à moins de N minutes).
 * Retourne ['action' => 'created'|'merged', 'id' => int, 'nb_mises_a_jour' => int].
 */
function ma_chat_lead_upsert(PDO $db, array $d, ?int $windowMin = null): array
{
    if ($windowMin === null) {
        $st = $db->prepare("SELECT valeur FROM parametres WHERE cle = 'chat_lead_fenetre_min'");
        $st->execute();
        $windowMin = (int) ($st->fetchColumn() ?: 60);
    }
    $norm = fn($v) => mb_strtolower(trim((string) ($v ?? '')));
    $bad = ['', 'non communiqué', 'non communique', 'non renseigné', 'non renseigne', 'nc', 'n/a', '-', 'inconnu'];
    $date = $d['date'] ?? ma_now();
    $societe = $norm($d['societe'] ?? '');
    $nom = $norm($d['nom'] ?? '');
    $email = $norm($d['email'] ?? '');
    $tel = ma_tel((string) ($d['telephone'] ?? ''));
    $session = trim((string) ($d['session_id'] ?? ''));
    $since = date('Y-m-d H:i:s', strtotime($date) - $windowMin * 60);
    $until = date('Y-m-d H:i:s', strtotime($date) + $windowMin * 60);
    $conds = [];
    $args = [];
    if ($session !== '') {
        $conds[] = 'session_id = ?';
        $args[] = $session;
    }
    if (!in_array($societe, $bad, true) && !in_array($nom, $bad, true)) {
        $conds[] = '(lower(societe) = ? AND lower(nom) = ?)';
        array_push($args, $societe, $nom);
    } elseif (!in_array($societe, $bad, true)) {
        $conds[] = 'lower(societe) = ?';
        $args[] = $societe;
    }
    if (!in_array($email, $bad, true) && str_contains($email, '@')) {
        $conds[] = 'lower(email) = ?';
        $args[] = $email;
    }
    if ($tel !== null && strlen($tel) >= 9) {
        $conds[] = 'telephone = ?';
        $args[] = $tel;
    }
    $existing = null;
    if ($conds) {
        $sql = 'SELECT * FROM chat_leads WHERE (' . implode(' OR ', $conds) . ') AND COALESCE(updated_at, date) >= ? AND date <= ? ORDER BY date DESC, id DESC LIMIT 1';
        $st = $db->prepare($sql);
        $st->execute(array_merge($args, [$since, $until]));
        $existing = $st->fetch() ?: null;
    }
    $cols = array_column($db->query('PRAGMA table_info(chat_leads)')->fetchAll(), 'name');
    $row = [];
    foreach ($cols as $c) {
        if ($c !== 'id' && array_key_exists($c, $d)) {
            $row[$c] = is_string($d[$c]) ? trim($d[$c]) : $d[$c];
        }
    }
    if ($tel !== null) {
        $row['telephone'] = $tel;
    }
    if ($existing) {
        // Fusion : on garde la date du premier contact, on prend les nouvelles valeurs non vides
        $upd = [];
        foreach ($row as $c => $v) {
            if (in_array($c, ['date', 'suivi', 'commentaire', 'nb_mises_a_jour', 'updated_at'], true)) {
                continue;
            }
            $vs = $norm($v);
            if ($vs === '' || (in_array($vs, $bad, true) && !in_array($norm($existing[$c] ?? ''), $bad, true))) {
                continue;
            }
            if ((string) $v !== (string) ($existing[$c] ?? '')) {
                $upd[$c] = $v;
            }
        }
        $upd['updated_at'] = max((string) $date, (string) ($existing['updated_at'] ?? ''));
        $upd['nb_mises_a_jour'] = (int) $existing['nb_mises_a_jour'] + 1;
        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($upd)));
        $vals = array_values($upd);
        $vals[] = $existing['id'];
        $db->prepare("UPDATE chat_leads SET $set WHERE id = ?")->execute($vals);
        return ['action' => 'merged', 'id' => (int) $existing['id'], 'nb_mises_a_jour' => $upd['nb_mises_a_jour']];
    }
    $row['date'] = $date;
    $row['nb_mises_a_jour'] = 0;
    $keys = array_keys($row);
    $db->prepare('INSERT INTO chat_leads (' . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute(array_values($row));
    return ['action' => 'created', 'id' => (int) $db->lastInsertId(), 'nb_mises_a_jour' => 0];
}

/** Résout les destinataires d'un scénario pour une clé (avec repli paramétré). */
function ma_routage(PDO $db, string $scenario, ?string $cle): array
{
    $cle = trim((string) $cle);
    $r = null;
    if ($cle !== '') {
        $st = $db->prepare('SELECT * FROM routage WHERE scenario = ? AND cle = ? COLLATE NOCASE');
        $st->execute([$scenario, $cle]);
        $r = $st->fetch() ?: null;
    }
    if (!$r) {
        $st = $db->prepare("SELECT * FROM routage WHERE scenario = ? AND cle IN ('autre', 'AUTRE', 'defaut', 'DEFAUT') LIMIT 1");
        $st->execute([$scenario]);
        $r = $st->fetch() ?: null;
    }
    $params = $db->query('SELECT cle, valeur FROM parametres')->fetchAll(PDO::FETCH_KEY_PAIR);
    $fb = $params['routage_fallback_email'] ?? 'cyril.mortier@airwco.com';
    return [
        'trouve' => (bool) $r && strcasecmp((string) $r['cle'], $cle) === 0,
        'cle' => $cle,
        'dest_to' => ($r && $r['dest_to']) ? $r['dest_to'] : $fb,
        'dest_cc' => ($r && $r['dest_cc'] !== null && $r['dest_cc'] !== '') ? $r['dest_cc'] : ($scenario === 'chatbot' ? $fb : ''),
        'dest_libelle' => ($r && $r['libelle']) ? $r['libelle'] : ($params['routage_fallback_libelle'] ?? 'Non classe'),
    ];
}

/** Deux leads désignent-ils le même contact, à l'intérieur de la fenêtre de regroupement ? */
/**
 * Découpe une liste d'adresses (séparées par ; ou ,) en tableau [minuscule => adresse].
 */
function ma_liste_emails(?string $brut): array
{
    $out = [];
    foreach (preg_split('/[;,]+/', (string) $brut) ?: [] as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($p)] = $p;
        }
    }
    return $out;
}

/**
 * Destinataires d'une relance : elle reprend la distribution du mail d'origine.
 * En « à » : le destinataire du devis (le client). En « copie » : la boîte CSO,
 * le commercial qui a fait le devis et les autres copies du mail d'origine.
 * La réponse du client revient au commercial grâce au Répondre-à.
 */
/** Domaines de la maison (MultiAir, Airwco, Abac…), lus depuis les paramètres. */
function ma_domaines_internes(?PDO $db = null): array
{
    $defaut = 'airwco.com,multiairfrance.fr,multiairfrance.store,abacfrance.fr';
    $brut = $defaut;
    if ($db) {
        $st = $db->prepare("SELECT valeur FROM parametres WHERE cle = 'domaines_internes'");
        $st->execute();
        $brut = (string) ($st->fetchColumn() ?: $defaut);
    }
    return array_values(array_filter(array_map('trim', explode(',', $brut)), fn($d) => $d !== ''));
}

/** Vrai si l'adresse appartient à l'un des domaines de la maison. */
function ma_est_interne(?string $email, array $internes): bool
{
    $email = strtolower(trim((string) $email));
    foreach ($internes as $dom) {
        if ($email !== '' && str_ends_with($email, '@' . strtolower($dom))) {
            return true;
        }
    }
    return false;
}

/** Ne garde que les adresses extérieures à la maison. */
function ma_emails_externes(array $emails, array $internes): array
{
    return array_filter($emails, fn($e) => !ma_est_interne($e, $internes));
}

/**
 * Destinataires d'une relance CSO.
 *
 * Le client seul en « À », la boîte cso@ seule en copie. Les collègues présents
 * dans l'en-tête du mail d'origine (commercial, personnes en copie) sont écartés :
 * la relance part vers l'extérieur, et c'est la boîte cso@ — dépouillée dans cette
 * page — qui reçoit la réponse. Si le champ « À » du mail d'origine était une
 * adresse de la maison (offre envoyée en interne puis transférée), on retombe sur
 * les adresses externes en copie, puis sur l'adresse client lue dans le PDF.
 */
function ma_destinataires_relance(array $devis, ?string $boiteCso = null, array $internes = []): array
{
    $to = ma_emails_externes(ma_liste_emails($devis['destinataire_email'] ?? null), $internes);
    if (!$to) {
        $to = ma_emails_externes(ma_liste_emails($devis['copies_email'] ?? null), $internes);
    }
    if (!$to) {
        $to = ma_emails_externes(ma_liste_emails($devis['email_client'] ?? null), $internes);
    }
    $cc = ma_liste_emails($boiteCso);
    foreach ($to as $k => $_) {
        unset($cc[$k]);
    }
    return ['to' => implode(';', array_values($to)), 'cc' => implode(';', array_values($cc))];
}

/**
 * Modèles des mails de relance CSO. Stockés dans la table parametres
 * (relance_1_objet / relance_1_texte, …) pour être lisibles et modifiables
 * depuis la page ; le scénario Make se contente d'envoyer ce que l'API rend.
 * Champs disponibles : {contact} {client} {n_offre} {date_offre}
 * {validite_offre} {montant_ht} {commercial}
 */
function ma_relance_modeles(PDO $db): array
{
    $signature = "\n\nBien cordialement,\n\n{commercial}\nService Commercial MultiAir France\n"
        . "Worthington Creyssensac - Mauguière - Pneumatech - ABAC\n01 34 32 95 00";
    $entete = "Bonjour {contact},\n\nOffre n° {n_offre} du {date_offre} - {montant_ht} € HT"
        . " - valable jusqu'au {validite_offre}.\n\n";
    $defauts = [
        1 => ['objet' => "Votre offre de prix n° {n_offre}",
            'texte' => $entete . "Je me permets de m'assurer que cette offre vous est bien parvenue et qu'elle "
                . "correspond à votre demande. Si un point mérite d'être ajusté, référence, quantité ou délai, "
                . "dites-le nous et nous la reprendrons." . $signature],
        2 => ['objet' => "Suivi de votre offre n° {n_offre}",
            'texte' => $entete . "Avez-vous pu l'examiner ? Si le délai d'approvisionnement ou le montant posent "
                . "question, nous pouvons regarder ensemble les alternatives possibles sur certaines références." . $signature],
        3 => ['objet' => "Validité de votre offre n° {n_offre}",
            'texte' => $entete . "Passé la date de validité, les tarifs devront être reconsidérés. Si le projet est "
                . "toujours d'actualité, un simple retour de votre part suffit pour enclencher la commande. S'il ne "
                . "l'est plus, dites-le nous également : nous clôturerons le dossier sans vous relancer davantage." . $signature],
    ];
    $params = $db->query('SELECT cle, valeur FROM parametres')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($defauts as $n => $d) {
        foreach (['objet', 'texte'] as $champ) {
            $v = $params["relance_{$n}_{$champ}"] ?? '';
            if (trim((string) $v) !== '') {
                $defauts[$n][$champ] = (string) $v;
            }
        }
    }
    return $defauts;
}

/**
 * Remplace les champs d'un modèle de relance par les valeurs du devis.
 */
function ma_relance_rendu(array $devis, array $modele): array
{
    $date = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';
    $nom = $devis['commercial'] ? ucwords(str_replace('.', ' ', explode('@', (string) $devis['commercial'])[0])) : '';
    // « AR DE COMMANDE », « SERVICE ACHATS »… ne sont pas des noms : on écrit simplement « Bonjour, »
    $contact = trim((string) ($devis['contact_client'] ?? ''));
    if (preg_match('/^(ar de commande|service achats?|achats?|adv|commande|contact)$/i', $contact)) {
        $contact = '';
    }
    $champs = [
        '{contact}' => $contact,
        '{client}' => (string) ($devis['client'] ?? ''),
        '{n_offre}' => (string) ($devis['n_offre'] ?? ''),
        '{date_offre}' => $date($devis['date_offre'] ?? null),
        '{validite_offre}' => $date($devis['validite_offre'] ?? null),
        '{montant_ht}' => number_format((float) ($devis['montant_ht'] ?? 0), 2, ',', ' '),
        '{commercial}' => $nom,
    ];
    $propre = fn(string $t) => str_replace(['Bonjour ,', 'Bonjour  ,'], 'Bonjour,', strtr($t, $champs));
    return ['objet' => $propre($modele['objet']), 'texte' => $propre($modele['texte'])];
}

/**
 * Relances programmées : pour chaque devis encore ouvert, la prochaine relance
 * à envoyer, sa date, ses destinataires et le mail tel qu'il partira.
 * $seulementDues limite aux relances dont la date est atteinte (le scénario Make).
 */
function ma_relances_planifiees(PDO $db, bool $seulementDues = true, bool $inclureEcart = false): array
{
    $today = date('Y-m-d');
    $st = $db->prepare("SELECT valeur FROM parametres WHERE cle = 'cso_boite'");
    $st->execute();
    $boiteCso = (string) ($st->fetchColumn() ?: 'cso@multiairfrance.store');
    $internes = ma_domaines_internes($db);
    $modeles = ma_relance_modeles($db);
    $colonnes = [1 => 'relance_1_j3', 2 => 'relance_2_j7', 3 => 'relance_3_j15'];
    $out = [];
    $rows = $db->query("SELECT * FROM cso_devis WHERE statut IN ('En attente','Relance 1','Relance 2')
        ORDER BY date_traitement")->fetchAll();
    foreach ($rows as $r) {
        $num = (int) $r['relances_envoyees'] + 1;
        if ($num > 3 || empty($r[$colonnes[$num]])) {
            continue;
        }
        $datePrevue = (string) $r[$colonnes[$num]];
        $due = $datePrevue <= $today;
        if ($seulementDues && !$due) {
            continue;
        }
        if (($r['controle_coherence'] ?? '') === 'ECART' && !$inclureEcart) {
            continue;
        }
        $dest = ma_destinataires_relance($r, $boiteCso, $internes);
        // Aucune adresse client exploitable : la relance est injouable. On l'ecarte de
        // la liste envoyee a Make — qui echouerait sur un destinataire vide — mais on
        // la garde dans l'apercu de la page, signalee, pour qu'elle soit corrigee.
        if ($dest['to'] === '') {
            if ($seulementDues) {
                continue;
            }
            $r['sans_destinataire'] = 1;
        }
        $mail = ma_relance_rendu($r, $modeles[$num]);
        $r['relance_due'] = $num;
        $r['date_prevue'] = $datePrevue;
        $r['due'] = $due ? 1 : 0;
        $r['email_relance'] = $dest['to'];
        $r['cc_relance'] = $dest['cc'];
        // « Repondre a » porte les deux adresses : le client qui clique sur Repondre
        // ecrit d'un seul geste a la boite cso@ — que Make depouille pour mettre le
        // statut du devis a jour — et au commercial, qui recoit ainsi le mail et ses
        // pieces jointes (bon de commande) directement dans sa boite.
        // Virgule et non point-virgule : « Reply-To » est un en-tete de message,
        // et la RFC 5322 y separe les adresses par des virgules.
        $r['repondre_a'] = implode(', ', array_values(
            ma_liste_emails($boiteCso) + ma_liste_emails($r['commercial'] ?? null)
        ));
        $r['objet_relance'] = $mail['objet'];
        $r['texte_relance'] = $mail['texte'];
        $r['commercial_nom'] = $r['commercial'] ? ucwords(str_replace('.', ' ', explode('@', (string) $r['commercial'])[0])) : '';
        $out[] = $r;
    }
    usort($out, fn($a, $b) => [$a['date_prevue'], $a['n_offre']] <=> [$b['date_prevue'], $b['n_offre']]);
    return $out;
}

function ma_chat_meme_lead(array $a, array $b, int $windowMin): bool
{
    $norm = fn($v) => mb_strtolower(trim((string) ($v ?? '')));
    $bad = ['', 'non communiqué', 'non communique', 'non renseigné', 'non renseigne', 'nc', 'n/a', '-', 'inconnu'];
    // Fenêtre : on compare la date du candidat à la dernière activité du lead conservé.
    $ref = max((string) $a['date'], (string) ($a['updated_at'] ?? ''));
    if (abs(strtotime((string) $b['date']) - strtotime($ref)) > $windowMin * 60) {
        return false;
    }
    $sa = trim((string) ($a['session_id'] ?? ''));
    $sb = trim((string) ($b['session_id'] ?? ''));
    if ($sa !== '' && $sa === $sb) {
        return true;
    }
    $ea = $norm($a['email'] ?? ''); $eb = $norm($b['email'] ?? '');
    if (!in_array($ea, $bad, true) && str_contains($ea, '@') && $ea === $eb) {
        return true;
    }
    $ta = ma_tel((string) ($a['telephone'] ?? '')); $tb = ma_tel((string) ($b['telephone'] ?? ''));
    if ($ta !== null && strlen($ta) >= 9 && $ta === $tb) {
        return true;
    }
    $ca = $norm($a['societe'] ?? ''); $cb = $norm($b['societe'] ?? '');
    $na = $norm($a['nom'] ?? ''); $nb = $norm($b['nom'] ?? '');
    if (!in_array($ca, $bad, true) && $ca === $cb) {
        if (!in_array($na, $bad, true) && $na === $nb) {
            return true;
        }
        if (in_array($na, $bad, true) && in_array($nb, $bad, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Regroupe les doublons déjà présents dans la table (import historique, ou leads reçus
 * avant la mise en place du regroupement). Conserve le lead le plus ancien et son suivi
 * commercial, complète ses champs vides avec ceux des doublons, puis supprime ces derniers.
 */
function ma_chat_dedup(PDO $db, ?int $windowMin = null): array
{
    if ($windowMin === null) {
        $st = $db->prepare("SELECT valeur FROM parametres WHERE cle = 'chat_lead_fenetre_min'");
        $st->execute();
        $windowMin = (int) ($st->fetchColumn() ?: 60);
    }
    $norm = fn($v) => mb_strtolower(trim((string) ($v ?? '')));
    $bad = ['', 'non communiqué', 'non communique', 'non renseigné', 'non renseigne', 'nc', 'n/a', '-', 'inconnu'];
    $rows = $db->query('SELECT * FROM chat_leads ORDER BY date, id')->fetchAll();
    $gardes = [];
    $aSupprimer = [];
    $fusions = [];
    foreach ($rows as $r) {
        $cible = null;
        foreach ($gardes as $i => $g) {
            if (ma_chat_meme_lead($g, $r, $windowMin)) {
                $cible = $i;
                break;
            }
        }
        if ($cible === null) {
            $gardes[] = $r;
            continue;
        }
        $g = $gardes[$cible];
        foreach ($r as $c => $v) {
            if (in_array($c, ['id', 'date', 'suivi', 'commentaire', 'nb_mises_a_jour', 'updated_at'], true)) {
                continue;
            }
            $vs = $norm($v);
            if ($vs === '' || in_array($vs, $bad, true)) {
                continue;
            }
            // Le doublon, plus récent, porte l'information la plus à jour.
            $g[$c] = $v;
        }
        if (($g['suivi'] ?? 'nouveau') === 'nouveau' && ($r['suivi'] ?? 'nouveau') !== 'nouveau') {
            $g['suivi'] = $r['suivi'];
        }
        if (empty($g['commentaire']) && !empty($r['commentaire'])) {
            $g['commentaire'] = $r['commentaire'];
        }
        $g['updated_at'] = max((string) $r['date'], (string) ($g['updated_at'] ?? ''));
        $g['nb_mises_a_jour'] = (int) ($g['nb_mises_a_jour'] ?? 0) + 1;
        $gardes[$cible] = $g;
        $aSupprimer[] = (int) $r['id'];
        $fusions[(int) $g['id']] = true;
    }
    if (!$aSupprimer) {
        return ['fusionnes' => 0, 'restants' => count($rows), 'leads_touches' => 0];
    }
    $db->beginTransaction();
    foreach ($gardes as $g) {
        if (!isset($fusions[(int) $g['id']])) {
            continue;
        }
        $data = $g;
        $id = (int) $data['id'];
        unset($data['id']);
        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($data)));
        $vals = array_values($data);
        $vals[] = $id;
        $db->prepare("UPDATE chat_leads SET $set WHERE id = ?")->execute($vals);
    }
    $db->prepare('DELETE FROM chat_leads WHERE id IN (' . implode(',', array_fill(0, count($aSupprimer), '?')) . ')')
        ->execute($aSupprimer);
    $db->commit();
    return ['fusionnes' => count($aSupprimer), 'restants' => count($rows) - count($aSupprimer), 'leads_touches' => count($fusions)];
}

// ======================================================================== Répondeur : routage
//
// Chaque demande (SAV, finance, commercial) est orientée selon trois critères — la marque,
// le type de client (direct ou distributeur) et l'urgence — complétés du service et, pour
// ABAC, du type d'équipement. Les règles sont évaluées dans l'ordre ; la première qui
// correspond désigne un rôle (RSO, CTA, back-office…) ou des personnes de l'annuaire.

/** Minuscules sans accents, pour comparer des libellés saisis librement. */
function ma_plat(?string $s): string
{
    $s = mb_strtolower(trim((string) $s));
    return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
}

/** Liste « a, b ;c » → ['a', 'b', 'c'] en minuscules sans accents. */
function ma_csv(?string $s): array
{
    return array_values(array_filter(array_map('ma_plat', preg_split('/[,;]+/', (string) $s) ?: []), fn($x) => $x !== ''));
}

/**
 * Listes paramétrables du routage : marques, natures de demande, rôles de l'annuaire.
 * Chaque liste se modifie depuis la page (paramètres rep_liste_*, en JSON) ; à défaut,
 * on part des valeurs ci-dessous. « mots » : mots-clés qui permettent de reconnaître
 * l'élément dans un texte libre. Pour une nature, « devis+piece » exige les deux mots.
 */
function ma_rep_listes(PDO $db): array
{
    $defauts = [
        'marques' => [
            ['code' => 'worthington', 'libelle' => 'Worthington Creyssensac', 'mots' => 'worthington, creyssensac, rollair, rlr, dixair, dnx, decibair, snx, pixair, pxr, blocair'],
            ['code' => 'mauguiere', 'libelle' => 'Mauguière', 'mots' => 'mauguiere, mav, mrl'],
            ['code' => 'abac', 'libelle' => 'ABAC', 'mots' => 'abac, genesis, formula, cross'],
            ['code' => 'pneumatech', 'libelle' => 'Pneumatech', 'mots' => 'pneumatech'],
            ['code' => 'ovity', 'libelle' => 'OVITY (acquisition)', 'mots' => 'ovity'],
            ['code' => 'fitec', 'libelle' => 'FITEC (acquisition)', 'mots' => 'fitec'],
        ],
        'natures' => [
            ['code' => 'commande_pieces', 'libelle' => 'Pièces : commande ou suivi de livraison',
                'mots' => 'commande+piece, livraison+piece, suivi+piece, commande+kit, commande+filtre, commande+huile'],
            ['code' => 'devis_pieces', 'libelle' => 'Devis pièces détachées ou SAV (réparation, entretien)',
                'mots' => 'devis+piece, devis+kit, devis+filtre, devis+entretien, devis+sav, devis+reparation, prix+piece'],
            ['code' => 'commande_equipement', 'libelle' => 'Équipement : commande ou suivi de livraison',
                'mots' => 'commande+compresseur, livraison+compresseur, suivi+compresseur, commande+secheur, commande+equipement, livraison+equipement'],
            ['code' => 'devis_equipement', 'libelle' => 'Devis équipement neuf',
                'mots' => 'devis+compresseur, devis+secheur, devis+equipement, prix+compresseur, devis+materiel'],
            ['code' => 'autre', 'libelle' => 'Autre demande commerciale', 'mots' => ''],
        ],
        // Chaque rôle appartient à un seul service : les équipes finance, SAV et commerce sont distinctes.
        'roles' => [
            ['code' => 'finance', 'libelle' => 'Finance', 'mots' => 'finance'],
            ['code' => 'backoffice', 'libelle' => 'Back-office support', 'mots' => 'sav'],
            ['code' => 'rso', 'libelle' => 'RSO (terrain)', 'mots' => 'sav'],
            ['code' => 'cta', 'libelle' => 'CTA ABAC (agent externe)', 'mots' => 'sav'],
            ['code' => 'boite', 'libelle' => 'Boîte partagée', 'mots' => 'commerce'],
            ['code' => 'direct_projet', 'libelle' => 'Direct / Projet', 'mots' => 'commerce'],
        ],
    ];
    $params = $db->query("SELECT cle, valeur FROM parametres WHERE cle LIKE 'rep_liste_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach ($defauts as $cle => $def) {
        $v = json_decode((string) ($params['rep_liste_' . $cle] ?? ''), true);
        $v = is_array($v) ? array_values(array_filter($v, fn($x) => is_array($x) && trim((string) ($x['code'] ?? '')) !== '')) : [];
        $out[$cle] = $v ?: $def;
    }
    return $out;
}

/** Un mot-clé est présent en début de mot (« mav » reconnaît « MAVD 151 », pas « amave »). */
function ma_mot_present(string $texte, string $mot): bool
{
    $mot = ma_plat($mot);
    return $mot !== '' && preg_match('/(^|[^a-z0-9])' . preg_quote($mot, '/') . '/', $texte) === 1;
}

/** Marque normalisée (code de la liste des marques), 'autre' si inconnue, '' si non renseignée. */
function ma_rep_marque(?string $marque, ?string $modele = null, ?array $marques = null): string
{
    $marques ??= ma_rep_listes(ma_db())['marques'];
    $codes = array_map(fn($x) => ma_plat($x['code']), $marques);
    $m = ma_plat($marque);
    if ($m !== '' && in_array($m, $codes, true)) {
        return $m;
    }
    // La marque dite, puis le modèle : les gammes reconnaissables trahissent la marque.
    foreach ([$m, ma_plat($modele)] as $texte) {
        if ($texte === '') {
            continue;
        }
        foreach ($marques as $x) {
            foreach (ma_csv($x['mots'] ?? '') as $mot) {
                if (ma_mot_present($texte, $mot)) {
                    return ma_plat($x['code']);
                }
            }
        }
    }
    return $m === '' ? '' : 'autre';
}

/** Nature de la demande : le code transmis par Claire, sinon déduit des mots de la demande. */
function ma_rep_nature(array $d, array $natures): string
{
    $codes = array_map(fn($x) => ma_plat($x['code']), $natures);
    $dit = ma_plat($d['nature'] ?? $d['type_demande'] ?? null);
    if ($dit !== '' && in_array($dit, $codes, true)) {
        return $dit;
    }
    $texte = ma_plat(implode(' ', [$d['nature'] ?? '', $d['besoin_commercial'] ?? '', $d['resume'] ?? '']));
    if (trim($texte) === '') {
        return '';
    }
    foreach ($natures as $x) {
        foreach (ma_csv($x['mots'] ?? '') as $groupe) {
            $tous = array_filter(array_map('trim', explode('+', $groupe)));
            if ($tous && count(array_filter($tous, fn($mot) => ma_mot_present($texte, $mot))) === count($tous)) {
                return ma_plat($x['code']);
            }
        }
    }
    return '';
}

/** direct | distributeur. Un compte distributeur retrouvé vaut preuve. */
function ma_rep_type_client(array $d): string
{
    $t = ma_plat($d['type_client'] ?? $d['type_interlocuteur'] ?? null);
    if (str_contains($t, 'distri') || str_contains($t, 'revend') || trim((string) ($d['compte_distributeur'] ?? '')) !== '') {
        return 'distributeur';
    }
    return 'direct';
}

/** Département à partir du code postal du site (ou d'un département déjà donné). */
function ma_departement(?string $codePostal, ?string $departement = null): ?string
{
    $dep = strtoupper(trim((string) $departement));
    if (preg_match('/^(2A|2B)$/', $dep)) {
        return $dep;
    }
    if (preg_match('/^\d{1,3}$/', $dep)) {
        return str_pad($dep, 2, '0', STR_PAD_LEFT);
    }
    $cp = preg_replace('/\D/', '', (string) $codePostal);
    if (strlen($cp) === 4) {
        $cp = '0' . $cp;                    // 1000 → 01000
    }
    if (strlen($cp) !== 5) {
        return null;
    }
    if (str_starts_with($cp, '97') || str_starts_with($cp, '98')) {
        return substr($cp, 0, 3);
    }
    if (str_starts_with($cp, '20')) {
        return ((int) $cp < 20200) ? '2A' : '2B';
    }
    return substr($cp, 0, 2);
}

/** Portable au format international pour l'envoi de SMS (+33612345678), sinon null. */
function ma_mobile_sms(?string $tel): ?string
{
    $t = ma_tel($tel);
    if (!$t) {
        return null;
    }
    // En France, seuls les 06 et 07 reçoivent des SMS ; l'étranger est accepté tel quel.
    if (str_starts_with($t, '33') && !preg_match('/^33[67]\d{8}$/', $t)) {
        return null;
    }
    return '+' . $t;
}

function ma_rep_urgent(array $d): bool
{
    return ma_bool($d['urgence'] ?? false) || strtoupper(trim((string) ($d['priorite'] ?? ''))) === 'URGENT';
}

/** Service normalisé : sav | commercial | finance | autre. */
function ma_rep_service(?string $s): string
{
    $s = ma_plat($s);
    return ['sav' => 'sav', 'technique' => 'sav', 'commercial' => 'commercial', 'finance' => 'finance'][$s] ?? ($s ?: 'autre');
}

/** Une règle s'applique-t-elle à ces critères (déjà normalisés) ? Un critère vide vaut « tous ». */
function ma_rep_regle_correspond(array $r, array $c): bool
{
    $s = ma_csv($r['service'] ?? null);
    $m = ma_csv($r['marques'] ?? null);
    $n = ma_csv($r['natures'] ?? null);
    $equip = (string) ($c['equip'] ?? '');
    return (!$s || in_array($c['service'], $s, true))
        && (!$m || in_array(($c['marque'] ?? '') === '' ? 'autre' : $c['marque'], $m, true))
        // Nature non reconnue : « autre », pour tomber sur la règle générale plutôt que nulle part.
        && (!$n || in_array(($c['nature'] ?? '') === '' ? 'autre' : $c['nature'], $n, true))
        && (trim((string) ($r['type_client'] ?? '')) === '' || ma_plat($r['type_client']) === ($c['type_client'] ?? 'direct'))
        && (trim((string) ($r['urgence'] ?? '')) === '' || (ma_plat($r['urgence']) === 'oui') === (bool) ($c['urgent'] ?? false))
        // Équipement non précisé : on le traite comme « autre » plutôt que de perdre la demande.
        && (trim((string) ($r['type_equipement'] ?? '')) === '' || ma_plat($r['type_equipement']) === ($equip ?: 'autre'));
}

/**
 * Destinataires d'une demande du répondeur.
 *
 * Retourne la règle retenue, les personnes désignées, les adresses (to, cc), les portables
 * pour le SMS, et le contenu prêt à envoyer (objet, corps HTML, texte du SMS). Rien n'est
 * écrit en base : la même fonction sert à la création d'une demande et au simulateur.
 */
function ma_rep_router(PDO $db, array $d): array
{
    $params = $db->query('SELECT cle, valeur FROM parametres')->fetchAll(PDO::FETCH_KEY_PAIR);
    $listes = ma_rep_listes($db);
    $service = ma_rep_service($d['service'] ?? null);
    $marque = ma_rep_marque($d['marque'] ?? null, $d['modele'] ?? null, $listes['marques']);
    $nature = ma_rep_nature($d, $listes['natures']);
    $typeClient = ma_rep_type_client($d);
    // L'urgence (panne, production arrêtée) n'existe qu'au SAV : finance et commerce ne sont jamais urgents.
    $urgent = $service === 'sav' && ma_rep_urgent($d);
    $equip = ma_plat($d['type_equipement'] ?? null);
    $equip = $equip === '' ? '' : (str_contains($equip, 'piston') ? 'piston' : 'autre');
    $dep = ma_departement($d['code_postal'] ?? null, $d['departement'] ?? null);
    $notes = [];

    $contacts = $db->query('SELECT * FROM rep_contacts WHERE actif = 1 ORDER BY nom')->fetchAll();
    $regles = $db->query('SELECT * FROM rep_regles WHERE actif = 1 ORDER BY ordre, id')->fetchAll();

    $critere = compact('service', 'marque', 'nature', 'urgent', 'equip') + ['type_client' => $typeClient];
    $correspond = fn(array $r): bool => ma_rep_regle_correspond($r, $critere);
    $pourLaMarque = fn(array $c) => !ma_csv($c['marques']) || $marque === '' || in_array($marque, ma_csv($c['marques']), true);
    $duRole = fn(string $role) => array_values(array_filter($contacts,
        fn($c) => ma_plat($c['role']) === ma_plat($role) && $pourLaMarque($c)));

    $regle = null;
    foreach ($regles as $r) {
        if ($correspond($r)) {
            $regle = $r;
            break;
        }
    }

    $personnes = [];
    if ($regle) {
        $role = (string) ($regle['cible_role'] ?? '');
        if ($regle['cible'] === 'contacts') {
            $ids = array_map('intval', ma_csv($regle['cible_contacts']));
            $personnes = array_values(array_filter($contacts, fn($c) => in_array((int) $c['id'], $ids, true)));
        } elseif ($regle['cible'] === 'role_departement') {
            $personnes = $dep === null ? [] : array_values(array_filter($duRole($role),
                fn($c) => in_array(strtolower($dep), array_map(fn($x) => str_pad($x, 2, '0', STR_PAD_LEFT), ma_csv($c['departements'])), true)));
            if (!$personnes) {
                $repli = (string) ($regle['repli_role'] ?? '');
                $noms = ['rso' => 'RSO', 'cta' => 'CTA', 'backoffice' => 'back-office support', 'finance' => 'finance'];
                $notes[] = $dep === null
                    ? 'Département du site inconnu : impossible de choisir le ' . ($noms[$role] ?? $role) . ' du secteur'
                    : 'Aucun ' . ($noms[$role] ?? $role) . ' ne couvre le ' . $dep;
                if ($repli !== '') {
                    $personnes = $duRole($repli);
                    $notes[] = 'Relais : ' . ($noms[$repli] ?? $repli);
                }
            }
        } else {
            $personnes = $duRole($role);
        }
    } else {
        $notes[] = 'Aucune règle ne correspond';
    }

    // Personne de joignable : le responsable du service, puis le responsable des sujets indéterminés.
    $joignable = fn(array $liste) => array_filter($liste, fn($p) => ma_liste_emails($p['email'] ?? null));
    if (!$joignable($personnes)) {
        $resp = ma_responsables($db);
        foreach ([$service, 'autre'] as $svcResp) {
            $r = $resp[$svcResp] ?? null;
            if ($r && (int) $r['actif'] === 1 && ma_liste_emails($r['email'] ?? null)) {
                $personnes = [$r];
                $notes[] = 'Transmise au responsable ' . (['sav' => 'SAV', 'finance' => 'finance', 'commercial' => 'commerce'][$svcResp] ?? 'des sujets indéterminés')
                    . ' (' . $r['nom'] . ')';
                break;
            }
        }
    }

    $to = [];
    $sms = [];
    foreach ($personnes as $p) {
        $to += ma_liste_emails($p['email'] ?? null);
        if (trim((string) ($p['email'] ?? '')) === '') {
            $notes[] = $p['nom'] . ' : email manquant dans l\'annuaire';
        }
        if ($m = ma_mobile_sms($p['mobile'] ?? null)) {
            $sms[$m] = $m;
        }
    }
    if (!$to) {
        $to = ma_liste_emails($params['rep_repli_email'] ?? 'cyril.mortier@airwco.com');
        $notes[] = 'Aucun destinataire joignable : envoi à l\'adresse de repli';
    }
    $cc = ma_liste_emails($regle['cc'] ?? null) + ($urgent ? ma_liste_emails($params['rep_cc_urgence'] ?? null) : []);
    foreach ($to as $k => $_) {
        unset($cc[$k]);
    }

    $libelle = function (array $liste, string $code): string {
        foreach ($liste as $x) {
            if (ma_plat($x['code']) === $code) {
                return (string) ($x['libelle'] ?? $code);
            }
        }
        return $code === 'autre' ? 'Autre marque' : '';
    };
    $contenu = ma_rep_message($d, [
        'marque_libelle' => $libelle($listes['marques'], $marque), 'nature_libelle' => $nature ? $libelle($listes['natures'], $nature) : '',
        'service' => $service, 'marque' => $marque, 'type_client' => $typeClient, 'urgent' => $urgent,
        'departement' => $dep, 'personnes' => $personnes, 'url' => $params['url_tableau_de_bord']
            ?? 'https://multiairfrance.store/calculateurs/interne/MULTIAIR/',
        'lien_interne' => !empty($d['jeton_interne']) ? ma_url_base($db) . 'demande.php?t=' . $d['jeton_interne'] : '',
    ]);

    return [
        'service' => $service, 'marque' => $marque, 'nature' => $nature, 'type_client' => $typeClient, 'urgent' => $urgent,
        'type_equipement' => $equip, 'departement' => $dep,
        'regle_id' => $regle ? (int) $regle['id'] : null,
        'regle_libelle' => $regle['libelle'] ?? 'Aucune règle (repli)',
        'personnes' => array_map(fn($p) => ['id' => (int) $p['id'], 'nom' => $p['nom'], 'role' => $p['role'],
            'email' => $p['email'], 'mobile' => $p['mobile'], 'membres' => $p['membres'] ?? null], $personnes),
        'to' => implode(';', array_values($to)),
        'cc' => implode(';', array_values($cc)),
        'sms' => implode(';', array_values($sms)),
        'notes' => $notes,
    ] + $contenu;
}

/** Objet, corps HTML et texte SMS de la transmission. L'urgence se voit dès l'objet. */
function ma_rep_message(array $d, array $x): array
{
    $svc = ['sav' => 'SAV', 'commercial' => 'Commercial', 'finance' => 'Finance'][$x['service']] ?? 'Sujet à orienter';
    $marque = (string) ($x['marque_libelle'] ?? '');
    $societe = trim((string) ($d['societe'] ?? $d['distributeur'] ?? '')) ?: 'Société non précisée';
    $contact = trim((string) ($d['contact'] ?? ''));
    $telBrut = ma_tel($d['tel'] ?? $d['telephone'] ?? null);
    $tel = $telBrut && preg_match('/^33(\d{9})$/', $telBrut, $mm) ? '0' . $mm[1] : ($telBrut ?? '');
    $telLisible = preg_match('/^0\d{9}$/', $tel) ? trim(chunk_split($tel, 2, ' ')) : $tel;
    $client = $x['type_client'] === 'distributeur' ? 'Distributeur' : 'Client direct';
    $dep = $x['departement'] ? ' (' . $x['departement'] . ')' : '';

    $nature = (string) ($x['nature_libelle'] ?? '');
    $objet = ($x['urgent'] ? '🔴 URGENT — PRODUCTION ARRÊTÉE — ' : '')
        . $svc . ($marque ? ' ' . $marque : '') . ($nature && $x['service'] === 'commercial' ? ' — ' . $nature : '')
        . ' — ' . $societe . $dep . ' — ' . $client;

    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $qui = implode(', ', array_map(fn($p) => $p['nom'], $x['personnes']));
    $canal = ma_canal($d);
    $sujet = trim((string) ($d['type_panne'] ?? '')) ?: (trim((string) ($d['besoin_commercial'] ?? '')) ?: ($nature ?: (
        trim((string) ($d['reference_facture'] ?? '')) !== '' ? 'Facture ' . trim((string) $d['reference_facture']) : '')));
    $titre = 'Rappeler ' . ($contact ?: 'le client') . ($societe !== 'Société non précisée' ? ' (' . $societe . ')' : '')
        . ($sujet !== '' ? ' — ' . $sujet : '');
    $materiel = trim(implode(' ', array_filter([$marque, trim((string) ($d['modele'] ?? ''))])))
        . (trim((string) ($d['numero_serie'] ?? '')) !== '' ? ' · n° de série ' . trim((string) $d['numero_serie']) : '');
    $site = trim((string) ($d['code_postal'] ?? '')) ?: ($x['departement'] ?? '');
    $lien = (string) ($x['lien_interne'] ?? '');
    $html = ma_mail_gabarit([
        'surtitre' => !empty($x['transfert']) ? 'Demande transférée' : 'Nouvelle demande ' . $svc,
        'bandeau' => $x['urgent'] ? 'URGENT — production arrêtée · à rappeler tout de suite' : '',
        'etiquettes' => [
            $x['urgent'] ? ['URGENT', '#b3261e', '#ffffff'] : ['À rappeler', '#fff1e0', '#9a4a06'],
            ['Reçue ' . (MA_CANAUX[$canal][1] ?? 'par téléphone'), '#eef1f4', '#334155'],
        ],
        'titre' => $titre,
        'corps' => !empty($x['transfert']) ? '<p style="margin:0;background:#e5edfb;color:#1d4ed8;border-radius:10px;padding:10px 14px;font-weight:600">'
            . $e($x['transfert']) . '</p>' : '',
        'fiche' => ma_mail_fiche([
            ['Téléphone', $telLisible ? '<a href="tel:+' . $e($telBrut) . '" style="color:#0f2f52;font-weight:800;font-size:16px;text-decoration:none">' . $e($telLisible) . '</a>' : '', true],
            ['Client', $societe . ' · ' . mb_strtolower($client), false],
            ['Site', $site, false],
            ['Matériel', $materiel, false],
            ['Équipement', $d['type_equipement'] ?? '', false],
            ['Demande', $sujet !== ($d['type_panne'] ?? null) ? $sujet : '', false],
            ['Problème', $d['type_panne'] ?? $d['description'] ?? '', false],
            ['Pourquoi urgent', $x['urgent'] ? ($d['justification_urgence'] ?? '') : '', false],
            ['Résumé', $d['resume'] ?? '', false],
            ['E-mail du client', $d['email'] ?? '', false],
            ['Transmise à', $qui, false],
        ]),
        'bouton' => $lien !== '' ? ['Ouvrir la demande — je la prends en charge', $lien] : ['Ouvrir la plateforme Multiair', $x['url']],
        'apres' => 'Le client a reçu un message de confirmation. Il est prévenu automatiquement quand vous prenez la demande, '
            . 'planifiez un rappel ou la marquez traitée.<br>Répondre à cet e-mail écrit directement au client.',
        'pied' => 'Plateforme Multiair — le lien de ce message est réservé à l\'équipe.',
    ]);

    $sms = ($x['urgent'] ? 'URGENT prod arretee - ' : '') . 'Multiair ' . $svc . ($marque ? ' ' . $marque : '')
        . ' : ' . $societe . $dep . ' - rappeler ' . ($contact ?: 'le client') . ($telLisible ? ' au ' . $telLisible : '')
        . (trim((string) ($d['type_panne'] ?? $d['resume'] ?? '')) !== '' ? ' - ' . trim((string) ($d['type_panne'] ?? $d['resume'])) : '');
    $lienSms = (string) ($x['lien_interne'] ?? '');
    $max = $lienSms !== '' ? 300 - mb_strlen($lienSms) - 3 : 300;
    if (mb_strlen($sms) > $max) {
        $sms = mb_substr($sms, 0, $max - 3) . '...';
    }
    if ($lienSms !== '') {
        $sms .= ' - ' . $lienSms;
    }
    return ['objet' => $objet, 'mail_html' => $html, 'sms_texte' => $sms];
}

// ------------------------------------------------------------------------ Répondeur : tableaux de routage
//
// L'écran ne montre pas de règles : il montre, pour chaque service, un tableau « qui reçoit
// quoi ». SAV : marque × type de client. Commerce : nature de la demande × marque. Finance :
// toutes les personnes de la finance. Les règles sont reconstruites à partir de ces tableaux.

/** Lignes et colonnes des tableaux. */
function ma_rep_grille_axes(): array
{
    return [
        'sav_marques' => ['worthington', 'mauguiere', 'pneumatech', 'abac', 'autre'],
        'sav_clients' => ['direct', 'distributeur'],
        'sav_choix' => ['rso', 'cta', 'backoffice', 'repli'],
        'com_natures' => ['commande_pieces', 'devis_pieces', 'commande_equipement', 'devis_equipement'],
        'com_marques' => ['abac', 'worthington', 'mauguiere', 'pneumatech', 'autre'],
    ];
}

/** Les tableaux tels que les règles actuelles les appliquent. */
function ma_rep_grilles_lire(PDO $db): array
{
    $axes = ma_rep_grille_axes();
    $regles = $db->query('SELECT * FROM rep_regles WHERE actif = 1 ORDER BY ordre, id')->fetchAll();
    $premiere = function (array $critere, ?callable $garder = null) use ($regles): ?array {
        foreach ($regles as $r) {
            if (($garder === null || $garder($r)) && ma_rep_regle_correspond($r, $critere)) {
                return $r;
            }
        }
        return null;
    };
    $sav = [];
    foreach ($axes['sav_marques'] as $m) {
        foreach ($axes['sav_clients'] as $t) {
            $r = $premiere(['service' => 'sav', 'marque' => $m, 'type_client' => $t]);
            $sav[$m][$t] = $r && in_array($r['cible_role'], ['rso', 'cta', 'backoffice'], true) ? $r['cible_role'] : 'repli';
        }
    }
    $idDe = fn(?array $r) => $r && $r['cible'] === 'contacts' ? (int) (ma_csv($r['cible_contacts'])[0] ?? 0) ?: null : null;
    // Une case du commerce ne lit que les règles qui précisent une nature : l'acquisition et
    // « tout le reste » ont leurs propres lignes.
    $commerce = [];
    foreach ($axes['com_natures'] as $n) {
        foreach ($axes['com_marques'] as $m) {
            $commerce[$n][$m] = $idDe($premiere(['service' => 'commercial', 'marque' => $m, 'nature' => $n],
                fn($r) => trim((string) $r['natures']) !== ''));
        }
    }
    $acq = $premiere(['service' => 'commercial', 'marque' => 'ovity', 'nature' => 'autre'],
        fn($r) => trim((string) $r['natures']) === '' && trim((string) $r['marques']) !== '');
    $reste = $premiere(['service' => 'commercial', 'marque' => 'autre', 'nature' => 'autre'],
        fn($r) => trim((string) $r['natures']) === '' && trim((string) $r['marques']) === '');
    return ['sav' => $sav, 'commerce' => $commerce, 'acquisitions' => $idDe($acq), 'reste' => $idDe($reste)];
}

/** Reconstruit les règles à partir des tableaux. Une case vide renvoie à l'adresse de repli. */
function ma_rep_grilles_enregistrer(PDO $db, array $g): void
{
    $axes = ma_rep_grille_axes();
    $noms = ['worthington' => 'Worthington', 'mauguiere' => 'Mauguière', 'pneumatech' => 'Pneumatech', 'abac' => 'ABAC', 'autre' => 'autre marque'];
    $cibles = ['rso' => 'RSO du secteur', 'cta' => 'CTA du secteur', 'backoffice' => 'back-office'];
    $natures = ['commande_pieces' => 'Pièces : commande / livraison', 'devis_pieces' => 'Devis pièces / SAV',
        'commande_equipement' => 'Équipement : commande / livraison', 'devis_equipement' => 'Devis équipement'];
    $contacts = $db->query('SELECT id, nom FROM rep_contacts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $ins = $db->prepare('INSERT INTO rep_regles(ordre, libelle, service, marques, type_client, natures, cible, cible_role, cible_contacts, repli_role, actif)
        VALUES (?,?,?,?,?,?,?,?,?,?,1)');
    $db->beginTransaction();
    $db->exec("DELETE FROM rep_regles WHERE service IN ('finance', 'sav', 'commercial')");
    $ins->execute([10, 'Finance → toutes les personnes de la finance', 'finance', null, null, null, 'role', 'finance', null, null]);
    $ordre = 20;
    foreach ($axes['sav_marques'] as $m) {
        foreach ($axes['sav_clients'] as $t) {
            $v = (string) ($g['sav'][$m][$t] ?? 'repli');
            if (!isset($cibles[$v])) {
                continue;
            }
            $ins->execute([$ordre++, 'SAV ' . $noms[$m] . ' — ' . ($t === 'direct' ? 'client direct' : 'distributeur') . ' → ' . $cibles[$v],
                'sav', $m, $t, null, $v === 'backoffice' ? 'role' : 'role_departement', $v, null, $v === 'backoffice' ? null : 'backoffice']);
        }
    }
    $boite = fn($id) => ($id && isset($contacts[(int) $id])) ? (int) $id : null;
    if ($id = $boite($g['acquisitions'] ?? null)) {
        $ins->execute([95, 'Acquisitions OVITY / FITEC → ' . $contacts[$id], 'commercial', 'ovity,fitec', null, null, 'contacts', null, (string) $id, null]);
    }
    $ordre = 100;
    foreach ($axes['com_natures'] as $n) {
        foreach ($axes['com_marques'] as $m) {
            if ($id = $boite($g['commerce'][$n][$m] ?? null)) {
                $ins->execute([$ordre, $natures[$n] . ' — ' . $noms[$m] . ' → ' . $contacts[$id], 'commercial', $m, null, $n, 'contacts', null, (string) $id, null]);
            }
            $ordre++;
        }
    }
    if ($id = $boite($g['reste'] ?? null)) {
        $ins->execute([190, 'Commercial — tout le reste → ' . $contacts[$id], 'commercial', null, null, null, 'contacts', null, (string) $id, null]);
    }
    $db->commit();
}

/** Annuaire et tableaux de départ, d'après les schémas SAV / finance / commerce fournis par Multiair. */
function ma_rep_routage_initial(PDO $db): void
{
    $c = $db->prepare('INSERT INTO rep_contacts(nom, role, email, membres, competences, externe, actif, commentaire) VALUES (?,?,?,?,?,?,1,?)');
    $ajout = function (string $nom, string $role, ?string $email, string $membres = '', string $activite = '', ?string $commentaire = null) use ($db, $c): int {
        $c->execute([$nom, $role, $email, $membres ?: null, $activite ?: null, $role === 'cta' ? 1 : 0, $commentaire]);
        return (int) $db->lastInsertId();
    };
    // Finance
    $ajout('Compta clients', 'finance', 'Compta.clients@airwco.com', '', 'Facturation, règlements', 'Boîte partagée de la finance');
    // SAV
    $ajout('Julien', 'backoffice', null, '', 'Support technique distributeurs', 'Email et portable à compléter');
    $ajout('Marien', 'backoffice', null, '', 'Support technique distributeurs', 'Email et portable à compléter');
    // Commerce : les boîtes partagées et ceux qui les lisent
    $b = [];
    $b['cts_abac'] = $ajout('CTS commandes ABAC', 'boite', 'commandes.abac.pieces@multiairfrance.fr',
        'Aurelien Slastan, Prisca Vieille, Audrey Dovillers, Nadiya Messioui, Marie Laure Gomez, Anaelle Alexandre',
        'Commandes et suivi de livraison des pièces ABAC');
    $b['cts_frb'] = $ajout('CTS commandes FRB', 'boite', 'commandes.pieces@multiairfrance.fr', 'Stephanie Quesmel',
        'Commandes et suivi de livraison des pièces Worthington, Mauguière, Pneumatech');
    $b['cts_devis'] = $ajout('CTS devis', 'boite', 'devis.pieces@multiairfrance.fr',
        'Melvin Mayennaquiby, Axel Grimaud, Nicolas Boulet, Charline Laptes, Ndeye Beye', 'Devis pièces détachées et SAV, toutes marques');
    $b['pad_cdes'] = $ajout('PAD commandes', 'boite', 'commandes.abac.materiel@multiairfrance.fr',
        'Prisca Vieille, Audrey Dovillers, Nadiya Messioui, Marie Laure Gomez, Anaelle Alexandre, Aurelien Slastan, Doriane Afonso Da Silva',
        'Commandes et suivi de livraison des équipements ABAC');
    $b['pad_devis'] = $ajout('PAD devis', 'boite', 'devis.abac.materiel@multiairfrance.fr',
        'Aurelien Slastan, Prisca Vieille, Audrey Dovillers, Nadiya Messioui, Marie Laure Gomez, Anaelle Alexandre', 'Devis équipements ABAC');
    $b['aim_cdes'] = $ajout('AIM commandes', 'boite', 'commandes.materiel@multiairfrance.fr',
        'Marie Jotterand, Mathieu Patte, Aurelien Slastan, Prisca Vieille, Audrey Dovillers, Nadiya Messioui, Marie Laure Gomez, Anaelle Alexandre, Doriane Afonso Da Silva',
        'Commandes et suivi de livraison des équipements Worthington et Mauguière');
    $b['devis_mat'] = $ajout('Devis matériel neuf', 'boite', 'devis.materiel@multiairfrance.fr', 'Celine Charlier, Chloe Baudet',
        'Devis équipements Worthington, Mauguière, Pneumatech');
    $b['acq'] = $ajout('Devis acquisitions', 'boite', 'devis.acquisitions@multiairfrance.fr', 'Cecile Ollier, Bruno Picciano',
        'Acquisitions OVITY et FITEC');
    $ajout('Devis techniciens', 'boite', 'devis.techniciens@airwco.com', 'Lionel Lebre, Esteban Godefroy', 'À préciser',
        'Rôle à préciser : aucune case du tableau ne l\'utilise encore');
    foreach (['Gwenaelle Pacheco', 'Doriane Afonso Da Silva', 'Emma De Faria'] as $nom) {
        $ajout($nom, 'direct_projet', null, '', 'Direct / Projet', 'Email à compléter ; critère de routage à préciser');
    }

    $wmp = ['direct' => 'rso', 'distributeur' => 'backoffice'];
    $cts = ['abac' => $b['cts_abac'], 'worthington' => $b['cts_frb'], 'mauguiere' => $b['cts_frb'], 'pneumatech' => $b['cts_frb'], 'autre' => null];
    ma_rep_grilles_enregistrer($db, [
        'sav' => ['worthington' => $wmp, 'mauguiere' => $wmp, 'pneumatech' => $wmp,
            'abac' => ['direct' => 'cta', 'distributeur' => 'backoffice'], 'autre' => ['direct' => 'repli', 'distributeur' => 'repli']],
        'commerce' => [
            'commande_pieces' => $cts,
            'devis_pieces' => array_fill_keys(['abac', 'worthington', 'mauguiere', 'pneumatech', 'autre'], $b['cts_devis']),
            // Commande d'équipement Pneumatech : pas de boîte désignée dans le tableau fourni.
            'commande_equipement' => ['abac' => $b['pad_cdes'], 'worthington' => $b['aim_cdes'], 'mauguiere' => $b['aim_cdes'], 'pneumatech' => null, 'autre' => null],
            'devis_equipement' => ['abac' => $b['pad_devis'], 'worthington' => $b['devis_mat'], 'mauguiere' => $b['devis_mat'], 'pneumatech' => $b['devis_mat'], 'autre' => null],
        ],
        'acquisitions' => $b['acq'],
        'reste' => null,
    ]);
}

// ------------------------------------------------------------------ Suivi des demandes du répondeur
// Deux liens secrets par demande : l'un pour l'équipe (voir et faire avancer la demande sans
// compte), l'autre pour le client (voir où en est SA demande, rien d'autre). Aucun des deux ne
// montre le portable ni l'e-mail d'un collaborateur.

function ma_url_base(PDO $db): string
{
    $st = $db->prepare("SELECT valeur FROM parametres WHERE cle = 'url_tableau_de_bord'");
    $st->execute();
    $url = trim((string) ($st->fetchColumn() ?: 'https://multiairfrance.store/calculateurs/interne/MULTIAIR/'));
    $url = preg_replace('/[#?].*$/', '', $url);
    return rtrim(preg_replace('#/index\.php$#', '/', $url), '/') . '/';
}

function ma_param(PDO $db, string $cle, string $defaut = ''): string
{
    $st = $db->prepare('SELECT valeur FROM parametres WHERE cle = ?');
    $st->execute([$cle]);
    $v = $st->fetchColumn();
    return $v === false || $v === null || trim((string) $v) === '' ? $defaut : (string) $v;
}

function ma_jeton(): string
{
    return bin2hex(random_bytes(16));
}

/** Liens de suivi d'une demande (vides si la demande n'a pas encore ses jetons). */
function ma_rep_liens(PDO $db, array $dem): array
{
    $base = ma_url_base($db);
    return [
        'interne' => !empty($dem['jeton_interne']) ? $base . 'demande.php?t=' . $dem['jeton_interne'] : '',
        'client' => !empty($dem['jeton_client']) ? $base . 'suivi.php?c=' . $dem['jeton_client'] : '',
    ];
}

function ma_date_fr(?string $dt, bool $heure = true): string
{
    $t = $dt ? strtotime($dt) : false;
    if (!$t) {
        return '';
    }
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    return $jours[(int) date('w', $t)] . ' ' . date('d/m', $t) . ($heure && date('H:i', $t) !== '00:00' ? ' à ' . date('G\hi', $t) : '');
}

function ma_rep_service_libelle(?string $svc): string
{
    return ['SAV' => 'service après-vente', 'COMMERCIAL' => 'service commercial', 'FINANCE' => 'service comptabilité'][strtoupper((string) $svc)]
        ?? 'service client';
}

/**
 * Personnes qui peuvent prendre la demande en charge : les destinataires, et pour une boîte
 * partagée, les personnes qui la lisent. Sert à la liste « Qui êtes-vous ? » de la page interne.
 */
function ma_rep_personnes_demande(PDO $db, array $dem): array
{
    $noms = [];
    foreach (ma_csv($dem['destinataires'] ?? null) as $nom) {
        $st = $db->prepare('SELECT role, membres FROM rep_contacts WHERE nom = ? LIMIT 1');
        $st->execute([$nom]);
        $c = $st->fetch();
        if ($c && $c['role'] === 'boite' && trim((string) $c['membres']) !== '') {
            foreach (ma_csv($c['membres']) as $m) {
                $noms[$m] = $m;
            }
        } else {
            $noms[$nom] = $nom;
        }
    }
    return array_values($noms);
}

const MA_CANAUX = ['telephone' => ['Téléphone', 'par téléphone'], 'whatsapp' => ['WhatsApp', 'sur WhatsApp'],
    'chat' => ['Chat du site', 'par le chat du site'], 'email' => ['E-mail', 'par e-mail']];

/** Canal d'une demande : celui qui est indiqué, sinon déduit de sa source. */
function ma_canal(array $d): string
{
    $c = ma_plat($d['canal'] ?? null);
    if (isset(MA_CANAUX[$c])) {
        return $c;
    }
    $src = ma_plat($d['source'] ?? null);
    return str_contains($src, 'chat') ? 'chat' : (in_array($src, ['email', 'adv', 'mail'], true) ? 'email' : ($src === 'whatsapp' ? 'whatsapp' : 'telephone'));
}

/** Objet de la demande tel que le client le reconnaît : matériel et problème, sans jargon interne. */
function ma_rep_objet_client(array $dem): array
{
    $materiel = trim(($dem['marque'] ?? '') . ' ' . ($dem['modele'] ?? ''));
    $materiel = in_array(ma_plat($materiel), ['inconnue', 'autre'], true) ? '' : $materiel;
    $sujet = trim((string) ($dem['type_panne'] ?: $dem['besoin_commercial'] ?: ($dem['reference_facture'] ? 'Facture ' . $dem['reference_facture'] : '')));
    return [$materiel, $sujet];
}

/** Ce que le client lit à chaque étape : titre, phrase, étape atteinte (1 reçue, 2 prise en charge, 3 traitée). */
function ma_rep_message_client(PDO $db, array $dem, string $evenement): array
{
    $qui = trim((string) ($dem['pris_par'] ?? ''));
    $role = strtoupper((string) $dem['service']) === 'SAV' ? 'responsable technique Multiair' : 'de l\'équipe Multiair';
    $rappel = ma_date_fr($dem['rappel_prevu'] ?? null);
    $num = 'n° ' . $dem['id'];
    switch ($evenement) {
        case 'recue':
            return ['Demande ' . $num . ' enregistrée', 'Votre demande est bien enregistrée et transmise à notre ' . ma_rep_service_libelle($dem['service']) . '.'
                . (($dem['priorite'] ?? '') === 'URGENT' ? ' Elle est traitée en priorité.' : ' Nous vous recontactons au plus vite.'), 1];
        case 'prise_en_charge':
            return ['Demande prise en charge', ($qui !== '' ? $qui . ', ' . $role . ',' : 'Notre équipe') . ' s\'occupe de votre demande ' . $num
                . ($rappel !== '' ? ' et vous rappelle ' . $rappel : ' et va vous recontacter') . '.', 2];
        case 'rappel':
            return ['Rappel prévu ' . $rappel, 'Un rappel est prévu ' . $rappel . ' pour votre demande ' . $num
                . ($qui !== '' ? ', par ' . $qui . ', ' . $role : '') . '.', 2];
        case 'traitee':
            return ['Demande traitée', 'Votre demande ' . $num . ' est traitée. Merci de votre confiance.', 3];
        case 'relance_client':
            $futur = !empty($dem['rappel_prevu']) && strtotime((string) $dem['rappel_prevu']) > time();
            return ['Demande toujours en cours', 'Votre demande ' . $num . ' est toujours en cours' . ($qui !== '' ? ', suivie par ' . $qui . ', ' . $role : '') . '. '
                . ($futur ? 'Votre rappel reste prévu ' . ma_date_parlee($dem['rappel_prevu']) . ', et nous avons prévenu l\'équipe de votre appel.'
                    : 'Nous avons relancé l\'équipe pour qu\'elle vous recontacte au plus vite.'), 2];
        case 'rouverte':
            return ['Demande rouverte', 'Votre demande ' . $num . ' a été rouverte et transmise à nouveau à notre équipe. Nous vous recontactons au plus vite.', 1];
    }
    return ['', '', 1];
}

/** Texte envoyé au client par WhatsApp. Jamais de coordonnée interne. */
function ma_rep_texte_client(PDO $db, array $dem, string $evenement): string
{
    [$titre, $phrase, $etape] = ma_rep_message_client($db, $dem, $evenement);
    if ($titre === '') {
        return '';
    }
    $bonjour = ma_rep_bonjour($db, $dem);
    $fin = $etape < 3 ? "\nSuivre ma demande : " . ma_rep_liens($db, $dem)['client']
        : "\nUne question ? Standard Multiair : " . ma_param($db, 'rep_standard_tel', '01 34 32 95 00');
    return '*' . $titre . "*\n" . $bonjour . "\n" . $phrase . $fin;
}

/** Version e-mail du même message : gabarit Multiair, barre d'avancement, bouton de suivi. */
function ma_rep_html_client(PDO $db, array $dem, string $evenement): string
{
    [$titre, $phrase, $etape] = ma_rep_message_client($db, $dem, $evenement);
    [$materiel, $sujet] = ma_rep_objet_client($dem);
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $standard = ma_param($db, 'rep_standard_tel', '01 34 32 95 00');
    $bonjour = ma_rep_bonjour($db, $dem);
    return ma_mail_gabarit([
        'titre' => $titre,
        'corps' => '<p style="margin:0 0 10px">' . $e($bonjour) . '</p><p style="margin:0">' . $e($phrase) . '</p>',
        'avancement' => ma_mail_avancement($etape),
        'fiche' => ma_mail_fiche([['Demande', 'n° ' . $dem['id'], false], ['Matériel', $materiel, false], ['Objet', $sujet, false]]),
        'bouton' => ['Suivre ma demande', ma_rep_liens($db, $dem)['client']],
        'apres' => 'Une question ? Appelez notre standard au <b>' . $e($standard) . '</b> en indiquant le n° ' . (int) $dem['id'] . '.',
        'pied' => 'Multiair France · Vous recevez ce message car vous avez contacté notre service.',
    ]);
}

/**
 * Prévient le client d'une étape (reçue, prise en charge, rappel prévu, traitée). Le texte est
 * préparé ici ; l'envoi (WhatsApp, e-mail) est fait par le scénario Make dont l'adresse est dans
 * le paramètre rep_webhook_suivi. Sans ce paramètre, rien ne part : la plateforme reste la référence.
 */
function ma_rep_notifier_client(PDO $db, array $dem, string $evenement): array
{
    $tel = (string) ($dem['tel'] ?? '');
    [$titre] = ma_rep_message_client($db, $dem, $evenement);
    $payload = [
        'evenement' => $evenement,
        'demande_id' => (int) $dem['id'],
        'service' => $dem['service'] ?? '',
        'contact' => $dem['contact'] ?? '',
        'societe' => $dem['societe'] ?? '',
        'tel' => $tel,
        // WhatsApp n'autorise pas d'écrire en premier à quelqu'un venu par le chat du site ou par e-mail.
        'mobile' => (bool) preg_match('/^33[67]\d{8}$/', $tel) && in_array(ma_canal($dem), ['telephone', 'whatsapp'], true),
        'email' => str_contains((string) ($dem['email'] ?? ''), '@') ? $dem['email'] : '',
        'lien' => ma_rep_liens($db, $dem)['client'],
        // Sans guillemets doubles ni antislash : le texte est inséré tel quel dans le JSON WhatsApp de Make.
        'texte' => str_replace(['"', '\\'], ["'", '/'], ma_rep_texte_client($db, $dem, $evenement)),
        'objet' => 'Multiair — ' . $titre,
        'html' => ma_rep_html_client($db, $dem, $evenement),
    ];
    $statut = ma_webhook_suivi($db, $payload);
    $db->prepare('INSERT INTO executions_log(scenario, date, statut, type_evenement, resume, payload) VALUES (?,?,?,?,?,?)')
        ->execute(['repondeur', ma_now(), str_starts_with($statut, 'échec') ? 'erreur' : 'info', 'suivi_client_' . $evenement,
            'Demande ' . $dem['id'] . ' — client prévenu : ' . $statut, json_encode(['texte' => $payload['texte']], JSON_UNESCAPED_UNICODE)]);
    $canaux = [];
    if ($payload['mobile']) {
        $canaux[] = ['canal' => 'WhatsApp', 'a' => ma_tel_lisible($tel)];
    }
    if ($payload['email'] !== '') {
        $canaux[] = ['canal' => 'E-mail', 'a' => $payload['email']];
    }
    ma_rep_evenement($db, (int) $dem['id'], 'client_prevenu', 'Plateforme', 'make',
        'Client prévenu — ' . $titre . ($canaux ? ' (' . implode(' + ', array_column($canaux, 'canal')) . ')' : ' — aucun WhatsApp ni e-mail connu'),
        ['etape' => $evenement, 'canaux' => $canaux, 'texte' => $payload['texte'], 'envoi' => $statut]);
    return $payload + ['envoi' => $statut];
}

/**
 * Fait avancer une demande : prise en charge, rappel prévu, traitée, commentaire. Même logique
 * depuis le tableau de bord et depuis la page interne ; le client est prévenu à chaque étape.
 */
function ma_rep_avancer(PDO $db, int $id, array $chg, string $par = '', string $via = 'plateforme'): array
{
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
    $st->execute([$id]);
    $avant = $st->fetch();
    if (!$avant) {
        throw new RuntimeException('Demande introuvable');
    }
    $now = ma_now();
    $maj = [];
    $evenements = [];
    $statut = $chg['statut'] ?? null;
    if ($statut === 'en_cours' && $avant['statut'] !== 'en_cours') {
        $maj['statut'] = 'en_cours';
        $maj['pris_at'] = $avant['pris_at'] ?: $now;
        if (trim((string) ($chg['pris_par'] ?? $par)) !== '') {
            $maj['pris_par'] = trim((string) ($chg['pris_par'] ?? $par));
        }
        $evenements[] = 'prise_en_charge';
    } elseif ($statut === 'traite' && $avant['statut'] !== 'traite') {
        $maj['statut'] = 'traite';
        $maj['traite_at'] = $now;
        $maj['traite_par'] = trim((string) ($chg['traite_par'] ?? $par)) ?: null;
        $evenements[] = 'traitee';
    } elseif ($statut === 'a_traiter' && $avant['statut'] !== 'a_traiter') {
        $maj['statut'] = 'a_traiter';
    }
    foreach (['pris_par', 'traite_par'] as $c) {
        if (!array_key_exists($c, $maj) && array_key_exists($c, $chg) && trim((string) $chg[$c]) !== (string) $avant[$c]) {
            $maj[$c] = trim((string) $chg[$c]) ?: null;
        }
    }
    if (array_key_exists('rappel_prevu', $chg)) {
        $r = trim((string) $chg['rappel_prevu']);
        $r = $r === '' ? null : date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $r)) ?: time());
        if ($r !== $avant['rappel_prevu']) {
            $maj['rappel_prevu'] = $r;
            if ($r && !$evenements) {
                $evenements[] = 'rappel';
            }
        }
    }
    if (trim((string) ($chg['note'] ?? '')) !== '') {
        $ligne = '[' . date('d/m H:i') . ($par !== '' ? ' — ' . $par : '') . '] ' . trim((string) $chg['note']);
        $maj['commentaire'] = trim(((string) $avant['commentaire']) . "\n" . $ligne);
    }
    if ($maj) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
        $db->prepare("UPDATE rep_demandes SET $sets WHERE id = ?")->execute([...array_values($maj), $id]);
    }
    $st->execute([$id]);
    $apres = $st->fetch();
    // Historique : ce qui a changé, par qui, depuis où.
    $qui = $par !== '' ? $par : null;
    if (isset($maj['statut'])) {
        $libelle = ['en_cours' => 'Prise en charge' . (!empty($maj['pris_par']) ? ' par ' . $maj['pris_par'] : ''),
            'traite' => 'Marquée traitée', 'a_traiter' => 'Remise à traiter'][$maj['statut']];
        ma_rep_evenement($db, $id, ['en_cours' => 'prise_en_charge', 'traite' => 'traitee', 'a_traiter' => 'rouverte'][$maj['statut']],
            $qui, $via, $libelle, ['statut_avant' => $avant['statut'], 'statut_apres' => $maj['statut']]);
    } elseif (isset($maj['pris_par'])) {
        ma_rep_evenement($db, $id, 'modifiee', $qui, $via, 'Pris en charge par : ' . ($maj['pris_par'] ?? '—'), []);
    }
    if (array_key_exists('rappel_prevu', $maj)) {
        ma_rep_evenement($db, $id, 'rappel', $qui, $via, $maj['rappel_prevu'] ? 'Rappel prévu ' . ma_date_fr($maj['rappel_prevu']) : 'Rappel prévu retiré', []);
    }
    if (trim((string) ($chg['note'] ?? '')) !== '') {
        ma_rep_evenement($db, $id, 'note', $qui, $via, 'Note interne', ['texte' => trim((string) $chg['note'])]);
    }
    foreach ($evenements as $e) {
        ma_rep_notifier_client($db, $apres, $e);
    }
    if ($maj) {
        $db->prepare('INSERT INTO executions_log(scenario, date, statut, type_evenement, resume) VALUES (?,?,?,?,?)')
            ->execute(['repondeur', $now, 'info', 'demande_mise_a_jour', 'Demande ' . $id . ' : ' . implode(', ', array_keys($maj))
                . ($par !== '' ? ' (par ' . $par . ')' : '')]);
    }
    return $apres;
}

// ------------------------------------------------------------------ Équipe, responsables de service, accès
// L'annuaire du routage est aussi l'équipe de la plateforme. Chaque personne a un service (sav,
// commerce, finance, direction) et un accès : admin (tout, réglages compris), sav, commerce ou
// finance (les demandes de ce service), ou aucun. Les boîtes partagées n'ont jamais d'accès.

const MA_SERVICES = ['sav' => 'SAV', 'commerce' => 'Commerce', 'finance' => 'Finance', 'direction' => 'Direction'];
const MA_ACCES = ['admin' => 'Administrateur', 'sav' => 'Service SAV', 'commerce' => 'Service Commerce', 'finance' => 'Service Finance'];

/** Service d'une personne : celui qui est renseigné, sinon celui de son rôle de routage. */
function ma_contact_service(array $c): string
{
    $s = ma_plat($c['service'] ?? null);
    if (isset(MA_SERVICES[$s])) {
        return $s;
    }
    return ['finance' => 'finance', 'backoffice' => 'sav', 'rso' => 'sav', 'cta' => 'sav',
        'boite' => 'commerce', 'direct_projet' => 'commerce', 'direction' => 'direction'][ma_plat($c['role'] ?? null)] ?? '';
}

/** Service d'une demande (SAV, COMMERCIAL, FINANCE, AUTRE) pour un accès (sav, commerce, finance). */
function ma_acces_service_demande(string $acces): ?string
{
    return ['sav' => 'SAV', 'commerce' => 'COMMERCIAL', 'finance' => 'FINANCE'][$acces] ?? null;
}

/** Équipe de départ : les accès et les responsables de service convenus avec Multiair. */
function ma_equipe_initiale(PDO $db): void
{
    $trouver = function (string $email, string $nomDebut = '') use ($db): ?array {
        $st = $db->prepare('SELECT * FROM rep_contacts WHERE lower(email) = lower(?) LIMIT 1');
        $st->execute([$email]);
        $c = $st->fetch();
        if (!$c && $nomDebut !== '') {
            $st = $db->prepare("SELECT * FROM rep_contacts WHERE nom LIKE ? AND role != 'boite' ORDER BY id LIMIT 1");
            $st->execute([$nomDebut . '%']);
            $c = $st->fetch();
        }
        return $c ?: null;
    };
    $poser = function (array $v, string $email, string $nomDebut = '') use ($db, $trouver): int {
        $c = $trouver($email, $nomDebut);
        if ($c) {
            $maj = ['email' => $c['email'] ?: $email];
            foreach ($v as $k => $x) {
                // On complète sans écraser ce qui a déjà été saisi dans la page.
                if ($k === 'nom' || trim((string) ($c[$k] ?? '')) === '') {
                    $maj[$k] = $x;
                }
            }
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
            $db->prepare("UPDATE rep_contacts SET $sets WHERE id = ?")->execute([...array_values($maj), $c['id']]);
            return (int) $c['id'];
        }
        $v += ['role' => 'membre', 'actif' => 1];
        $v['email'] = $email;
        $cols = array_keys($v);
        $db->prepare('INSERT INTO rep_contacts(' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($v));
        return (int) $db->lastInsertId();
    };
    $ids = [];
    $ids['cyril'] = $poser(['nom' => 'Cyril Mortier', 'role' => 'direction', 'service' => 'direction', 'acces' => 'admin', 'fonction' => 'Direction'], 'cyril.mortier@airwco.com');
    $poser(['nom' => 'Marco Carraro', 'role' => 'direction', 'service' => 'direction', 'acces' => 'admin', 'fonction' => 'Direction'], 'marco.carraro@airwco.com');
    $poser(['nom' => 'Eric Rouhier', 'role' => 'direction', 'service' => 'direction', 'acces' => 'admin', 'fonction' => 'Direction'], 'eric.rouhier@multiairfrance.fr');
    $poser(['nom' => 'Eric Audon', 'role' => 'direction', 'service' => 'direction', 'acces' => 'admin', 'fonction' => 'Direction'], 'eric.audon@airwco.com');
    $ids['julien'] = $poser(['nom' => 'Julien Jardin', 'service' => 'sav', 'acces' => 'sav', 'fonction' => 'Back-office SAV'], 'julien.jardin@airwco.com', 'Julien');
    $poser(['nom' => 'Marien Guirauton', 'service' => 'sav', 'acces' => 'sav', 'fonction' => 'Back-office SAV'], 'marien.guirauton@airwco.com', 'Marien');
    $ids['sandrine'] = $poser(['nom' => 'Sandrine Gallardo', 'service' => 'finance', 'acces' => 'finance', 'fonction' => 'Comptabilité'], 'sandrine.gallardo@airwco.com');
    $ids['stephanie'] = $poser(['nom' => 'Stéphanie Leblanc', 'service' => 'commerce', 'acces' => 'commerce', 'fonction' => 'Commerce'], 'stephanie.leblanc@airwco.com');
    // Les RSO voient tout le SAV (congés, absences) ; les boîtes et les agents externes n'ont pas d'accès.
    $db->exec("UPDATE rep_contacts SET service = 'sav', acces = COALESCE(NULLIF(acces, ''), 'sav'), fonction = COALESCE(NULLIF(fonction, ''), 'RSO') WHERE role = 'rso'");
    $db->exec("UPDATE rep_contacts SET service = 'sav', fonction = COALESCE(NULLIF(fonction, ''), 'CTA ABAC') WHERE role = 'cta'");
    $db->exec("UPDATE rep_contacts SET service = 'finance' WHERE role = 'finance' AND (service IS NULL OR service = '')");
    $db->exec("UPDATE rep_contacts SET service = 'commerce' WHERE role IN ('boite', 'direct_projet') AND (service IS NULL OR service = '')");
    $ins = $db->prepare('INSERT OR REPLACE INTO parametres(cle, valeur) VALUES (?, ?)');
    foreach (['resp_sav' => 'julien', 'resp_finance' => 'sandrine', 'resp_commerce' => 'stephanie', 'resp_indetermine' => 'cyril'] as $cle => $qui) {
        if (ma_param($db, $cle) === '') {
            $ins->execute([$cle, (string) $ids[$qui]]);
        }
    }
}

/** Les quatre responsables : reçoivent ce que rien d'autre n'attribue. Clés : sav, finance, commercial, autre. */
function ma_responsables(PDO $db): array
{
    $out = [];
    foreach (['sav' => 'resp_sav', 'finance' => 'resp_finance', 'commercial' => 'resp_commerce', 'autre' => 'resp_indetermine'] as $svc => $cle) {
        $id = (int) ma_param($db, $cle, '0');
        $st = $db->prepare('SELECT * FROM rep_contacts WHERE id = ?');
        $st->execute([$id]);
        $out[$svc] = $st->fetch() ?: null;
    }
    return $out;
}

/** Utilisateur connecté (session), ou null. */
function ma_user(): ?array
{
    if (!ma_is_logged()) {
        return null;
    }
    // Sessions ouvertes avant les comptes nominatifs : l'identifiant du fichier de configuration est administrateur.
    return $_SESSION['ma_user'] ?? ['id' => 0, 'nom' => (string) ($_SESSION['ma_login'] ?? 'admin'), 'email' => '', 'acces' => 'admin'];
}

/** Vérifie un identifiant (e-mail, ou l'identifiant de secours de config.php) et un mot de passe. */
function ma_verifier_connexion(PDO $db, string $login, string $pass): ?array
{
    $cfg = ma_config();
    $login = trim($login);
    if ($login !== '' && hash_equals((string) $cfg['login'], $login) && hash_equals((string) $cfg['password'], $pass)) {
        return ['id' => 0, 'nom' => 'Administrateur', 'email' => '', 'acces' => 'admin'];
    }
    if (!str_contains($login, '@') || $pass === '') {
        return null;
    }
    $st = $db->prepare("SELECT * FROM rep_contacts WHERE lower(email) = lower(?) AND actif = 1 AND acces IN ('admin','sav','commerce','finance')
        AND mdp_hash IS NOT NULL AND mdp_hash != '' LIMIT 1");
    $st->execute([$login]);
    $c = $st->fetch();
    if (!$c || !password_verify($pass, (string) $c['mdp_hash'])) {
        return null;
    }
    $db->prepare('UPDATE rep_contacts SET derniere_connexion = ? WHERE id = ?')->execute([ma_now(), $c['id']]);
    return ['id' => (int) $c['id'], 'nom' => (string) $c['nom'], 'email' => (string) $c['email'], 'acces' => (string) $c['acces']];
}

function ma_ouvrir_session(array $u): void
{
    ma_session_start();
    session_regenerate_id(true);
    $_SESSION['ma_auth'] = true;
    $_SESSION['ma_login'] = $u['email'] ?: 'admin';
    $_SESSION['ma_user'] = $u;
}

/** Envoi par le scénario Make « Suivi client » (e-mail ; WhatsApp seulement si mobile = true). */
function ma_webhook_suivi(PDO $db, array $payload): string
{
    $webhook = ma_param($db, 'rep_webhook_suivi');
    if ($webhook === '' || !function_exists('curl_init')) {
        return 'non configuré';
    }
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $rep = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $rep !== false && $code >= 200 && $code < 300 ? 'envoyé' : 'échec (' . $code . ')';
}

/**
 * Lien pour choisir son mot de passe (invitation ou mot de passe oublié), valable 7 jours.
 * Seule l'empreinte du jeton est gardée en base. Le lien part par e-mail ; il est aussi rendu
 * à l'administrateur qui invite, pour le transmettre autrement si l'e-mail n'arrive pas.
 */
function ma_compte_lien(PDO $db, array $c, string $motif = 'invitation'): array
{
    $jeton = ma_jeton();
    $db->prepare('UPDATE rep_contacts SET mdp_jeton = ?, mdp_jeton_exp = ? WHERE id = ?')
        ->execute([hash('sha256', $jeton), date('Y-m-d H:i:s', time() + 7 * 86400), $c['id']]);
    $lien = ma_url_base($db) . 'compte.php?j=' . $jeton;
    $prenom = explode(' ', trim((string) $c['nom']))[0] ?? '';
    $invitation = $motif === 'invitation';
    $titre = $invitation ? 'Votre accès à la plateforme Multiair' : 'Choisissez un nouveau mot de passe';
    $phrase = $invitation
        ? 'Un accès à la plateforme des demandes clients Multiair vient d\'être ouvert pour vous (' . (MA_ACCES[$c['acces']] ?? '') . '). Choisissez votre mot de passe pour vous connecter.'
        : 'Vous avez demandé à changer de mot de passe. Si ce n\'est pas vous, ignorez ce message.';
    $html = ma_mail_gabarit([
        'titre' => $titre,
        'corps' => '<p style="margin:0 0 14px">Bonjour ' . htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') . ',</p><p style="margin:0">' . htmlspecialchars($phrase, ENT_QUOTES, 'UTF-8') . '</p>',
        'bouton' => ['Choisir mon mot de passe', $lien],
        'pied' => 'Ce lien est valable 7 jours. Votre identifiant est votre adresse e-mail : ' . $c['email'] . '.',
    ]);
    $envoi = ma_webhook_suivi($db, ['evenement' => 'compte', 'demande_id' => 0, 'mobile' => false, 'tel' => '',
        'email' => (string) $c['email'], 'objet' => 'Multiair — ' . $titre, 'html' => $html, 'texte' => $titre . ' : ' . $lien, 'lien' => $lien]);
    $db->prepare('INSERT INTO executions_log(scenario, date, statut, type_evenement, resume) VALUES (?,?,?,?,?)')
        ->execute(['plateforme', ma_now(), str_starts_with($envoi, 'échec') ? 'erreur' : 'info', 'compte_' . $motif,
            $c['nom'] . ' — lien ' . ($invitation ? 'd\'invitation' : 'de nouveau mot de passe') . ' : ' . $envoi]);
    return ['lien' => $lien, 'envoi' => $envoi];
}

/** Retire les champs secrets d'une personne avant de l'envoyer à la page. */
function ma_contact_public(array $c): array
{
    $c['a_mot_de_passe'] = trim((string) ($c['mdp_hash'] ?? '')) !== '';
    $c['invitation_en_cours'] = !$c['a_mot_de_passe'] && trim((string) ($c['mdp_jeton'] ?? '')) !== ''
        && (string) ($c['mdp_jeton_exp'] ?? '') > ma_now();
    unset($c['mdp_hash'], $c['mdp_jeton'], $c['mdp_jeton_exp']);
    $c['service_equipe'] = ma_contact_service($c);
    return $c;
}

// ------------------------------------------------------------------ Gabarit des e-mails
// Un seul gabarit pour tout ce qui part de la plateforme : e-mails à l'équipe, au client, comptes.
// Tableaux et styles en ligne : c'est ce que les messageries affichent de façon fiable.

function ma_mail_gabarit(array $o): string
{
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $police = "font-family:'Segoe UI',Helvetica,Arial,sans-serif";
    $bandeau = !empty($o['bandeau'])
        ? '<tr><td style="background:#b3261e;color:#ffffff;padding:14px 28px;font-weight:700;font-size:16px;' . $police . '">' . $e($o['bandeau']) . '</td></tr>' : '';
    $etiquettes = '';
    foreach ($o['etiquettes'] ?? [] as [$texte, $fond, $couleur]) {
        $etiquettes .= '<span style="display:inline-block;background:' . $fond . ';color:' . $couleur . ';font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;margin:0 6px 6px 0">' . $e($texte) . '</span>';
    }
    $bouton = '';
    if (!empty($o['bouton'])) {
        [$libelle, $url] = $o['bouton'];
        $bouton = '<tr><td style="padding:4px 28px 8px"><a href="' . $e($url) . '" style="display:block;background:#0f2f52;color:#ffffff;text-align:center;'
            . 'text-decoration:none;font-weight:700;font-size:16px;padding:15px 20px;border-radius:10px;' . $police . '">' . $e($libelle) . '</a></td></tr>';
    }
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef1f4">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f4"><tr><td align="center" style="padding:20px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden">'
        . '<tr><td style="background:#0f2f52;color:#ffffff;padding:18px 28px;' . $police . '"><span style="font-weight:800;letter-spacing:.06em;font-size:15px">MULTIAIR</span>'
        . (!empty($o['surtitre']) ? '<span style="float:right;font-size:13px;color:#a9bbd0;padding-top:1px">' . $e($o['surtitre']) . '</span>' : '') . '</td></tr>'
        . $bandeau
        . '<tr><td style="padding:26px 28px 8px;color:#1c2431;font-size:15px;line-height:1.6;' . $police . '">'
        . ($etiquettes !== '' ? '<div style="margin-bottom:6px">' . $etiquettes . '</div>' : '')
        . '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:#1c2431">' . $e($o['titre'] ?? '') . '</h1>'
        . ($o['corps'] ?? '') . '</td></tr>'
        . ($o['avancement'] ?? '')
        . ($o['fiche'] ?? '')
        . $bouton
        . (!empty($o['apres']) ? '<tr><td style="padding:8px 28px 4px;color:#5b6675;font-size:13px;line-height:1.6;' . $police . '">' . $o['apres'] . '</td></tr>' : '')
        . '<tr><td style="padding:18px 28px 22px;border-top:1px solid #eef1f4;color:#5b6675;font-size:12px;line-height:1.6;' . $police . '">' . $e($o['pied'] ?? 'Multiair France') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Tableau « libellé : valeur » sur fond gris, pour les e-mails. Les valeurs vides sont omises. */
function ma_mail_fiche(array $lignes): string
{
    $police = "font-family:'Segoe UI',Helvetica,Arial,sans-serif";
    $html = '';
    foreach ($lignes as [$k, $v, $brut]) {
        if (trim(strip_tags((string) $v)) === '') {
            continue;
        }
        $val = $brut ? $v : nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        $html .= '<tr><td style="padding:6px 14px 6px 0;color:#5b6675;font-weight:600;white-space:nowrap;vertical-align:top;width:1%">'
            . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:6px 0;vertical-align:top">' . $val . '</td></tr>';
    }
    return $html === '' ? '' : '<tr><td style="padding:8px 28px 18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="background:#f5f7f9;border-radius:12px;font-size:14px;color:#1c2431;' . $police . '"><tr><td style="padding:12px 18px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;' . $police . '">' . $html . '</table></td></tr></table></td></tr>';
}

/** Barre d'avancement Reçue / Prise en charge / Traitée (étape atteinte : 1, 2 ou 3). */
function ma_mail_avancement(int $etape): string
{
    $police = "font-family:'Segoe UI',Helvetica,Arial,sans-serif";
    $cases = '';
    foreach (['Reçue', 'Prise en charge', 'Traitée'] as $i => $t) {
        $fait = $i < $etape;
        $cases .= '<td width="33%" style="padding:0 4px;vertical-align:top"><div style="height:6px;border-radius:3px;background:' . ($fait ? '#1f6b47' : '#dfe4ea') . '"></div>'
            . '<div style="padding-top:8px;font-size:13px;font-weight:700;color:' . ($fait ? '#1f6b47' : '#5b6675') . ';' . $police . '">' . $t . '</div></td>';
    }
    return '<tr><td style="padding:6px 24px 18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cases . '</tr></table></td></tr>';
}

// ------------------------------------------------------------------ Historique d'une demande (traçabilité)

function ma_tel_lisible(?string $tel): string
{
    $t = (string) ma_tel($tel);
    $local = preg_match('/^33(\d{9})$/', $t, $m) ? '0' . $m[1] : $t;
    return preg_match('/^0\d{9}$/', $local) ? trim(chunk_split($local, 2, ' ')) : $local;
}

function ma_rep_evenement(PDO $db, int $demandeId, string $type, ?string $qui, string $via, string $resume, array $detail = [], ?string $date = null): void
{
    $db->prepare('INSERT INTO rep_evenements(demande_id, date, type, qui, via, resume, detail) VALUES (?,?,?,?,?,?,?)')
        ->execute([$demandeId, $date ?? ma_now(), $type, $qui, $via, $resume, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
}

/** Réception et transmission d'une demande qui vient d'être créée : d'où elle vient, à qui elle est partie. */
function ma_rep_evenements_creation(PDO $db, array $dem, array $rt): void
{
    $canal = ma_canal($dem);
    ma_rep_evenement($db, (int) $dem['id'], 'recue', 'Claire', $canal,
        'Demande reçue ' . (MA_CANAUX[$canal][1] ?? '') . (($dem['source'] ?? '') === 'whatsapp_qualifie' ? ', validée par le client sur WhatsApp'
            : (($dem['source'] ?? '') === 'sans_reponse_10min' ? ', sans réponse WhatsApp du client (informations non validées)' : '')),
        ['source' => $dem['source'] ?? null, 'service' => $dem['service'], 'priorite' => $dem['priorite']], $dem['created_at']);
    $envois = [];
    foreach (array_filter(explode(';', (string) $rt['to'])) as $a) {
        $envois[] = ['canal' => 'E-mail', 'a' => $a, 'role' => 'destinataire'];
    }
    foreach (array_filter(explode(';', (string) $rt['cc'])) as $a) {
        $envois[] = ['canal' => 'E-mail', 'a' => $a, 'role' => 'copie'];
    }
    foreach (array_filter(explode(';', (string) $rt['sms'])) as $a) {
        $envois[] = ['canal' => 'SMS', 'a' => ma_tel_lisible($a)];
    }
    $noms = implode(', ', array_map(fn($p) => $p['nom'], $rt['personnes'])) ?: 'adresse de secours';
    ma_rep_evenement($db, (int) $dem['id'], 'transmise', 'Plateforme', 'make', 'Transmise à ' . $noms,
        ['envois' => $envois, 'regle' => $rt['regle_libelle'], 'notes' => $rt['notes'], 'objet' => $rt['objet'], 'sms_texte' => $rt['sms_texte']],
        $dem['created_at']);
}

/**
 * Tout ce qui s'est passé sur une demande, dans l'ordre : les événements enregistrés, la conversation
 * avec Claire (WhatsApp), et, pour les demandes d'avant l'historique, ce que ses champs racontent.
 */
function ma_rep_historique(PDO $db, array $dem): array
{
    $id = (int) $dem['id'];
    $st = $db->prepare('SELECT * FROM rep_evenements WHERE demande_id = ? ORDER BY date, id');
    $st->execute([$id]);
    $ev = array_map(function ($e) {
        $e['detail'] = $e['detail'] ? (json_decode($e['detail'], true) ?: []) : [];
        return $e;
    }, $st->fetchAll());
    $types = array_column($ev, 'type');
    if (!in_array('recue', $types, true)) {
        // Demande antérieure à l'historique : on reconstitue l'essentiel à partir de ses champs.
        $envois = [];
        foreach (array_filter(explode(';', (string) $dem['dest_to'])) as $a) {
            $envois[] = ['canal' => 'E-mail', 'a' => $a, 'role' => 'destinataire'];
        }
        foreach (array_filter(explode(';', (string) $dem['dest_cc'])) as $a) {
            $envois[] = ['canal' => 'E-mail', 'a' => $a, 'role' => 'copie'];
        }
        foreach (array_filter(explode(';', (string) $dem['dest_sms'])) as $a) {
            $envois[] = ['canal' => 'SMS', 'a' => ma_tel_lisible($a)];
        }
        $canal = ma_canal($dem);
        $ev[] = ['date' => $dem['created_at'], 'type' => 'recue', 'qui' => 'Claire', 'via' => $canal, 'resume' => 'Demande reçue ' . (MA_CANAUX[$canal][1] ?? ''), 'detail' => [], 'reconstitue' => true];
        if ($envois) {
            $ev[] = ['date' => $dem['created_at'], 'type' => 'transmise', 'qui' => 'Plateforme', 'via' => 'make',
                'resume' => 'Transmise à ' . ($dem['destinataires'] ?: 'adresse de secours'), 'detail' => ['envois' => $envois, 'regle' => $dem['regle_libelle']], 'reconstitue' => true];
        }
        if (!in_array('prise_en_charge', $types, true) && $dem['pris_at']) {
            $ev[] = ['date' => $dem['pris_at'], 'type' => 'prise_en_charge', 'qui' => $dem['pris_par'], 'via' => null,
                'resume' => 'Prise en charge' . ($dem['pris_par'] ? ' par ' . $dem['pris_par'] : ''), 'detail' => [], 'reconstitue' => true];
        }
        if (!in_array('traitee', $types, true) && $dem['traite_at']) {
            $ev[] = ['date' => $dem['traite_at'], 'type' => 'traitee', 'qui' => $dem['traite_par'], 'via' => null,
                'resume' => 'Marquée traitée', 'detail' => [], 'reconstitue' => true];
        }
        if (!in_array('client_prevenu', $types, true)) {
            $st = $db->prepare("SELECT date, type_evenement, resume, payload FROM executions_log WHERE type_evenement LIKE 'suivi_client_%' AND resume LIKE ?");
            $st->execute(['Demande ' . $id . ' —%']);
            foreach ($st->fetchAll() as $l) {
                $p = json_decode((string) $l['payload'], true) ?: [];
                $ev[] = ['date' => $l['date'], 'type' => 'client_prevenu', 'qui' => 'Plateforme', 'via' => 'make', 'resume' => 'Client prévenu (' . substr($l['type_evenement'], 13) . ')',
                    'detail' => ['texte' => $p['texte'] ?? '', 'envoi' => trim(explode(':', (string) $l['resume'])[1] ?? '')], 'reconstitue' => true];
            }
        }
    }
    // Conversation avec Claire, rattachée à la fiche d'appel.
    if (!empty($dem['fiche_id'])) {
        $st = $db->prepare('SELECT * FROM rep_messages WHERE fiche_id = ? ORDER BY date, id');
        $st->execute([(int) $dem['fiche_id']]);
        foreach ($st->fetchAll() as $m) {
            if (trim((string) $m['message']) !== '') {
                $ev[] = ['date' => $m['date'], 'type' => 'message_client', 'qui' => $dem['contact'] ?: 'Client', 'via' => $m['canal'] ?: 'whatsapp',
                    'resume' => 'Message du client', 'detail' => ['texte' => $m['message']]];
            }
            if (trim((string) $m['reponse']) !== '') {
                $ev[] = ['date' => $m['date'], 'type' => 'reponse_claire', 'qui' => 'Claire', 'via' => $m['canal'] ?: 'whatsapp',
                    'resume' => 'Réponse de Claire', 'detail' => ['texte' => $m['reponse']]];
            }
        }
    }
    // Conversation sur le chat du site : celle de la session du visiteur.
    $st = $db->prepare('SELECT DISTINCT session_id FROM chat_leads WHERE demande_id = ? AND session_id IS NOT NULL AND session_id != \'\'');
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sess) {
        $sm = $db->prepare('SELECT * FROM chat_messages WHERE session_id = ? ORDER BY date, id');
        $sm->execute([$sess]);
        foreach ($sm->fetchAll() as $m) {
            if (trim((string) $m['message']) !== '') {
                $ev[] = ['date' => $m['date'], 'type' => 'message_client', 'qui' => $dem['contact'] ?: 'Visiteur', 'via' => 'chat', 'resume' => 'Message du visiteur (chat du site)', 'detail' => ['texte' => $m['message']]];
            }
            if (trim((string) $m['reponse']) !== '') {
                $ev[] = ['date' => $m['date'], 'type' => 'reponse_claire', 'qui' => 'Claire', 'via' => 'chat', 'resume' => 'Réponse de Claire (chat du site)', 'detail' => ['texte' => $m['reponse']]];
            }
        }
    }
    $ordre = ['recue' => 0, 'transmise' => 1, 'client_prevenu' => 2];
    usort($ev, fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']) ?: (($ordre[$a['type']] ?? 5) <=> ($ordre[$b['type']] ?? 5)));
    return $ev;
}

// ------------------------------------------------------------------ Création d'une demande (tous canaux)

/** Insère une ligne en ne gardant que les colonnes qui existent. */
function ma_inserer(PDO $db, string $table, array $data): int
{
    $cols = array_diff(array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name'), ['id']);
    $data = array_intersect_key($data, array_flip($cols));
    $keys = array_keys($data);
    $db->prepare("INSERT INTO $table (" . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')')
        ->execute(array_values($data));
    return (int) $db->lastInsertId();
}

/**
 * Crée une demande, quel que soit son canal (appel, chat du site, e-mail), et la route vers les bonnes
 * personnes. Options : notifier (prévenir le client, oui par défaut), statut / traite_at / pris_at (reprise
 * d'une demande existante), reprise (texte : la demande vient d'un ancien outil, rien n'a été envoyé).
 */
function ma_rep_creer_demande(PDO $db, array $d, array $opt = []): array
{
    $now = ma_now();
    // Le client rappelle au sujet d'une demande existante (Claire écrit « Relance de la demande n° 37 »).
    if (empty($opt['reprise'])) {
        $relanceId = (int) ($d['relance_demande_id'] ?? 0);
        if (!$relanceId && preg_match('/relance\s+(?:de\s+)?(?:la\s+)?demande\s+n\W{0,2}\s*(\d+)/iu', (string) ($d['resume'] ?? ''), $m)) {
            $relanceId = (int) $m[1];
        }
        if ($relanceId && ($x = ma_rep_demande_du_client($db, $relanceId, $d))) {
            $jours = (int) (ma_param($db, 'rep_reouverture_jours', '15') ?: 15);
            if ($x['statut'] !== 'traite') {
                return ma_rep_relancer($db, $x, $d, 'relance');
            }
            if (strtotime((string) ($x['traite_at'] ?: $x['created_at'])) >= time() - $jours * 86400) {
                return ma_rep_relancer($db, $x, $d, 'reouverture');
            }
            // Fermée depuis longtemps : nouvelle demande, liée à l'ancienne pour garder le fil.
            $d['demande_liee_id'] = $relanceId;
            $d['resume'] = 'Suite de la demande n° ' . $relanceId . ' (traitée le ' . ma_date_fr($x['traite_at'], false) . '). '
                . trim(preg_replace('/relance\s+(?:de\s+)?(?:la\s+)?demande\s+n\W{0,2}\s*\d+\.?/iu', '', (string) ($d['resume'] ?? '')));
        }
    }
    $svc = strtoupper(ma_plat(ma_str($d['service'] ?? null) ?? ''));
    $map = ['TECHNIQUE' => 'SAV', 'SAV' => 'SAV', 'COMMERCIAL' => 'COMMERCIAL', 'COMMERCE' => 'COMMERCIAL', 'FINANCE' => 'FINANCE',
        'COMPTABILITE' => 'FINANCE', 'COMPTA' => 'FINANCE'];
    // Sujet que personne n'a su classer : il part au responsable des sujets indéterminés.
    $d['service'] = $map[$svc] ?? 'AUTRE';
    $d['canal'] = ma_canal($d);
    $d['priorite'] = $d['service'] === 'SAV'
        && (ma_bool($d['urgence'] ?? ($d['priorite'] ?? false)) || strtoupper((string) ($d['priorite'] ?? '')) === 'URGENT') ? 'URGENT' : 'Normal';
    $tel = ma_tel((string) ($d['tel'] ?? $d['telephone'] ?? ''));
    $d['tel'] = $tel && preg_match('/^\d{9,15}$/', $tel) ? $tel : null;
    $d['societe'] = ma_str($d['societe'] ?? $d['distributeur'] ?? null);
    $d['created_at'] = ma_date($d['date'] ?? ($d['created_at'] ?? null)) ?? $now;
    $d['fiche_id'] = !empty($d['fiche_id']) ? (int) $d['fiche_id'] : null;
    $d['type_panne'] = ma_str($d['type_panne'] ?? $d['description'] ?? null);
    // Une demande créée après la qualification WhatsApp ne connaît que la fiche :
    // on y reprend ce que Claire a recueilli au téléphone (type d'appelant, site, etc.).
    $fiche = null;
    if ($d['fiche_id']) {
        $st = $db->prepare('SELECT * FROM rep_fiches WHERE id = ?');
        $st->execute([$d['fiche_id']]);
        $fiche = $st->fetch() ?: null;
    }
    foreach (['code_postal', 'type_interlocuteur', 'nature', 'type_equipement', 'departement'] as $c) {
        if (trim((string) ($d[$c] ?? '')) === '' && $fiche && trim((string) ($fiche[$c] ?? '')) !== '') {
            $d[$c] = $fiche[$c];
        }
    }
    if (!str_contains((string) ($d['email'] ?? ''), '@')) {
        $d['email'] = $fiche['email'] ?? null;
    }
    // Claire écrit toujours « Site : 69003 » dans son résumé : filet si le champ n'est pas arrivé.
    if (trim((string) ($d['code_postal'] ?? '')) === '' && preg_match('/Site\s*:\s*(\d{5})\b/u', (string) ($d['resume'] ?? ''), $m)) {
        $d['code_postal'] = $m[1];
    }
    // Une demande naît « à traiter » : seul un humain la fait avancer (sauf reprise d'un ancien outil).
    $d['statut'] = $opt['statut'] ?? 'a_traiter';
    foreach (['pris_at', 'pris_par', 'traite_at', 'traite_par'] as $c) {
        if (isset($opt[$c])) {
            $d[$c] = $opt[$c];
        }
    }
    $d['jeton_interne'] = ma_jeton();
    $d['jeton_client'] = ma_jeton();
    $rt = ma_rep_router($db, $d);
    $d['marque_norm'] = $rt['marque'];
    $d['nature'] = $rt['nature'] ?: null;
    $d['type_client'] = $rt['type_client'];
    $d['type_equipement'] = ma_str($d['type_equipement'] ?? null) ?? ($rt['type_equipement'] ?: null);
    $d['departement'] = $rt['departement'] ?? ma_str($d['departement'] ?? null);
    $d['regle_id'] = $rt['regle_id'];
    $d['regle_libelle'] = $rt['regle_libelle'];
    $d['destinataires'] = implode(', ', array_map(fn($p) => $p['nom'], $rt['personnes'])) ?: null;
    $d['dest_to'] = $rt['to'];
    $d['dest_cc'] = $rt['cc'];
    $d['dest_sms'] = $rt['sms'];
    $id = ma_inserer($db, 'rep_demandes', $d);
    if ($d['fiche_id']) {
        $db->prepare("UPDATE rep_fiches SET transmis_at = COALESCE(transmis_at, ?) WHERE id = ?")->execute([$now, $d['fiche_id']]);
    }
    ma_client_rattacher($db, $id);
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
    $st->execute([$id]);
    $cree = $st->fetch();
    if (!empty($opt['reprise'])) {
        ma_rep_evenement($db, $id, 'recue', 'Claire', $cree['canal'], 'Demande reçue ' . (MA_CANAUX[$cree['canal']][1] ?? ''), ['source' => $cree['source']], $cree['created_at']);
        ma_rep_evenement($db, $id, 'modifiee', 'Plateforme', 'plateforme', $opt['reprise'] . ' — attribuée à ' . ($cree['destinataires'] ?: 'personne')
            . ', sans nouvel envoi', ['regle' => $rt['regle_libelle']]);
    } else {
        $db->prepare('INSERT INTO executions_log(scenario, date, statut, type_evenement, resume, payload) VALUES (?,?,?,?,?,?)')
            ->execute([$cree['canal'] === 'chat' ? 'chatbot' : 'repondeur', $now, 'ok', ($rt['urgent'] ? 'urgence_' : 'demande_') . strtolower($cree['service']),
                ($rt['urgent'] ? 'URGENT — ' : '') . ($cree['societe'] ?? '') . ' → ' . ($cree['destinataires'] ?? $rt['to'])
                    . ' [' . $rt['regle_libelle'] . ']' . ($rt['notes'] ? ' — ' . implode(' ; ', $rt['notes']) : ''),
                json_encode(['id' => $id, 'to' => $rt['to'], 'sms' => $rt['sms']], JSON_UNESCAPED_UNICODE)]);
        ma_rep_evenements_creation($db, $cree, $rt);
        if ($opt['notifier'] ?? true) {
            ma_rep_notifier_client($db, $cree, 'recue');
        }
    }
    return ['id' => $id, 'demande' => $cree, 'rt' => $rt, 'liens' => ma_rep_liens($db, $cree)];
}

// ------------------------------------------------------------------ Chatbot du site : chaque lead est une demande

/** Service d'une demande du chat : la catégorie donnée par Claire, sinon les mots de la demande. */
function ma_chat_service(array $l): string
{
    $cat = ma_plat($l['categorie'] ?? null);
    $parCat = ['sav' => 'SAV', 'technique' => 'SAV', 'finance' => 'FINANCE', 'compta' => 'FINANCE', 'comptabilite' => 'FINANCE',
        'equipement' => 'COMMERCIAL', 'pieces' => 'COMMERCIAL', 'commercial' => 'COMMERCIAL', 'commerce' => 'COMMERCIAL'];
    if (isset($parCat[$cat])) {
        return $parCat[$cat];
    }
    $t = ma_plat(($l['besoin_resume'] ?? '') . ' ' . ($l['statut'] ?? ''));
    foreach (['FINANCE' => ['factur', 'reglement', 'paiement', 'compta', 'avoir', 'relance de paiement', 'impaye'],
        'SAV' => ['panne', 'fuite', 'depann', 'intervention', 'ne demarre', 'alarme', 'reparation', 'probleme technique', 'entretien', 'maintenance', 'sav'],
        'COMMERCIAL' => ['devis', 'prix', 'tarif', 'achat', 'commande', 'piece', 'compresseur', 'secheur', 'documentation', 'livraison']] as $svc => $mots) {
        foreach ($mots as $m) {
            if (str_contains($t, $m)) {
                return $svc;
            }
        }
    }
    return 'AUTRE';
}

/**
 * Bloc de fin de conversation du chatbot (« Société : … », « Urgence : oui »…) : chaque ligne « Libellé : valeur »
 * devient un champ. Les libellés sont comparés sans accents ni majuscules.
 */
function ma_chat_lire_bloc(string $bloc): array
{
    $cles = ['societe' => 'societe', 'nom' => 'nom', 'prenom' => 'prenom', 'email' => 'email', 'telephone' => 'telephone',
        'departement' => 'departement', 'besoin resume' => 'besoin_resume', 'marque orientee' => 'marque_orientee',
        'type interlocuteur' => 'type_interlocuteur', 'statut' => 'statut', 'a verifier' => 'a_verifier', 'categorie' => 'categorie',
        'nature' => 'nature', 'reference' => 'reference', 'marque du materiel' => 'marque_materiel', 'modele' => 'modele',
        'n de serie' => 'numero_serie', 'numero de serie' => 'numero_serie', 'code postal du site' => 'code_postal',
        'urgence' => 'urgence', 'pourquoi urgent' => 'justification_urgence', 'demande existante' => 'demande_existante',
        'preference de contact' => 'preference_contact'];
    $out = [];
    foreach (preg_split('/\R/u', $bloc) ?: [] as $ligne) {
        if (!preg_match('/^\s*[-*]?\s*([^:]{2,40}?)\s*:\s*(.*)$/u', $ligne, $m)) {
            continue;
        }
        $k = trim(preg_replace('/[^a-z ]+/', ' ', ma_plat($m[1])));
        $k = preg_replace('/\s+/', ' ', $k);
        if (isset($cles[$k]) && trim($m[2], " \t`*") !== '') {
            $out[$cles[$k]] = trim($m[2], " \t`*");
        }
    }
    return $out;
}

/** Ce que le chat transmet, traduit en champs de demande. Les « non communiqué » sont écartés. */
function ma_chat_vers_demande(array $l): array
{
    $vide = fn($v) => in_array(ma_plat($v), ['', 'non communique', 'noncommunique', 'non renseigne', 'a qualifier', 'indetermine', 'inconnu', 'nc', 'n/a', '-',
        'non concerne', 'non', 'aucun', 'aucune'], true);
    $propre = fn($v) => $vide($v) ? null : trim((string) $v);
    $cat = ma_plat($l['categorie'] ?? null);
    $interloc = ma_plat($l['type_interlocuteur'] ?? null);
    $dep = strtoupper(trim((string) ($l['departement'] ?? '')));
    $cp = preg_match('/\b(\d{5})\b/', (string) ($l['code_postal'] ?? ''), $m) ? $m[1] : null;
    $nature = ma_plat($l['nature'] ?? null);
    $natures = ['devis_equipement', 'commande_equipement', 'devis_pieces', 'commande_pieces', 'autre'];
    $nature = in_array($nature, $natures, true) ? $nature : (['equipement' => 'devis_equipement', 'pieces' => 'devis_pieces'][$cat] ?? null);
    $ref = $propre($l['reference'] ?? null);
    $svc = ma_chat_service($l);
    // Marque : celle de la machine concernée (SAV, pièces), sinon la marque vers laquelle le chat oriente.
    $marque = $propre($l['marque_materiel'] ?? null) ?? $propre($l['marque_orientee'] ?? ($l['marque'] ?? null));
    $besoin = $propre($l['besoin_resume'] ?? null);
    $infos = array_filter([
        $besoin,
        $ref && $svc !== 'FINANCE' ? 'Référence : ' . $ref : null,
        ($p = $propre($l['type_interlocuteur'] ?? null)) ? 'Profil : ' . $p : null,
        ($p = $propre($l['marque_orientee'] ?? null)) && $p !== $marque ? 'Marque suggérée : ' . $p : null,
        in_array(ma_plat($l['statut'] ?? ''), ['expert n2'], true) ? 'Demande technique pointue (expert)' : null,
        ($p = $propre($l['a_verifier'] ?? null)) ? 'Coordonnées à vérifier : ' . $p : null,
        ($p = $propre($l['preference_contact'] ?? null)) ? 'Préfère être contacté ' . (['email' => 'par e-mail', 'e mail' => 'par e-mail', 'telephone' => 'par téléphone',
            'les deux' => 'par e-mail ou par téléphone'][ma_plat($p)] ?? 'par ' . $p) : null,
        ($p = $propre($l['page_title'] ?? null) ?? $propre($l['page_url'] ?? null)) ? 'Page : ' . $p : null,
    ]);
    $existante = trim((string) ($l['demande_existante'] ?? ''));
    return [
        'service' => $svc,
        'canal' => 'chat',
        'source' => 'chatbot',
        'date' => $l['date'] ?? null,
        'societe' => $propre($l['societe'] ?? null),
        'contact' => trim(implode(' ', array_filter([$propre($l['prenom'] ?? null), $propre($l['nom'] ?? null)]))) ?: null,
        'telephone' => $propre($l['telephone'] ?? null),
        'email' => str_contains((string) ($l['email'] ?? ''), '@') ? trim((string) $l['email']) : null,
        'marque' => $marque,
        'modele' => $propre($l['modele'] ?? null),
        'numero_serie' => $propre($l['numero_serie'] ?? null),
        'departement' => $cp ? ma_departement($cp) : (preg_match('/^(\d{2,3}|2A|2B)$/', $dep) ? $dep : null),
        'code_postal' => $cp,
        'type_interlocuteur' => str_contains($interloc, 'distri') ? 'distributeur' : (str_contains($interloc, 'final') || str_contains($interloc, 'utilisateur') ? 'utilisateur_final'
            : (str_contains($interloc, 'install') ? 'installateur' : (str_contains($interloc, 'particulier') ? 'particulier' : null))),
        'nature' => $svc === 'COMMERCIAL' ? $nature : null,
        'besoin_commercial' => $svc === 'COMMERCIAL' ? $besoin : null,
        'type_panne' => $svc === 'SAV' ? $besoin : null,
        'reference_facture' => $svc === 'FINANCE' ? $ref : null,
        'resume' => implode(' | ', $infos) ?: null,
        'urgence' => $svc === 'SAV' && ma_bool($l['urgence'] ?? false),
        'justification_urgence' => $propre($l['justification_urgence'] ?? null),
        'relance_demande_id' => preg_match('/(\d+)/', $existante, $m) ? (int) $m[1] : null,
        'demande_existante' => $existante !== '' && !$vide($existante),
    ];
}

/**
 * Le lead du chat devient une demande (ou met à jour la sienne, si le visiteur complète sa demande dans
 * l'heure). Même routage que le répondeur. Le client n'est prévenu par la plateforme que lorsque le
 * paramètre chat_notifier_client vaut 1 : tant que le scénario du chatbot envoie son propre e-mail au
 * visiteur, on évite le doublon.
 */
function ma_chat_demande(PDO $db, int $leadId): ?array
{
    $st = $db->prepare('SELECT * FROM chat_leads WHERE id = ?');
    $st->execute([$leadId]);
    $l = $st->fetch();
    if (!$l) {
        return null;
    }
    $d = ma_chat_vers_demande($l);
    if (!empty($l['demande_id'])) {
        $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
        $st->execute([(int) $l['demande_id']]);
        if ($dem = $st->fetch()) {
            $maj = [];
            foreach (['societe', 'contact', 'email', 'marque', 'modele', 'numero_serie', 'code_postal', 'departement', 'nature', 'resume',
                'besoin_commercial', 'type_panne', 'reference_facture', 'justification_urgence'] as $c) {
                if (($d[$c] ?? null) !== null && (string) $d[$c] !== (string) $dem[$c]) {
                    $maj[$c] = $d[$c];
                }
            }
            $tel = ma_tel((string) ($d['telephone'] ?? ''));
            if ($tel && preg_match('/^\d{9,15}$/', $tel) && $tel !== $dem['tel']) {
                $maj['tel'] = $tel;
            }
            // Urgence précisée après coup : la demande SAV passe en URGENT (et l'équipe reçoit le SMS).
            $devientUrgente = $dem['service'] === 'SAV' && !empty($d['urgence']) && $dem['priorite'] !== 'URGENT';
            if ($devientUrgente) {
                $maj['priorite'] = 'URGENT';
            }
            if ($maj) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
                $db->prepare("UPDATE rep_demandes SET $sets WHERE id = ?")->execute([...array_values($maj), $dem['id']]);
                ma_rep_evenement($db, (int) $dem['id'], 'modifiee', 'Claire', 'chat', 'Le visiteur a complété sa demande sur le chat : ' . implode(', ', array_keys($maj)), $maj);
                ma_client_rattacher($db, (int) $dem['id']);
            }
            $rt = ma_rep_router($db, array_merge($dem, $maj, ['jeton_interne' => $dem['jeton_interne']]));
            $st->execute([(int) $dem['id']]);
            $dem = $st->fetch();
            return ['id' => (int) $dem['id'], 'demande' => $dem, 'rt' => $rt, 'liens' => ma_rep_liens($db, $dem), 'nouvelle' => false,
                'devient_urgente' => $devientUrgente];
        }
    }
    // « Je vous ai déjà contactés pour ça » sans numéro : sa dernière demande du même service (60 jours).
    if (empty($d['relance_demande_id']) && !empty($d['demande_existante'])) {
        $tel = ma_tel_cle($d['telephone'] ?? null);
        $email = $d['email'] ? mb_strtolower($d['email']) : null;
        if ($tel || $email) {
            $q = $db->prepare("SELECT id FROM rep_demandes WHERE service = ? AND created_at >= ? AND ((? IS NOT NULL AND tel = ?) OR (? IS NOT NULL AND lower(email) = ?))
                ORDER BY created_at DESC LIMIT 1");
            $q->execute([$d['service'], date('Y-m-d H:i:s', time() - 60 * 86400), $tel, $tel, $email, $email]);
            $d['relance_demande_id'] = (int) $q->fetchColumn() ?: null;
        }
    }
    $r = ma_rep_creer_demande($db, $d, ['notifier' => ma_param($db, 'chat_notifier_client') === '1']);
    $db->prepare('UPDATE chat_leads SET demande_id = ? WHERE id = ?')->execute([$r['id'], $leadId]);
    return $r + ['nouvelle' => true];
}

/** Reprise, une seule fois, des leads du chat enregistrés avant le circuit commun. Rien n'est envoyé. */
function ma_chat_reprise(PDO $db): int
{
    $n = 0;
    foreach ($db->query('SELECT * FROM chat_leads WHERE demande_id IS NULL ORDER BY date, id')->fetchAll() as $l) {
        $suivi = ma_plat($l['suivi'] ?? 'nouveau');
        $quand = $l['updated_at'] ?: $l['date'];
        $opt = ['reprise' => 'Reprise du chatbot'];
        if ($suivi === 'contacte') {
            $opt += ['statut' => 'en_cours', 'pris_at' => $quand];
        } elseif (in_array($suivi, ['converti', 'perdu'], true)) {
            $opt += ['statut' => 'traite', 'traite_at' => $quand, 'traite_par' => $suivi === 'converti' ? 'Converti (chatbot)' : 'Perdu (chatbot)'];
        }
        $d = ma_chat_vers_demande($l);
        if (trim((string) ($l['commentaire'] ?? '')) !== '') {
            $d['commentaire'] = trim((string) $l['commentaire']);
        }
        $r = ma_rep_creer_demande($db, $d, $opt);
        $db->prepare('UPDATE chat_leads SET demande_id = ? WHERE id = ?')->execute([$r['id'], $l['id']]);
        $n++;
    }
    return $n;
}

// ------------------------------------------------------------------ Transfert d'une demande

/**
 * Transfère une demande à une personne ou une boîte de l'équipe : elle change de service si besoin,
 * repart « à traiter » chez son nouveau destinataire, qui reçoit l'e-mail (et le SMS pour le SAV).
 * Le client n'est pas prévenu : c'est une affaire interne.
 */
function ma_rep_transferer(PDO $db, array $dem, array $cible, string $par, string $message = ''): array
{
    $svcCible = ['sav' => 'SAV', 'finance' => 'FINANCE', 'commerce' => 'COMMERCIAL'][ma_contact_service($cible)] ?? $dem['service'];
    $sms = $svcCible === 'SAV' ? (ma_mobile_sms($cible['mobile'] ?? null) ?? '') : '';
    $maj = ['service' => $svcCible, 'destinataires' => $cible['nom'], 'dest_to' => implode(';', ma_liste_emails($cible['email'] ?? null)),
        'dest_cc' => '', 'dest_sms' => $sms, 'statut' => 'a_traiter', 'pris_par' => null, 'pris_at' => null,
        'regle_libelle' => 'Transférée par ' . ($par ?: 'l\'équipe')];
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
    $db->prepare("UPDATE rep_demandes SET $sets WHERE id = ?")->execute([...array_values($maj), $dem['id']]);
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
    $st->execute([$dem['id']]);
    $apres = $st->fetch();
    $lien = ma_rep_liens($db, $apres)['interne'];
    $contenu = ma_rep_message($apres, [
        'marque_libelle' => $apres['marque'] ?? '', 'nature_libelle' => '', 'service' => ma_rep_service($svcCible), 'marque' => $apres['marque_norm'] ?? '',
        'type_client' => $apres['type_client'] ?? 'direct', 'urgent' => $apres['priorite'] === 'URGENT' && $svcCible === 'SAV',
        'departement' => $apres['departement'], 'personnes' => [$cible], 'url' => ma_url_base($db), 'lien_interne' => $lien,
        'transfert' => 'Transférée par ' . ($par ?: 'l\'équipe') . ($message !== '' ? ' : « ' . $message . ' »' : '.'),
    ]);
    $envoi = 'aucune adresse';
    if ($maj['dest_to'] !== '') {
        $envoi = ma_webhook_suivi($db, ['evenement' => 'transfert', 'demande_id' => (int) $apres['id'], 'mobile' => false, 'tel' => '',
            'email' => str_replace(';', ',', $maj['dest_to']), 'objet' => 'Demande transférée — ' . $contenu['objet'], 'html' => $contenu['mail_html'],
            'texte' => '', 'lien' => $lien, 'sms' => $sms, 'sms_texte' => $contenu['sms_texte']]);
    }
    $envois = array_values(array_map(fn($a) => ['canal' => 'E-mail', 'a' => $a], ma_liste_emails($cible['email'] ?? null)));
    if ($sms !== '') {
        $envois[] = ['canal' => 'SMS', 'a' => ma_tel_lisible($sms)];
    }
    ma_rep_evenement($db, (int) $apres['id'], 'transferee', $par ?: null, 'plateforme', 'Transférée à ' . $cible['nom'] . ($svcCible !== $dem['service'] ? ', passée au ' . ma_rep_service_libelle($svcCible) : ''),
        ['envois' => $envois, 'texte' => $message, 'envoi' => $envoi, 'avant' => $dem['destinataires']]);
    return $apres;
}

// ------------------------------------------------------------------ Base clients et reconnaissance

/** Numéro utilisable pour reconnaître quelqu'un (chiffres uniquement, 9 à 15), sinon null. */
function ma_tel_cle(?string $tel): ?string
{
    $t = ma_tel($tel);
    return $t && preg_match('/^\d{9,15}$/', $t) ? $t : null;
}

/**
 * Rattache une demande à la fiche de son client (même numéro, sinon même e-mail), crée la fiche au
 * besoin, et la met à jour avec ce que la demande apprend de plus récent. Les compteurs sont recalculés.
 */
function ma_client_rattacher(PDO $db, int $demandeId): ?int
{
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
    $st->execute([$demandeId]);
    $d = $st->fetch();
    if (!$d) {
        return null;
    }
    $tel = ma_tel_cle($d['tel'] ?? null);
    $email = str_contains((string) ($d['email'] ?? ''), '@') ? mb_strtolower(trim((string) $d['email'])) : null;
    if (!$tel && !$email) {
        return null;
    }
    $c = null;
    if ($tel) {
        $q = $db->prepare('SELECT * FROM rep_clients WHERE tel = ? ORDER BY id LIMIT 1');
        $q->execute([$tel]);
        $c = $q->fetch() ?: null;
    }
    if (!$c && $email) {
        $q = $db->prepare('SELECT * FROM rep_clients WHERE lower(email) = ? ORDER BY id LIMIT 1');
        $q->execute([$email]);
        $c = $q->fetch() ?: null;
        // Même e-mail mais un autre numéro déjà connu : c'est une autre personne de la même maison.
        if ($c && $tel && $c['tel'] && $c['tel'] !== $tel) {
            $c = null;
        }
    }
    $now = ma_now();
    if (!$c) {
        $id = ma_inserer($db, 'rep_clients', ['tel' => $tel, 'email' => $email, 'created_at' => $now, 'updated_at' => $now]);
        $q = $db->prepare('SELECT * FROM rep_clients WHERE id = ?');
        $q->execute([$id]);
        $c = $q->fetch();
    }
    $cid = (int) $c['id'];
    $db->prepare('UPDATE rep_demandes SET client_id = ? WHERE id = ?')->execute([$cid, $demandeId]);

    // Ce que l'on sait de plus récent, demande après demande (la plus récente l'emporte, les vides n'effacent rien).
    $q = $db->prepare('SELECT * FROM rep_demandes WHERE client_id = ? ORDER BY created_at, id');
    $q->execute([$cid]);
    $liste = $q->fetchAll();
    $maj = ['tel' => $c['tel'] ?: $tel, 'email' => $c['email'] ?: $email];
    $champs = ['contact' => 'contact', 'societe' => 'societe', 'type_client' => 'type_client', 'type_interlocuteur' => 'type_interlocuteur',
        'code_postal' => 'code_postal', 'departement' => 'departement', 'marque' => 'marque', 'modele' => 'modele', 'numero_serie' => 'numero_serie',
        'compte_distributeur' => 'compte_distributeur'];
    $corriges = ma_csv($c['champs_corriges'] ?? '');
    foreach ($corriges as $k) {
        unset($champs[$k]);
    }
    if (in_array('tel', $corriges, true)) {
        $maj['tel'] = $c['tel'];
    }
    if (in_array('email', $corriges, true)) {
        $maj['email'] = $c['email'];
    }
    foreach ($liste as $x) {
        foreach ($champs as $k => $col) {
            $v = trim((string) ($x[$col] ?? ''));
            if ($v !== '' && !in_array(ma_plat($v), ['inconnue', 'autre', 'non communique', 'societe non precisee'], true)) {
                if ($k === 'contact') {
                    // « Monsieur Mortier » : la civilité va dans sa colonne, et un nom seul ne remplace pas
                    // « Cyril Mortier » déjà connu.
                    [$civ, $v] = ma_civilite_de($v);
                    if ($civ && !in_array('civilite', $corriges, true)) {
                        $maj['civilite'] = $civ;
                    }
                    $avant = mb_strtolower((string) ($maj['contact'] ?? ''));
                    if ($v === '' || (!str_contains($v, ' ') && str_ends_with($avant, ' ' . mb_strtolower($v)))) {
                        continue;
                    }
                }
                $maj[$k] = $v;
            }
        }
    }
    $der = end($liste);
    $maj += [
        'nb_demandes' => count($liste),
        'nb_urgences' => count(array_filter($liste, fn($x) => $x['priorite'] === 'URGENT')),
        'premiere_demande' => $liste[0]['created_at'] ?? null,
        'derniere_demande' => $der['created_at'] ?? null,
        'derniere_demande_id' => $der['id'] ?? null,
        'updated_at' => $now,
    ];
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
    $db->prepare("UPDATE rep_clients SET $sets WHERE id = ?")->execute([...array_values($maj), $cid]);
    ma_equipement_rattacher($db, $demandeId);
    return $cid;
}

/**
 * Ajoute au parc du client la machine citée par une demande, ou reconnaît celle qu'il a déjà : même
 * numéro de série, sinon même marque et même modèle sur le même site. Une nouvelle machine s'ajoute,
 * elle ne remplace jamais les autres.
 */
function ma_equipement_rattacher(PDO $db, int $demandeId): ?int
{
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
    $st->execute([$demandeId]);
    $d = $st->fetch();
    if (!$d || !$d['client_id']) {
        return null;
    }
    $marque = in_array(ma_plat($d['marque'] ?? ''), ['', 'inconnue', 'autre', 'a qualifier'], true) ? '' : trim((string) $d['marque']);
    $modele = trim((string) ($d['modele'] ?? ''));
    $serie = trim((string) ($d['numero_serie'] ?? ''));
    if ($marque === '' && $modele === '' && $serie === '') {
        return null;
    }
    $site = trim((string) ($d['code_postal'] ?? ''));
    $cid = (int) $d['client_id'];
    $eq = null;
    $parc = $db->prepare('SELECT * FROM rep_equipements WHERE client_id = ? ORDER BY id');
    $parc->execute([$cid]);
    $plat = fn($v) => preg_replace('/[^a-z0-9]/', '', ma_plat($v));
    foreach ($parc->fetchAll() as $e) {
        if ($serie !== '' && $plat($e['numero_serie']) === $plat($serie)) {
            $eq = $e;
            break;
        }
        $memeModele = $modele !== '' && $plat($e['modele']) === $plat($modele);
        $memeMarque = $marque === '' || $e['marque'] === '' || $e['marque'] === null || ma_rep_marque($e['marque']) === ma_rep_marque($marque);
        $memeSite = $site === '' || (string) $e['code_postal'] === '' || (string) $e['code_postal'] === $site;
        if ($serie === '' || trim((string) $e['numero_serie']) === '') {
            if (($memeModele && $memeMarque && $memeSite) || ($modele === '' && trim((string) $e['modele']) === '' && $memeMarque && $memeSite && $marque !== '')) {
                $eq = $e;
                break;
            }
        }
    }
    $now = ma_now();
    if (!$eq) {
        $eid = ma_inserer($db, 'rep_equipements', ['client_id' => $cid, 'marque' => $marque ?: null, 'modele' => $modele ?: null,
            'numero_serie' => $serie ?: null, 'type_equipement' => $d['type_equipement'] ?: null, 'code_postal' => $site ?: null,
            'departement' => $d['departement'] ?: null, 'source' => 'auto', 'created_at' => $d['created_at'] ?: $now, 'updated_at' => $now]);
    } else {
        $eid = (int) $eq['id'];
        // On complète ce qui manquait, sans écraser ce qui a été saisi ou corrigé.
        $maj = [];
        foreach (['marque' => $marque, 'modele' => $modele, 'numero_serie' => $serie, 'code_postal' => $site,
            'type_equipement' => (string) ($d['type_equipement'] ?? ''), 'departement' => (string) ($d['departement'] ?? '')] as $k => $v) {
            if ($v !== '' && trim((string) $eq[$k]) === '') {
                $maj[$k] = $v;
            }
        }
        if ($maj) {
            $maj['updated_at'] = $now;
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
            $db->prepare("UPDATE rep_equipements SET $sets WHERE id = ?")->execute([...array_values($maj), $eid]);
        }
    }
    $db->prepare('UPDATE rep_demandes SET equipement_id = ? WHERE id = ?')->execute([$eid, $demandeId]);
    ma_equipement_compter($db, $eid);
    return $eid;
}

function ma_equipement_compter(PDO $db, int $eid): void
{
    $db->prepare('UPDATE rep_equipements SET nb_demandes = (SELECT COUNT(*) FROM rep_demandes WHERE equipement_id = ?),
        derniere_demande = (SELECT MAX(created_at) FROM rep_demandes WHERE equipement_id = ?) WHERE id = ?')->execute([$eid, $eid, $eid]);
}

/** Réunit deux fiches d'une même personne : les demandes et les machines de l'autre passent sur celle-ci. */
function ma_clients_fusionner(PDO $db, int $garder, int $absorber): void
{
    if ($garder === $absorber) {
        return;
    }
    $db->prepare('UPDATE rep_demandes SET client_id = ? WHERE client_id = ?')->execute([$garder, $absorber]);
    $db->prepare('UPDATE rep_equipements SET client_id = ? WHERE client_id = ?')->execute([$garder, $absorber]);
    $st = $db->prepare('SELECT * FROM rep_clients WHERE id IN (?, ?)');
    $st->execute([$garder, $absorber]);
    $fiches = array_column($st->fetchAll(), null, 'id');
    $g = $fiches[$garder] ?? null;
    $a = $fiches[$absorber] ?? null;
    if ($g && $a) {
        // Ce qui manque à la fiche gardée est repris de l'autre ; les notes s'ajoutent.
        $maj = [];
        foreach (['tel', 'email', 'contact', 'societe', 'type_client', 'type_interlocuteur', 'code_postal', 'departement', 'compte_distributeur', 'statut_client'] as $k) {
            if (trim((string) $g[$k]) === '' && trim((string) $a[$k]) !== '') {
                $maj[$k] = $a[$k];
            }
        }
        if (trim((string) $a['notes']) !== '') {
            $maj['notes'] = trim(trim((string) $g['notes']) . "
" . trim((string) $a['notes']));
        }
        if ($maj) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
            $db->prepare("UPDATE rep_clients SET $sets WHERE id = ?")->execute([...array_values($maj), $garder]);
        }
    }
    $db->prepare('DELETE FROM rep_clients WHERE id = ?')->execute([$absorber]);
    $d = $db->prepare('SELECT id FROM rep_demandes WHERE client_id = ? ORDER BY created_at DESC LIMIT 1');
    $d->execute([$garder]);
    if ($did = $d->fetchColumn()) {
        ma_client_rattacher($db, (int) $did);
    }
}

/** Constitution de la base : toutes les demandes sans fiche client, de la plus ancienne à la plus récente. */
function ma_clients_reprise(PDO $db): int
{
    $n = 0;
    foreach ($db->query('SELECT id FROM rep_demandes WHERE client_id IS NULL ORDER BY created_at, id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (ma_client_rattacher($db, (int) $id)) {
            $n++;
        }
    }
    return $n;
}

/**
 * Comment saluer la personne : « Monsieur Mortier », « Madame Leroy » quand la civilité est connue
 * (fiche client), sinon prénom et nom tels qu'enregistrés. Null quand on ne connaît pas son nom.
 */
function ma_salutation(?string $contact, ?string $civilite): ?string
{
    [$civDite, $contact] = ma_civilite_de($contact);
    if ($contact === '' || !preg_match('/\p{L}/u', $contact)) {
        return null;
    }
    $civ = ['M' => 'Monsieur', 'MME' => 'Madame'][strtoupper(trim((string) ($civilite ?: $civDite)))] ?? null;
    if (!$civ) {
        return $contact;
    }
    $mots = explode(' ', $contact);
    if (count($mots) === 1) {
        return $civ . ' ' . $mots[0];
    }
    // Nom écrit en capitales (« MORTIER Cyril ») : c'est lui. Sinon, tout ce qui suit le prénom.
    $caps = array_values(array_filter($mots, fn($m) => mb_strlen($m) > 1 && $m === mb_strtoupper($m) && preg_match('/\p{L}/u', $m)));
    $nom = $caps && count($caps) < count($mots) ? implode(' ', $caps) : implode(' ', array_slice($mots, 1));
    $nom = $nom === mb_strtoupper($nom) ? mb_convert_case(mb_strtolower($nom), MB_CASE_TITLE) : $nom;
    return $civ . ' ' . $nom;
}

/**
 * « Monsieur Mortier », « Mme Sophie Leroy » : la civilité dite par la personne (ou notée par Claire)
 * devant son nom. Rend [M|Mme|null, nom sans la civilité].
 */
function ma_civilite_de(?string $contact): array
{
    $contact = trim(preg_replace('/\s+/u', ' ', (string) $contact));
    if (preg_match('/^(monsieur|mr\.?|m\.)\s+(.+)$/iu', $contact, $m)) {
        return ['M', trim($m[2])];
    }
    if (preg_match('/^(madame|mme\.?|mlle\.?|mademoiselle)\s+(.+)$/iu', $contact, $m)) {
        return ['Mme', trim($m[2])];
    }
    return [null, $contact];
}

/** « Bonjour Monsieur Mortier, » dans les messages au client (fiche client si elle existe). */
function ma_rep_bonjour(PDO $db, array $dem): string
{
    $civ = null;
    if (ma_param($db, 'rep_accueil_nom', '0') !== '1') {
        return 'Bonjour' . (trim((string) ($dem['contact'] ?? '')) !== '' ? ' ' . trim((string) $dem['contact']) : '') . ',';
    }
    if (!empty($dem['client_id'])) {
        $q = $db->prepare('SELECT civilite, contact FROM rep_clients WHERE id = ?');
        $q->execute([(int) $dem['client_id']]);
        $c = $q->fetch();
        // La civilité ne vaut que si c'est bien la même personne que sur la fiche.
        $nom = fn($x) => mb_strtolower(ma_civilite_de($x)[1]);
        $a = $c ? $nom($c['contact']) : '';
        $b = $nom($dem['contact'] ?? '');
        if ($c && $a !== '' && $b !== '' && ($a === $b || str_ends_with($a, ' ' . $b) || str_ends_with($b, ' ' . $a))) {
            $civ = $c['civilite'];
        }
    }
    $s = ma_salutation($dem['contact'] ?? null, $civ);
    return 'Bonjour' . ($s ? ' ' . $s : '') . ',';
}

/** Numéro de portable (06, 07) : une seule personne derrière, on peut la saluer par son nom d'emblée. */
function ma_tel_portable(?string $tel): bool
{
    return (bool) preg_match('/^33[67]\d{8}$/', (string) ma_tel_cle($tel));
}

/** Libellé de fidélité : choisi par l'équipe, sinon déduit du nombre de demandes. */
function ma_client_fidelite(array $c): string
{
    $s = ma_plat($c['statut_client'] ?? '');
    if ($s !== '') {
        return $s;
    }
    return ((int) ($c['nb_demandes'] ?? 0)) >= 3 ? 'fidele' : (((int) ($c['nb_demandes'] ?? 0)) >= 2 ? 'recurrent' : 'nouveau');
}

/**
 * Qui appelle ? Cherche le numéro (ou l'e-mail) dans la base clients, puis dans les appels passés, les
 * distributeurs et les devis. Rend ce qu'il faut à Claire pour accueillir la personne par son nom et ne
 * pas redemander ce qu'on sait déjà. Jamais de coordonnée d'un collaborateur.
 */
function ma_client_reconnaitre(PDO $db, ?string $tel, ?string $email = null): array
{
    $tel = ma_tel_cle($tel);
    $email = str_contains((string) $email, '@') ? mb_strtolower(trim((string) $email)) : null;
    $out = ['connu' => false, 'tel' => $tel];
    $c = null;
    if ($tel) {
        $q = $db->prepare('SELECT * FROM rep_clients WHERE tel = ? ORDER BY derniere_demande DESC LIMIT 1');
        $q->execute([$tel]);
        $c = $q->fetch() ?: null;
    }
    if (!$c && $email) {
        $q = $db->prepare('SELECT * FROM rep_clients WHERE lower(email) = ? ORDER BY derniere_demande DESC LIMIT 1');
        $q->execute([$email]);
        $c = $q->fetch() ?: null;
    }
    // Distributeur de l'annuaire : reconnu même s'il n'a jamais appelé.
    $distri = null;
    $tous = $db->query("SELECT * FROM distributeurs WHERE (telephone IS NOT NULL AND telephone != '') OR (email IS NOT NULL AND email != '')")->fetchAll();
    foreach ($tous as $x) {
        if (($tel && ma_tel_cle($x['telephone']) === $tel) || ($email && mb_strtolower(trim((string) $x['email'])) === $email)) {
            $distri = $x;
            break;
        }
    }
    $fiche = null;
    if (!$c && $tel) {
        $q = $db->prepare('SELECT * FROM rep_fiches WHERE tel_norm = ? ORDER BY created_at DESC LIMIT 1');
        $q->execute([$tel]);
        $fiche = $q->fetch() ?: null;
    }
    if (!$c && !$distri && !$fiche) {
        $out['contexte'] = 'Appelant inconnu : première prise de contact. Recueillez toutes les informations.';
        return $out;
    }
    $out['connu'] = true;
    $out['client_id'] = $c ? (int) $c['id'] : null;
    $out['contact'] = $c['contact'] ?? ($fiche['contact'] ?? ($distri ? trim(($distri['prenom'] ?? '') . ' ' . ($distri['nom'] ?? '')) : null));
    $out['societe'] = $c['societe'] ?? ($fiche['societe'] ?? ($distri['raison_sociale'] ?? null));
    $out['type_interlocuteur'] = $distri ? 'distributeur' : ($c['type_interlocuteur'] ?? ($c && $c['type_client'] === 'distributeur' ? 'distributeur' : null));
    $out['email'] = $c['email'] ?? ($fiche['email'] ?? ($distri['email'] ?? null));
    $out['code_postal'] = $c['code_postal'] ?? ($fiche['code_postal'] ?? null);
    $out['departement'] = $c['departement'] ?? ($fiche['departement'] ?? null);
    $out['marque'] = $c['marque'] ?? ($fiche['marque'] ?? ($distri['marque'] ?? null));
    $out['modele'] = $c['modele'] ?? ($fiche['modele'] ?? null);
    $out['numero_serie'] = $c['numero_serie'] ?? null;
    $out['compte_distributeur'] = $distri['compte'] ?? ($c['compte_distributeur'] ?? null);
    $out['nb_demandes'] = (int) ($c['nb_demandes'] ?? 0);
    $out['fidelite'] = $c ? ma_client_fidelite($c) : ($distri ? 'distributeur' : 'nouveau');
    $out['notes'] = $c['notes'] ?? null;
    $out['civilite'] = $c['civilite'] ?? null;
    $out['salutation'] = ma_salutation($out['contact'], $out['civilite']);
    // Portable : une seule personne, on la salue par son nom. Ligne fixe (standard d'une société) :
    // c'est peut-être un collègue, on vérifie d'abord à qui on parle.
    $out['portable'] = ma_tel_portable($tel);
    $out['accueil'] = $out['salutation']
        ? ($out['portable'] || !$tel ? 'Bonjour ' . $out['salutation'] . '.' : 'Bonjour, je suis bien avec ' . $out['salutation'] . ' ?')
        : ($out['societe'] ? 'Bonjour, vous appelez bien de la part de ' . $out['societe'] . ' ?' : null);
    // Dernière demande, et celle qui serait encore ouverte : Claire peut dire où elle en est.
    $ouverte = null;
    $derniere = null;
    if ($c) {
        $q = $db->prepare("SELECT * FROM rep_demandes WHERE client_id = ? ORDER BY created_at DESC LIMIT 5");
        $q->execute([$c['id']]);
        $dems = $q->fetchAll();
        $derniere = $dems[0] ?? null;
        foreach ($dems as $x) {
            if ($x['statut'] !== 'traite') {
                $ouverte = $x;
                break;
            }
        }
    }
    $resume = function (?array $x): ?array {
        if (!$x) {
            return null;
        }
        [$materiel, $sujet] = ma_rep_objet_client($x);
        return ['id' => (int) $x['id'], 'date' => ma_date_fr($x['created_at'], false), 'service' => $x['service'], 'objet' => trim($materiel . ($materiel && $sujet ? ' — ' : '') . $sujet),
            'statut' => ['a_traiter' => 'pas encore prise en charge', 'en_cours' => 'prise en charge' . ($x['pris_at'] ? ' ' . ma_date_parlee($x['pris_at']) : ''),
                'traite' => 'traitée' . ($x['traite_at'] ? ' ' . ma_date_parlee($x['traite_at']) : '')][$x['statut']] ?? $x['statut'],
            'pris_par' => $x['pris_par'] ?: null, 'rappel_prevu' => $x['rappel_prevu'] ? ma_date_parlee($x['rappel_prevu']) : null,
            'rappel_depasse' => $x['rappel_prevu'] && $x['statut'] !== 'traite' && strtotime((string) $x['rappel_prevu']) < time(),
            'traite_at' => $x['traite_at'], 'nb_relances' => (int) ($x['nb_relances'] ?? 0)];
    };
    $out['derniere_demande'] = $resume($derniere);
    $out['demande_ouverte'] = $resume($ouverte);
    // Phrase prête à l'emploi pour Claire. L'identité se confirme toujours ; le matériel et le site
    // dépendent du sujet de l'appel : un distributeur ou un client équipé a plusieurs machines.
    $jours = (int) (ma_param($db, 'rep_reco_jours', '30') ?: 30);
    $recente = $derniere && strtotime((string) $derniere['created_at']) >= time() - $jours * 86400;
    $ilya = function (?string $dt): string {
        $j = $dt ? (int) floor((time() - strtotime($dt)) / 86400) : 0;
        return $j <= 0 ? "aujourd'hui" : ($j === 1 ? 'hier' : ($j < 60 ? "il y a $j jours" : 'il y a ' . (int) round($j / 30) . ' mois'));
    };
    $distributeur = $out['type_interlocuteur'] === 'distributeur';
    $p = [];
    $p[] = 'Appelant connu' . ($out['contact'] ? ' : ' . $out['contact'] : '') . ($out['societe'] ? ', ' . $out['societe'] : '')
        . ($distributeur ? ' (distributeur' . ($out['compte_distributeur'] ? ', compte ' . $out['compte_distributeur'] : '') . ')' : '') . '.';
    // Accueil « Bonjour Monsieur Mortier » sobre (sans fidélité) : activé par le paramètre rep_accueil_nom.
    $accueilNom = ma_param($db, 'rep_accueil_nom', '0') === '1';
    if (!$accueilNom) {
        $out['accueil'] = null;
    }
    if (!$accueilNom && $out['nb_demandes'] > 0) {
        $p[] = $out['nb_demandes'] . ' demande' . ($out['nb_demandes'] > 1 ? 's' : '') . ' déjà enregistrée' . ($out['nb_demandes'] > 1 ? 's' : '')
            . ($out['fidelite'] === 'fidele' || $out['fidelite'] === 'vip' ? ' : client fidèle, à remercier de sa confiance' : '') . '.';
    }
    if ($accueilNom && $out['accueil']) {
        $p[] = 'Salutation : « ' . $out['accueil'] . ' » (si le premier message l\'a déjà saluée par son nom, ne la resaluez pas).'
            . ' Sobre et professionnel : ni remerciement pour sa fidélité, ni nombre d\'appels, ni « je vous reconnais ».'
            . ' Si ce n\'est pas cette personne, excusez-vous brièvement et demandez son nom.';
    }
    $ident = array_filter(['nom et société' => (bool) ($out['contact'] || $out['societe']), 'e-mail ' . $out['email'] => (bool) $out['email'],
        'distributeur' => $distributeur]);
    if ($ident) {
        $p[] = 'Identité à faire confirmer, sans la redemander : ' . implode(', ', array_keys($ident)) . '.';
    }
    // Parc machines (les plus récemment citées d'abord) : à proposer, jamais à supposer.
    $materiels = [];
    if ($c) {
        $q = $db->prepare('SELECT * FROM rep_equipements WHERE client_id = ? AND actif = 1 ORDER BY COALESCE(derniere_demande, created_at) DESC LIMIT 5');
        $q->execute([$c['id']]);
        foreach ($q->fetchAll() as $x) {
            $m = trim(implode(' ', array_filter([$x['marque'], $x['modele']])));
            $site = $x['code_postal'] ?: ($x['departement'] ? 'dpt ' . $x['departement'] : '');
            $materiels[] = ($m ?: 'machine') . ($x['numero_serie'] ? ' n° ' . $x['numero_serie'] : '') . ($site ? ' (site ' . $site . ')' : '');
        }
    }
    $out['materiels'] = array_values($materiels);
    $p[] = 'Nous sommes ' . ma_date_parlee(ma_now(), true) . '.';
    if ($out['demande_ouverte']) {
        $o = $out['demande_ouverte'];
        $p[] = 'Demande encore ouverte n° ' . $o['id'] . ' du ' . $o['date'] . ($o['objet'] ? ' (' . $o['objet'] . ')' : '') . ' : ' . $o['statut']
            . ($o['pris_par'] ? ', suivie par ' . $o['pris_par'] : '') . '.'
            . ($o['rappel_prevu'] ? ($o['rappel_depasse']
                ? ' Le rappel était prévu ' . $o['rappel_prevu'] . ' et n\'a pas encore eu lieu : excusez-vous du retard.'
                : ' Rappel prévu ' . $o['rappel_prevu'] . ' : dites-le au client.') : '')
            . ($o['nb_relances'] ? ' Le client a déjà relancé ' . $o['nb_relances'] . ' fois.' : '')
            . ' Demandez si l\'appel concerne cette demande. Si oui : confirmez-lui qu\'elle est toujours ouverte, dites où elle en est, dites que vous relancez l\'équipe,'
            . ' faites confirmer le matériel et le site déjà connus, et transmettez avec « Relance de la demande n° ' . $o['id'] . '. » au début de resume.'
            . ' Sinon, c\'est un nouveau sujet : demandez ce dont il a besoin.';
    } elseif ($derniere && $derniere['statut'] === 'traite' && strtotime((string) ($derniere['traite_at'] ?: $derniere['created_at'])) >= time() - (int) (ma_param($db, 'rep_reouverture_jours', '15') ?: 15) * 86400) {
        $o = $out['derniere_demande'];
        $p[] = 'Dernière demande n° ' . $o['id'] . ($o['objet'] ? ' (' . $o['objet'] . ')' : '') . ' : ' . $o['statut'] . '.'
            . ' Si le client revient pour le même problème (il n\'est pas réglé, il revient), dites-lui que vous rouvrez sa demande et transmettez avec'
            . ' « Relance de la demande n° ' . $o['id'] . '. » au début de resume. Sinon, c\'est un nouveau sujet.';
    } elseif ($recente) {
        $o = $out['derniere_demande'];
        $p[] = 'Dernier contact ' . $ilya($derniere['created_at']) . ($o['objet'] ? ' pour : ' . $o['objet'] : '') . ' (' . $o['statut'] . ').'
            . ' Demandez si c\'est au sujet de cette demande. Si oui, faites confirmer le matériel et le site déjà connus ; sinon, demandez ce dont il a besoin.';
    } elseif ($derniere) {
        $p[] = 'Dernier contact ' . $ilya($derniere['created_at']) . ' : c\'est ancien, ne présumez ni du matériel ni du site. Demandez ce qui l\'amène.';
    }
    if ($materiels && !$out['demande_ouverte']) {
        $p[] = 'Machines connues de ce client : ' . implode(' ; ', $materiels) . '. Pour un nouveau sujet, vous pouvez les proposer ("C\'est pour le '
            . explode(' n° ', explode(' (', reset($materiels))[0])[0] . ' ou pour une autre machine ?"), sans jamais supposer laquelle. Une machine non listée est une nouvelle machine : recueillez-la normalement.';
    }
    if ($distributeur) {
        $p[] = 'Un distributeur a plusieurs clients et machines : demandez toujours pour quel matériel et quel site il appelle, sauf s\'il relance une demande en cours.';
    }
    if (trim((string) $out['notes']) !== '') {
        $p[] = 'Note de l\'équipe (ne pas lire au client) : ' . trim((string) $out['notes']);
    }
    $out['contexte'] = implode(' ', $p);
    return $out;
}

// ------------------------------------------------------------------ Relances : le client rappelle pour une demande existante

/** La demande n° $id, si elle appartient bien à ce client (même numéro, même e-mail ou même fiche client). */
function ma_rep_demande_du_client(PDO $db, int $id, array $d): ?array
{
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
    $st->execute([$id]);
    $x = $st->fetch();
    if (!$x) {
        return null;
    }
    $tel = ma_tel_cle($d['tel'] ?? $d['telephone'] ?? null);
    $email = str_contains((string) ($d['email'] ?? ''), '@') ? mb_strtolower(trim((string) $d['email'])) : null;
    if (($tel && $tel === ma_tel_cle($x['tel'])) || ($email && $email === mb_strtolower(trim((string) $x['email'])))) {
        return $x;
    }
    if ($tel && $x['client_id']) {
        $c = $db->prepare('SELECT tel FROM rep_clients WHERE id = ?');
        $c->execute([$x['client_id']]);
        if ($tel === (string) $c->fetchColumn()) {
            return $x;
        }
    }
    return null;
}

/**
 * Le client revient sur une demande : si elle est ouverte, on relance l'équipe ; si elle a été fermée
 * récemment, on la rouvre. Pas de doublon : ce que le client a dit s'ajoute à la demande existante.
 * Rend la même forme que ma_rep_creer_demande : le scénario Make envoie l'e-mail et le SMS de relance.
 */
function ma_rep_relancer(PDO $db, array $x, array $d, string $mode): array
{
    $now = ma_now();
    $id = (int) $x['id'];
    $canal = ma_canal($d);
    $dit = trim(preg_replace('/relance\s+(?:de\s+)?(?:la\s+)?demande\s+n\W{0,2}\s*\d+\.?/iu', '', (string) ($d['resume'] ?? ($d['type_panne'] ?? ''))));
    $maj = ['nb_relances' => (int) $x['nb_relances'] + 1, 'derniere_relance' => $now];
    $urgentMaintenant = $x['service'] === 'SAV' && ma_rep_urgent($d);
    if ($urgentMaintenant && $x['priorite'] !== 'URGENT') {
        $maj['priorite'] = 'URGENT';
        $maj['justification_urgence'] = ma_str($d['justification_urgence'] ?? null) ?? $x['justification_urgence'];
    }
    if ($mode === 'reouverture') {
        $maj['statut'] = 'a_traiter';
    }
    foreach (['email', 'code_postal', 'numero_serie', 'modele'] as $k) {
        if (trim((string) ($x[$k] ?? '')) === '' && trim((string) ($d[$k] ?? '')) !== '' && ($k !== 'email' || str_contains((string) $d[$k], '@'))) {
            $maj[$k] = trim((string) $d[$k]);
        }
    }
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($maj)));
    $db->prepare("UPDATE rep_demandes SET $sets WHERE id = ?")->execute([...array_values($maj), $id]);
    if (!empty($d['fiche_id'])) {
        $db->prepare("UPDATE rep_fiches SET transmis_at = COALESCE(transmis_at, ?) WHERE id = ?")->execute([$now, (int) $d['fiche_id']]);
    }
    $st = $db->prepare('SELECT * FROM rep_demandes WHERE id = ?');
    $st->execute([$id]);
    $apres = $st->fetch();

    // Qui relancer : ceux qui ont la demande, et la personne qui l'a prise ou fermée.
    $personnes = [];
    $noms = array_filter(array_unique([$apres['pris_par'], $apres['traite_par']]));
    foreach ($noms as $nom) {
        $q = $db->prepare("SELECT * FROM rep_contacts WHERE actif = 1 AND (lower(nom) = lower(?) OR lower(nom) LIKE lower(?)) ORDER BY length(nom) LIMIT 1");
        $q->execute([$nom, $nom . ' %']);
        if ($p = $q->fetch()) {
            $personnes[$p['id']] = $p;
        }
    }
    $to = ma_liste_emails($apres['dest_to']);
    $sms = [];
    foreach (array_filter(explode(';', (string) $apres['dest_sms'])) as $m) {
        $sms[$m] = $m;
    }
    foreach ($personnes as $p) {
        $to += ma_liste_emails($p['email'] ?? null);
        if ($apres['service'] === 'SAV' && ($m = ma_mobile_sms($p['mobile'] ?? null))) {
            $sms[$m] = $m;
        }
    }
    $nomsListe = implode(', ', array_unique(array_filter(array_merge(array_column($personnes, 'nom'), [$apres['destinataires']]))));
    $quand = ma_date_fr($now);
    $intro = ($mode === 'reouverture'
            ? 'Demande rouverte : le client a rappelé ' . $quand . ' (' . (MA_CANAUX[$canal][1] ?? '') . '), le problème n\'est pas réglé.'
            : 'Relance du client : il a rappelé ' . $quand . ' (' . (MA_CANAUX[$canal][1] ?? '') . '), sa demande est toujours ouverte'
                . ($apres['pris_par'] ? ' (prise par ' . $apres['pris_par'] . ')' : ' et personne ne l\'a encore prise') . '.')
        . ($apres['rappel_prevu'] && $mode !== 'reouverture' ? (strtotime((string) $apres['rappel_prevu']) < time()
            ? ' Le rappel prévu ' . ma_date_parlee($apres['rappel_prevu']) . ' n\'a pas eu lieu.' : ' Rappel prévu ' . ma_date_parlee($apres['rappel_prevu']) . '.') : '')
        . ((int) $apres['nb_relances'] > 1 ? ' ' . $apres['nb_relances'] . 'e relance.' : '')
        . ($dit !== '' ? ' Ce qu\'il dit : « ' . $dit . ' »' : '');
    $contenu = ma_rep_message($apres, [
        'marque_libelle' => $apres['marque'] ?? '', 'nature_libelle' => '', 'service' => ma_rep_service($apres['service']), 'marque' => $apres['marque_norm'] ?? '',
        'type_client' => $apres['type_client'] ?? 'direct', 'urgent' => $apres['priorite'] === 'URGENT' && $apres['service'] === 'SAV',
        'departement' => $apres['departement'], 'personnes' => array_values($personnes) ?: [['nom' => $apres['destinataires'] ?: '']],
        'url' => ma_url_base($db), 'lien_interne' => ma_rep_liens($db, $apres)['interne'], 'transfert' => $intro,
    ]);
    $objet = ($mode === 'reouverture' ? 'ROUVERTE' : 'RELANCE CLIENT') . ' — demande n° ' . $id . ' — ' . $contenu['objet'];
    $smsTexte = ($mode === 'reouverture' ? 'Demande ROUVERTE' : 'RELANCE client') . ' n° ' . $id . ' - ' . $contenu['sms_texte'];
    if (mb_strlen($smsTexte) > 320) {
        $smsTexte = mb_substr($smsTexte, 0, 317) . '...';
    }
    $envois = array_values(array_map(fn($a) => ['canal' => 'E-mail', 'a' => $a], $to));
    foreach ($sms as $m) {
        $envois[] = ['canal' => 'SMS', 'a' => ma_tel_lisible($m)];
    }
    ma_rep_evenement($db, $id, $mode === 'reouverture' ? 'rouverte' : 'relance', $d['contact'] ?? 'Client', $canal,
        ($mode === 'reouverture' ? 'Rouverte : le client a rappelé, le problème n\'est pas réglé' : 'Le client a rappelé : relance de l\'équipe')
        . ($urgentMaintenant && $x['priorite'] !== 'URGENT' ? ' — passée en URGENT' : ''),
        ['texte' => $dit, 'envois' => $envois]);
    $db->prepare('INSERT INTO executions_log(scenario, date, statut, type_evenement, resume) VALUES (?,?,?,?,?)')
        ->execute([$canal === 'chat' ? 'chatbot' : 'repondeur', $now, 'ok', $mode === 'reouverture' ? 'demande_rouverte' : 'demande_relancee',
            'Demande ' . $id . ' ' . ($mode === 'reouverture' ? 'rouverte' : 'relancée') . ' par le client → ' . ($nomsListe ?: implode(', ', $to))]);
    ma_rep_notifier_client($db, $apres, $mode === 'reouverture' ? 'rouverte' : 'relance_client');
    $rt = ['to' => implode(';', array_values($to)), 'cc' => '', 'sms' => implode(';', array_values($sms)), 'urgent' => $apres['priorite'] === 'URGENT',
        'regle_libelle' => $mode === 'reouverture' ? 'Demande rouverte' : 'Relance du client', 'notes' => [], 'personnes' => array_values($personnes),
        'objet' => $objet, 'mail_html' => $contenu['mail_html'], 'sms_texte' => $smsTexte, 'marque' => $apres['marque_norm'], 'nature' => $apres['nature'],
        'type_client' => $apres['type_client'], 'type_equipement' => $apres['type_equipement'], 'departement' => $apres['departement'], 'regle_id' => null];
    $st->execute([$id]);
    $apres = $st->fetch();
    return ['id' => $id, 'demande' => $apres, 'rt' => $rt, 'liens' => ma_rep_liens($db, $apres), 'relance' => $mode];
}

/** Date dite à voix haute, par rapport à maintenant : « aujourd'hui à 15h », « demain mercredi 30/09 à 10h », « hier ». */
function ma_date_parlee(?string $dt, bool $jourComplet = false): string
{
    $t = $dt ? strtotime($dt) : false;
    if (!$t) {
        return '';
    }
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $j = $jours[(int) date('w', $t)] . ' ' . date('d/m', $t);
    $heure = date('H:i', $t) !== '00:00' ? ' à ' . date('G\hi', $t) : '';
    $ecart = (int) round((strtotime(date('Y-m-d', $t)) - strtotime(date('Y-m-d'))) / 86400);
    if ($jourComplet) {
        return $j . $heure;
    }
    return match ($ecart) {
        0 => "aujourd'hui" . $heure,
        1 => 'demain ' . $j . $heure,
        -1 => 'hier ' . $j . $heure,
        default => 'le ' . $j . $heure,
    };
}
