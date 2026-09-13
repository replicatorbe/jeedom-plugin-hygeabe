# Changelog

## 0.4 — 13/09/2026

**Déchets saisonniers**

- Les commandes sont désormais créées pour tous les déchets desservis à
  l'adresse, et plus seulement pour ceux qui passent dans les deux mois. Les
  sapins de Noël, les encombrants à domicile et le verre n'apparaissaient au
  calendrier qu'une fois l'an : leur commande surgissait le jour venu, et on ne
  pouvait pas écrire le scénario à l'avance.
- Hors saison, la date reste vide et les jours restants valent `-1`.

## 0.3 — 13/09/2026

**Correctif**

- L'onglet Calendrier restait vide et « Dernier rafraîchissement » affichait
  toujours un tiret : la garde qui écarte la réponse d'un équipement qu'on a
  quitté entre-temps comparait un entier à une chaîne, et rejetait donc toutes
  les réponses.
- « Adresse retenue » n'était recalculée qu'au chargement de l'équipement, donc
  restait vide pendant toute la saisie — au seul moment où on la regarde. Elle
  suit maintenant la localité, la rue et le numéro au fur et à mesure.

## 0.2 — 13/09/2026

**Dashboard**

- Une seule commande est désormais visible : la tuile « Prochaine collecte ».
  Les autres, destinées aux scénarios et aux graphiques, encombraient
  l'équipement — jusqu'à vingt widgets empilés dans quelques centimètres.
- La tuile change d'aspect à l'approche de la collecte : date en orange la
  veille avec un rappel « à sortir ce soir », en rouge le jour même.
- Un paramètre optionnel `icons` ajoute le pictogramme du déchet dans chaque
  étiquette.
- Les étiquettes se replient proprement sur plusieurs lignes, s'adaptent au
  thème sombre, et la couleur du texte est calculée quand le service n'en
  fournit pas : les papiers-cartons ne s'affichent plus en blanc sur jaune.

**Rafraîchissement**

- La commande d'action « Rafraîchir » ne force plus de lecture réseau : un
  scénario qui l'appelait en boucle pouvait émettre des milliers d'appels par
  jour au service.
- Après un échec, le plugin attend trois heures avant de réessayer, au lieu de
  solliciter chaque heure un service déjà en difficulté.
- Une réponse sans aucune collecte ne remplace plus le calendrier connu : c'est
  ce qui arrive quand l'intercommunale n'a pas encore publié l'année suivante.
- Enregistrer un équipement ne relit le calendrier que si l'adresse a changé.
- « Déchets à sortir ce soir », vide six jours sur sept, ne réveille plus les
  scénarios qui l'écoutent à chaque heure.

## 0.1 — 13/09/2026

Première version.

**Calendrier**

- Une adresse belge (code postal, rue, numéro) suffit : le plugin récupère le
  calendrier de collecte publié par Recycle! et le tient à jour tout seul.
- Recherche assistée de la localité et de la rue, avec un bouton de test qui
  vérifie l'adresse et annonce la prochaine collecte avant d'enregistrer.
- L'intercommunale qui dessert l'adresse est détectée et affichée.

**Commandes**

- Prochaine collecte, date, déchets concernés, jours restants, collecte
  aujourd'hui, collecte demain, déchets à sortir ce soir.
- En option, un jeu de commandes par type de déchet, pour les scénarios qui ne
  surveillent qu'une poubelle.
- Un widget dédié affiche la date et une étiquette colorée par type de déchet.
