# Répondeur IA V3 — Suivi WhatsApp : Google Sheets -> API MULTIAIR (bascule directe)
import json, sys, re
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('Répondeur IA V3'), bp['name']
assert find(bp['flow'], 2)['module'] == 'google-sheets:filterRows', 'déjà transformé ?'
FROM = '{{1.entry[1].changes[1].value.messages[1].from}}'
F = '2.data.fiche'
# --- remap textuel des colonnes 2.`N` -> 2.data.fiche.<col>, __ROW_NUMBER__ -> id, aggrégateurs -> réponse distributeurs/find
txt = json.dumps(bp, ensure_ascii=False)
txt = remap_cols(txt, 2, F)
txt = txt.replace('2.__ROW_NUMBER__', F + '.id')
for agg, http in ((41, 21), (42, 24), (43, 27)):
    for k in ('compte', 'commercial', 'extra'):
        txt = txt.replace('{{%d.array[1].%s}}' % (agg, k), '{{%d.data.%s}}' % (http, k))
txt = txt.replace('Ligne du Sheet', 'Fiche MULTIAIR n°').replace('la colonne B (service pressenti)', 'le service pressenti').replace('Valeur lue en colonne B', 'Service pressenti')
bp = json.loads(txt)
flow = bp['flow']
# --- module 2 : recherche de la fiche
old2 = find(flow, 2)
m2 = http_module(2, 'rep/fiches/find', [('tel', FROM), ('statut', 'En attente,Urgent'), ('limit', '1')], 300, 900, method='get', filt=old2['filter'])
m2['metadata']['designer']['name'] = 'Fiche en attente ?'
flow[flow.index(old2)] = m2
# filtre "Fiche trouvee" du module 3
find(flow, 3)['filter'] = {"name": "Fiche trouvee", "conditions": [[{"a": "{{2.data.trouve}}", "b": "true", "o": "text:equal"}]]}
find(flow, 3)['mapper']['threadId'] = FROM + '-{{' + F + '.id}}'
router6 = find(flow, 6)
rq, rnq = router6['routes'][0]['flow'], router6['routes'][1]['flow']
# --- route Qualifié : 20 updateCell -> PATCH statut Traite
old20 = rq[0]
rq[0] = patch_module(20, '{{' + F + '.id}}', [('statut', 'Traite')], 1800, 300, filt=old20['filter'])
rq[0]['metadata']['designer']['name'] = 'Fiche -> Traite'
router30 = rq[1]
svc_map = {21: ('SAV', 22, 41), 24: ('COMMERCIAL', 25, 42), 27: ('FINANCE', 28, 43)}
for route in router30['routes'][:3]:
    fl = route['flow']
    fr = fl[0]; svc, addrow_id, agg_id = svc_map[fr['id']]
    y = fr['metadata']['designer']['y']
    # filterRows DISTRIBUTEUR -> GET distributeurs/find
    new_fr = http_module(fr['id'], 'distributeurs/find', [('societe', '{{trim(ifempty(4.societe; ' + F + '.societe))}}')], 2400, y, method='get', filt=fr['filter'])
    new_fr['metadata']['designer']['name'] = 'Distributeur ?'
    # addRow -> POST rep/demandes
    resume = '{{' + F + '.resume}} | Dépt : {{ifempty(4.departement; ' + F + '.departement)}} | Complément WhatsApp : {{4.infos_complementaires}}'
    fields = demande_fields(svc, F, '{{4.urgence_revisee}}', '{{ifempty(4.societe; ' + F + '.societe)}}', '{{ifempty(4.contact; ' + F + '.contact)}}', resume,
                            [('compte_distributeur', '{{%d.data.compte}}' % fr['id']), ('commercial', '{{%d.data.commercial}}' % fr['id']),
                             ('email', '{{ifempty(4.email; ' + F + '.email)}}'), ('departement', '{{ifempty(4.departement; ' + F + '.departement)}}')], 'whatsapp_qualifie')
    new_add = http_module(addrow_id, 'rep/demandes', fields, 2700, y)
    new_add['metadata']['designer']['name'] = 'Demande ' + svc
    email = [m for m in fl if m['module'] == 'email:ActionSendEmail'][0]
    email['mapper']['to'] = DEST(addrow_id)
    email['metadata']['designer']['x'] = 3000
    route['flow'] = [new_fr, new_add, email]
# route service non reconnu : routage 'autre'
r4 = router30['routes'][3]['flow']
m44 = r4[0]
m45 = routage_get(45, 'rep', 'autre', 2400, 900); m45['filter'] = m44.pop('filter')
m44['mapper']['to'] = DEST(45)
router30['routes'][3]['flow'] = [m45, m44]
# --- route Pas encore qualifié : 6 updateCell -> 1 PATCH
old31 = rnq[0]
m31 = patch_module(31, '{{' + F + '.id}}', [('derniere_reponse_ia', '{{4.reply}}'), ('append_resume', '{{4.infos_complementaires}}'),
                   ('societe', '{{ifempty(4.societe; ' + F + '.societe)}}'), ('contact', '{{ifempty(4.contact; ' + F + '.contact)}}'),
                   ('email', '{{ifempty(4.email; ' + F + '.email)}}'), ('departement', '{{ifempty(4.departement; ' + F + '.departement)}}')],
                   1800, 900, filt=old31['filter'])
m31['metadata']['designer']['name'] = 'Fiche mise à jour'
router6['routes'][1]['flow'] = [m31]
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
t = json.dumps(out, ensure_ascii=False)
assert 'google-sheets' not in t and '`' not in t.replace('`message-id`', '').replace('`auto-submitted`', '').replace('```json', '').replace('```', ''), 'références Sheets restantes'
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
