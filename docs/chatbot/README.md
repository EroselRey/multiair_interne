# Chatbot « Claire » (scénario Make 9295374)

Le texte des consignes en service est `claire_chatbot_prompt_v12.txt` (champ « System prompt » du module 2, agent IA).

## v12 : même compétence que le répondeur, sur toutes les pages

- Principe commercial inchangé : profil deviné, marque orientée en interne, vérification de l'e-mail et du
  téléphone, jamais de prix, de produit ni de délai.
- Clients existants traités comme au téléphone :
  - SAV : machine (marque, modèle, n° de série si possible), panne, **code postal du site**, **urgence**,
    téléphone en priorité ;
  - pièces et commandes : nature (devis ou commande, pièces ou machine), n° de commande ou de devis ;
  - facture : n° de facture ;
  - relance : n° de demande (ou « oui »), sans jamais inventer de statut.
- Page consultée (blog, tarif en ligne, page produit…) prise en compte : sur le tarif, aucun prix confirmé
  ni négocié.
- Le chatbot se présente comme l'assistante virtuelle s'il est interrogé (obligation de transparence).
- Bloc de fin : 12 champs d'origine + 10 champs (Nature, Référence, Marque du matériel, Modèle, N° de série,
  Code postal du site, Urgence, Pourquoi urgent, Demande existante, Préférence de contact).

## Circuit Make

1. Module 21 envoie à `chat/leads` les champs habituels **et** la réponse complète (`bloc`), la page
   (`page_url`, `page_title`). La plateforme lit tout le bloc elle-même.
2. E-mail à l'équipe : gabarit de la plateforme (`mail_html`, `objet`), aux destinataires calculés, réponse
   directe au visiteur. Envoyé pour une nouvelle demande et pour une relance.
3. SAV urgent : SMS aux portables renvoyés par la plateforme (`alerte_sms`, `sms`, `sms_texte`).
4. Visiteur : e-mail de confirmation envoyé par la plateforme (n° de demande + lien de suivi,
   paramètre `chat_notifier_client = 1`) ; SMS de courtoisie conservé pour les portables.
