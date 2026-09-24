/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================ OUTILS */

/* Les déchets desservis à l'adresse, pour le filtre d'un rappel. Renseignés par
   la réponse du serveur, donc vides tant que le calendrier n'a pas été lu. */
var hygeabeFractions = []

/* Vrai pendant la reconstruction de l'onglet Rappels. Reposer une valeur dans un
   champ émet « change » exactement comme une saisie : sans ce drapeau, ouvrir
   une adresse suffirait à la déclarer modifiée, et l'avertissement « quitter
   sans enregistrer ? » tomberait sans que rien n'ait été touché. */
var hygeabeRendering = false

/* Requête AJAX vers le contrôleur du plugin.
   _options : { button: <élément à désactiver pendant l'appel>,
                failure: <fonction recevant le message d'erreur>,
                silent: true pour ne rien afficher } */
function hygeabeAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var button = isset(options.button) ? options.button : null
  var released = false
  var release = function () {
    if (button === null || released) { return }
    released = true
    button.removeAttribute('disabled')
    button.classList.remove('disabled')
  }
  if (button !== null) {
    button.setAttribute('disabled', 'disabled')
    button.classList.add('disabled')
    /* Filet de sécurité : jamais de bouton bloqué si la réponse n'arrive pas. */
    setTimeout(release, 60000)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/hygeabe/core/ajax/hygeabe.ajax.php',
    data: payload,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      release()
      if (isset(options.failure)) {
        options.failure('{{Jeedom n\'a pas répondu.}}')
        return
      }
      if (options.silent === true) { return }
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      release()
      if (data.state != 'ok') {
        if (isset(options.failure)) {
          options.failure(data.result)
          return
        }
        if (options.silent !== true) {
          jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        }
        return
      }
      _success(data.result)
    }
  })
}

/* Identifiant de l'équipement actuellement ouvert, ou null s'il n'est pas encore enregistré. */
function hygeabeCurrentId() {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord l\'équipement.}}', level: 'warning' })
    return null
  }
  return input.value
}

/* L'équipement affiché est-il toujours celui dont on attendait la réponse ?
   Comparaison en chaînes des deux côtés : le coeur transmet l'identifiant en
   entier dans printEqLogic, alors qu'un champ de formulaire rend toujours une
   chaîne. Une comparaison stricte rejetait donc toutes les réponses. */
function hygeabeIsDisplayed(_id) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (input !== null && String(input.value) === String(_id))
}

/* Les actions serveur travaillent sur les valeurs en base : refuse de partir
   si l'écran contient des modifications non enregistrées. */
function hygeabeCheckSaved() {
  var modified = (typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.modifyWithoutSave === true)
    || window.modifyWithoutSave === true
  if (modified) {
    jeedomUtils.showAlert({ message: '{{Enregistrez vos modifications avant de continuer}}', level: 'warning' })
    return false
  }
  return true
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function hygeabeMarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Valeur d'un champ caché de configuration. */
function hygeabeConfig(_key) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + _key + '"]')
  return (input === null) ? '' : input.value
}

function hygeabeSetConfig(_key, _value) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + _key + '"]')
  if (input !== null) { input.value = _value }
}

/* Récapitule l'adresse telle qu'elle sera enregistrée. Appelée à chaque fois
   qu'un morceau change : recalculée seulement au chargement, elle restait à « - »
   pendant toute la configuration, c'est-à-dire au seul moment où on la regarde. */
function hygeabeShowAddress() {
  var street = hygeabeConfig('street_label')
  var zipcode = hygeabeConfig('zipcode_label')
  var number = hygeabeConfig('house_number')

  var parts = []
  if (street !== '') { parts.push(street + ((number === '') ? '' : ' ' + number)) }
  if (zipcode !== '') { parts.push(zipcode) }
  document.getElementById('span_hygeabeAddress').textContent = (parts.length === 0) ? '-' : parts.join(', ')
}

/* Une couleur de texte lisible sur le fond d'une étiquette quand le service
   n'en fournit pas. Même calcul que le widget du dashboard, qui ne peut pas
   partager ce fichier. */
