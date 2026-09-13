# Plugin Hygea

Ce plugin récupère le calendrier des collectes de déchets publié par Recycle!
(recycleapp.be) et le transforme en commandes Jeedom. Vous renseignez une
adresse une fois ; le plugin sait ensuite quand la collecte passe et ce qu'il
faut sortir.

Il fonctionne pour Hygea, mais aussi pour toutes les autres intercommunales
belges qui publient sur le même service : le plugin lit l'opérateur réel de
l'adresse et l'affiche.

Aucune dépendance, aucun démon, aucun compte à créer. Le calendrier est relu une
fois par jour, les commandes sont recalculées toutes les heures.

## Installation

1. Plugins → Gestion des plugins → Ajouter → Github.
2. Renseignez le dépôt (voir le README), branche `master`.
3. Activez le plugin.

Aucune dépendance n'est à installer.

## Créer une adresse

Plugins → Organisation → Hygea → **Ajouter une adresse**.

| Champ | Valeur |
|---|---|
| Nom | ce que vous voulez, par exemple `Maison` |
| Code postal | tapez-le, puis cliquez sur la loupe |
| Localité | choisissez-la dans la liste |
| Rue | tapez les premières lettres, cliquez sur la loupe, choisissez |
| Numéro | le numéro de voirie, un entier |

Cliquez sur **Tester l'adresse** avant d'enregistrer : le plugin vérifie que la
rue appartient bien à la localité, affiche l'intercommunale qui dessert
l'adresse, et annonce la prochaine collecte.

### Pourquoi le numéro compte

Certaines rues sont collectées en deux tournées, côté pair et côté impair, ou
coupées en deux tronçons. Le numéro départage.

> Le service accepte n'importe quel numéro sans jamais se plaindre, y compris
> un numéro qui n'existe pas dans la rue. Un numéro erroné ne produit donc
> aucune erreur : il produit silencieusement le calendrier du voisin. Vérifiez-le.

### Options

| Option | Effet |
|---|---|
| Commandes par fraction | crée, pour chaque type de déchet rencontré, sa date de prochaine collecte, le nombre de jours restants et un booléen « demain » |
| Heure de bascule | heure à partir de laquelle la collecte du jour est tenue pour passée. `0` la laisse affichée toute la journée ; `9` fait passer l'affichage à la collecte suivante une fois le camion parti |

## Commandes disponibles

| Commande | Type | Description |
|---|---|---|
| Prochaine collecte | info / string | la date et les déchets concernés, affichés par un widget dédié |
| Résumé | info / string | la même chose en texte simple : `jeudi 17/09 : Déchets organiques, PMC` |
| Date de la prochaine collecte | info / string | au format `2026-09-17`, pour les calculs |
| Déchets de la prochaine collecte | info / string | les fractions séparées par des virgules |
| Jours avant la prochaine collecte | info / numeric | `0` le jour même, `1` la veille |
| Collecte aujourd'hui | info / binary | |
| Collecte demain | info / binary | la commande à surveiller pour être prévenu la veille au soir |
| Déchets à sortir ce soir | info / binary | les fractions de la collecte de demain, vide s'il n'y en a pas |
| Intercommunale | info / string | `HYGEA`, `TIBI`... le nom renvoyé par le service |
| Rafraîchir | action | relit le calendrier immédiatement |

Avec l'option « commandes par fraction », chaque type de déchet ajoute :

| Commande | Type |
|---|---|
| `<Déchet> : date` | info / string |
| `<Déchet> : jours restants` | info / numeric |
| `<Déchet> : demain` | info / binary |

## Le widget

La commande « Prochaine collecte » utilise un widget fourni par le plugin : il
affiche la date en gros et une étiquette par type de déchet, reprenant les
couleurs officielles du service.

Si vous préférez l'affichage standard, masquez cette commande et rendez
« Résumé » visible à la place.

## Utilisation dans un scénario

Être prévenu la veille au soir, à 20 h, uniquement s'il y a quelque chose à
sortir :

```
Déclencheur : programmation, 0 20 * * *
Si : #[Maison][Hygea][Collecte demain]# == 1
Alors : message::notification avec
        "À sortir ce soir : " + #[Maison][Hygea][Déchets à sortir ce soir]#
```

N'annoncer que la poubelle bleue :

```
Si : #[Maison][Hygea][PMC : demain]# == 1
```

Rappeler le matin même, si la collecte est encore à venir :

```
Si : #[Maison][Hygea][Jours avant la prochaine collecte]# == 0
```

## Configuration du plugin

| Réglage | Rôle |
|---|---|
| Délai d'attente des requêtes | secondes avant d'abandonner un appel. 10 par défaut |
| Horizon du calendrier | nombre de jours demandés à chaque lecture. 60 par défaut |
| Langue des libellés | langue dans laquelle le service renvoie les noms de déchets |

## Fréquence des appels

Le plugin relit le calendrier **une fois par jour** au maximum, et recalcule ses
commandes toutes les heures sans toucher au réseau. Les conditions d'utilisation
du service demandent un usage raisonnable : évitez de multiplier les
rafraîchissements manuels ou les scénarios qui appellent la commande
« Rafraîchir ».

Quand le service est indisponible, le dernier calendrier connu reste affiché et
un message apparaît dans le centre de messages. Rien n'est effacé.

## En cas de problème

Les journaux sont dans Analyse → Historique, log `hygeabe`.

| Symptôme | Cause probable |
|---|---|
| « Adresse incomplète » | la localité ou la rue n'a pas été choisie dans la liste : un texte tapé à la main ne suffit pas, le service travaille avec des identifiants |
| « Adresse inconnue du service » | la rue choisie n'appartient pas à la localité choisie ; recommencez la recherche après avoir sélectionné la bonne localité |
| Aucune collecte alors que l'adresse est valide | le calendrier de l'année suivante n'est pas encore publié, ou le numéro de maison est faux |
| Le calendrier s'arrête fin décembre | normal : les intercommunales publient l'année suivante à des dates différentes |
| « Le service de collecte ne répond pas » | panne ou coupure réseau ; le plugin réessaie au prochain cron |
