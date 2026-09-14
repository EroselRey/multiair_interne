<?php
// MULTIAIR — fonctions communes (config, base, session, utilitaires)
declare(strict_types=1);

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
    $tables = ['parametres', 'executions_log', 'rep_fiches', 'rep_demandes', 'distributeurs',
        'chat_messages', 'chat_leads', 'routage', 'adv_demandes', 'cso_devis', 'cso_lignes',
        'cso_relances', 'cee_leads', 'cee_conversations', 'cee_actions'];
    $colonnes = [
        'chat_leads' => ['updated_at' => 'TEXT', 'nb_mises_a_jour' => 'INTEGER NOT NULL DEFAULT 0'],
        'adv_demandes' => ['commentaire' => 'TEXT'],
        'cso_devis' => ['commentaire' => 'TEXT', 'contact_interne' => 'TEXT'],
        'cee_leads' => ['commentaire' => 'TEXT'],
        'rep_demandes' => ['commentaire' => 'TEXT', 'email' => 'TEXT', 'departement' => 'TEXT'],
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
