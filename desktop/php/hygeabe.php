<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('hygeabe');
sendVarToJS('eqType', $plugin->getId());
// Les blocs qu'un rappel refuse : une seule liste, celle qui s'applique aussi à l'exécution.
sendVarToJS('hygeabeRefusedBlocks', hygeabe::REMINDER_REFUSED);
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter une adresse}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-list"></i> {{Mes adresses}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune adresse pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter une adresse » et donnez-lui un nom, par exemple « Collectes ».}}</li>';
			echo '<li>{{Saisissez le code postal, puis choisissez la localité proposée.}}</li>';
			echo '<li>{{Saisissez les premières lettres de la rue, puis choisissez-la dans la liste.}}</li>';
			echo '<li>{{Indiquez le numéro de maison et enregistrez : les commandes sont créées et le calendrier est récupéré.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-map-marker-alt" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#collectiontab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-calendar-alt"></i><span class="hidden-xs"> {{Calendrier}}</span></a></li>
			<li role="presentation"><a href="#remindertab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-bell"></i><span class="hidden-xs"> {{Rappels}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Collectes}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>

						<!-- =========================== ADRESSE =========================== -->
						<fieldset>
							<legend><i class="fas fa-map-marker-alt"></i> {{Adresse}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Code postal}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft" id="in_hygeabeZipcode" placeholder="7000">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_hygeabeSearchZipcode" title="{{Chercher les localités de ce code postal}}"><i class="fas fa-search"></i></a>
										</span>
									</div>
								</div>
								<div class="col-sm-6">
									<span class="help-block" style="margin:0;">{{Tapez le code postal puis cliquez sur la loupe : un code postal peut couvrir plusieurs localités.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Localité}}</label>
								<div class="col-sm-6">
									<select class="form-control" id="sel_hygeabeZipcode"></select>
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="zipcode_id" style="display:none;">
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="zipcode_label" style="display:none;">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Rue}}</label>
								<div class="col-sm-6">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft" id="in_hygeabeStreet" placeholder="{{Premières lettres de la rue}}">
										<span class="input-group-btn">
											<a class="btn btn-default roundedRight" id="bt_hygeabeSearchStreet" title="{{Chercher la rue}}"><i class="fas fa-search"></i></a>
										</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-6">
									<select class="form-control" id="sel_hygeabeStreet"></select>
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="street_id" style="display:none;">
									<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="street_label" style="display:none;">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Numéro}}</label>
								<div class="col-sm-2">
									<input type="number" min="1" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="house_number" placeholder="1">
								</div>
								<div class="col-sm-6">
									<span class="help-block" style="margin:0;">{{Le numéro de maison sert à départager les rues collectées en deux tournées. Sans lui, le calendrier peut être celui du voisin d'en face. Le service n'accepte qu'un entier : les bis et les lettres ne lui sont pas transmis.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-9">
									<a class="btn btn-info" id="bt_hygeabeTestAddress"><i class="fas fa-vial"></i> {{Tester l'adresse}}</a>
									<a class="btn btn-default" id="bt_hygeabeRefresh" title="{{Relit le calendrier de l'adresse enregistrée. Inutile juste après une sauvegarde, qui le fait déjà.}}"><i class="fas fa-sync"></i> {{Rafraîchir maintenant}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-9">
									<span id="span_hygeabeTestResult"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-sliders-h"></i> {{Options}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Commandes par fraction}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="per_fraction">
								</div>
								<div class="col-sm-6">
									<span class="help-block" style="margin:0;">{{Crée, pour chaque type de déchet rencontré dans le calendrier, sa date de prochaine collecte et le nombre de jours restants. Pratique pour un scénario qui ne surveille qu'une poubelle.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Heure de bascule}}</label>
								<div class="col-sm-2">
									<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="rollover_hour" placeholder="0">
								</div>
								<div class="col-sm-6">
									<span class="help-block" style="margin:0;">{{Heure à partir de laquelle la collecte du jour est considérée comme passée et « Prochaine collecte » saute au tour suivant. 0 la garde affichée toute la journée, 9 la fait basculer après le passage du camion.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-heartbeat"></i> {{État}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Dernier rafraîchissement}}</label>
								<div class="col-sm-8">
									<span class="form-control-static" id="span_hygeabeLastUpdate">-</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Adresse retenue}}</label>
								<div class="col-sm-8">
									<span class="form-control-static" id="span_hygeabeAddress">-</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== CALENDRIER ========================== -->
			<div role="tabpanel" class="tab-pane" id="collectiontab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">{{Les collectes connues pour cette adresse, telles que le service les publie. Ce calendrier est relu à chaque rafraîchissement.}}</div>
					<div class="table-responsive">
						<table id="table_hygeabeCollections" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:220px;">{{Date}}</th>
								<th style="width:100px;">{{Dans}}</th>
								<th>{{À sortir}}</th>
							</tr>
						</thead>
						<tbody></tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- =========================== RAPPELS =========================== -->
			<div role="tabpanel" class="tab-pane" id="remindertab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">
						<b>{{Être prévenu sans écrire de scénario.}}</b>
						{{Un rappel choisit le moment, les déchets concernés, et ce qu'il déclenche : une notification, un SMS, un message vocal, une lampe — n'importe quelle commande d'action de votre Jeedom. Un rappel qui ne concerne aucun déchet de la collecte ne part pas.}}
					</div>

					<!-- form-horizontal : c'est lui qui donne aux .form-group des rappels
					     leurs marges négatives et leur clearfix. Sans ce parent, les
					     colonnes des lignes d'action flottent sans être refermées et le
					     bloc s'effondre sur lui-même. -->
					<form class="form-horizontal">
						<div id="div_hygeabeReminders"></div>
					</form>

					<a class="btn btn-default btn-sm" id="bt_hygeabeAddReminder"><i class="fas fa-plus-circle"></i> {{Ajouter un rappel}}</a>

					<fieldset style="margin-top:20px;">
						<legend><i class="fas fa-code"></i> {{Jetons utilisables dans le titre et le message}}</legend>
						<div class="table-responsive">
							<table class="table table-bordered table-condensed">
								<tbody>
									<tr><td style="width:170px;"><code>#dechets#</code></td><td>{{Les déchets concernés, séparés par des virgules : « PMC, Papiers-cartons ». Un rappel filtré ne cite que les déchets qu'il surveille.}}</td></tr>
									<tr><td><code>#collecte#</code></td><td>{{« aujourd'hui », « demain », ou « jeudi 17/09 » au-delà.}}</td></tr>
									<tr><td><code>#jour#</code></td><td>{{Le jour de la collecte, toujours en toutes lettres : « jeudi 17/09 ».}}</td></tr>
									<tr><td><code>#jours#</code></td><td>{{Le nombre de jours avant la collecte.}}</td></tr>
									<tr><td><code>#adresse#</code></td><td>{{L'adresse de cet équipement.}}</td></tr>
									<tr><td><code>#equipement#</code></td><td>{{Le nom de cet équipement.}}</td></tr>
									<tr><td><code>#intercommunale#</code></td><td>{{L'intercommunale qui dessert l'adresse.}}</td></tr>
								</tbody>
							</table>
						</div>
						<span class="help-block" style="margin:0;">{{Les jetons de Jeedom restent utilisables par-dessus : #[Objet][Équipement][Commande]#, variable(), etc.}}</span>
					</fieldset>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:180px;">{{Type}}</th>
								<th style="width:250px;">{{Paramètres}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'hygeabe', 'js', 'hygeabe'); ?>
