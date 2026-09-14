# WCF A : double écriture Leads WCF (Sheets) + POST cee/leads (MULTIAIR)
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('WCF - A'), bp['name']
assert 20 not in all_ids(bp['flow']), 'déjà transformé'
keys = ['Prenom', 'Nom', 'Societe', 'Email', 'Message_initial', 'Profil', 'Page_url', 'Eco_an', 'Eco_5ans', 'CEE_min', 'CEE_max', 'ROI_avec_CEE', 'Regime', 'Usage', 'Nb_compresseurs', 'Solutions', 'CO2_tonnes', 'Nb_simulations']
def fields(avec_tel):
    f = [(k.lower(), '{{1.' + k + '}}') for k in keys]
    f += [('telephone_intl', '{{1.Telephone_intl}}' if avec_tel else ''), ('telephone_brut', '{{1.Telephone_brut}}' if avec_tel else ''),
          ('statut', 'WhatsApp accueil envoye' if avec_tel else 'Sans tel - relance email manuelle')]
    return f
m20 = http_module(20, 'cee/leads', fields(True), 1200, 0, ignore_id=22)
m21 = http_module(21, 'cee/leads', fields(False), 1200, 300, ignore_id=23)
assert insert_after(bp['flow'], 4, m20) and insert_after(bp['flow'], 6, m21)
for i in (7, 8, 11, 5, 9, 10):
    find(bp['flow'], i)['metadata']['designer']['x'] += 300
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
