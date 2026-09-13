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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function hygeabe_install() {
}

function hygeabe_update() {
}

function hygeabe_remove() {
    /*
     * L'adresse du service est en cache pour une semaine : la laisser derrière
     * soi ferait repartir une réinstallation sur une adresse peut-être périmée,
     * sans moyen de le voir depuis l'interface. Les calendriers, eux, sont
     * nettoyés équipement par équipement par hygeabe::preRemove().
     */
    try {
        $cache = cache::byKey('hygeabe::apiBase');
        if (is_object($cache)) {
            $cache->remove();
        }
    } catch (Throwable $e) {
        log::add('hygeabe', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
    }
}
