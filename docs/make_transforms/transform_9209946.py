# Claire ADV : journalisation dans MULTIAIR + routage AUTO (client) / ESCALADE (à valider)
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('Claire ADV'), bp['name']
ids = all_ids(bp['flow']); assert 20 not in ids, 'déjà transformé'
flow = bp['flow']
m10 = find(flow, 10); m1 = find(flow, 1); m5 = find(flow, 5)
assert m10 and m1 and m5
base = [('from_email', '{{10.from.address}}'), ('from_nom', '{{10.from.name}}'), ('sujet', '{{10.subject}}'), ('message', '{{10.text}}'),
        ('message_id', '{{10.headers.`message-id`}}'), ('date', '{{10.date}}')]
m20 = http_module(20, 'adv/demandes', [('response', '{{1.response}}')] + base, 600, 300, ignore_id=24)
# mail client : uniquement si AUTO (repli true si l'API n'a pas répondu) ; copies = routage du cas
m5['filter'] = {"name": "Réponse automatique [AUTO]", "conditions": [[{"a": '{{ifempty(20.data.envoyer_au_client; "true")}}', "b": "true", "o": "text:equal"}]]}
m5['mapper']['to'] = ["{{10.from.address}}"]
m5['mapper']['cc'] = '{{split(ifempty(20.data.dest_to; "cyril.mortier@airwco.com"); ";")}}'
m5['metadata']['designer'] = {"x": 1200, "y": 0}
for e in m5.get('onerror', []) or []: e['metadata']['designer'] = {"x": 1500, "y": 0}
# mail interne d'escalade (remplace l'envoi au client)
html = ("<h2>Escalade Claire ADV - a valider</h2>"
        "<p><strong>De :</strong> {{10.from.name}} &lt;{{10.from.address}}&gt;<br><strong>Sujet :</strong> {{10.subject}}<br>"
        "<strong>Famille :</strong> {{20.data.famille}} - <strong>Cas :</strong> {{20.data.cas}}</p>"
        "<h3>Message du client</h3><p>{{replace(10.text; newline; \"<br>\")}}</p>"
        "<h3>Reponse proposee par Claire (non envoyee)</h3><p>{{replace(20.data.mail; newline; \"<br>\")}}</p>"
        "<p>Valider ou corriger dans <a href=\"https://multiairfrance.store/calculateurs/interne/MULTIAIR/index.php#adv\">MULTIAIR - Claire ADV</a> (fiche n° {{20.data.id}}).</p>")
m21 = {"id": 21, "module": "email:ActionSendEmail", "version": 7,
       "filter": {"name": "Escalade -> validation humaine", "conditions": [[{"a": "{{20.data.envoyer_au_client}}", "b": "false", "o": "text:equal"}]]},
       "parameters": {"account": 13907871, "saveAfterSent": False},
       "mapper": {"cc": [], "to": '{{split(ifempty(20.data.dest_to; "cyril.mortier@airwco.com"); ";")}}', "bcc": [], "from": "", "html": html, "sender": "", "headers": [],
                  "replyTo": "{{10.from.address}}", "subject": "[ESCALADE Claire ADV] {{10.subject}}", "priority": "high", "inReplyTo": "", "references": [], "attachments": [], "contentType": "html"},
       "metadata": {"designer": {"x": 1200, "y": 300}}}
router = {"id": 22, "module": "builtin:BasicRouter", "version": 1, "mapper": None, "routes": [{"flow": [m5]}, {"flow": [m21]}], "metadata": {"designer": {"x": 900, "y": 300}}}
# onerror de l'agent : journaliser l'échec avant le mail d'alerte
m23 = http_module(23, 'adv/demandes', [('tag', 'ERREUR'), ('response', ''), ('commentaire', 'Erreur technique : {{error.message}}')] + base, 600, 600)
m1['onerror'] = [m23] + [e for e in (m1.get('onerror') or [])]
for e in m1['onerror'][1:]:
    e['metadata']['designer']['y'] = 600
    e['metadata']['designer']['x'] += 300
alerte = find(m1['onerror'], 12)
if alerte:
    alerte['mapper']['to'] = '{{split(ifempty(23.data.dest_to; "cyril.mortier@airwco.com"); ";")}}'
bp['flow'] = [m10, m1, m20, router]
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])))
