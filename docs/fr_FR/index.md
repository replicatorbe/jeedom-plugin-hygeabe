# Plugin Hygea

Ce plugin récupère le calendrier des collectes de déchets publié par Recycle!
(recycleapp.be) et le transforme en commandes Jeedom. Vous renseignez une
adresse une fois ; le plugin sait ensuite quand la collecte passe et ce qu'il
faut sortir.

Il fonctionne pour Hygea, mais aussi pour toutes les autres intercommunales
belges qui publient sur le même service : le plugin lit l'opérateur réel de
l'adresse et l'affiche.

Il sait aussi **prévenir tout seul** : un rappel réglé dans l'équipement
déclenche la notification, le SMS ou la lampe de votre choix quelques heures
avant la collecte, sans qu'il y ait de scénario à écrire.

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
| Nom | ce que vous voulez, par exemple `Collectes` |
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
| Déchets à sortir ce soir | info / string | les fractions de la collecte de demain, vide s'il n'y en a pas |
| Intercommunale | info / string | `HYGEA`, `TIBI`... le nom renvoyé par le service |
| Rafraîchir | action | recalcule les commandes, et relit le calendrier s'il a vieilli |

Avec l'option « commandes par fraction », chaque type de déchet ajoute :

| Commande | Type |
|---|---|
| `<Déchet> : date` | info / string |
| `<Déchet> : jours restants` | info / numeric |
| `<Déchet> : demain` | info / binary |

Ces commandes existent pour **tous les déchets desservis à l'adresse**, pas
seulement pour ceux qui passent dans les deux mois : les sapins de Noël, les
encombrants à domicile et le verre n'apparaissent au calendrier qu'une fois l'an,
voire jamais. Vous pouvez donc écrire dès aujourd'hui le scénario qui vous
rappellera de sortir le sapin en janvier.

Hors saison, la date est vide et le nombre de jours restants vaut `-1`, qui
signifie « aucune collecte connue ». C'est aussi la valeur qu'on lit quand le
calendrier de l'année suivante n'est pas encore publié.

> Testez vos conditions sur `>= 0`, jamais sur `== 0` seul : `-1` n'est pas
> « aujourd'hui ». La commande « Collecte aujourd'hui » est là pour ça.

## Sur le dashboard

Une seule commande est visible par défaut : **Prochaine collecte**. Toutes les
autres existent pour les scénarios et les graphiques, et resteraient sans cela
empilées dans une tuile de quelques centimètres — jusqu'à vingt widgets pour une
adresse desservie en verre, textiles et encombrants.

La tuile affiche la date en gros, une phrase de rappel, et une étiquette par
type de déchet aux couleurs officielles du service. Elle change d'aspect quand
l'information compte : la date passe en orange la veille, en rouge le jour même,
et le rappel devient une étiquette « à sortir ce soir ».

Deux réglages, dans la configuration de la commande, onglet Affichage, bloc
« Paramètres optionnels widget » :

| Paramètre | Valeur | Effet |
|---|---|---|
| `icons` | `1` | ajoute le pictogramme du déchet dans chaque étiquette |
| `time` | `duration` ou `date` | affiche l'ancienneté de la valeur sous la tuile |

Pour afficher une autre commande, rendez-la visible depuis l'onglet Commandes.
Les commandes binaires utilisent un widget du plugin qui montre une poubelle
orange quand c'est vrai, un tiret discret sinon.

## Rappels

L'onglet **Rappels** d'une adresse permet d'être prévenu sans écrire le moindre
scénario. Un rappel dit trois choses : **quand**, **pour quels déchets**, et
**ce qu'il déclenche**.

| Réglage | Rôle |
|---|---|
| Actif | décocher suspend le rappel sans le supprimer |
| Quand | `le jour même`, `la veille`, jusqu'à `une semaine avant`, et l'heure |
| Déchets concernés | rien de sélectionné : le rappel part pour n'importe quelle collecte. Un ou plusieurs déchets : il ne part que pour eux |
| Actions | une ou plusieurs commandes d'action de votre Jeedom — notification, SMS, message vocal, lampe — ou un bloc (message, scénario, variable) |

