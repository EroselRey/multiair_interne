<?php
// MULTIAIR — API JSON (appelée par Make et par la page)
// Routes : api.php?r=<ressource>[/<id>[/<action>]]   Méthodes : GET, POST, PATCH, DELETE
// Auth   : header X-Api-Key (Make) ou session (page)
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = ma_config();
$method = $_SERVER['REQUEST_METHOD'];
$route = trim((string) ($_GET['r'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);

// Corps JSON (Make envoie du JSON ; on accepte aussi du form-urlencoded)
$raw = file_get_contents('php://input');
$body = [];
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    } else {
        parse_str($raw, $body);
    }
}
if ($method === 'POST' && empty($body) && !empty($_POST)) {
    $body = $_POST;
}
// Surcharge de méthode (Make ne sait pas toujours faire PATCH/DELETE)
if (!empty($body['_method'])) {
    $method = strtoupper((string) $body['_method']);
} elseif (!empty($_GET['_method'])) {
    $method = strtoupper((string) $_GET['_method']);
}

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'erreur' => $msg], $code);
}

// ------------------------------------------------------------------ Authentification
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? ($body['api_key'] ?? null));
$viaApiKey = is_string($apiKey) && $apiKey !== '' && hash_equals((string) $cfg['api_key'], $apiKey);
$viaSession = ma_is_logged();

if ($parts === ['auth', 'login'] && $method === 'POST') {
    ma_session_start();
    $login = (string) ($body['login'] ?? '');
    $pass = (string) ($body['password'] ?? '');
    if (hash_equals((string) $cfg['login'], $login) && hash_equals((string) $cfg['password'], $pass)) {
        session_regenerate_id(true);
        $_SESSION['ma_auth'] = true;
        $_SESSION['ma_login'] = $login;
        out(['ok' => true, 'login' => $login]);
    }
    usleep(500000);
    fail('Identifiant ou mot de passe incorrect', 401);
}
if ($parts === ['auth', 'logout']) {
    ma_session_start();
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);
}
if ($parts === ['auth', 'recover'] && $method === 'POST') {
    // Envoie le mot de passe à l'adresse de récupération configurée (jamais à une adresse fournie par le client)
    $to = (string) ($cfg['recovery_email'] ?? '');
    if ($to === '') {
        fail('Aucune adresse de récupération configurée', 500);
    }
    $ok = ma_send_mail(
        $to,
        'MULTIAIR - Rappel de vos identifiants',
        "Bonjour,\n\nIdentifiants de la page MULTIAIR (interne) :\n\nIdentifiant : {$cfg['login']}\nMot de passe : {$cfg['password']}\n\n"
        . "Ils sont également lisibles dans le fichier MULTIAIR/config.php sur le serveur (FileZilla).\n"
    );
    out(['ok' => $ok, 'message' => $ok ? "Identifiants envoyés à $to" : "Échec de l'envoi du mail (vérifier la configuration SMTP dans config.php)"]);
}
if ($parts === ['auth', 'me']) {
    out(['ok' => true, 'connecte' => $viaSession, 'login' => $viaSession ? ($_SESSION['ma_login'] ?? 'admin') : null]);
}
if ($parts === ['ping']) {
    out(['ok' => true, 'version' => MA_VERSION, 'auth' => $viaApiKey ? 'api_key' : ($viaSession ? 'session' : 'aucune'), 'date' => ma_now()]);
}

if (!$viaApiKey && !$viaSession) {
    fail('Non autorisé (clé API ou session requise)', 401);
}

$db = ma_db();
$now = ma_now();

// ------------------------------------------------------------------ Helpers SQL
function cols(PDO $db, string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $cache[$table] = array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    }
    return $cache[$table];
}

/** Ne garde que les colonnes existantes (hors id). */
function pick(PDO $db, string $table, array $data): array
{
    $allowed = array_diff(cols($db, $table), ['id']);
    $out = [];
    foreach ($allowed as $c) {
        if (array_key_exists($c, $data)) {
            $out[$c] = $data[$c];
        }
    }
    return $out;
}

function insert(PDO $db, string $table, array $data): int
{
    $data = pick($db, $table, $data);
    if (!$data) {
        fail("Aucune donnée exploitable pour $table");
    }
    $keys = array_keys($data);
    $sql = "INSERT INTO $table (" . implode(',', $keys) . ') VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')';
    $db->prepare($sql)->execute(array_values($data));
    return (int) $db->lastInsertId();
}

function update(PDO $db, string $table, int $id, array $data): void
{
    $data = pick($db, $table, $data);
    if (!$data) {
        return;
    }
    $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($data)));
    $vals = array_values($data);
    $vals[] = $id;
    $db->prepare("UPDATE $table SET $set WHERE id = ?")->execute($vals);
}

