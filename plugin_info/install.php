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
    hygeabe_migrateDisplay();
}

/*
 * Remet l'affichage des équipements existants dans l'état que le plugin pose
 * désormais à la création : seule la tuile « Prochaine collecte » visible, les
 * commandes binaires habillées, la tuile assez large pour quatre étiquettes de
 * déchets. Quatorze widgets empilés dans 230 pixels rendaient l'équipement
 * illisible sur le dashboard.
 *
 * Une seule fois : ce que l'utilisateur réaffiche ou redimensionne ensuite lui
 * appartient.
 */
function hygeabe_migrateDisplay() {
    if (config::byKey('migration::display', 'hygeabe', 0) == 1) {
        return;
    }
    foreach (eqLogic::byType('hygeabe') as $eqLogic) {
        try {
            if ($eqLogic->getDisplay('width') == '') {
                $eqLogic->setDisplay('width', '280px');
                $eqLogic->save(true);
            }
        } catch (Throwable $e) {
            log::add('hygeabe', 'error', 'migration ' . $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }

        foreach ($eqLogic->getCmd() as $cmd) {
            $logicalId = $cmd->getLogicalId();
            if ($logicalId == 'next' || $logicalId == 'refresh') {
                continue;
            }
            try {
                $changed = false;
                if ($cmd->getIsVisible() == 1) {
                    $cmd->setIsVisible(0);
                    $changed = true;
                }
                if ($cmd->getSubType() == 'binary' && strpos($cmd->getTemplate('dashboard'), 'hygeabe::') !== 0) {
                    $cmd->setTemplate('dashboard', 'hygeabe::binLine');
                    $cmd->setTemplate('mobile', 'hygeabe::binLine');
                    $changed = true;
                }
                if ($changed) {
                    $cmd->save();
                }
            } catch (Throwable $e) {
                log::add('hygeabe', 'error', 'migration ' . $logicalId . ' : ' . $e->getMessage());
            }
        }
    }
    config::save('migration::display', 1, 'hygeabe');
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