Plusieurs rappels peuvent cohabiter sur la même adresse : « la veille à 19 h pour
tout », « le jour même à 6 h 30 pour les encombrants », « le jour même à 19 h
pour penser à rentrer les poubelles ».

Le sélecteur d'action est celui des scénarios : les champs *Titre* et *Message*
qui apparaissent à droite sont ceux de la commande choisie, dessinés par Jeedom
lui-même. Un rappel qui ne concerne aucun déchet de la collecte ne part pas.

### Jetons du message

Utilisables dans le titre comme dans le message :

| Jeton | Donne |
|---|---|
| `#dechets#` | `PMC, Papiers-cartons` — un rappel filtré ne cite que les déchets qu'il surveille |
| `#collecte#` | `aujourd'hui`, `demain`, ou `jeudi 17/09` au-delà |
| `#jour#` | `jeudi 17/09`, toujours |
| `#jours#` | le nombre de jours avant la collecte |
| `#adresse#` | l'adresse de l'équipement |
| `#equipement#` | le nom de l'équipement |
| `#intercommunale#` | l'intercommunale qui dessert l'adresse |

Les jetons de Jeedom continuent de fonctionner par-dessus :
`#[Objet][Équipement][Commande]#`, `variable()`, etc.

Un message courant :

```
À sortir ce soir : #dechets#
```

### Vérifier qu'un rappel est bien réglé

Un rappel mal réglé ne produit aucune erreur : il ne part simplement jamais.
Deux garde-fous pour l'éviter :

- sous chaque rappel, **Prochain envoi** annonce la date, l'heure et les déchets
  du prochain départ, d'après la configuration **enregistrée** ;
- le bouton **Tester** joue le rappel tout de suite, sur la prochaine collecte
  qui le concerne. Il envoie pour de vrai — c'est bien l'intérêt — et ne
  consomme pas le rappel du soir.

Si une action échoue, le message apparaît au centre de messages et dans le log
`hygeabe`. Il disparaît de lui-même au premier rappel qui repasse.

### Quand les rappels partent

Le plugin examine ses rappels toutes les cinq minutes, sans jamais interroger le
service : il travaille sur le calendrier déjà en mémoire. Un rappel réglé à
19 h 00 part donc entre 19 h 00 et 19 h 05.

Si Jeedom était éteint à l'heure dite, le rappel part encore au démarrage tant
qu'il n'a pas plus de **deux heures** de retard. Au-delà il se tait : annoncer à
minuit une poubelle à sortir pour la veille au soir ne rend service à personne.

Enregistrer un rappel ne le fait pas partir pour une échéance déjà passée : ce
rattrapage vaut pour une box éteinte, pas pour un rappel qu'on vient d'écrire.
Le bouton **Tester** est là pour ça.

### Ce qu'une action de rappel ne peut pas être

Les actions sont jouées dans le cron du cœur, partagé par tous les plugins :
une action lente — une notification vers un service qui ne répond plus — retient
tout le monde le temps de son délai d'attente. La seconde case à gauche d'une
action la lance en parallèle ; en échange, son éventuel échec ne sera plus
rapporté.

Pour la même raison, cinq blocs sont refusés : **Attendre**, **Pause**, **Faire
une demande**, **Rapport** et **Export historique**, qui retiennent le cron
pendant des secondes ou des minutes. Le sont aussi ceux qui n'ont de sens que
dans un scénario : **Stop**, **Ajouter un log**, **Retourner un texte**,
**Icône**, **Tag**.

## Utilisation dans un scénario

Les commandes info restent là pour tout ce que les rappels ne couvrent pas : une
condition particulière, un enchaînement, un horaire qui dépend d'autre chose.

Être prévenu la veille au soir, à 20 h, uniquement s'il y a quelque chose à
sortir :

