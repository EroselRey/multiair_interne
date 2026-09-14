# Aiguilleur WhatsApp : lecture de la fiche VAPI via l'API (Leads WCF reste sur Sheets pour l'instant)
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert 'Aiguilleur' in bp['name'], bp['name']
assert find(bp['flow'], 7)['module'] == 'google-sheets:filterRows', 'déjà transformé ?'
txt = json.dumps(bp, ensure_ascii=False)
txt = txt.replace('{{10.array[1].tel}}', '{{7.data.fiche.tel_norm}}')
txt = txt.replace('{{parseDate(10.array[1].date; \\"DD/MM/YYYY HH:mm\\")}}', '{{parseDate(7.data.fiche.created_at; \\"YYYY-MM-DD HH:mm:ss\\")}}')
assert '10.array' not in txt
bp = json.loads(txt)
route = find(bp['flow'], 2)['routes'][1]['flow']
old7 = find(route, 7)
m7 = http_module(7, 'rep/fiches/find', [('tel', '{{9.entry[1].changes[1].value.messages[1].from}}'), ('limit', '1')], 1200, 300, method='get', filt=old7['filter'])
m7['metadata']['designer']['name'] = 'Fiche VAPI ? (MULTIAIR)'
route[route.index(old7)] = m7
route[:] = [m for m in route if m['id'] != 10]
# alerte numéro inconnu : destinataires via routage 'aiguilleur'
r8 = find(route, 8)
fl = r8['routes'][2]['flow']; m60 = fl[0]
m61 = routage_get(61, 'rep', 'aiguilleur', 2400, 600); m61['filter'] = m60.pop('filter')
m60['mapper']['to'] = DEST(61); m60['metadata']['designer']['x'] = 2700
r8['routes'][2]['flow'] = [m61, m60]
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
