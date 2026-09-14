# Chatbot : le routage vient désormais de la réponse de l'API (chat/leads) et non plus
# d'une recherche dans le Google Sheet, qui renvoyait le nombre 14 au lieu des adresses.
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import find, all_ids
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('Chatbot'), bp['name']
router = find(bp['flow'], 3)
route1 = router['routes'][1]['flow']
ids = [m['id'] for m in route1]
assert ids == [6, 14, 16, 21, 7, 8, 9, 13], ids

TO  = 'ifempty(21.data.dest_to; "cyril.mortier@airwco.com")'
CC  = 'ifempty(21.data.dest_cc; "cyril.mortier@airwco.com")'
LIB = 'ifempty(21.data.dest_libelle; "Non classe")'

m14, m16 = find(route1, 14), find(route1, 16)
filtre_lead = m14['filter']                       # « Lead exploitable » : on le déplace sur l'appel API
m21 = find(route1, 21)
m21['filter'] = filtre_lead
m21['metadata']['designer']['x'] = 1500

# Remplacement des références aux variables issues du Sheet
txt = json.dumps({'flow': [m for m in route1 if m['id'] not in (14, 16)]}, ensure_ascii=False)
for avant, apres in (
    ('{{split(16.dest_to; \\";\\")}}',  '{{split(' + TO.replace('"', '\\"') + '; \\";\\")}}'),
    ('{{split(16.dest_cc; \\";\\")}}',  '{{split(' + CC.replace('"', '\\"') + '; \\";\\")}}'),
    ('{{16.dest_libelle}}',             '{{' + LIB.replace('"', '\\"') + '}}'),
    ('{{16.dest_to}}',                  '{{' + TO.replace('"', '\\"') + '}}'),
    ('{{16.dest_cc}}',                  '{{' + CC.replace('"', '\\"') + '}}'),
):
    txt = txt.replace(avant, apres)
assert '16.dest' not in txt, 'référence au module 16 restante'
nouveaux = json.loads(txt)['flow']
for i, m in enumerate(nouveaux):
    if m['id'] in (7, 8, 9, 13):
        m['metadata']['designer']['x'] = 1800 + 300 * (i - 1)
router['routes'][1]['flow'] = nouveaux

out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
t = json.dumps(out, ensure_ascii=False)
assert '16.dest' not in t and 'get(14;' not in t, 'références Sheets de routage restantes'
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK route lead :', [m['id'] for m in nouveaux])
