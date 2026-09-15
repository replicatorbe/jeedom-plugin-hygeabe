# Plugin Jeedom — Hygea

Le calendrier des collectes de déchets d'une adresse belge, dans Jeedom.

## Ce qu'il apporte

- **Une adresse, pas une configuration.** Code postal, rue, numéro : la localité
  et la rue se choisissent dans une liste renvoyée par le service, il n'y a rien
  à deviner ni d'identifiant à aller chercher soi-même.
- **Ce qu'il faut sortir, et quand.** Prochaine collecte, jours restants,
  « collecte demain », et la liste des déchets concernés — de quoi écrire en
  trois lignes le scénario qui prévient la veille au soir.
- **Des rappels sans scénario.** Un onglet dédié dans chaque adresse : quand
  prévenir, pour quels déchets, et par quoi — n'importe quelle commande d'action
  de Jeedom, notification, SMS, message vocal ou lampe. Le message accepte des
  jetons (`#dechets#`, `#collecte#`...), un bouton d'essai l'envoie pour de vrai,
  et une ligne annonce le prochain départ.
- **Une commande par poubelle, en option.** Pour les scénarios qui ne
  s'intéressent qu'aux PMC ou qu'aux papiers-cartons.
- **Un widget dédié.** La date en gros, une étiquette par type de déchet, aux
  couleurs officielles du service.
- **Toutes les intercommunales belges.** Le plugin lit l'opérateur réel de
  l'adresse et l'affiche ; il ne se limite pas à Hygea malgré son nom.

## Ce qu'il ne peut pas faire

Le service n'expose pas de calendrier au-delà de ce que l'intercommunale a
publié : selon les communes, il s'arrête entre fin décembre et l'été suivant.
Une liste vide n'est donc pas forcément une erreur de configuration.

Un numéro de maison inexistant n'est jamais refusé par le service : il renvoie
le calendrier d'un tronçon voisin, sans le moindre message. Le plugin ne peut
pas détecter cette erreur à votre place, il se contente de vérifier que la rue
appartient bien à la localité.

Il n'y a ni réservation d'encombrants, ni commande d'action vers
l'intercommunale : le service ne le permet pas.

## Prérequis

Jeedom 4.4 ou supérieur, PHP 7.4 ou supérieur. Aucune dépendance, aucun démon,
aucun compte.

## Installation

Plugins → Gestion des plugins → Ajouter → Github.

| Champ | Valeur |
|---|---|
| ID logique du plugin | `hygeabe` |
| Utilisateur | `replicatorbe` |
| Dépôt | `jeedom-plugin-hygeabe` |
| Branche | `master` pour la version stable, `beta` pour la version de développement |

## Branches

- **`master`** — version stable. Tout commit poussé ici est proposé en mise à
  jour aux utilisateurs, Jeedom identifiant la version par le SHA du dernier
  commit de la branche.
- **`beta`** — développement. C'est la branche par défaut du dépôt.

## Architecture

```
cron horaire ─┬─ calendrier en cache de moins de 23 h ? ─── non ──┐
              │                                                   │
              │                                            api.fostplus.be
              │                                             /public/v1
              │                                                   │
              └─ oui ──────────────────► recalcul des commandes ◄──┘
                                          (« demain » change à minuit)
```

Le calendrier est relu une fois par jour ; les commandes, elles, sont recalculées
à chaque heure sans appel réseau. Le dernier calendrier connu survit à une panne
du service comme à une réponse vide, et le plugin attend trois heures avant de
réessayer après un échec.

## Documentation

- [Français](docs/fr_FR/index.md)
- [English](docs/en_US/index.md)

## Licence

AGPL v3. Voir [LICENSE](LICENSE).