function hygeabeReadableOn(_background, _given) {
  if (typeof _given === 'string' && /^#[0-9a-f]{3,8}$/i.test(_given)) { return _given }
  var found = /^#([0-9a-f]{6})$/i.exec(String(_background || ''))
  if (!found) { return '#FFFFFF' }
  var value = parseInt(found[1], 16)
  var luminance = 0.2126 * ((value >> 16) & 255) + 0.7152 * ((value >> 8) & 255) + 0.0722 * (value & 255)
  return (luminance > 150) ? '#1E1E1E' : '#FFFFFF'
}

/* Message affiché sous les boutons de test. */
function hygeabeShowTestResult(_message, _level) {
  var container = document.getElementById('span_hygeabeTestResult')
  container.innerHTML = ''
  if (_message === '') { return }
  var box = document.createElement('div')
  box.className = 'alert alert-' + _level
  box.style.margin = '0'
  /* textContent et non innerHTML : le message peut reprendre un libellé venu
     d'un service extérieur. */
  box.textContent = _message
  container.appendChild(box)
}

/* ========================================================== LISTES DÉROULANTES */

/*
 * Remplit un select à partir d'une liste [{id, name}].
 *
 * La première option est toujours un repère vide : sans elle, le navigateur
 * sélectionne d'office la première entrée réelle sans émettre « change », et
 * l'utilisateur qui clique sur cette entrée-là ne déclencherait rien — son
 * choix ne serait jamais enregistré.
 */
function hygeabeFillSelect(_select, _items, _selectedId, _placeholder) {
  _select.innerHTML = ''

  var placeholder = document.createElement('option')
  placeholder.value = ''
  placeholder.textContent = (_items.length === 0) ? '{{Aucun résultat}}' : _placeholder
  _select.appendChild(placeholder)

  for (var i = 0; i < _items.length; i++) {
    /* textContent et non innerHTML : les libellés viennent d'un service extérieur. */
    var option = document.createElement('option')
    option.value = _items[i].id
    option.textContent = _items[i].name
    if (_items[i].id === _selectedId) { option.selected = true }
    _select.appendChild(option)
  }
}

/*
 * Recopie le choix de localité dans les champs enregistrés. Appelée aussi bien
 * sur « change » qu'après chaque remplissage de la liste, pour que l'affichage
 * et la valeur en base ne puissent jamais diverger.
 */
function hygeabeCommitZipcode(_select) {
  var id = _select.value
  if (hygeabeConfig('zipcode_id') === id) { return }

  hygeabeSetConfig('zipcode_id', id)
  hygeabeSetConfig('zipcode_label', (id === '') ? '' : _select.options[_select.selectedIndex].textContent)

  /* Changer de localité invalide la rue : son identifiant n'a de sens que dans
     la localité où il a été cherché. */
  hygeabeSetConfig('street_id', '')
  hygeabeSetConfig('street_label', '')
  document.getElementById('in_hygeabeStreet').value = ''
  hygeabeFillSelect(document.getElementById('sel_hygeabeStreet'), [], '', '{{Cherchez une rue}}')

  hygeabeShowAddress()
  hygeabeMarkModified()
}

function hygeabeCommitStreet(_select) {
  var id = _select.value
  if (hygeabeConfig('street_id') === id) { return }

  hygeabeSetConfig('street_id', id)
  hygeabeSetConfig('street_label', (id === '') ? '' : _select.options[_select.selectedIndex].textContent)
  hygeabeShowAddress()
  hygeabeMarkModified()
}

/* ============================================================ ÉQUIPEMENT */

function printEqLogic(_eqLogic) {
  /* Le coeur ne réinitialise que les .eqLogicAttr : tout le reste de l'écran
     garderait sinon l'état de l'équipement précédemment ouvert. */
  hygeabeShowTestResult('', 'info')
  document.getElementById('table_hygeabeCollections').querySelector('tbody').innerHTML = ''
  document.getElementById('span_hygeabeLastUpdate').textContent = '-'

  var zipcodeId = hygeabeConfig('zipcode_id')
  var zipcodeLabel = hygeabeConfig('zipcode_label')
  var streetId = hygeabeConfig('street_id')
  var streetLabel = hygeabeConfig('street_label')

  document.getElementById('in_hygeabeZipcode').value = zipcodeLabel.split(' ')[0] || ''
  document.getElementById('in_hygeabeStreet').value = streetLabel
  hygeabeShowAddress()

  hygeabeFillSelect(document.getElementById('sel_hygeabeZipcode'),
    (zipcodeId === '') ? [] : [{ id: zipcodeId, name: zipcodeLabel }], zipcodeId, '{{Cherchez un code postal}}')
  hygeabeFillSelect(document.getElementById('sel_hygeabeStreet'),
    (streetId === '') ? [] : [{ id: streetId, name: streetLabel }], streetId, '{{Cherchez une rue}}')

  hygeabeFractions = []
  hygeabeRenderReminders(_eqLogic)

  if (isset(_eqLogic.id) && _eqLogic.id != '') {
    hygeabeLoadCollections(_eqLogic.id)
  }
}

/* Remplit l'onglet Calendrier à partir du cache serveur, sans rappeler l'API. */
function hygeabeLoadCollections(_id) {
  var tbody = document.getElementById('table_hygeabeCollections').querySelector('tbody')

  var message = function (_text) {
    tbody.innerHTML = ''
    var row = document.createElement('tr')
    var cell = document.createElement('td')
    cell.setAttribute('colspan', '3')
    cell.textContent = _text
    row.appendChild(cell)
    tbody.appendChild(row)
  }

  hygeabeAjax('collections', { id: _id }, function (result) {
    /* La réponse d'un équipement qu'on a quitté entre-temps remplirait le
       calendrier de celui qu'on regarde maintenant. */
    if (!hygeabeIsDisplayed(_id)) { return }

    tbody.innerHTML = ''
    document.getElementById('span_hygeabeLastUpdate').textContent =
      (result.lastUpdate === '') ? '{{jamais}}' : result.lastUpdate

    /* Avant le test sur les collectes : un calendrier vide ne doit pas priver
       l'onglet Rappels de la liste des déchets ni du prochain envoi. */
    hygeabeFractions = isset(result.fractions) ? result.fractions : []
    hygeabeRefreshFractionSelects()
    hygeabeShowNextReminders(isset(result.reminders) ? result.reminders : {})

    if (result.collections.length === 0) {
      message('{{Aucune collecte connue. Vérifiez l\'adresse, puis cliquez sur « Rafraîchir maintenant ».}}')
      return
    }
    for (var i = 0; i < result.collections.length; i++) {
      tbody.appendChild(hygeabeCollectionRow(result.collections[i]))
    }
  }, {
    failure: function () {
      if (!hygeabeIsDisplayed(_id)) { return }
      message('{{Calendrier indisponible.}}')
    }
  })
}

/* Une ligne du calendrier. Construite en DOM : insertAdjacentHTML sur une table
   crée un <tbody> par insertion et empile toutes les lignes au même endroit. */
function hygeabeCollectionRow(_collection) {
  var row = document.createElement('tr')

  var dateCell = document.createElement('td')
  dateCell.textContent = _collection.label
  if (_collection.days === 0) { dateCell.style.fontWeight = 'bold' }
  row.appendChild(dateCell)

  var daysCell = document.createElement('td')
  if (_collection.days === 0) {
    daysCell.textContent = '{{aujourd\'hui}}'
  } else if (_collection.days === 1) {
    daysCell.textContent = '{{demain}}'
  } else {
    daysCell.textContent = _collection.days + ' {{jours}}'
  }
  row.appendChild(daysCell)

  var fractionCell = document.createElement('td')
  for (var i = 0; i < _collection.fractions.length; i++) {
    var badge = document.createElement('span')
    badge.className = 'label'
    badge.style.display = 'inline-block'
    badge.style.margin = '2px 4px 2px 0'
    badge.style.fontSize = '1em'
    var background = _collection.fractions[i].color || '#777777'
    badge.style.backgroundColor = background
    /* La couleur de texte vient du service ; à défaut elle est calculée : le
       blanc d'office rendrait le jaune des papiers-cartons illisible. */
    badge.style.color = hygeabeReadableOn(background, _collection.fractions[i].textColor)
    badge.textContent = _collection.fractions[i].name
    fractionCell.appendChild(badge)
  }
  row.appendChild(fractionCell)

  return row
}

/* ================================================================ RAPPELS */

/* Une option de la liste des déchets. */
function hygeabeFractionOption(_slug, _name, _selected) {
  var option = document.createElement('option')
  option.value = _slug
  /* textContent et non innerHTML : les libellés viennent d'un service extérieur. */
  option.textContent = _name
  option.selected = (_selected === true)
  return option
}

/* Remplit la liste des déchets d'un rappel en conservant la sélection. */
function hygeabeFillFractionSelect(_select, _selected) {
  var selected = _selected || []
  var known = []
  var i

  _select.innerHTML = ''
  for (i = 0; i < hygeabeFractions.length; i++) {
    known.push(hygeabeFractions[i].slug)
    _select.appendChild(hygeabeFractionOption(hygeabeFractions[i].slug, hygeabeFractions[i].name,
      selected.indexOf(hygeabeFractions[i].slug) !== -1))
  }
  /* Un déchet retenu par le rappel mais absent du calendrier du moment — les
     sapins en juillet, les encombrants hors tournée — reste dans la liste :
     sinon il disparaîtrait du filtre au premier enregistrement, et le rappel
     changerait de sens sans que personne ne l'ait demandé. */
  for (i = 0; i < selected.length; i++) {
    if (known.indexOf(selected[i]) === -1) {
      /* Le libellé du service n'est pas connu : l'identifiant technique reste le
         seul nom disponible, autant dire pourquoi il a cette tête. */
      _select.appendChild(hygeabeFractionOption(selected[i], selected[i] + ' {{(hors calendrier)}}', true))
    }
  }
  if (_select.options.length === 0) {
    var empty = document.createElement('option')
    empty.value = ''
    empty.disabled = true
    empty.textContent = '{{Calendrier pas encore lu}}'
    _select.appendChild(empty)
  }
}

/* Réaffiche les listes de déchets une fois le calendrier connu du serveur. */
function hygeabeRefreshFractionSelects() {
  var selects = document.querySelectorAll('#div_hygeabeReminders .hygeabeReminderFractions')
  for (var i = 0; i < selects.length; i++) {
    var selected = []
    for (var j = 0; j < selects[i].selectedOptions.length; j++) {
      selected.push(selects[i].selectedOptions[j].value)
    }
    hygeabeFillFractionSelect(selects[i], selected)
  }
}

/* Ce que chaque rappel enverra, et quand, d'après la configuration enregistrée.
   Un rappel mal réglé ne lève aucune erreur : il ne part jamais, et c'est la
   seule ligne qui permette de s'en apercevoir avant le jour de la collecte. */
function hygeabeShowNextReminders(_next) {
  var blocks = document.querySelectorAll('#div_hygeabeReminders .hygeabeReminder')
  for (var i = 0; i < blocks.length; i++) {
    var id = blocks[i].querySelector('.reminderAttr[data-l1key="id"]').value
    /* Le serveur rend une phrase entière : « Prochain envoi : ... », mais aussi
       « Ce rappel est désactivé. » ou « Aucune action... ». Recoller un préfixe
       ici donnerait « Prochain envoi : aucune action », c'est-à-dire la ligne la
       moins lisible au moment précis où elle signale un défaut. */
    blocks[i].querySelector('.hygeabeReminderNext').textContent =
      (id !== '' && isset(_next[id])) ? _next[id] : hygeabeReminderPending()
  }
}

/* L'état d'un rappel que le serveur n'a pas encore vu. */
function hygeabeReminderPending() {
  return '{{Enregistrez l\'équipement pour savoir quand ce rappel partira.}}'
}

/*
 * Une ligne d'action, calquée sur le sélecteur d'action des scénarios. L'ordre
 * html() → setJeeValues → appendChild → replaceWith est celui du coeur : le HTML
 * des options contient des <script> que seul Element.prototype.html() exécute.
 */
function hygeabeAddReminderAction(_block, _action) {
  var container = (_block === null) ? null : _block.querySelector('.hygeabeReminderActions')
  if (container === null) { return null }
  var action = _action || {}
  if (!isset(action.options)) { action.options = {} }

  var div = '<div class="hygeabeReminderAction expression" style="margin-bottom:4px;">'
  div += '<input class="expressionAttr" data-l1key="type" style="display:none;" value="action">'
  div += '<div class="form-group" style="margin:0;">'
  div += '<div class="col-sm-1">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable" checked title="{{Décocher pour désactiver cette action sans la supprimer}}">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="background" title="{{Exécuter en parallèle des autres actions}}">'
  div += '</div>'
  div += '<div class="col-sm-5">'
  div += '<div class="input-group">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm bt_hygeabeRemoveAction roundedLeft"><i class="fas fa-minus-circle"></i></a>'
  div += '</span>'
  div += '<input class="expressionAttr form-control input-sm cmdAction" data-l1key="cmd" placeholder="{{Commande à déclencher}}">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm hygeabeListAction" title="{{Choisir un bloc (message, scénario, variable...)}}"><i class="fas fa-tasks"></i></a>'
  div += '<a class="btn btn-default btn-sm hygeabeListCmd roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span>'
  div += '</div>'
  div += '</div>'
  div += '<div class="col-sm-6 actionOptions"></div>'
  div += '</div>'
  div += '</div>'

  var wrapper = document.createElement('div')
  wrapper.html(div)
  wrapper.setJeeValues(action, '.expressionAttr')
  container.appendChild(wrapper)
  var nodes = Array.prototype.slice.call(wrapper.childNodes)
  wrapper.replaceWith(...nodes)

  if (nodes.length > 0) {
    hygeabeRefreshActionOptions(nodes[0], init(action.cmd, ''), action.options)
  }
  return nodes[0]
}

/* Les options connues d'une ligne dont le coeur n'a pas (encore) dessiné les
   champs. Rendues au relevé, pour ne pas enregistrer du vide à leur place. */
function hygeabePendingOptions(_line) {
  return (isset(_line.hygeabePending) && _line.hygeabePending !== null) ? _line.hygeabePending : null
}

/* Réaffiche les options — titre, message, curseur — après un changement de
   commande. C'est le coeur qui les dessine, d'après la commande visée : le
   plugin n'a donc rien à savoir du moyen de prévenir.

   La variante synchrone de displayActionOption fige l'onglet le temps d'un
   aller-retour PAR action : un rappel à quatre actions le bloquerait quatre
   fois. */
function hygeabeRefreshActionOptions(_line, _expression, _options) {
  var expression = String(init(_expression, ''))

  /* Le rendu détruit et reconstruit le champ Message. Le rejouer à chaque perte
     de focus effacerait ce que l'utilisateur est en train d'y taper : on ne
     redessine que si la commande visée a réellement changé. */
  if (_line.hygeabeExpression === expression) { return }
  _line.hygeabeExpression = expression

  /* Titre et message n'existent que dans le HTML renvoyé par le coeur. Tant
     qu'il n'est pas arrivé — requête en échec, commande visée supprimée,
     réponse vide — la ligne ne porte plus que ses deux cases, et l'enregistrement
     remplacerait par du vide un message rédigé de longue date. Les options
     connues restent donc sur la ligne jusqu'à ce qu'un rendu les remplace. */
  _line.hygeabePending = _options || {}

  jeedom.cmd.displayActionOption(expression, _options, function (html) {
    var target = _line.querySelector('.actionOptions')
    if (target === null) { return }

    /* Le coeur répond ce mot, tel quel, pour un bloc réservé aux scénarios. */
    if (html === 'Unsupported') {
      target.textContent = '{{Ce bloc n\'est utilisable que dans un scénario.}}'
      return
    }
    if (html === '' && expression !== '') {
      target.textContent = '{{Options indisponibles : la commande visée a peut-être été supprimée. Le message enregistré est conservé.}}'
      return
    }
    target.html(html)
    jeedomUtils.taAutosize()
    _line.hygeabePending = null
  })
}

/* Un rappel : quand, pour quels déchets, et ce qu'il déclenche. */
function hygeabeAddReminder(_reminder) {
  /* Garde, comme sur chaque getElementById du plugin : printEqLogic appelle
     cette fonction avant de remplir l'onglet Calendrier, et une exception ici
     laisserait les deux onglets vides — exactement ce qui arrive si le JS est
     déployé avant la page. */
  var container = document.getElementById('div_hygeabeReminders')
  if (container === null) { return null }
  var reminder = _reminder || {}
  var i

  /* Toutes les valeurs que le serveur accepte, sans trou : un « 5 jours avant »
     venu d'une restauration ne correspondrait à aucune option, le select
     resterait vide et le premier enregistrement le ramènerait silencieusement à
     « le jour même ». */
  var days = ''
  for (i = 0; i <= 7; i++) {
    days += '<option value="' + i + '">'
    days += (i === 0) ? '{{le jour même}}' : (i === 1) ? '{{la veille}}'
          : (i === 7) ? '{{une semaine avant}}' : i + ' {{jours avant}}'
    days += '</option>'
  }

  var div = '<div class="hygeabeReminder" style="border:1px solid rgba(128,128,128,.35);border-radius:4px;padding:10px;margin-bottom:10px;">'
  div += '<input class="reminderAttr" data-l1key="id" style="display:none;">'
  div += '<div class="form-group" style="margin:0 0 8px 0;">'
  div += '<div class="col-sm-12">'
  div += '<label class="checkbox-inline" style="padding-left:20px;"><input type="checkbox" class="reminderAttr" data-l1key="enable" checked> {{Actif}}</label>'
  div += '&nbsp;&nbsp;'
  div += '<select class="reminderAttr form-control input-sm" data-l1key="days" style="width:auto;display:inline-block;">'
  div += days
  div += '</select>'
  div += ' {{à}} '
  div += '<input type="time" class="reminderAttr form-control input-sm" data-l1key="time" style="width:auto;display:inline-block;">'
  div += '<a class="btn btn-default btn-sm bt_hygeabeTestReminder" style="margin-left:10px;" title="{{Joue ce rappel tout de suite, sur la prochaine collecte concernée}}"><i class="fas fa-bell"></i> {{Tester}}</a>'
  div += '<a class="btn btn-danger btn-sm bt_hygeabeRemoveReminder pull-right"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>'
  div += '</div>'
  div += '</div>'
  div += '<div class="form-group" style="margin:0 0 8px 0;">'
  div += '<label class="col-sm-2 control-label" style="text-align:left;">{{Déchets concernés}}</label>'
  div += '<div class="col-sm-4">'
  div += '<select class="form-control input-sm hygeabeReminderFractions" multiple size="4"></select>'
  div += '</div>'
  div += '<div class="col-sm-6">'
  div += '<span class="help-block" style="margin:0;">{{Rien de sélectionné : le rappel part pour n\'importe quelle collecte. Un ou plusieurs déchets : il ne part que pour eux, et #dechets# ne cite qu\'eux. Ctrl+clic pour en choisir plusieurs.}}</span>'
  div += '</div>'
  div += '</div>'
  div += '<div class="hygeabeReminderActions"></div>'
  div += '<a class="btn btn-default btn-xs bt_hygeabeAddAction"><i class="fas fa-plus"></i> {{Ajouter une action}}</a>'
  div += '<span class="help-block" style="margin:6px 0 0 0;">{{Les deux cases à gauche d\'une action : la jouer, et la jouer en parallèle des autres.}}</span>'
  div += '<span class="hygeabeReminderNext help-block" style="margin:6px 0 0 0;font-style:italic;"></span>'
  div += '</div>'

  var wrapper = document.createElement('div')
  wrapper.html(div)
  /* Seuls les champs simples : setJeeValues ne sait pas poser une liste, et le
     filtre comme les actions se reconstruisent juste après. */
  wrapper.setJeeValues({
    id: init(reminder.id, ''),
    enable: (isset(reminder.enable) && reminder.enable != 1) ? '0' : '1',
    days: String(init(reminder.days, 1)),
    time: init(reminder.time, '19:00')
  }, '.reminderAttr')
  container.appendChild(wrapper)
  var nodes = Array.prototype.slice.call(wrapper.childNodes)
  wrapper.replaceWith(...nodes)
  var block = nodes[0]

  /* Sans cela, un rappel tout juste ajouté serait le seul à n'afficher aucune
     ligne d'état, sans qu'on sache si c'est normal. */
  block.querySelector('.hygeabeReminderNext').textContent = hygeabeReminderPending()

  hygeabeFillFractionSelect(block.querySelector('.hygeabeReminderFractions'), init(reminder.fractions, []))

  var actions = init(reminder.actions, [])
  for (i = 0; i < actions.length; i++) {
    hygeabeAddReminderAction(block, actions[i])
  }
  if (actions.length === 0) {
    // Un rappel sans ligne d'action n'invite à rien et n'enverrait rien.
    hygeabeAddReminderAction(block, {})
  }
  return block
}

/* Reconstruit l'onglet. Le coeur ne réinitialise que les .eqLogicAttr : sans
   cela les rappels de l'adresse précédente resteraient affichés, et seraient
   enregistrés sur celle-ci. */
function hygeabeRenderReminders(_eqLogic) {
  var container = document.getElementById('div_hygeabeReminders')
  if (container === null) { return }
  container.innerHTML = ''

  var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
  var reminders = isset(configuration.reminders) ? configuration.reminders : []

  hygeabeRendering = true
  try {
    for (var i = 0; i < reminders.length; i++) {
      hygeabeAddReminder(reminders[i])
    }
  } finally {
    hygeabeRendering = false
  }
  hygeabeShowNextReminders({})
}

/* Relève les rappels de l'écran. */
function hygeabeCollectReminders() {
  var reminders = []
  var blocks = document.querySelectorAll('#div_hygeabeReminders .hygeabeReminder')

  for (var i = 0; i < blocks.length; i++) {
    var reminder = blocks[i].getJeeValues('.reminderAttr')[0]
    /* jeeValue() ne rend que la première option d'un select multiple : le filtre
       se relève à la main, sinon un rappel portant sur deux déchets en perdrait
       un à chaque enregistrement. */
    reminder.fractions = []
    var options = blocks[i].querySelector('.hygeabeReminderFractions').selectedOptions
    for (var j = 0; j < options.length; j++) {
      if (options[j].value !== '') { reminder.fractions.push(options[j].value) }
    }
    reminder.actions = []
    var lines = blocks[i].querySelectorAll('.hygeabeReminderAction')
    for (var k = 0; k < lines.length; k++) {
      var action = lines[k].getJeeValues('.expressionAttr')[0]
      var pending = hygeabePendingOptions(lines[k])
      if (pending !== null) {
        /* Les champs du coeur ne sont pas à l'écran : on repose les options
           connues, en laissant les deux cases de la ligne, elles bien présentes,
           faire foi. */
        action.options = Object.assign({}, pending, action.options)
      }
      reminder.actions.push(action)
    }
    reminders.push(reminder)
  }
  return reminders
}

/* Appelée par plugin.template.js juste avant l'enregistrement. Les rappels sont
   une liste imbriquée : data-lXkey ne descend qu'à trois niveaux, il faut les
   collecter à la main. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  _eqLogic.configuration.reminders = hygeabeCollectReminders()
  return _eqLogic
}

/* ============================================================== COMMANDES */

/* Ligne du tableau des commandes. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== RECHERCHES */

function hygeabeSearchZipcode(_button) {
  var query = document.getElementById('in_hygeabeZipcode').value.trim()
  if (query === '') {
    jeedomUtils.showAlert({ message: '{{Saisissez d\'abord un code postal.}}', level: 'warning' })
    return
  }
  hygeabeAjax('searchZipcode', { q: query }, function (result) {
    var select = document.getElementById('sel_hygeabeZipcode')
    hygeabeFillSelect(select, result, (result.length === 1) ? result[0].id : hygeabeConfig('zipcode_id'),
      '{{Choisissez votre localité}}')
    hygeabeCommitZipcode(select)
    if (result.length > 1) {
      jeedomUtils.showAlert({ message: '{{Plusieurs localités portent ce code postal, choisissez la vôtre.}}', level: 'info', timeOut: 6000 })
    }
  }, { button: _button })
}

function hygeabeSearchStreet(_button) {
  if (hygeabeConfig('zipcode_id') === '') {
    jeedomUtils.showAlert({ message: '{{Choisissez d\'abord la localité.}}', level: 'warning' })
    return
  }
  var query = document.getElementById('in_hygeabeStreet').value.trim()
  if (query === '') {
    jeedomUtils.showAlert({ message: '{{Saisissez les premières lettres de la rue.}}', level: 'warning' })
    return
  }
  hygeabeAjax('searchStreet', { q: query, zipcode: hygeabeConfig('zipcode_id') }, function (result) {
    var select = document.getElementById('sel_hygeabeStreet')
    hygeabeFillSelect(select, result, (result.length === 1) ? result[0].id : hygeabeConfig('street_id'),
      '{{Choisissez votre rue}}')
    hygeabeCommitStreet(select)
    if (result.length > 1) {
      jeedomUtils.showAlert({ message: '{{Plusieurs rues correspondent, choisissez la vôtre.}}', level: 'info', timeOut: 6000 })
    }
  }, { button: _button })
}

/* =============================================================== ÉCOUTEURS */

/* Les écouteurs sont posés à la racine du script : les pages sont chargées en
   AJAX par jeedomUtils.loadPage, l'évènement DOMContentLoaded a déjà eu lieu.
   La garde évite qu'une absence du conteneur ne casse tout le fichier. */
var hygeabeContainer = document.getElementById('div_pageContainer') || document.body

/*
 * Ce changement de champ vient-il de l'utilisateur ? Reposer une valeur émet
 * « change » exactement comme une saisie, et le HTML des options d'une action
 * est injecté en asynchrone, bien après la fin du rendu : le drapeau seul ne
 * suffit donc pas. Le coeur résout le même problème de la même façon sur ses
 * propres champs (plugin.template.js) — un champ d'un onglet qu'on ne regarde
 * pas n'a pas pu être modifié à la main.
 */
function hygeabeReminderEdited(_target) {
  return _target.closest('#div_hygeabeReminders') !== null
      && !hygeabeRendering
      && _target.isVisible()
}

hygeabeContainer.addEventListener('input', function (event) {
  if (event.target.closest('.eqLogicAttr[data-l2key="house_number"]')) {
    hygeabeShowAddress()
    return
  }
  /* Les champs d'un rappel ne sont pas des .eqLogicAttr : le coeur ne les voit
     pas, et sans cela on quitterait la page en perdant un message tout juste
     écrit, sans le moindre avertissement. */
  if (hygeabeReminderEdited(event.target)) {
    hygeabeMarkModified()
  }
})

hygeabeContainer.addEventListener('change', function (event) {
  if (event.target.closest('#sel_hygeabeZipcode')) {
    hygeabeCommitZipcode(event.target)
    return
  }
  if (event.target.closest('#sel_hygeabeStreet')) {
    hygeabeCommitStreet(event.target)
    return
  }
  if (hygeabeReminderEdited(event.target)) {
    hygeabeMarkModified()
  }
})

/* Les options d'une action dépendent de la commande visée : elles sont
   redessinées quand celle-ci est saisie à la main, pas seulement choisie dans
   la liste. */
hygeabeContainer.addEventListener('focusout', function (event) {
  var input = event.target.closest('.hygeabeReminderAction .cmdAction')
  if (input === null) { return }
  var line = input.closest('.hygeabeReminderAction')
  var current = line.getJeeValues('.expressionAttr')[0]
  hygeabeRefreshActionOptions(line, input.jeeValue(), init(current.options))
})

/* Entrée dans un champ de recherche vaut clic sur la loupe : le formulaire
   compte plusieurs champs, la touche n'y déclencherait rien d'autre. */
hygeabeContainer.addEventListener('keydown', function (event) {
  if (event.key !== 'Enter') { return }

  if (event.target.closest('#in_hygeabeZipcode')) {
    event.preventDefault()
    hygeabeSearchZipcode(document.getElementById('bt_hygeabeSearchZipcode'))
    return
  }
  if (event.target.closest('#in_hygeabeStreet')) {
    event.preventDefault()
    hygeabeSearchStreet(document.getElementById('bt_hygeabeSearchStreet'))
    return
  }
})

hygeabeContainer.addEventListener('click', function (event) {
  var target = null

  if (target = event.target.closest('#bt_hygeabeSearchZipcode')) {
    if (target.classList.contains('disabled')) { return }
    hygeabeSearchZipcode(target)
    return
  }

  if (target = event.target.closest('#bt_hygeabeSearchStreet')) {
    if (target.classList.contains('disabled')) { return }
    hygeabeSearchStreet(target)
    return
  }

  if (target = event.target.closest('#bt_hygeabeTestAddress')) {
    if (target.classList.contains('disabled')) { return }
    hygeabeShowTestResult('', 'info')

    /* Dire lequel des trois manque, plutôt que de laisser le serveur répondre
       « renseignez tout » : le champ a souvent l'air rempli alors que le choix
       dans la liste n'a pas été fait. */
    if (hygeabeConfig('zipcode_id') === '') {
      hygeabeShowTestResult('{{Choisissez la localité dans la liste.}}', 'warning')
      return
    }
    if (hygeabeConfig('street_id') === '') {
      hygeabeShowTestResult('{{Choisissez la rue dans la liste.}}', 'warning')
      return
    }
    if (hygeabeConfig('house_number') === '') {
      hygeabeShowTestResult('{{Indiquez le numéro de maison.}}', 'warning')
      return
    }

    hygeabeAjax('testAddress', {
      zipcode: hygeabeConfig('zipcode_id'),
      street: hygeabeConfig('street_id'),
      number: hygeabeConfig('house_number')
    }, function (data) {
      hygeabeShowTestResult(data.summary, 'success')
    }, {
      button: target,
      failure: function (message) { hygeabeShowTestResult(message, 'danger') }
    })
    return
  }

  /* --- Rappels --- */

  if (event.target.closest('#bt_hygeabeAddReminder')) {
    hygeabeAddReminder({})
    hygeabeMarkModified()
    return
  }

  if (target = event.target.closest('.bt_hygeabeRemoveReminder')) {
    var doomed = target.closest('.hygeabeReminder')

    /* Un rappel vide s'enlève sans cérémonie. Un rappel rempli emporte ses
       actions et les messages qui y ont été rédigés, et la seule façon de
       revenir en arrière serait de recharger la page — donc de perdre aussi
       tout le reste de la saisie. */
    var filled = false
    var written = doomed.querySelectorAll('.hygeabeReminderAction .cmdAction')
    for (var w = 0; w < written.length; w++) {
      if (written[w].value.trim() !== '') { filled = true }
    }
    if (!filled) {
      doomed.remove()
      hygeabeMarkModified()
      return
    }
    jeeDialog.confirm('{{Supprimer ce rappel et toutes ses actions ?}}', function (confirmed) {
      if (confirmed !== true) { return }
      doomed.remove()
      hygeabeMarkModified()
    })
    return
  }

  if (target = event.target.closest('.bt_hygeabeAddAction')) {
    hygeabeAddReminderAction(target.closest('.hygeabeReminder'), {})
    hygeabeMarkModified()
    return
  }

  if (target = event.target.closest('.bt_hygeabeRemoveAction')) {
    target.closest('.hygeabeReminderAction').remove()
    hygeabeMarkModified()
    return
  }

  if (target = event.target.closest('.hygeabeListCmd')) {
    var cmdLine = target.closest('.hygeabeReminderAction')
    /* Le sélecteur rappelle avec { human: '#[Objet][Équipement][Commande]#' }.
       C'est cette forme qui est posée dans le champ ; le coeur la convertit en
       identifiant à l'enregistrement (jeedom::fromHumanReadable), si bien qu'un
       renommage ultérieur ne casse pas le rappel. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      cmdLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      hygeabeRefreshActionOptions(cmdLine, result.human, '')
      hygeabeMarkModified()
    })
    return
  }

  if (target = event.target.closest('.hygeabeListAction')) {
    var blockLine = target.closest('.hygeabeReminderAction')
    jeedom.getSelectActionModal({}, function (result) {
      /* Les actions d'un rappel sont jouées dans le cron du coeur, partagé par
         tous les plugins : « Attendre », « Pause », « Faire une demande » et les
         rapports y retiendraient tout le monde, jusqu'à faire tuer la tâche. Les
         autres n'ont de sens que dans un scénario. La liste vient de
         hygeabe::REMINDER_REFUSED, qui refuse aussi à l'exécution — le champ
         reste en saisie libre. */
      if (hygeabeRefusedBlocks.indexOf(result.human) !== -1) {
        jeedomUtils.showAlert({
          message: '{{Ce bloc n\'est pas utilisable dans un rappel : il retiendrait le cron de Jeedom ou n\'a de sens que dans un scénario. Passez par un scénario.}}',
          level: 'warning',
          timeOut: 10000
        })
        return
      }
      blockLine.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      hygeabeRefreshActionOptions(blockLine, result.human, '')
      hygeabeMarkModified()
    })
    return
  }

  if (target = event.target.closest('.bt_hygeabeTestReminder')) {
    if (target.classList.contains('disabled')) { return }
    /* Le test travaille sur les rappels en base : une action tout juste choisie
       et pas encore enregistrée ne serait pas celle qui est jouée. */
    if (!hygeabeCheckSaved()) { return }
    var testId = hygeabeCurrentId()
    if (testId === null) { return }
    var reminderId = target.closest('.hygeabeReminder').querySelector('.reminderAttr[data-l1key="id"]').value
    if (reminderId === '') {
      jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord ce rappel.}}', level: 'warning' })
      return
    }
    var button = target
    jeeDialog.confirm('{{Le test envoie réellement le rappel : notification, message, lampe. Continuer ?}}', function (confirmed) {
      if (confirmed !== true) { return }
      hygeabeAjax('testReminder', { id: testId, reminder: reminderId }, function (data) {
        jeedomUtils.showAlert({ message: '{{Rappel envoyé pour la collecte du}} ' + data.summary, level: 'success', timeOut: 12000 })
      }, { button: button })
    })
    return
  }

  if (target = event.target.closest('#bt_hygeabeRefresh')) {
    if (target.classList.contains('disabled')) { return }
    if (!hygeabeCheckSaved()) { return }
    var id = hygeabeCurrentId()
    if (id === null) { return }
    hygeabeAjax('refresh', { id: id }, function (data) {
      jeedomUtils.showAlert({ message: data.summary, level: 'success', timeOut: 12000 })
      hygeabeLoadCollections(id)
    }, { button: target })
    return
  }
})
