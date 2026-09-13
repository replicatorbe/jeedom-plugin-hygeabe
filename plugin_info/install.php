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
     * Le jeton d'accès et la clé extraite du site sont en cache : les laisser
     * derrière soi ferait repartir une réinstallation avec une clé peut-être
     * périmée, sans moyen de le voir depuis l'interface.
     */
    hygeabe_clearCache();
}

/* Vide le cache partagé du plugin (clé d'API, jeton, réponses). */
function hygeabe_clearCache() {
    foreach (array('secret', 'token') as $key) {
        try {
            $cache = cache::byKey('hygeabe::' . $key);
            if (is_object($cache)) {
                $cache->remove();
            }
        } catch (Throwable $e) {
            log::add('hygeabe', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
        }
    }
}
