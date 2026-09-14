# Relance 10 min : source = API (fiches En attente > 10 min), demandes et statuts via API
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert 'Relance 10 min' in bp['name'], bp['name']
assert find(bp['flow'], 1)['module'] == 'google-sheets:filterRows', 'déjà transformé ?'
F = '3'
txt = json.dumps(bp, ensure_ascii=False)
txt = remap_cols(txt, 1, F).replace('1.__ROW_NUMBER__', F + '.id')
bp = json.loads(txt)
m1 = http_module(1, 'rep/fiches/find', [('statut', 'En attente'), ('older_than_min', '10'), ('limit', '20'), ('dir', 'asc')], 0, 300, method='get')
m1['metadata']['designer']['name'] = 'Fiches en attente > 10 min'
m3 = {"id": 3, "module": "builtin:BasicFeeder", "version": 1, "parameters": {}, "mapper": {"array": "{{1.data.fiches}}"}, "metadata": {"designer": {"x": 300, "y": 300, "name": "Pour chaque fiche"}}}
router = find(bp['flow'], 2); router.pop('filter', None); router['metadata']['designer']['x'] = 600
svc_of = {10: 'SAV', 20: 'COMMERCIAL', 30: 'FINANCE'}
suffix = ' | Sans réponse WhatsApp après 10 min (infos non validées)'
for route in router['routes']:
    fl = route['flow']
    first = fl[0]
    if first['id'] in svc_of:
        svc = svc_of[first['id']]; y = first['metadata']['designer']['y']
        fields = demande_fields(svc, F, 'false', '{{3.societe}}', '{{3.contact}}', '{{3.resume}}' + suffix, [('departement', '{{3.departement}}')], 'sans_reponse_10min')
        new_add = http_module(first['id'], 'rep/demandes', fields, 900, y, filt=first['filter']); new_add['metadata']['designer']['name'] = 'Demande ' + svc
        email = fl[1]; email['mapper']['to'] = DEST(first['id']); email['metadata']['designer']['x'] = 1200
        upd = patch_module(fl[2]['id'], '{{3.id}}', [('statut', 'Transmis (sans réponse WhatsApp)')], 1500, y); upd['metadata']['designer']['name'] = 'Fiche -> Transmis'
        route['flow'] = [new_add, email, upd]
    else:
        # service non reconnu : routage 'autre' + email + PATCH
        m40 = fl[0]; y = m40['metadata']['designer']['y']
        m42 = routage_get(42, 'rep', 'autre', 900, y); m42['filter'] = m40.pop('filter')
        m40['mapper']['to'] = DEST(42); m40['metadata']['designer']['x'] = 1200
        upd = patch_module(fl[1]['id'], '{{3.id}}', [('statut', 'Transmis (sans réponse WhatsApp)')], 1500, y); upd['metadata']['designer']['name'] = 'Fiche -> Transmis'
        route['flow'] = [m42, m40, upd]
bp['flow'] = [m1, m3, router]
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
t = json.dumps(out, ensure_ascii=False)
assert 'google-sheets' not in t and '1.`' not in t
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
