<?php
// MULTIAIR — statistiques pour les tableaux de bord
declare(strict_types=1);

function ma_series(PDO $db, string $table, string $dateCol, int $days, string $where = '', array $args = []): array
{
    $from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $sql = "SELECT substr($dateCol,1,10) AS jour, COUNT(*) AS n FROM $table WHERE substr($dateCol,1,10) >= ?"
        . ($where ? " AND ($where)" : '') . " GROUP BY jour";
    $st = $db->prepare($sql);
    $st->execute(array_merge([$from], $args));
    $map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $out[] = ['jour' => $d, 'n' => (int) ($map[$d] ?? 0)];
    }
    return $out;
}

function ma_count(PDO $db, string $sql, array $args = []): int
{
    $st = $db->prepare($sql);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function ma_group(PDO $db, string $table, string $col, string $where = '', array $args = []): array
{
    $st = $db->prepare("SELECT COALESCE(NULLIF($col,''),'(vide)') AS k, COUNT(*) AS n FROM $table" . ($where ? " WHERE $where" : '') . " GROUP BY k ORDER BY n DESC LIMIT 12");
    $st->execute($args);
    return $st->fetchAll();
}

function ma_stats(PDO $db, string $scenario, array $q): array
{
    $days = max(7, min(365, (int) ($q['days'] ?? 30)));
    $d7 = date('Y-m-d', strtotime('-6 days'));
    $d30 = date('Y-m-d', strtotime('-29 days'));
    $mois = date('Y-m');

    switch ($scenario) {
        case 'overview':
            $defs = [
                'repondeur' => ['rep_fiches', 'created_at', 'Répondeur IA'],
                'chatbot' => ['chat_messages', 'date', 'Chatbot Claire'],
                'adv' => ['adv_demandes', 'date', 'Claire ADV'],
                'cso' => ['cso_devis', 'date_traitement', 'CSO devis'],
                'cee' => ['cee_leads', 'date', 'Prime CEE'],
            ];
            $out = [];
            foreach ($defs as $key => [$table, $col, $label]) {
                $out[$key] = [
                    'label' => $label,
                    'total' => ma_count($db, "SELECT COUNT(*) FROM $table"),
                    'j7' => ma_count($db, "SELECT COUNT(*) FROM $table WHERE substr($col,1,10) >= ?", [$d7]),
                    'j30' => ma_count($db, "SELECT COUNT(*) FROM $table WHERE substr($col,1,10) >= ?", [$d30]),
                    'dernier' => $db->query("SELECT MAX($col) FROM $table")->fetchColumn(),
                    'erreurs_j7' => ma_count($db, "SELECT COUNT(*) FROM executions_log WHERE scenario = ? AND statut = 'erreur' AND substr(date,1,10) >= ?", [$key, $d7]),
                    'dernier_log' => (function () use ($db, $key) {
                        $st = $db->prepare('SELECT date, statut, type_evenement, resume FROM executions_log WHERE scenario = ? ORDER BY id DESC LIMIT 1');
                        $st->execute([$key]);
                        return $st->fetch() ?: null;
                    })(),
                    'serie' => ma_series($db, $table, $col, 14),
                ];
            }
            $out['a_traiter'] = [
                'rep_fiches_en_attente' => ma_count($db, "SELECT COUNT(*) FROM rep_fiches WHERE statut IN ('En attente','Urgent')"),
                'rep_demandes_a_traiter' => ma_count($db, "SELECT COUNT(*) FROM rep_demandes WHERE statut = 'a_traiter'"),
                'adv_a_valider' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE statut_suivi = 'a_valider'"),
                'cso_en_cours' => ma_count($db, "SELECT COUNT(*) FROM cso_devis WHERE statut IN ('En attente','Relance 1','Relance 2','Relance 3')"),
                'cso_ecart' => ma_count($db, "SELECT COUNT(*) FROM cso_devis WHERE controle_coherence = 'ECART' AND statut NOT IN ('Gagne','Perdu','Sans suite')"),
                'chat_leads_nouveaux' => ma_count($db, "SELECT COUNT(*) FROM chat_leads WHERE suivi = 'nouveau'"),
                'cee_actions_a_faire' => ma_count($db, "SELECT COUNT(*) FROM cee_actions WHERE fait = 0"),
            ];
            $out['journal'] = $db->query('SELECT * FROM executions_log ORDER BY id DESC LIMIT 30')->fetchAll();
            return $out;

        case 'repondeur':
            return [
                'kpi' => [
                    'appels_total' => ma_count($db, 'SELECT COUNT(*) FROM rep_fiches'),
                    'appels_j30' => ma_count($db, 'SELECT COUNT(*) FROM rep_fiches WHERE substr(created_at,1,10) >= ?', [$d30]),
                    'en_attente' => ma_count($db, "SELECT COUNT(*) FROM rep_fiches WHERE statut = 'En attente'"),
                    'urgents' => ma_count($db, "SELECT COUNT(*) FROM rep_fiches WHERE urgence = 1 OR statut = 'Urgent'"),
                    'traites_whatsapp' => ma_count($db, "SELECT COUNT(*) FROM rep_fiches WHERE statut = 'Traite'"),
                    'sans_reponse' => ma_count($db, "SELECT COUNT(*) FROM rep_fiches WHERE statut LIKE 'Transmis (sans%'"),
                    'demandes_a_traiter' => ma_count($db, "SELECT COUNT(*) FROM rep_demandes WHERE statut = 'a_traiter'"),
                    'demandes_total' => ma_count($db, 'SELECT COUNT(*) FROM rep_demandes'),
                ],
                'taux_reponse_whatsapp' => (function () use ($db) {
                    $tot = ma_count($db, "SELECT COUNT(*) FROM rep_fiches WHERE statut IN ('Traite','Transmis (sans réponse WhatsApp)','Transmis (sans reponse WhatsApp)')");
                    $ok = ma_count($db, "SELECT COUNT(*) FROM rep_fiches WHERE statut = 'Traite'");
                    return $tot ? round(100 * $ok / $tot) : null;
                })(),
                'par_service' => ma_group($db, 'rep_demandes', 'service'),
                'par_statut' => ma_group($db, 'rep_fiches', 'statut'),
                'par_marque' => ma_group($db, 'rep_demandes', 'marque'),
                'serie' => ma_series($db, 'rep_fiches', 'created_at', $days),
                'serie_demandes' => ma_series($db, 'rep_demandes', 'created_at', $days),
            ];

        case 'chatbot':
            $sessions = ma_count($db, 'SELECT COUNT(DISTINCT session_id) FROM chat_messages');
            $leads = ma_count($db, 'SELECT COUNT(*) FROM chat_leads');
            return [
                'kpi' => [
                    'messages_total' => ma_count($db, 'SELECT COUNT(*) FROM chat_messages'),
                    'conversations_total' => $sessions,
                    'conversations_j30' => ma_count($db, 'SELECT COUNT(DISTINCT session_id) FROM chat_messages WHERE substr(date,1,10) >= ?', [$d30]),
                    'leads_total' => $leads,
                    'leads_j30' => ma_count($db, 'SELECT COUNT(*) FROM chat_leads WHERE substr(date,1,10) >= ?', [$d30]),
                    'leads_nouveaux' => ma_count($db, "SELECT COUNT(*) FROM chat_leads WHERE suivi = 'nouveau'"),
                    'taux_conversion' => $sessions ? round(100 * $leads / $sessions, 1) : null,
                    'msg_par_conversation' => $sessions ? round(ma_count($db, 'SELECT COUNT(*) FROM chat_messages') / $sessions, 1) : null,
                ],
                'par_categorie' => ma_group($db, 'chat_leads', 'categorie'),
                'par_marque' => ma_group($db, 'chat_leads', 'marque_orientee'),
                'par_statut' => ma_group($db, 'chat_leads', 'statut'),
                'par_page' => ma_group($db, 'chat_messages', 'page_url'),
                'serie' => ma_series($db, 'chat_messages', 'date', $days),
                'serie_leads' => ma_series($db, 'chat_leads', 'date', $days),
            ];

        case 'adv':
            return [
                'kpi' => [
                    'mails_recus' => ma_count($db, 'SELECT COUNT(*) FROM adv_demandes'),
                    'mails_total' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE tag IS NOT NULL AND tag != 'RECU'"),
                    'mails_j30' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE tag IS NOT NULL AND tag != 'RECU' AND substr(date,1,10) >= ?", [$d30]),
                    'non_traites' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE tag = 'RECU'"),
                    'auto' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE tag = 'AUTO'"),
                    'escalade' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE tag = 'ESCALADE'"),
                    'a_valider' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE statut_suivi = 'a_valider'"),
                    'erreurs' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE tag = 'ERREUR'"),
                    'equipements' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE famille = 'equipements'"),
                    'maintenance' => ma_count($db, "SELECT COUNT(*) FROM adv_demandes WHERE famille = 'maintenance'"),
                ],
                'par_cas' => ma_group($db, 'adv_demandes', 'cas'),
                'par_techno' => ma_group($db, 'adv_demandes', 'techno'),
                'par_expediteur' => ma_group($db, 'adv_demandes', 'from_email'),
                'serie' => ma_series($db, 'adv_demandes', 'date', $days),
            ];

        case 'cso':
            $enCours = "statut IN ('En attente','Relance 1','Relance 2','Relance 3')";
            $sumMois = $db->prepare("SELECT COALESCE(SUM(montant_ht),0) FROM cso_devis WHERE substr(date_traitement,1,7) = ?");
            $sumMois->execute([$mois]);
            $sumGagne = $db->query("SELECT COALESCE(SUM(montant_ht),0) FROM cso_devis WHERE statut = 'Gagne'")->fetchColumn();
            $clos = ma_count($db, "SELECT COUNT(*) FROM cso_devis WHERE statut IN ('Gagne','Perdu','Sans suite')");
            $gagne = ma_count($db, "SELECT COUNT(*) FROM cso_devis WHERE statut = 'Gagne'");
            return [
                'kpi' => [
                    'devis_total' => ma_count($db, 'SELECT COUNT(*) FROM cso_devis'),
                    'devis_mois' => ma_count($db, 'SELECT COUNT(*) FROM cso_devis WHERE substr(date_traitement,1,7) = ?', [$mois]),
                    'montant_mois' => round((float) $sumMois->fetchColumn(), 2),
                    'montant_en_cours' => round((float) $db->query("SELECT COALESCE(SUM(montant_ht),0) FROM cso_devis WHERE $enCours")->fetchColumn(), 2),
                    'en_cours' => ma_count($db, "SELECT COUNT(*) FROM cso_devis WHERE $enCours"),
                    'relances' => ma_count($db, 'SELECT COUNT(*) FROM cso_relances'),
                    'gagnes' => $gagne,
                    'perdus' => ma_count($db, "SELECT COUNT(*) FROM cso_devis WHERE statut = 'Perdu'"),
                    'montant_gagne' => round((float) $sumGagne, 2),
                    'taux_transformation' => $clos ? round(100 * $gagne / $clos) : null,
                    'ecarts' => ma_count($db, "SELECT COUNT(*) FROM cso_devis WHERE controle_coherence = 'ECART'"),
                    'panier_moyen' => round((float) $db->query('SELECT COALESCE(AVG(montant_ht),0) FROM cso_devis')->fetchColumn(), 2),
                ],
                'par_statut' => ma_group($db, 'cso_devis', 'statut'),
                'par_commercial' => ma_group($db, 'cso_devis', 'commercial'),
                'par_client' => ma_group($db, 'cso_devis', 'client'),
                'serie' => ma_series($db, 'cso_devis', 'date_traitement', $days),
                'serie_montant' => (function () use ($db, $days) {
                    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
                    $st = $db->prepare('SELECT substr(date_traitement,1,10) AS jour, ROUND(SUM(montant_ht),2) AS n FROM cso_devis WHERE substr(date_traitement,1,10) >= ? GROUP BY jour');
                    $st->execute([$from]);
                    $map = $st->fetchAll(PDO::FETCH_KEY_PAIR);
                    $out = [];
                    for ($i = $days - 1; $i >= 0; $i--) {
                        $d = date('Y-m-d', strtotime("-$i days"));
                        $out[] = ['jour' => $d, 'n' => (float) ($map[$d] ?? 0)];
                    }
                    return $out;
                })(),
            ];

        case 'cee':
            $leads = ma_count($db, 'SELECT COUNT(*) FROM cee_leads');
            return [
                'kpi' => [
                    'leads_total' => $leads,
                    'leads_j30' => ma_count($db, 'SELECT COUNT(*) FROM cee_leads WHERE substr(date,1,10) >= ?', [$d30]),
                    'avec_telephone' => ma_count($db, "SELECT COUNT(*) FROM cee_leads WHERE telephone_brut IS NOT NULL AND telephone_brut != ''"),
                    'qualifies' => ma_count($db, "SELECT COUNT(*) FROM cee_leads WHERE suivi IN ('qualifie','rappel_planifie','converti')"),
                    'conversations' => ma_count($db, 'SELECT COUNT(*) FROM cee_conversations'),
                    'actions_a_faire' => ma_count($db, 'SELECT COUNT(*) FROM cee_actions WHERE fait = 0'),
                    'prime_moyenne' => $leads ? round((float) $db->query('SELECT AVG((COALESCE(cee_min,0)+COALESCE(cee_max,0))/2) FROM cee_leads WHERE cee_max > 0')->fetchColumn()) : null,
                    'economies_cumulees' => round((float) $db->query('SELECT COALESCE(SUM(eco_an),0) FROM cee_leads')->fetchColumn()),
                    'co2_cumule' => round((float) $db->query('SELECT COALESCE(SUM(co2_tonnes),0) FROM cee_leads')->fetchColumn(), 1),
                ],
                'par_profil' => ma_group($db, 'cee_leads', 'profil'),
                'par_statut' => ma_group($db, 'cee_leads', 'statut'),
                'par_suivi' => ma_group($db, 'cee_leads', 'suivi'),
                'par_type_action' => ma_group($db, 'cee_actions', 'type_profil'),
                'serie' => ma_series($db, 'cee_leads', 'date', $days),
                'serie_conversations' => ma_series($db, 'cee_conversations', 'date', $days),
            ];
    }
    return ['erreur' => 'scénario inconnu'];
}
