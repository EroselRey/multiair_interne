import json, copy
import os
KEY = os.environ.get('MULTIAIR_API_KEY', 'CLE_API_MULTIAIR')
API='https://multiairfrance.store/calculateurs/interne/MULTIAIR/api.php?r='
def http_module(mid, route, fields, x, y, ignore_id=None, filt=None, method='post'):
    m={"id":mid,"module":"http:ActionSendData","version":3,
       "parameters":{"handleErrors":False,"useNewZLibDeCompress":True},
       "mapper":{"url":API+route,"method":method,"headers":[{"name":"X-Api-Key","value":KEY}],"qs":[],
                 "bodyType":"x_www_form_urlencoded","formFields":[{"key":k,"value":v} for k,v in fields],
                 "parseResponse":True,"timeout":40,"serializeUrl":False,"shareCookies":False,"rejectUnauthorized":True,
                 "followRedirect":True,"followAllRedirects":False,"useQuerystring":False,"gzip":True,"useMtls":False},
       "metadata":{"designer":{"x":x,"y":y,"name":"MULTIAIR "+route}}}
    if method=='get':
        m['mapper']['bodyType']=''; m['mapper'].pop('formFields'); m['mapper']['qs']=[{"name":k,"value":v} for k,v in fields]
    if filt: m['filter']=filt
    if ignore_id:
        m['onerror']=[{"id":ignore_id,"module":"builtin:Ignore","version":1,"metadata":{"designer":{"x":x,"y":y+150}}}]
    return m
def find(flow,i):
    for m in flow:
        if m['id']==i: return m
        for r in m.get('routes',[]) or []:
            x=find(r['flow'],i)
            if x: return x
def insert_after(flow, after_id, new):
    for idx,m in enumerate(flow):
        if m['id']==after_id:
            flow.insert(idx+1,new); return True
        for r in m.get('routes',[]) or []:
            if insert_after(r['flow'],after_id,new): return True
    return False
def all_ids(flow,acc=None):
    acc=acc if acc is not None else []
    for m in flow:
        acc.append(m['id'])
        for e in m.get('onerror',[]) or []: acc.append(e['id'])
        for r in m.get('routes',[]) or []: all_ids(r['flow'],acc)
    return acc

FICHE_COLS = {0:'tel_norm',1:'service',2:'societe',3:'contact',4:'marque',5:'modele',6:'numero_serie',7:'type_panne',8:'besoin_commercial',
              9:'reference_facture',10:'resume',11:'justification_urgence',12:'created_at',13:'statut',14:'derniere_reponse_ia',15:'email',16:'departement'}
import re
def remap_cols(text, mod, prefix):
    """Remplace {{mod.`N`}} (et mod.`N` dans les expressions) par prefix.<colonne>."""
    def rep(m):
        n = int(m.group(1)); return prefix + '.' + FICHE_COLS[n]
    return re.sub(r'\b' + str(mod) + r'\.`(\d+)`', rep, text)
def patch_module(mid, fiche_id_expr, fields, x, y, ignore_id=None, filt=None):
    m = http_module(mid, 'rep/fiches/' + fiche_id_expr, [('_method', 'PATCH')] + fields, x, y, ignore_id=ignore_id, filt=filt)
    return m
def routage_get(mid, scenario, cle, x, y):
    return http_module(mid, scenario + '/routage/find', [('cle', cle)], x, y, method='get')
DEST = lambda mid, fb='cyril.mortier@airwco.com': '{{split(ifempty(' + str(mid) + '.data.dest_to; "' + fb + '"); ";")}}'
def demande_fields(svc, fiche, urgence_expr, societe, contact, resume, extra, source):
    f = [('fiche_id', fiche + '.id'), ('service', svc), ('urgence', urgence_expr), ('societe', societe), ('contact', contact), ('tel', fiche + '.tel_norm'),
         ('marque', fiche + '.marque'), ('modele', fiche + '.modele'), ('numero_serie', fiche + '.numero_serie'), ('type_panne', fiche + '.type_panne'),
         ('besoin_commercial', fiche + '.besoin_commercial'), ('reference_facture', fiche + '.reference_facture'), ('resume', resume),
         ('justification_urgence', fiche + '.justification_urgence'), ('email', fiche + '.email'), ('source', source)] + extra
    return [(k, v if v.startswith('{{') or not v.startswith(fiche) else '{{' + v + '}}') for k, v in f]