function getOne(PDO $db, string $table, int $id): ?array
{
    $st = $db->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * Liste générique avec filtres simples :
 *   ?q=texte (recherche sur les colonnes texte), ?statut=a,b, ?from=YYYY-MM-DD, ?to=YYYY-MM-DD,
 *   ?limit=, ?offset=, ?order=col, ?dir=asc|desc, plus ?<colonne>=valeur pour une égalité.
 */
function listRows(PDO $db, string $table, string $dateCol, array $q, array $textCols = []): array
{
    $where = [];
    $args = [];
    $allCols = cols($db, $table);
    if (!empty($q['statut'])) {
        $vals = array_map('trim', explode(',', (string) $q['statut']));
        $where[] = 'statut IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
        array_push($args, ...$vals);
    }
    if (!empty($q['from'])) {
        $where[] = "$dateCol >= ?";
        $args[] = ma_date((string) $q['from']);
    }
    if (!empty($q['to'])) {
        $where[] = "$dateCol <= ?";
        $args[] = ma_date((string) $q['to'] . ' 23:59:59');
    }
    if (!empty($q['q']) && $textCols) {
        $like = [];
        foreach ($textCols as $c) {
            $like[] = "$c LIKE ?";
            $args[] = '%' . $q['q'] . '%';
        }
        $where[] = '(' . implode(' OR ', $like) . ')';
    }
    foreach ($q as $k => $v) {
        if (in_array($k, ['r', 'key', 'q', 'statut', 'from', 'to', 'limit', 'offset', 'order', 'dir', '_method'], true)) {
            continue;
        }
        if (in_array($k, $allCols, true) && $v !== '') {
            $where[] = "$k = ?";
            $args[] = $v;
        }
    }
    $order = (!empty($q['order']) && in_array($q['order'], $allCols, true)) ? $q['order'] : $dateCol;
    $dir = (isset($q['dir']) && strtolower((string) $q['dir']) === 'asc') ? 'ASC' : 'DESC';
    $limit = max(1, min(5000, (int) ($q['limit'] ?? 2000)));
    $offset = max(0, (int) ($q['offset'] ?? 0));
    $sql = "SELECT * FROM $table" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY $order $dir, id $dir LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function logEvent(PDO $db, string $scenario, string $statut, string $type, ?string $resume, $payload = null): void
{
    $db->prepare('INSERT INTO executions_log(scenario, date, statut, type_evenement, resume, payload) VALUES (?,?,?,?,?,?)')
        ->execute([$scenario, ma_now(), $statut, $type, $resume, is_null($payload) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

function idFrom(array $parts, int $i): int
{
    $id = (int) ($parts[$i] ?? 0);
    if ($id <= 0) {
        fail('Identifiant manquant');
    }
    return $id;
}

function param(array $body, array $get, string $key, $default = null)
{
    return $body[$key] ?? ($get[$key] ?? $default);
}

/** Routes de routage communes : find / liste / création / suppression. */
function routageRoutes(PDO $db, string $scenario, ?string $sub2, string $method, array $body): never
{
    if ($sub2 === 'find' || isset($_GET['cle'])) {
        out(['ok' => true] + ma_routage($db, $scenario, (string) ($_GET['cle'] ?? '')));
    }
    if ($method === 'POST' || $method === 'PATCH') {
        $cle = trim((string) ($body['cle'] ?? ''));
        if ($cle === '') {
            fail('cle requise');
        }
        if ($scenario === 'chatbot') {
            $cle = strtolower($cle);
        }
        $db->prepare('INSERT OR REPLACE INTO routage(scenario, cle, dest_to, dest_cc, libelle) VALUES (?,?,?,?,?)')
            ->execute([$scenario, $cle, ma_str($body['dest_to'] ?? null), ma_str($body['dest_cc'] ?? null) ?? '', ma_str($body['libelle'] ?? null)]);
    }
    if ($method === 'DELETE') {
        $db->prepare('DELETE FROM routage WHERE scenario = ? AND cle = ?')->execute([$scenario, trim((string) ($body['cle'] ?? $sub2 ?? ''))]);
    }
    $st = $db->prepare('SELECT * FROM routage WHERE scenario = ? ORDER BY cle');
    $st->execute([$scenario]);
    out(['ok' => true, 'scenario' => $scenario, 'rows' => $st->fetchAll()]);
}

$res = $parts[0] ?? '';
$sub = $parts[1] ?? null;
$sub2 = $parts[2] ?? null;

try {
    switch ($res) {

        // ============================================================ Journal & paramètres
        case 'log':
            if ($method === 'POST') {
                $scenario = ma_str($body['scenario'] ?? null) ?? fail('scenario requis');
                logEvent($db, $scenario, ma_str($body['statut'] ?? null) ?? 'ok', ma_str($body['type'] ?? null) ?? 'execution',
                    ma_str($body['resume'] ?? null), $body['payload'] ?? null);
                out(['ok' => true]);
            }
            out(['ok' => true, 'rows' => listRows($db, 'executions_log', 'date', $_GET, ['resume', 'type_evenement'])]);

        case 'parametres':
            if ($method === 'POST' || $method === 'PATCH') {
                foreach ((array) ($body['valeurs'] ?? $body) as $k => $v) {
                    if (preg_match('/^[a-z0-9_]+$/', (string) $k)) {
                        $db->prepare('INSERT OR REPLACE INTO parametres(cle, valeur) VALUES (?,?)')->execute([$k, ma_str($v)]);
                    }
                }
            }
            out(['ok' => true, 'parametres' => $db->query('SELECT cle, valeur FROM parametres')->fetchAll(PDO::FETCH_KEY_PAIR)]);

        // ============================================================ Répondeur IA
        case 'rep':
            if ($sub === 'fiches') {
                if ($sub2 === 'find') {
                    // Remplace filterRows QUALIFICATION_EN_COURS : ?tel=…&statut=En attente,Urgent
                    $tel = ma_tel((string) ($_GET['tel'] ?? ''));
                    $where = ['1=1'];
                    $args = [];
                    if ($tel) {
                        $where[] = 'tel_norm = ?';
                        $args[] = $tel;
                    }
                    if (!empty($_GET['statut'])) {
                        $vals = array_map('trim', explode(',', (string) $_GET['statut']));
                        $where[] = 'statut IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
                        array_push($args, ...$vals);
                    }
                    if (!empty($_GET['older_than_min'])) {
                        $where[] = "created_at <= ?";
                        $args[] = date('Y-m-d H:i:s', time() - 60 * (int) $_GET['older_than_min']);
                    }
                    $dir = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
                    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 1)));
                    $st = $db->prepare('SELECT * FROM rep_fiches WHERE ' . implode(' AND ', $where) . " ORDER BY created_at $dir, id $dir LIMIT $limit");
                    $st->execute($args);
                    $rows = $st->fetchAll();
                    out(['ok' => true, 'trouve' => count($rows) > 0, 'fiche' => $rows[0] ?? null, 'fiches' => $rows, 'nb' => count($rows)]);
                }
                if ($method === 'POST' && $sub2 === null) {
                    $d = $body;
                    $d['tel_norm'] = ma_tel((string) ($d['telephone'] ?? $d['tel'] ?? $d['tel_norm'] ?? ''));
                    $d['societe'] = ma_str($d['societe'] ?? $d['distributeur'] ?? null);
                    $d['service'] = strtolower(ma_str($d['service'] ?? null) ?? '');
                    $d['urgence'] = ma_bool($d['urgence'] ?? false) ? 1 : 0;
                    $d['statut'] = ma_str($d['statut'] ?? null) ?? ($d['urgence'] ? 'Urgent' : 'En attente');
                    $d['created_at'] = ma_date($d['date'] ?? null) ?? $now;
                    foreach (['contact', 'marque', 'modele', 'numero_serie', 'type_panne', 'besoin_commercial', 'reference_facture', 'resume', 'justification_urgence', 'email', 'departement', 'canal', 'derniere_reponse_ia'] as $c) {
                        $d[$c] = ma_str($d[$c] ?? null);
                    }
                    $id = insert($db, 'rep_fiches', $d);
                    logEvent($db, 'repondeur', 'ok', 'fiche_creee', ($d['societe'] ?? '') . ' / ' . ($d['contact'] ?? '') . ' [' . $d['statut'] . ']', ['id' => $id]);
                    out(['ok' => true, 'id' => $id, 'tel_norm' => $d['tel_norm'], 'statut' => $d['statut']]);
                }
                if ($sub2 !== null) {
                    $id = idFrom($parts, 2);
                    if ($method === 'GET') {
                        $f = getOne($db, 'rep_fiches', $id) ?? fail('Fiche introuvable', 404);
                        $st = $db->prepare('SELECT * FROM rep_demandes WHERE fiche_id = ? ORDER BY id');
                        $st->execute([$id]);
                        $f['demandes'] = $st->fetchAll();
                        out(['ok' => true, 'fiche' => $f]);
                    }
                    if ($method === 'PATCH' || $method === 'POST') {
                        $d = $body;
                        if (isset($d['telephone']) || isset($d['tel'])) {
                            $d['tel_norm'] = ma_tel((string) ($d['telephone'] ?? $d['tel']));
                        }
                        if (isset($d['distributeur'])) {
                            $d['societe'] = ma_str($d['distributeur']);
                        }
                        if (!empty($d['append_resume'])) {
                            $cur = getOne($db, 'rep_fiches', $id)['resume'] ?? '';
                            $d['resume'] = trim($cur . ' | ' . $d['append_resume'], ' |');
                        }
                        $d['updated_at'] = $now;
                        $newStatut = (string) ($d['statut'] ?? '');
                        if ($newStatut !== '' && (str_starts_with($newStatut, 'Trans') || $newStatut === 'Traite')) {
                            $d['transmis_at'] = $now;
                        }
                        update($db, 'rep_fiches', $id, $d);
                        out(['ok' => true, 'fiche' => getOne($db, 'rep_fiches', $id)]);
                    }
                    if ($method === 'DELETE') {
                        $db->prepare('DELETE FROM rep_fiches WHERE id = ?')->execute([$id]);
                        out(['ok' => true]);
                    }
                }
                out(['ok' => true, 'rows' => listRows($db, 'rep_fiches', 'created_at', $_GET, ['societe', 'contact', 'tel_norm', 'resume', 'marque', 'modele'])]);
            }
            if ($sub === 'messages') {
                if ($method === 'POST') {
                    // Échange complet client <-> Claire (WhatsApp entrant + réponse envoyée)
                    $tel = ma_tel((string) ($body['telephone'] ?? $body['tel_norm'] ?? ''));
                    $ficheId = ma_int($body['fiche_id'] ?? null);
                    if (!$ficheId && $tel) {
                        $st = $db->prepare('SELECT id FROM rep_fiches WHERE tel_norm = ? ORDER BY id DESC LIMIT 1');
                        $st->execute([$tel]);
                        $ficheId = ma_int($st->fetchColumn()) ?: null;
                    }
                    $d = [
                        'date' => ma_date($body['date'] ?? null) ?? $now,
                        'fiche_id' => $ficheId,
                        'tel_norm' => $tel,
                        'canal' => ma_str($body['canal'] ?? null) ?? 'whatsapp',
                        'message' => ma_str($body['message'] ?? null),
                        'reponse' => ma_str($body['reponse'] ?? $body['reply'] ?? null),
                        'source' => ma_str($body['source'] ?? null),
                    ];
                    if ($d['message'] === null && $d['reponse'] === null) {
                        fail('message ou reponse requis');
                    }
                    $id = insert($db, 'rep_messages', $d);
                    out(['ok' => true, 'id' => $id, 'fiche_id' => $ficheId]);
                }
                // GET : la conversation d'une fiche (?fiche_id=) ou d'un numéro (?tel=)
                $where = ['1=1'];
                $args = [];
                if (!empty($_GET['fiche_id'])) {
                    $where[] = 'm.fiche_id = ?';
                    $args[] = (int) $_GET['fiche_id'];
                }
                if (!empty($_GET['tel'])) {
                    $where[] = 'm.tel_norm = ?';
                    $args[] = ma_tel((string) $_GET['tel']);
                }
                $sens = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
                $limite = min(2000, max(1, (int) ($_GET['limit'] ?? 500)));
                $st = $db->prepare('SELECT m.*, f.societe, f.contact, f.service, f.statut AS fiche_statut
                    FROM rep_messages m LEFT JOIN rep_fiches f ON f.id = m.fiche_id
                    WHERE ' . implode(' AND ', $where) . " ORDER BY m.date $sens, m.id $sens LIMIT $limite");
                $st->execute($args);
                out(['ok' => true, 'rows' => $st->fetchAll()]);
            }
            if ($sub === 'demandes') {
                if ($method === 'POST' && $sub2 === null) {
                    $d = $body;
                    $svc = strtoupper(ma_str($d['service'] ?? null) ?? '');
                    $map = ['TECHNIQUE' => 'SAV', 'SAV' => 'SAV', 'COMMERCIAL' => 'COMMERCIAL', 'FINANCE' => 'FINANCE'];
                    $d['service'] = $map[$svc] ?? fail("Service non reconnu : $svc");
                    $d['priorite'] = ma_bool($d['urgence'] ?? ($d['priorite'] ?? false)) || strtoupper((string) ($d['priorite'] ?? '')) === 'URGENT' ? 'URGENT' : 'Normal';
                    $d['tel'] = ma_tel((string) ($d['tel'] ?? $d['telephone'] ?? ''));
                    $d['societe'] = ma_str($d['societe'] ?? $d['distributeur'] ?? null);
                    $d['created_at'] = ma_date($d['date'] ?? null) ?? $now;
                    $d['fiche_id'] = isset($d['fiche_id']) ? (int) $d['fiche_id'] : null;
                    $id = insert($db, 'rep_demandes', $d);
                    if ($d['fiche_id']) {
                        $db->prepare("UPDATE rep_fiches SET transmis_at = COALESCE(transmis_at, ?) WHERE id = ?")->execute([$now, $d['fiche_id']]);
                    }
                    logEvent($db, 'repondeur', 'ok', 'demande_' . strtolower($d['service']), ($d['societe'] ?? '') . ' - ' . $d['priorite'] . ' (' . ($d['source'] ?? '') . ')', ['id' => $id]);
                    $rt = ma_routage($db, 'repondeur', ['SAV' => 'technique', 'COMMERCIAL' => 'commercial', 'FINANCE' => 'finance'][$d['service']]);
                    out(['ok' => true, 'id' => $id, 'service' => $d['service'], 'priorite' => $d['priorite'],
                        'dest_to' => $rt['dest_to'], 'dest_cc' => $rt['dest_cc'], 'dest_libelle' => $rt['dest_libelle']]);
                }
                if ($sub2 !== null) {
                    $id = idFrom($parts, 2);
                    if ($method === 'PATCH' || $method === 'POST') {
                        $d = $body;
                        if (($d['statut'] ?? '') === 'traite') {
                            $d['traite_at'] = $now;
                        }
                        update($db, 'rep_demandes', $id, $d);
                        out(['ok' => true, 'demande' => getOne($db, 'rep_demandes', $id)]);
                    }
                    if ($method === 'DELETE') {
                        $db->prepare('DELETE FROM rep_demandes WHERE id = ?')->execute([$id]);
                        out(['ok' => true]);
                    }
                    out(['ok' => true, 'demande' => getOne($db, 'rep_demandes', $id)]);
                }
                out(['ok' => true, 'rows' => listRows($db, 'rep_demandes', 'created_at', $_GET, ['societe', 'contact', 'tel', 'resume', 'marque', 'modele'])]);
            }
            if ($sub === 'routage') {
                routageRoutes($db, 'repondeur', $sub2, $method, $body);
            }
            fail('Route rep inconnue', 404);

        case 'distributeurs':
            if ($sub === 'find') {
                // Remplace filterRows DISTRIBUTEUR (col D = raison sociale, insensible à la casse)
                $s = trim((string) ($_GET['societe'] ?? ''));
                if ($s === '') {
                    out(['ok' => true, 'trouve' => false, 'distributeur' => null]);
                }
                $st = $db->prepare('SELECT * FROM distributeurs WHERE raison_sociale = ? COLLATE NOCASE LIMIT 1');
                $st->execute([$s]);
                $r = $st->fetch();
                if (!$r) {
                    $st = $db->prepare('SELECT * FROM distributeurs WHERE raison_sociale LIKE ? COLLATE NOCASE ORDER BY length(raison_sociale) LIMIT 1');
                    $st->execute(['%' . $s . '%']);
                    $r = $st->fetch();
                }
                out(['ok' => true, 'trouve' => (bool) $r, 'distributeur' => $r ?: null,
                    'commercial' => $r['vendeur'] ?? '', 'compte' => $r['compte'] ?? '', 'extra' => $r['raison_sociale'] ?? '']);
            }
            if ($method === 'POST' && $sub === null) {
                $id = insert($db, 'distributeurs', $body);
                out(['ok' => true, 'id' => $id]);
            }
            if ($sub !== null) {
                $id = idFrom($parts, 1);
                if ($method === 'PATCH' || $method === 'POST') {
                    update($db, 'distributeurs', $id, $body);
                    out(['ok' => true, 'distributeur' => getOne($db, 'distributeurs', $id)]);
                }
                if ($method === 'DELETE') {
                    $db->prepare('DELETE FROM distributeurs WHERE id = ?')->execute([$id]);
                    out(['ok' => true]);
                }
            }
            $q = $_GET;
            $q['order'] = $q['order'] ?? 'raison_sociale';
            $q['dir'] = $q['dir'] ?? 'asc';
            out(['ok' => true, 'rows' => listRows($db, 'distributeurs', 'raison_sociale', $q, ['raison_sociale', 'vendeur', 'compte', 'nom', 'prenom', 'email', 'marque'])]);

        // ============================================================ Chatbot Claire
        case 'chat':
            if ($sub === 'messages') {
                if ($method === 'POST') {
                    $d = [
                        'session_id' => ma_str($body['session_id'] ?? null),
                        'date' => ma_date($body['date'] ?? null) ?? $now,
                        'message' => ma_str($body['message'] ?? null),
                        'reponse' => ma_str($body['reponse'] ?? $body['reply'] ?? null),
                        'page_url' => ma_str($body['page_url'] ?? null),
                    ];
                    $id = insert($db, 'chat_messages', $d);
                    out(['ok' => true, 'id' => $id]);
                }
                out(['ok' => true, 'rows' => listRows($db, 'chat_messages', 'date', $_GET, ['message', 'reponse', 'session_id', 'page_url'])]);
            }
            if ($sub === 'sessions') {
                $rows = $db->query("SELECT session_id, MIN(date) AS debut, MAX(date) AS fin, COUNT(*) AS nb_messages, MIN(page_url) AS page_url,
                        (SELECT message FROM chat_messages m2 WHERE m2.session_id = m.session_id ORDER BY date, id LIMIT 1) AS premier_message,
                        (SELECT COUNT(*) FROM chat_leads l WHERE l.session_id = m.session_id) AS nb_leads
                    FROM chat_messages m GROUP BY session_id ORDER BY fin DESC LIMIT 2000")->fetchAll();
                out(['ok' => true, 'rows' => $rows]);
            }
            if ($sub === 'leads') {
                if ($sub2 === 'dedup') {
                    // Regroupement des doublons déjà enregistrés (import historique)
                    $r = ma_chat_dedup($db);
                    logEvent($db, 'chatbot', 'ok', 'dedup', $r['fusionnes'] . ' doublon(s) fusionné(s), ' . $r['restants'] . ' lead(s) restant(s)', $r);
                    out(['ok' => true] + $r);
                }
                if ($method === 'POST' && $sub2 === null) {
                    // Regroupement : un lead identique (session, société+nom, email ou téléphone) reçu dans la
                    // fenêtre paramétrée (chat_lead_fenetre_min, 60 min par défaut) met à jour le lead existant.
                    $d = $body;
                    $d['date'] = ma_date($d['date'] ?? null) ?? $now;
                    $d['marque_orientee'] = $d['marque_orientee'] ?? ($d['marque'] ?? null);
                    $r = ma_chat_lead_upsert($db, $d);
                    logEvent($db, 'chatbot', 'ok', $r['action'] === 'created' ? 'lead' : 'lead_maj',
                        ($d['societe'] ?? '') . ' - ' . ($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '') . ' [' . ($d['categorie'] ?? '') . ']' . ($r['action'] === 'merged' ? ' (mise à jour n°' . $r['nb_mises_a_jour'] . ')' : ''), ['id' => $r['id']]);
                    $rt = ma_routage($db, 'chatbot', strtolower((string) ($d['categorie'] ?? '')));
                    out(['ok' => true, 'id' => $r['id'], 'action' => $r['action'], 'nouveau' => $r['action'] === 'created', 'nb_mises_a_jour' => $r['nb_mises_a_jour'],
                        'dest_to' => $rt['dest_to'], 'dest_cc' => $rt['dest_cc'], 'dest_libelle' => $rt['dest_libelle']]);
                }
                if ($sub2 !== null) {
                    $id = idFrom($parts, 2);
                    if ($method === 'PATCH' || $method === 'POST') {
                        update($db, 'chat_leads', $id, $body);
                        out(['ok' => true, 'lead' => getOne($db, 'chat_leads', $id)]);
                    }
                    if ($method === 'DELETE') {
                        $db->prepare('DELETE FROM chat_leads WHERE id = ?')->execute([$id]);
                        out(['ok' => true]);
                    }
                    out(['ok' => true, 'lead' => getOne($db, 'chat_leads', $id)]);
                }
                out(['ok' => true, 'rows' => listRows($db, 'chat_leads', 'date', $_GET, ['societe', 'nom', 'prenom', 'email', 'telephone', 'besoin_resume', 'departement'])]);
            }
            if ($sub === 'routage') {
                $_GET['cle'] = $_GET['cle'] ?? ($_GET['categorie'] ?? null);
                $body['cle'] = $body['cle'] ?? ($body['categorie'] ?? null);
                routageRoutes($db, 'chatbot', $sub2, $method, $body);
            }
            fail('Route chat inconnue', 404);

        // ============================================================ Claire ADV
        case 'adv':
            if ($sub === 'demandes') {
                if ($method === 'POST' && $sub2 === null) {
                    // Reçoit la réponse brute de l'agent et la découpe (bloc [ANALYSE] --- [AUTO|ESCALADE] mail)
                    $resp = (string) ($body['response'] ?? $body['reponse_ia'] ?? '');
                    $analyse = null;
                    $mail = $resp;
                    if (str_contains($resp, '---')) {
                        $pos = strrpos($resp, '---');
                        $analyse = trim(substr($resp, 0, $pos));
                        $mail = trim(substr($resp, $pos + 3));
                    }
                    $tag = null;
                    foreach (['ESCALADE', 'AUTO', 'DRAFT'] as $t) {
                        if (stripos($resp, "[$t]") !== false) {
                            $tag = $t;
                            break;
                        }
                    }
                    $tag = ma_str($body['tag'] ?? null) ?? $tag ?? ($resp === '' ? 'RECU' : 'AUTO');
                    $mailClean = trim(str_ireplace(['[AUTO]', '[DRAFT]', '[ESCALADE]'], '', $mail));
                    $champ = function (string $label) use ($analyse): ?string {
                        if ($analyse === null) {
                            return null;
                        }
                        if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*(.+)/i', $analyse, $m)) {
                            return trim($m[1]);
                        }
                        return null;
                    };
                    $famille = ma_str($body['famille'] ?? null) ?? ($resp === '' ? null
                        : ((($analyse !== null && stripos($analyse, '[ANALYSE]') !== false) ? 'equipements'
                            : ((stripos($resp, 'maintenance') !== false || stripos($resp, 'Cadence des visites') !== false) ? 'maintenance' : 'equipements'))));
                    $d = [
                        'date' => ma_date($body['date'] ?? null) ?? $now,
                        'message_id' => ma_str($body['message_id'] ?? null),
                        'from_email' => ma_str($body['from_email'] ?? null),
                        'from_nom' => ma_str($body['from_nom'] ?? null),
                        'sujet' => ma_str($body['sujet'] ?? $body['subject'] ?? null),
                        'message' => ma_str($body['message'] ?? $body['text'] ?? null),
                        'famille' => $famille,
                        'cas' => ma_str($body['cas'] ?? null) ?? ($champ('Cas') ? strtoupper($champ('Cas')) : null),
                        'techno' => $champ('Techno'),
                        'critere' => $champ('Critere') ?? $champ('Critère'),
                        'pression' => $champ('Pression'),
                        'configuration' => $champ('Configuration'),
                        'options_retenues' => $champ('Options retenues'),
                        'tag' => $tag,
                        'analyse_brute' => $analyse,
                        'reponse_ia' => $resp,
                        'mail_envoye' => ma_str($body['mail_envoye'] ?? null) ?? $mailClean,
                        'envoye_at' => ma_bool($body['envoye'] ?? ($tag === 'AUTO')) ? $now : null,
                        'statut_suivi' => $tag === 'RECU' ? 'recu' : ($tag === 'AUTO' ? 'envoye' : 'a_valider'),
                        'commentaire' => ma_str($body['commentaire'] ?? null),
                    ];
                    // Le scénario écrit deux fois : à la réception du mail (tag RECU) puis après
                    // la réponse de Claire. Même Message-ID = même demande, on complète la ligne.
                    $existant = null;
                    if ($d['message_id'] !== null && $d['message_id'] !== '') {
                        $st = $db->prepare('SELECT * FROM adv_demandes WHERE message_id = ? ORDER BY id DESC LIMIT 1');
                        $st->execute([$d['message_id']]);
                        $existant = $st->fetch() ?: null;
                    }
                    $action = 'created';
                    if ($existant) {
                        $action = 'updated';
                        $id = (int) $existant['id'];
                        if ($tag === 'RECU') {
                            // Le mail est déjà journalisé (rejeu du webhook) : on ne touche à rien.
                            $maj = [];
                        } else {
                            $maj = array_filter($d, fn($v) => $v !== null && $v !== '');
                            unset($maj['date'], $maj['message_id'], $maj['commentaire']);
                        }
                        if ($maj) {
                            update($db, 'adv_demandes', $id, $maj);
                        }
                    } else {
                        $id = insert($db, 'adv_demandes', $d);
                    }
                    logEvent($db, 'adv', $tag === 'ERREUR' ? 'erreur' : 'ok', $tag === 'RECU' ? 'mail_recu' : 'mail_traite',
                        ($d['from_email'] ?? '') . ' - ' . ($d['sujet'] ?? '') . ' [' . $tag . ']', ['id' => $id]);
                    $cleRoutage = in_array($tag, ['ESCALADE', 'ERREUR'], true) ? $tag : ($famille === 'maintenance' ? 'MAINTENANCE' : ($d['cas'] ?? 'STANDARD'));
                    $rt = ma_routage($db, 'adv', $cleRoutage);
                    out(['ok' => true, 'id' => $id, 'action' => $action, 'tag' => $tag, 'famille' => $famille, 'cas' => $d['cas'], 'mail' => $mailClean, 'statut_suivi' => $d['statut_suivi'],
                        'envoyer_au_client' => $tag === 'AUTO', 'routage_cle' => $cleRoutage, 'dest_to' => $rt['dest_to'], 'dest_cc' => $rt['dest_cc'], 'dest_libelle' => $rt['dest_libelle']]);
                }
                if ($sub2 !== null) {
                    $id = idFrom($parts, 2);
                    if ($method === 'PATCH' || $method === 'POST') {
                        $d = $body;
                        if (in_array($d['statut_suivi'] ?? '', ['valide', 'traite'], true)) {
                            $d['traite_at'] = $now;
                        }
                        update($db, 'adv_demandes', $id, $d);
                        out(['ok' => true, 'demande' => getOne($db, 'adv_demandes', $id)]);
                    }
                    if ($method === 'DELETE') {
                        $db->prepare('DELETE FROM adv_demandes WHERE id = ?')->execute([$id]);
                        out(['ok' => true]);
                    }
                    out(['ok' => true, 'demande' => getOne($db, 'adv_demandes', $id)]);
                }
                out(['ok' => true, 'rows' => listRows($db, 'adv_demandes', 'date', $_GET, ['from_email', 'from_nom', 'sujet', 'message', 'techno', 'critere'])]);
            }
            if ($sub === 'routage') {
                routageRoutes($db, 'adv', $sub2, $method, $body);
            }
            fail('Route adv inconnue', 404);

        // ============================================================ CSO devis
        case 'cso':
            if ($sub === 'devis') {
                if ($sub2 === 'find') {
                    $n = trim((string) ($_GET['n_offre'] ?? ''));
                    $st = $db->prepare('SELECT * FROM cso_devis WHERE n_offre = ?');
                    $st->execute([$n]);
                    $r = $st->fetch();
                    out(['ok' => true, 'trouve' => (bool) $r, 'devis' => $r ?: null]);
                }
                if ($sub2 === 'relances_dues' || $sub2 === 'relances_prevues') {
                    // relances_dues : ce que le scénario Make envoie aujourd'hui.
                    // relances_prevues : toutes les relances programmées, pour l'aperçu dans la page.
                    $due = ma_relances_planifiees($db, $sub2 === 'relances_dues', !empty($_GET['inclure_ecart']));
                    out(['ok' => true, 'nb' => count($due), 'rows' => $due]);
                }
                if ($sub2 === 'modeles_relance') {
                    out(['ok' => true, 'modeles' => ma_relance_modeles($db)]);
                }
                if ($sub2 !== null) {
                    $id = idFrom($parts, 2);
                    if ($method === 'PATCH' || $method === 'POST') {
                        $d = $body;
                        $d['updated_at'] = $now;
                        unset($d['n_offre']);
                        update($db, 'cso_devis', $id, $d);
                        out(['ok' => true, 'devis' => getOne($db, 'cso_devis', $id)]);
                    }
                    if ($method === 'DELETE') {
                        $db->prepare('DELETE FROM cso_lignes WHERE devis_id = ?')->execute([$id]);
                        $db->prepare('DELETE FROM cso_relances WHERE devis_id = ?')->execute([$id]);
                        $db->prepare('DELETE FROM cso_devis WHERE id = ?')->execute([$id]);
                        out(['ok' => true]);
                    }
                    $dv = getOne($db, 'cso_devis', $id) ?? fail('Devis introuvable', 404);
                    $st = $db->prepare('SELECT * FROM cso_lignes WHERE devis_id = ? ORDER BY version DESC, poste, id');
                    $st->execute([$id]);
                    $dv['lignes'] = $st->fetchAll();
                    $st = $db->prepare('SELECT * FROM cso_relances WHERE devis_id = ? ORDER BY date_envoi');
                    $st->execute([$id]);
                    $dv['relances'] = $st->fetchAll();
                    out(['ok' => true, 'devis' => $dv]);
                }
                out(['ok' => true, 'rows' => listRows($db, 'cso_devis', 'date_traitement', $_GET, ['n_offre', 'client', 'contact_client', 'email_client', 'commercial', 'ref_demande_client', 'n_client'])]);
            }
            if ($sub === 'relances' && $method === 'GET') {
                // Historique des relances envoyées au client (avec la copie interne)
                $rows = $db->query("SELECT r.id, r.date_envoi, r.numero, r.n_offre, r.destinataire, r.cc, r.devis_id,
                        d.client, d.contact_client, d.commercial, d.montant_ht, d.statut, d.date_offre
                    FROM cso_relances r LEFT JOIN cso_devis d ON d.id = r.devis_id
                    ORDER BY r.date_envoi DESC, r.id DESC LIMIT 2000")->fetchAll();
                out(['ok' => true, 'rows' => $rows]);
            }
            if ($sub === 'relances' && $method === 'POST') {
                // Enregistre une relance envoyée et met à jour le devis (remplace updateRow Statut / Relances_envoyees)
                $id = (int) ($body['devis_id'] ?? 0);
                if ($id <= 0 && !empty($body['n_offre'])) {
                    $st = $db->prepare('SELECT id FROM cso_devis WHERE n_offre = ?');
                    $st->execute([(string) $body['n_offre']]);
                    $id = (int) $st->fetchColumn();
                }
                $dv = getOne($db, 'cso_devis', $id) ?? fail('Devis introuvable', 404);
                $num = (int) ($body['numero'] ?? ((int) $dv['relances_envoyees'] + 1));
                $db->prepare('INSERT INTO cso_relances(devis_id, n_offre, numero, date_envoi, destinataire, cc) VALUES (?,?,?,?,?,?)')
                    ->execute([$id, $dv['n_offre'], $num, $now, ma_str($body['destinataire'] ?? null), ma_str($body['cc'] ?? null)]);
                update($db, 'cso_devis', $id, ['statut' => "Relance $num", 'relances_envoyees' => $num, 'updated_at' => $now]);
                logEvent($db, 'cso', 'ok', "relance_$num", 'Devis ' . $dv['n_offre'] . ' - ' . ($dv['client'] ?? '') . ' -> ' . ($body['destinataire'] ?? ''), ['id' => $id]);
                out(['ok' => true, 'devis_id' => $id, 'statut' => "Relance $num", 'relances_envoyees' => $num]);
            }
            if ($sub === 'reponse' && $method === 'POST') {
                // Réponse d'un client à un devis ou à une relance, classée par l'agent IA du scénario.
                // Rattachement : en-tête In-Reply-To / References (Message-ID du mail d'origine),
                // à défaut le numéro d'offre trouvé dans l'objet.
                $sujet = ma_str($body['sujet'] ?? null) ?? '';
                $refs = trim((string) ($body['in_reply_to'] ?? '') . ' ' . (string) ($body['references'] ?? ''));
                $classement = strtolower(ma_str($body['classement'] ?? null) ?? 'autre');
                $texte = ma_str($body['texte'] ?? $body['message'] ?? null);
                $de = ma_str($body['from_email'] ?? null);
                $devis = null;
                if ($refs !== '') {
                    foreach ($db->query("SELECT * FROM cso_devis WHERE message_id IS NOT NULL AND message_id != ''
                        ORDER BY id DESC") as $d) {
                        if (str_contains($refs, trim((string) $d['message_id'], '<> '))) {
                            $devis = $d;
                            break;
                        }
                    }
                }
                if (!$devis && preg_match_all('/\b(\d{8,10})\b/', $sujet, $m)) {
                    $st = $db->prepare('SELECT * FROM cso_devis WHERE n_offre = ? ORDER BY id DESC LIMIT 1');
                    foreach ($m[1] as $n) {
                        $st->execute([$n]);
                        if ($row = $st->fetch()) {
                            $devis = $row;
                            break;
                        }
                    }
                }
                if (!$devis) {
                    logEvent($db, 'cso', 'erreur', 'reponse_non_rattachee',
                        ($de ?? '') . ' - ' . $sujet . ' : aucun devis correspondant',
                        ['classement' => $classement, 'texte' => $texte]);
                    out(['ok' => true, 'trouve' => false]);
                }
                // Un statut déjà tranché à la main n'est pas écrasé ; on ajoute seulement la réponse.
                $fige = in_array($devis['statut'], ['Gagne', 'Perdu', 'Sans suite'], true);
                $nouveau = ['commande' => 'Gagne', 'refus' => 'Perdu'][$classement] ?? 'Reponse recue';
                if ($classement === 'sans_objet') {
                    $nouveau = null;
                }
                $maj = ['updated_at' => $now];
                $maj['reponse_client'] = trim(((string) ($devis['reponse_client'] ?? '')) . "\n\n"
                    . '[' . $now . ' — ' . ($de ?? '') . ' — ' . $classement . ']' . "\n" . (string) $texte);
                if ($nouveau !== null && !$fige) {
                    $maj['statut'] = $nouveau;
                }
                update($db, 'cso_devis', (int) $devis['id'], $maj);
                logEvent($db, 'cso', 'ok', 'reponse_client',
                    'Devis ' . $devis['n_offre'] . ' - ' . ($devis['client'] ?? '') . ' : ' . $classement
                        . ($maj['statut'] ?? null ? ' -> ' . $maj['statut'] : ($fige ? ' (statut conservé)' : ' (statut inchangé)')),
                    ['id' => $devis['id'], 'de' => $de]);
                out(['ok' => true, 'trouve' => true, 'devis_id' => (int) $devis['id'], 'n_offre' => $devis['n_offre'],
                    'classement' => $classement, 'statut' => $maj['statut'] ?? $devis['statut'], 'statut_fige' => $fige]);
            }
            if ($sub === 'lignes') {
                out(['ok' => true, 'rows' => listRows($db, 'cso_lignes', 'date_traitement', $_GET, ['reference', 'designation', 'n_offre'])]);
            }
            fail('Route cso inconnue', 404);

        // ============================================================ Prime CEE
        case 'cee':
            if ($sub === 'leads') {
                if ($sub2 === 'find') {
                    // Remplace filterRows Leads WCF (dernier lead pour ce numéro) + comptage des simulations
                    $tel = ma_tel((string) ($_GET['tel'] ?? ''));
                    $st = $db->prepare('SELECT * FROM cee_leads WHERE telephone_intl = ? ORDER BY date DESC, id DESC LIMIT 1');
                    $st->execute([$tel]);
                    $r = $st->fetch();
                    $st = $db->prepare('SELECT COUNT(*) FROM cee_leads WHERE telephone_intl = ?');
                    $st->execute([$tel]);
                    $nb = (int) $st->fetchColumn();
                    out(['ok' => true, 'trouve' => (bool) $r, 'lead' => $r ?: null, 'nb_simulations' => $nb,
                        'date' => $r['date'] ?? null, 'statut' => $r['statut'] ?? null]);
                }
                if ($method === 'POST' && $sub2 === null) {
                    $d = $body;
                    $d['telephone_intl'] = ma_tel((string) ($d['telephone_intl'] ?? $d['Telephone_intl'] ?? $d['telephone_brut'] ?? $d['Telephone_brut'] ?? ''));
                    // Accepte les clés du formulaire (Prenom, Nom, ...) telles quelles
                    foreach (['Prenom' => 'prenom', 'Nom' => 'nom', 'Societe' => 'societe', 'Email' => 'email', 'Telephone_brut' => 'telephone_brut',
                        'Message_initial' => 'message_initial', 'Profil' => 'profil', 'Page_url' => 'page_url', 'Eco_an' => 'eco_an', 'Eco_5ans' => 'eco_5ans',
                        'CEE_min' => 'cee_min', 'CEE_max' => 'cee_max', 'ROI_avec_CEE' => 'roi_avec_cee', 'Regime' => 'regime', 'Usage' => 'usage',
                        'Nb_compresseurs' => 'nb_compresseurs', 'Solutions' => 'solutions', 'CO2_tonnes' => 'co2_tonnes', 'Nb_simulations' => 'nb_simulations'] as $src => $dst) {
                        if (isset($d[$src]) && !isset($d[$dst])) {
                            $d[$dst] = $d[$src];
                        }
                    }
                    foreach (['eco_an', 'eco_5ans', 'cee_min', 'cee_max', 'co2_tonnes'] as $c) {
                        $d[$c] = ma_float($d[$c] ?? null);
                    }
                    $d['nb_simulations'] = ma_int($d['nb_simulations'] ?? null);
                    $d['date'] = ma_date($d['date'] ?? null) ?? $now;
                    $d['statut'] = ma_str($d['statut'] ?? null) ?? (!empty($d['telephone_brut']) ? 'WhatsApp accueil envoye' : 'Sans tel - relance email manuelle');
                    $id = insert($db, 'cee_leads', $d);
                    logEvent($db, 'cee', 'ok', 'lead', ($d['prenom'] ?? '') . ' ' . ($d['nom'] ?? '') . ' - ' . ($d['societe'] ?? '') . ' [' . $d['statut'] . ']', ['id' => $id]);
                    out(['ok' => true, 'id' => $id, 'telephone_intl' => $d['telephone_intl'], 'statut' => $d['statut']]);
                }
                if ($sub2 !== null) {
                    $id = idFrom($parts, 2);
                    if ($method === 'PATCH' || $method === 'POST') {
                        update($db, 'cee_leads', $id, $body);
                        out(['ok' => true, 'lead' => getOne($db, 'cee_leads', $id)]);
                    }
                    if ($method === 'DELETE') {
                        $db->prepare('DELETE FROM cee_leads WHERE id = ?')->execute([$id]);
                        out(['ok' => true]);
                    }
                    $l = getOne($db, 'cee_leads', $id) ?? fail('Lead introuvable', 404);
                    $st = $db->prepare('SELECT * FROM cee_conversations WHERE telephone = ? ORDER BY date, id');
                    $st->execute([$l['telephone_intl']]);
                    $l['conversations'] = $st->fetchAll();
                    $st = $db->prepare('SELECT * FROM cee_actions WHERE telephone = ? ORDER BY date, id');
                    $st->execute([$l['telephone_intl']]);
                    $l['actions'] = $st->fetchAll();
                    out(['ok' => true, 'lead' => $l]);
                }
                out(['ok' => true, 'rows' => listRows($db, 'cee_leads', 'date', $_GET, ['prenom', 'nom', 'societe', 'email', 'telephone_intl', 'message_initial'])]);
            }
            if ($sub === 'conversations') {
                if ($method === 'POST') {
                    $tel = ma_tel((string) ($body['telephone'] ?? $body['from'] ?? ''));
                    $st = $db->prepare('SELECT id FROM cee_leads WHERE telephone_intl = ? ORDER BY date DESC LIMIT 1');
                    $st->execute([$tel]);
                    $leadId = (int) $st->fetchColumn() ?: null;
                    $d = [
                        'lead_id' => $leadId, 'telephone' => $tel, 'date' => ma_date($body['date'] ?? null) ?? $now,
                        'message' => ma_str($body['message'] ?? null), 'reponse' => ma_str($body['reponse'] ?? $body['reply'] ?? null),
                        'profil' => ma_str($body['profil'] ?? null), 'infos' => ma_str($body['infos'] ?? null), 'qualifie' => ma_str($body['qualifie'] ?? null),
                        'projet' => ma_str($body['projet'] ?? null), 'urgence' => ma_str($body['urgence'] ?? null), 'decideur' => ma_str($body['decideur'] ?? null),
                        'action' => ma_str($body['action'] ?? null),
                    ];
                    $id = insert($db, 'cee_conversations', $d);
                    if ($leadId && ma_bool($d['qualifie'] ?? false)) {
                        $db->prepare("UPDATE cee_leads SET suivi = CASE WHEN suivi = 'nouveau' THEN 'qualifie' ELSE suivi END, profil = COALESCE(?, profil) WHERE id = ?")->execute([$d['profil'], $leadId]);
                    }
                    out(['ok' => true, 'id' => $id, 'lead_id' => $leadId]);
                }
                out(['ok' => true, 'rows' => listRows($db, 'cee_conversations', 'date', $_GET, ['message', 'reponse', 'telephone', 'infos'])]);
            }
            if ($sub === 'actions') {
                if ($method === 'POST' && $sub2 === null) {
                    $tel = ma_tel((string) ($body['telephone'] ?? $body['from'] ?? ''));
                    $st = $db->prepare('SELECT id, societe FROM cee_leads WHERE telephone_intl = ? ORDER BY date DESC LIMIT 1');
                    $st->execute([$tel]);
                    $lead = $st->fetch() ?: null;
                    $d = [
                        'lead_id' => $lead['id'] ?? null, 'date' => ma_date($body['date'] ?? null) ?? $now,
                        'type_profil' => strtoupper(ma_str($body['type_profil'] ?? $body['profil'] ?? null) ?? ''),
                        'societe' => ma_str($body['societe'] ?? null) ?? ($lead['societe'] ?? null), 'telephone' => $tel,
                        'detail' => ma_str($body['detail'] ?? null), 'projet' => ma_str($body['projet'] ?? null), 'urgence' => ma_str($body['urgence'] ?? null),
                        'decideur' => ma_str($body['decideur'] ?? null), 'infos' => ma_str($body['infos'] ?? null), 'action' => ma_str($body['action'] ?? null),
                    ];
                    if (!$d['detail']) {
                        $d['detail'] = trim(implode(' | ', array_filter([$d['projet'], $d['urgence'] ? 'urgence: ' . $d['urgence'] : null, $d['decideur'] ? 'decideur: ' . $d['decideur'] : null, $d['infos']])));
                    }
                    $id = insert($db, 'cee_actions', $d);
                    logEvent($db, 'cee', 'ok', 'action', $d['type_profil'] . ' - ' . ($d['societe'] ?? '') . ' : ' . ($d['action'] ?? ''), ['id' => $id]);
                    out(['ok' => true, 'id' => $id]);
                }
                if ($sub2 !== null) {
                    $id = idFrom($parts, 2);
                    if ($method === 'PATCH' || $method === 'POST') {
                        $d = $body;
                        if (isset($d['fait'])) {
                            $d['fait'] = ma_bool($d['fait']) ? 1 : 0;
                            $d['fait_at'] = $d['fait'] ? $now : null;
                        }
                        update($db, 'cee_actions', $id, $d);
                        out(['ok' => true, 'action' => getOne($db, 'cee_actions', $id)]);
                    }
                    if ($method === 'DELETE') {
                        $db->prepare('DELETE FROM cee_actions WHERE id = ?')->execute([$id]);
                        out(['ok' => true]);
                    }
                }
                out(['ok' => true, 'rows' => listRows($db, 'cee_actions', 'date', $_GET, ['societe', 'telephone', 'detail', 'action'])]);
            }
            fail('Route cee inconnue', 404);

        case 'routage':
            $scenario = (string) ($_GET['scenario'] ?? $body['scenario'] ?? '');
            if (!in_array($scenario, ['chatbot', 'repondeur', 'adv'], true)) {
                fail('scenario requis (chatbot | repondeur | adv)');
            }
            routageRoutes($db, $scenario, $sub, $method, $body);

        // ============================================================ Statistiques
        case 'stats':
            require __DIR__ . '/stats.php';
            out(['ok' => true] + ma_stats($db, $sub ?? 'overview', $_GET));

        // ============================================================ Import en masse (page)
        case 'import':
            if ($method !== 'POST') {
                fail('POST attendu');
            }
            $table = (string) ($body['table'] ?? '');
            $rows = $body['rows'] ?? [];
            $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $st->execute([$table]);
            if (!preg_match('/^[a-z_]+$/', $table) || !$st->fetchColumn()) {
                fail("Table inconnue : $table");
            }
            $n = 0;
            $db->beginTransaction();
            foreach ($rows as $r) {
                if (is_array($r)) {
                    insert($db, $table, $r);
                    $n++;
                }
            }
            $db->commit();
            out(['ok' => true, 'table' => $table, 'inseres' => $n]);

        // ============================================================ Export CSV
        case 'export':
            $table = (string) ($sub ?? '');
            $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $st->execute([$table]);
            if (!$st->fetchColumn()) {
                fail("Table inconnue : $table", 404);
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $table . '_' . date('Y-m-d') . '.csv"');
            echo "\xEF\xBB\xBF";
            $fp = fopen('php://output', 'w');
            $first = true;
            foreach ($db->query("SELECT * FROM $table ORDER BY id DESC") as $row) {
                if ($first) {
                    fputcsv($fp, array_keys($row), ';');
                    $first = false;
                }
                fputcsv($fp, $row, ';');
            }
            exit;

        default:
            fail("Route inconnue : $route", 404);
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $msg = $e->getMessage();
    if (str_contains($msg, 'no such table') || str_contains($msg, 'no such column')) {
        fail("Base de données pas à jour (version du code : " . MA_VERSION . "). Déposez le dernier "
            . "correctif MULTIAIR en écrasant les fichiers, puis rechargez la page. Détail : " . $msg, 500);
    }
    fail('Erreur base de données : ' . $msg, 500);
} catch (Throwable $e) {
    fail('Erreur : ' . $e->getMessage(), 500);
}
