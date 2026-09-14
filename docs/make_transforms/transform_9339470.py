# WCF B : double écriture Conversations/Actions WCF (Sheets) + POST cee/conversations et cee/actions (MULTIAIR)
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('WCF - B'), bp['name']
assert 20 not in all_ids(bp['flow']), 'déjà transformé'
FROM = '{{1.entry[].changes[].value.messages[].from}}'
MSG = '{{ifempty(1.entry[].changes[].value.messages[].text.body; 1.entry[].changes[].value.messages[].button.text)}}'
m20 = http_module(20, 'cee/conversations', [('telephone', FROM), ('message', MSG), ('reply', '{{8.reply}}'), ('profil', '{{8.profil}}'), ('infos', '{{8.infos}}'),
                  ('qualifie', '{{8.qualifie}}'), ('projet', '{{8.projet}}'), ('urgence', '{{8.urgence}}'), ('decideur', '{{8.decideur}}'), ('action', '{{8.action}}')], 2700, 600, ignore_id=22)
assert insert_after(bp['flow'], 10, m20)
router = find(bp['flow'], 11); router['metadata']['designer']['x'] += 300
acts = {12: ('INDUSTRIEL', 'RAPPEL specialiste air comprime secteur', 23, 26), 13: ('INSTALLATEUR', 'Suivi projet - verifier si deja partenaire', 24, 27), 14: ('DISTRIBUTEUR', 'Orienter FORMATION + VISITE specialiste secteur', 25, 28)}
for route in router['routes']:
    fl = route['flow']; m = fl[0]; typ, action, nid, ign = acts[m['id']]
    m['metadata']['designer']['x'] += 300
    y = m['metadata']['designer']['y']
    detail = m['mapper']['values']['4']
    post = http_module(nid, 'cee/actions', [('telephone', FROM), ('type_profil', typ), ('societe', '{{7.`4`}}'), ('projet', '{{8.projet}}'), ('urgence', '{{8.urgence}}'),
                       ('decideur', '{{8.decideur}}'), ('infos', '{{8.infos}}'), ('detail', detail), ('action', action)], m['metadata']['designer']['x'] + 300, y, ignore_id=ign)
    fl.append(post)
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
