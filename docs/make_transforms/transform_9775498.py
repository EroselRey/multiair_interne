# CSO — Analyse des devis : ajoute (double écriture) TransformToJSON + POST cso/devis après l'agent IA
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('CSO'), bp['name']
ids = all_ids(bp['flow']); assert 20 not in ids, 'déjà transformé'
filt = {"name": "Devis valide uniquement", "conditions": [[{"a": "{{3.jsonResponse.est_un_devis}}", "b": "true", "o": "text:equal"}]]}
m20 = {"id": 20, "module": "json:TransformToJSON", "version": 1, "parameters": {}, "mapper": {"object": "{{3.jsonResponse.lignes}}"},
       "filter": filt, "metadata": {"designer": {"x": 900, "y": -300, "name": "Lignes -> JSON"}}}
fields = [('est_un_devis', '{{3.jsonResponse.est_un_devis}}'), ('numero_offre', '{{3.jsonResponse.numero_offre}}'), ('numero_client', '{{3.jsonResponse.numero_client}}'),
          ('client_nom', '{{3.jsonResponse.client_nom}}'), ('contact_client', '{{3.jsonResponse.contact_client}}'),
          ('email_client', '{{ifempty(3.jsonResponse.email_client; get(map(1.to; "address"); 1))}}'), ('tel_client', '{{3.jsonResponse.tel_client}}'),
          ('contact_interne', '{{3.jsonResponse.contact_interne}}'), ('date_offre', '{{3.jsonResponse.date_offre}}'), ('validite_offre', '{{3.jsonResponse.validite_offre}}'),
          ('ref_demande_client', '{{3.jsonResponse.ref_demande_client}}'), ('montant_ht', '{{3.jsonResponse.montant_ht}}'), ('transport', '{{3.jsonResponse.transport}}'),
          ('montant_ttc', '{{3.jsonResponse.montant_ttc}}'), ('lignes', '{{20.json}}'), ('commercial', '{{1.from.address}}'), ('fichier_source', '{{2.fileName}}'),
          ('message_id', '{{get(1.headers; "message-id")}}'), ('destinataire_email', '{{get(map(1.to; "address"); 1)}}'), ('copies_email', '{{join(map(1.cc; "address"); ", ")}}')]
m21 = http_module(21, 'cso/devis', fields, 1200, -300, ignore_id=22)
assert insert_after(bp['flow'], 3, m20) and insert_after(bp['flow'], 20, m21)
# décale les modules existants à droite
def shift(flow):
    for m in flow:
        if m['id'] not in (1, 2, 3, 20, 21):
            m['metadata']['designer']['x'] += 600
        for r in m.get('routes', []) or []: shift(r['flow'])
shift(bp['flow'])
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
