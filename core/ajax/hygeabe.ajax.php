<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* Récupère un équipement du plugin.
     * eqLogic::byId() charge n'importe quel équipement et le rend dans la classe
     * de SON type : sans ce contrôle, un id étranger ferait agir le plugin sur
     * l'équipement d'un autre. */
    $getHygeabe = function ($_id) {
        $eqLogic = hygeabe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'hygeabe') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /* unautorizedInDemo() sur toutes les actions qui sortent de la box : une
     * démonstration publique ne doit pas interroger un service extérieur. */
    if (init('action') == 'searchZipcode') {
        unautorizedInDemo();
        ajax::success(hygeabe::searchZipcodes(init('q')));
    }

    if (init('action') == 'searchStreet') {
        unautorizedInDemo();
        ajax::success(hygeabe::searchStreets(init('q'), init('zipcode')));
    }

    if (init('action') == 'testAddress') {
        unautorizedInDemo();
        ajax::success(hygeabe::testAddress(init('zipcode'), init('street'), init('number')));
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = $getHygeabe(init('id'));
        if (!$eqLogic->isConfigured()) {
            throw new Exception(__('Adresse incomplète : renseignez la localité, la rue et le numéro.', __FILE__));
        }

        $calendar = $eqLogic->update(true);
        /*
         * update() ne lève pas quand un calendrier est déjà en cache : sans ce
         * contrôle, une panne du service produirait un message vert « 37
         * collectes connues » et l'utilisateur croirait son calendrier à jour.
         */
        if ($eqLogic->getRefreshError() != '') {
            throw new Exception($eqLogic->getRefreshError());
        }

        $count = isset($calendar['collections']) ? count($calendar['collections']) : 0;
        ajax::success(array(
            'summary' => $count . ' ' . __('collectes connues pour cette adresse.', __FILE__),
        ));
    }

    if (init('action') == 'collections') {
        $eqLogic = $getHygeabe(init('id'));
        $calendar = $eqLogic->getCalendar();
        $collections = array();

        foreach (isset($calendar['collections']) ? $calendar['collections'] : array() as $collection) {
            $days = hygeabe::daysUntil($collection['date']);
            // Les collectes passées ne sont pas purgées du cache : les filtrer ici
            // évite de relire l'API rien que pour nettoyer l'affichage.
            if ($days < 0) {
                continue;
            }
            $collections[] = array(
                'date'      => $collection['date'],
                'label'     => hygeabe::dateLabel($collection['date']),
                'days'      => $days,
                'fractions' => $collection['fractions'],
            );
        }

        ajax::success(array(
            'lastUpdate'  => isset($calendar['fetchedAt']) ? date('d/m/Y H:i', $calendar['fetchedAt']) : '',
            'operator'    => isset($calendar['operator']) ? $calendar['operator'] : '',
            'collections' => $collections,
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
