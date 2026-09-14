# CSO — Relances : la source devient l'API (relances dues), les mises à jour passent par POST cso/relances
import json, sys
sys.path.insert(0, '/tmp/claude-0/-home-user-multiair-interne/ff347a18-8fe4-553a-9201-bd128e8c1869/scratchpad')
from mkhttp import *
src, dst = sys.argv[1], sys.argv[2]
raw = open(src).read(); d = json.loads(raw[raw.find('{'):])
bp = d['blueprint'] if 'blueprint' in d else d
assert bp['name'].startswith('CSO'), bp['name']
old = {m['id']: m for r in find(bp['flow'], 2)['routes'] for m in r['flow']}
sig = '\n\nBien cordialement,\n\n{{startcase(replace(get(split(2.commercial; "@"); 1); "."; " "))}}\nService Commercial MultiAir France\nWorthington Creyssensac - Mauguière - Pneumatech - ABAC\n01 34 32 95 00'
entete = 'Bonjour {{2.contact_client}},\n\nOffre n° {{2.n_offre}} du {{formatDate(parseDate(2.date_offre; "YYYY-MM-DD"); "DD/MM/YYYY")}} - {{formatNumber(2.montant_ht; 2; ","; " ")}} € HT - valable jusqu\'au {{formatDate(parseDate(2.validite_offre; "YYYY-MM-DD"); "DD/MM/YYYY")}}.\n\n'
textes = {
    1: ("Votre offre de prix n° {{2.n_offre}}", "Je me permets de m'assurer que cette offre vous est bien parvenue et qu'elle correspond à votre demande. Si un point mérite d'être ajusté, référence, quantité ou délai, dites-le nous et nous la reprendrons."),
    2: ("Suivi de votre offre n° {{2.n_offre}}", "Avez-vous pu l'examiner ? Si le délai d'approvisionnement ou le montant posent question, nous pouvons regarder ensemble les alternatives possibles sur certaines références."),
    3: ("Validité de votre offre n° {{2.n_offre}}", "Passé la date de validité, les tarifs devront être reconsidérés. Si le projet est toujours d'actualité, un simple retour de votre part suffit pour enclencher la commande. S'il ne l'est plus, dites-le nous également : nous clôturerons le dossier sans vous relancer davantage."),
}
m1 = http_module(1, 'cso/devis/relances_dues', [], 0, 0, method='get')
m2 = {"id": 2, "module": "builtin:BasicFeeder", "version": 1, "parameters": {}, "mapper": {"array": "{{1.data.rows}}"}, "metadata": {"designer": {"x": 300, "y": 0, "name": "Devis à relancer"}}}
routes = []
for n in (1, 2, 3):
    y = (n - 2) * 300
    subject, corps = textes[n]
    email = {"id": 10 * n, "module": "email:ActionSendEmail", "version": 7,
             "filter": {"name": f"Relance {n} due", "conditions": [[{"a": "{{2.relance_due}}", "b": str(n), "o": "number:equal"}]]},
             "parameters": {"account": 14578322, "saveAfterSent": False},
             "mapper": {"cc": ["{{2.commercial}}"], "to": ["{{2.email_relance}}"], "text": entete + corps + sig, "replyTo": "{{2.commercial}}", "subject": subject, "contentType": "text"},
             "metadata": {"designer": {"x": 900, "y": y}}}
    post = http_module(10 * n + 1, 'cso/relances', [('devis_id', '{{2.id}}'), ('numero', str(n)), ('destinataire', '{{2.email_relance}}'), ('cc', '{{2.commercial}}')], 1200, y)
    routes.append({"flow": [email, post]})
router = {"id": 3, "module": "builtin:BasicRouter", "version": 1, "mapper": None, "routes": routes, "metadata": {"designer": {"x": 600, "y": 0}}}
bp['flow'] = [m1, m2, router]
bp['metadata']['designer'] = {"orphans": []}
out = {k: bp[k] for k in ('flow', 'name', 'metadata') if k in bp}
json.dump(out, open(dst, 'w'), ensure_ascii=False)
print('OK ids:', sorted(all_ids(out['flow'])), '| anciens modules remplacés :', sorted(old))
