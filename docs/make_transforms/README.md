# Transformations des blueprints Make (bascule Google Sheets -> API MULTIAIR)

Scripts Python appliqués le 14/09/2026 aux scénarios Make, via l'API Make (scenarios_get -> transformation -> scenarios_update).
Usage : `MULTIAIR_API_KEY=... python3 transform_<id>.py <blueprint_source.json> <blueprint_cible.json>`

| Scénario | Script | Mode |
|---|---|---|
| 9295374 Chatbot Claire v11 | (construit inline, voir historique de session) | double écriture Sheets + API |
| 9775498 CSO analyse des devis | transform_9775498.py | double écriture |
| 9776471 CSO relances | transform_9776471.py | bascule directe (source = API) |
| 9209946 Claire ADV | transform_9209946.py | ajout log API + routage AUTO / ESCALADE |
| 9582857 Répondeur IA V2 | transform_9582857.py | bascule directe, suppression de l'attente 10 min |
| 9583172 Répondeur IA V3 | transform_9583172.py | bascule directe |
| 9583010 Aiguilleur WhatsApp | transform_9583010.py | fiche VAPI via API, Leads WCF encore sur Sheets |
| 9791097 Relance 10 min | transform_9791097.py | bascule directe (source = API) |
| 9324836 WCF A | transform_9324836.py | double écriture |
| 9339470 WCF B | transform_9339470.py | double écriture |

Les blueprints d'origine ont été sauvegardés hors dépôt (ils contiennent des jetons) au moment de chaque bascule.
