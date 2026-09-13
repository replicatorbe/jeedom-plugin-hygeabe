<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-cloud"></i> {{Service Recycle!}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente des requêtes}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="api_timeout" placeholder="10">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes avant d'abandonner un appel à l'API. 10 convient dans la quasi-totalité des cas.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Horizon du calendrier}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="days_ahead" placeholder="60">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Nombre de jours de collectes demandés à chaque rafraîchissement. Au-delà de 60 jours le calendrier n'est généralement pas encore publié.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Langue des libellés}}</label>
			<div class="col-md-2">
				<select class="configKey form-control" data-l1key="lang">
					<option value="fr">{{Français}}</option>
					<option value="nl">{{Néerlandais}}</option>
					<option value="de">{{Allemand}}</option>
					<option value="en">{{Anglais}}</option>
				</select>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Langue dans laquelle le service renvoie le nom des fractions (« Déchets organiques », « PMC »...). Les commandes déjà créées gardent leur nom, seule la valeur change.}}</span>
			</div>
		</div>
	</fieldset>
</form>