```
Déclencheur : programmation, 0 20 * * *
Si : #[Maison][Collectes][Collecte demain]# == 1
Alors : message::notification avec
        "À sortir ce soir : " + #[Maison][Collectes][Déchets à sortir ce soir]#
```

N'annoncer que la poubelle bleue :

```
Si : #[Maison][Collectes][PMC : demain]# == 1
```

Rappeler le matin même, si la collecte est encore à venir :

```
Si : #[Maison][Collectes][Jours avant la prochaine collecte]# == 0
```

## Configuration du plugin

| Réglage | Rôle |
|---|---|
| Délai d'attente des requêtes | secondes avant d'abandonner un appel. 10 par défaut |
| Horizon du calendrier | nombre de jours demandés à chaque lecture. 60 par défaut, jamais moins de 14 : un rappel « une semaine avant » doit connaître sa collecte bien avant qu'elle n'arrive |
| Langue des libellés | langue dans laquelle le service renvoie les noms de déchets |

## Fréquence des appels

Le plugin relit le calendrier **une fois par jour**, et recalcule ses commandes
toutes les heures sans toucher au réseau. Les conditions d'utilisation du service
demandent un usage raisonnable, et le plugin s'y tient tout seul :

- la commande d'action « Rafraîchir » ne force **pas** de lecture réseau. Un
  scénario qui l'appellerait en boucle ne ferait que recomposer les commandes ;
  seul le bouton « Rafraîchir maintenant » de la page du plugin force une
  lecture, et pas plus d'une toutes les cinq minutes ;
- après un échec, le plugin attend trois heures avant de réessayer, au lieu de
  marteler un service déjà en difficulté ;
- enregistrer l'équipement ne relit le calendrier que si l'adresse a changé.

Quand le service est indisponible, le dernier calendrier connu reste affiché et
un message apparaît dans le centre de messages. Rien n'est effacé. Il en va de
même si le service répond sans aucune collecte — ce qui arrive quand
l'intercommunale n'a pas encore publié l'année suivante : le calendrier
précédent est conservé plutôt qu'écrasé par du vide.

L'examen des rappels, toutes les cinq minutes, n'ajoute rien à ce décompte : il
se fait sur le calendrier déjà en mémoire, sans une seule requête.

Le recalcul horaire dépend du cron du coeur, partagé par tous les plugins : un
autre plugin anormalement lent peut faire sauter une heure. Sans conséquence
ici, le passage suivant rattrape.

## En cas de problème

Les journaux sont dans Analyse → Logs, log `hygeabe`.

| Symptôme | Cause probable |
|---|---|
| « Adresse incomplète » | la localité ou la rue n'a pas été choisie dans la liste : un texte tapé à la main ne suffit pas, le service travaille avec des identifiants |
| « Adresse inconnue du service » | la rue choisie n'appartient pas à la localité choisie ; recommencez la recherche après avoir sélectionné la bonne localité |
| Aucune collecte alors que l'adresse est valide | le calendrier de l'année suivante n'est pas encore publié, ou le numéro de maison est faux |
| Le calendrier s'arrête fin décembre | normal : les intercommunales publient l'année suivante à des dates différentes |
| « Le service de collecte ne répond pas » | panne ou coupure réseau ; le plugin réessaie au prochain cron |
| Un rappel ne part pas | regardez la ligne « Prochain envoi » sous le rappel : elle dit s'il est désactivé, sans action, ou si aucune collecte connue ne le concerne. Le bouton « Tester » tranche le reste |
| Un rappel part mais rien n'arrive | l'action a échoué : le centre de messages et le log `hygeabe` donnent la commande fautive et la raison |
| « bloc inutilisable dans un rappel » | l'action désigne un bloc qui retiendrait le cron de Jeedom, ou qui n'a de sens que dans un scénario ; passez par un scénario |
| Un rappel est parti en retard | Jeedom était éteint à l'heure dite ; il rattrape jusqu'à deux heures après, pas au-delà |
