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
    badge.style.backgroundColor = _collection.fractions[i].color || '#777777'
    /* La couleur de texte vient du service : le blanc d'office rendrait le
       jaune des papiers-cartons illisible. */
    badge.style.color = _collection.fractions[i].textColor || '#FFFFFF'
    badge.textContent = _collection.fractions[i].name
    fractionCell.appendChild(badge)
  }
  row.appendChild(fractionCell)

  return row
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

hygeabeContainer.addEventListener('input', function (event) {
  if (event.target.closest('.eqLogicAttr[data-l2key="house_number"]')) {
    hygeabeShowAddress()
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
