# Répondeur IA V2 : Google Sheets -> API MULTIAIR, suppression de l'attente 10 min (gérée par le scénario de relance)
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('Répondeur IA V2'), bp['name']
assert find(bp['flow'], 71) and find(bp['flow'], 71)['module'] == 'google-sheets:addRow', 'déjà transformé ?'
# références aux lookups distributeur (40/50/60.`1..3`) -> réponse de distributeurs/find
txt = json.dumps(bp, ensure_ascii=False)
for mid in (40, 50, 60):
    for n, k in ((1, 'commercial'), (2, 'compte'), (3, 'extra')):
        txt = txt.replace('{{%d.`%d`}}' % (mid, n), '{{%d.data.%s}}' % (mid, k))
bp = json.loads(txt)
flow = bp['flow']
vals = find(flow, 71)['mapper']['values']            # colonnes -> expressions VAPI
def fiche_fields(statut_expr):
    f = [('telephone', vals['0']), ('service', vals['1']), ('societe', vals['2']), ('contact', vals['3']), ('marque', vals['4']), ('modele', vals['5']),
         ('numero_serie', vals['6']), ('type_panne', vals['7']), ('besoin_commercial', vals['8']), ('reference_facture', vals['9']), ('resume', vals['10']),
         ('justification_urgence', vals['11']), ('email', vals['15']), ('departement', vals['16']), ('canal', '{{4.canal}}'), ('urgence', '{{4.urgence}}'), ('statut', statut_expr)]
    return f
router5 = find(flow, 5)
# --- route 0 : mobile non urgent -> fiche "En attente" puis template WhatsApp (plus d'attente 10 min ici)
r0 = router5['routes'][0]['flow']
m70 = find(r0, 70)
m71 = http_module(71, 'rep/fiches', fiche_fields('En attente'), 1500, 0, filt=m70.pop('filter'))
m71['metadata']['designer']['name'] = 'Fiche MULTIAIR (En attente)'
m70['metadata']['designer']['x'] = 1800
router5['routes'][0]['flow'] = [m71, m70]
# --- route 1 : urgent ou fixe -> fiche + distributeur + demande + email + WhatsApp
router6 = find(flow, 6)
svc_of = {40: ('SAV', 10, 11, 41, 72), 50: ('COMMERCIAL', 20, 21, 51, 73), 60: ('FINANCE', 30, 31, 61, 74)}
for route in router6['routes']:
    fl = route['flow']
    fr = fl[0]; svc, add_id, mail_id, wa_id, fiche_id = svc_of[fr['id']]
    y = fr['metadata']['designer']['y']
    fiche = http_module(fiche_id, 'rep/fiches', fiche_fields('{{if(4.urgence = "true"; "Urgent"; "Transmis")}}'), 1800, y, filt=fr.pop('filter'))
    fiche['metadata']['designer']['name'] = 'Fiche MULTIAIR'
    dist = http_module(fr['id'], 'distributeurs/find', [('societe', vals['2'])], 2100, y, method='get')
    dist['metadata']['designer']['name'] = 'Distributeur ?'
    fields = [('fiche_id', '{{%d.data.id}}' % fiche_id), ('service', svc), ('urgence', '{{4.urgence}}'), ('societe', vals['2']), ('contact', vals['3']), ('tel', vals['0']),
              ('marque', vals['4']), ('modele', vals['5']), ('numero_serie', vals['6']), ('type_panne', vals['7']), ('besoin_commercial', vals['8']),
              ('reference_facture', vals['9']), ('resume', vals['10']), ('justification_urgence', vals['11']), ('email', vals['15']), ('departement', vals['16']),
              ('compte_distributeur', '{{%d.data.compte}}' % fr['id']), ('commercial', '{{%d.data.commercial}}' % fr['id']), ('source', 'vapi_direct')]
    dem = http_module(add_id, 'rep/demandes', fields, 2400, y)
    dem['metadata']['designer']['name'] = 'Demande ' + svc
    mail = find(fl, mail_id); mail['mapper']['to'] = DEST(add_id); mail['metadata']['designer']['x'] = 2700
    wa = find(fl, wa_id); wa['metadata']['designer']['x'] = 3000
    route['flow'] = [fiche, dist, dem, mail, wa]
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
t = json.dumps(out, ensure_ascii=False)
assert 'google-sheets' not in t and 'FunctionSleep' not in t, 'modules Sheets/sleep restants'
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
