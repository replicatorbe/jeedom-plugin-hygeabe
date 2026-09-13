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
     * eqLogic::byId() charge n'importe quel équipement et le caste vers la classe
     * appelante : sans ce contrôle, un id étranger provoquerait une Error fatale. */
    $getHygeabe = function ($_id) {
        $eqLogic = hygeabe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'hygeabe') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        return $eqLogic;
    };

    if (init('action') == 'searchZipcode') {
        ajax::success(hygeabe::searchZipcodes(init('q')));
    }

    if (init('action') == 'searchStreet') {
        ajax::success(hygeabe::searchStreets(init('q'), init('zipcode')));
    }

    if (init('action') == 'testAddress') {
        ajax::success(hygeabe::testAddress(init('zipcode'), init('street'), init('number')));
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = $getHygeabe(init('id'));
        $calendar = $eqLogic->update(true);
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
