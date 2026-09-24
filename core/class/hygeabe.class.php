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

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class hygeabe extends eqLogic {

    /*
     * Le service n'utilise plus de jeton : la seule contrainte est l'en-tête
     * x-consumer, que l'API refuse de servir avec une autre valeur. L'adresse de
     * base est celle que le site charge lui-même au démarrage depuis
     * config/app.settings.json ; elle est relue à la volée si l'API se met à
     * répondre en erreur, pour survivre à un changement d'hébergement.
     */
    const API_HOST     = 'https://api.fostplus.be/recyclecms';
    const API_PATH     = '/public/v1';
    const API_CONSUMER = 'recycleapp.be';
    const API_SETTINGS = 'https://www.recycleapp.be/config/app.settings.json';
    const ASSETS_HOST  = 'https://assets.recycleapp.be';

    /* Un calendrier de collecte ne bouge pratiquement jamais : une lecture par
     * jour suffit, et les conditions d'utilisation du service demandent de ne
     * pas le solliciter davantage.
     *
     * 23 h et non 24 : le cron est horaire et le test porte sur un âge
     * strictement supérieur, si bien qu'une échéance de 24 h ne tombe jamais
     * pile et recule d'une heure par jour. 23 h fixe la lecture à l'heure de la
     * première, une fois pour toutes. */
    const CALENDAR_TTL = 82800;

    /* Un forçage ne relit pas un calendrier tout frais : sans ce plancher, un
     * scénario appelant la commande « Rafraîchir » chaque minute émettrait
     * près de trois mille appels par jour. */
    const FORCE_MIN_INTERVAL = 300;

    /* Après un échec, on laisse le service tranquille : c'est au moment où il
     * va le plus mal qu'il ne faut pas le marteler d'heure en heure. */
    const RETRY_DELAY = 10800;

    /* Valeur d'une commande « jours restants » quand la date est inconnue. Une
     * chaîne vide serait convertie en 0 par le coeur, c'est-à-dire « collecte
     * aujourd'hui ». */
    const UNKNOWN_DAYS = -1;

    /* Un rappel manqué — box éteinte, cron en retard — part encore s'il n'a que
     * deux heures de retard. Au-delà il se tait : annoncer à minuit une poubelle
     * à sortir pour la veille au soir ne rend service à personne. */
    const REMINDER_GRACE = 7200;

    /* Combien de temps on se souvient d'avoir envoyé un rappel. Il suffit que ce
     * soit plus long que l'écart entre deux collectes du même déchet. */
    const REMINDER_MEMORY = 1296000;

    /* Le plus loin qu'un rappel puisse viser avant la collecte. Au-delà d'une
     * semaine il annoncerait la collecte suivante avant que la précédente soit
     * passée. */
    const REMINDER_MAX_DAYS = 7;

    /* L'heure d'un rappel neuf : la veille au soir, quand les poubelles se
     * sortent. */
    const REMINDER_DEFAULT_TIME = '19:00';

    /*
     * Blocs du coeur qu'un rappel ne doit pas jouer. « Attendre », « Pause »,
     * « Faire une demande » et les rapports retiennent leur processus pendant
     * des secondes ou des minutes : les actions d'un rappel sont jouées dans le
     * cron partagé par tous les plugins, qui serait tué au bout de cinq minutes
     * en accusant celui-ci. Les autres n'ont de sens que dans un scénario.
     *
     * Le sélecteur de l'interface les écarte déjà ; ce contrôle vaut pour une
     * expression tapée à la main dans le champ, qui reste libre.
     */
    const REMINDER_REFUSED = array(
        'wait', 'sleep', 'ask', 'report', 'exportHistory',
        'stop', 'log', 'scenario_return', 'icon', 'tag',
    );

    /*
     * L'identifiant du pictogramme est la seule clé stable d'un type de déchet :
     * le libellé change avec la langue et avec l'intercommunale. On s'en sert
     * pour donner à chaque fraction un identifiant de commande durable et une
     * icône Jeedom ; une fraction inconnue retombe sur un identifiant dérivé de
     * son nom, ce qui reste correct pour une adresse donnée.
     */
    public static $_fractions = array(
        '5d610b86162c063cc0400108' => array('slug' => 'organique',   'icon' => 'fas fa-apple-alt'),
        '5d610b86162c063cc0400111' => array('slug' => 'organique',   'icon' => 'fas fa-apple-alt'),
        '5d610b86162c063cc0400112' => array('slug' => 'residuel',    'icon' => 'fas fa-trash'),
        '5d610b86162c063cc0400133' => array('slug' => 'residuel',    'icon' => 'fas fa-trash'),
        '609a4b94e85d9b1e58b530e8' => array('slug' => 'residuel',    'icon' => 'fas fa-trash'),
        '5d610b86162c063cc0400123' => array('slug' => 'papier',      'icon' => 'fas fa-newspaper'),
        '5d610b86162c063cc0400125' => array('slug' => 'pmc',         'icon' => 'fas fa-wine-bottle'),
        '5d610b86162c063cc0400110' => array('slug' => 'verre',       'icon' => 'fas fa-wine-glass'),
        '5d610b86162c063cc0400107' => array('slug' => 'verts',       'icon' => 'fas fa-leaf'),
        '5d610b86162c063cc0400127' => array('slug' => 'elagage',     'icon' => 'fas fa-seedling'),
        '5d610b86162c063cc0400117' => array('slug' => 'encombrants', 'icon' => 'fas fa-couch'),
        '5d610b86162c063cc0400131' => array('slug' => 'textiles',    'icon' => 'fas fa-tshirt'),
        '5d610b86162c063cc0400101' => array('slug' => 'piles',       'icon' => 'fas fa-battery-half'),
        '5d610b86162c063cc0400102' => array('slug' => 'sapins',      'icon' => 'fas fa-tree'),
    );

    /* Jours et mois écrits en toutes lettres : IntlDateFormatter n'est pas
     * garanti présent sur toutes les installations Jeedom. */
    public static $_days = array('dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi');

    /* Cause du dernier échec de lecture, pour que le contrôleur ajax puisse
     * répondre autre chose qu'un succès quand rien n'a été rafraîchi. */
    private $_refreshError = '';

    /*
     * Réutilise un gabarit du coeur en n'y changeant que les icônes. « Collecte
     * demain » doit se lire comme un rappel, pas comme une coche verte quand
     * c'est faux. Le coeur remplace les guillemets doubles par des apostrophes
     * (cmd::getWidgetTemplateCode) : écrire directement en apostrophes.
     */
    public static function templateWidget() {
        $icons = array(
            '#_icon_on_#'  => "<i class='icon_orange fas fa-dumpster'></i>",
            '#_icon_off_#' => "<i class='fas fa-minus'></i>",
        );
        return array(
            'info' => array(
                'binary' => array(
                    'bin'     => array('template' => 'tmplicon',    'replace' => $icons),
                    'binLine' => array('template' => 'tmpliconline', 'replace' => $icons),
                ),
            ),
        );
    }

    /* ==================================================================== CRON */

    /*
     * Le cron horaire fait deux choses distinctes : il relit le calendrier au
     * plus une fois par jour, et il recalcule les commandes à chaque passage.
     * Sans ce second geste, « collecte demain » resterait vrai toute la journée
     * du lendemain et les scénarios se déclencheraient un jour trop tard.
     */
    public static function cronHourly() {
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->update();
            } catch (Throwable $e) {
                // Une adresse en échec ne doit pas priver les autres de leur mise à jour.
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Les rappels, et rien d'autre : pas une requête réseau ici. Le pas de cinq
     * minutes est celui de l'heure que choisit l'utilisateur — le cron horaire
     * ne saurait honorer un rappel réglé à 19 h 30 — et chaque passage se réduit
     * à une lecture de cache pour les adresses sans rappel.
     */
    public static function cron5() {
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->checkReminders();
            } catch (Throwable $e) {
                // Un rappel en échec ne doit pas priver les autres adresses du leur.
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        if ($this->getConfiguration('rollover_hour', '') === '') {
            $this->setConfiguration('rollover_hour', 0);
        }
        // Les commandes par déchet sont l'intérêt principal du plugin pour les
        // scénarios : elles sont actives d'emblée, à charge de les retirer.
        if ($this->getConfiguration('per_fraction', '') === '') {
            $this->setConfiguration('per_fraction', 1);
        }
        $this->setConfiguration('rollover_hour', min(23, max(0, (int) $this->getConfiguration('rollover_hour'))));

        /* Les rappels arrivent du formulaire tels que le JS les a ramassés :
         * c'est ici, et pas à l'envoi, qu'on leur impose une forme. */
        $this->setConfiguration('reminders', self::cleanReminders($this->getConfiguration('reminders')));

        /*
         * Le service n'accepte qu'un entier positif. Un texte qui n'en contient
         * pas est effacé plutôt que ramené à 1 : le calendrier du numéro 1 est
         * peut-être celui d'une autre tournée, et l'erreur serait invisible.
         */
        $number = trim((string) $this->getConfiguration('house_number'));
        if ($number !== '') {
            $this->setConfiguration('house_number', ((int) $number < 1) ? '' : (int) $number);
        }
        $this->setConfiguration('street_id', trim((string) $this->getConfiguration('street_id')));
        $this->setConfiguration('zipcode_id', trim((string) $this->getConfiguration('zipcode_id')));

        /* 230 px est la largeur par défaut du coeur : trop étroit pour quatre
         * étiquettes de déchets. L'utilisateur reste libre de redimensionner. */
        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '280px');
        }

        /*
         * Aucune exception ici : le coeur crée l'équipement avec son seul nom,
         * toute validation rendrait le bouton « Ajouter » inutilisable. Une
         * adresse incomplète est signalée par un message dans le centre de
         * messages au moment du rafraîchissement.
         */
    }

    public function postSave() {
        $this->createCommands();

        if ($this->isConfigured()) {
            try {
                /*
                 * Forcer la lecture à chaque enregistrement ferait deux appels au
                 * service pour un simple changement de nom ou d'icône. Seule une
                 * adresse différente justifie de tout relire.
                 */
                $calendar = $this->getCalendar();
                $changed = !isset($calendar['address']) || $calendar['address'] !== $this->addressSignature();
                $this->update($changed);
            } catch (Throwable $e) {
                // L'enregistrement ne doit pas échouer parce que le service est
                // indisponible : l'adresse est valide, le cron réessaiera.
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        $this->settleReminders();
    }

    public function preRemove() {
        /*
         * DB::remove() met l'id à null avant postRemove : le cache du calendrier
         * doit donc être nettoyé tant que l'identifiant est encore lisible.
         */
        $this->clearCalendar();
        // Sans cela, une adresse recréée hériterait de la mémoire des rappels de
        // l'ancienne — des clés que plus aucun écran ne permet de voir.
        $this->forgetReminders();
        // Sans cela le message reste au centre de messages avec un identifiant
        // qu'aucun code ne pourra plus faire correspondre : impossible à effacer
        // autrement qu'à la main.
        $this->clearProblem();
        return true;
    }

    /* L'adresse est-elle complète ? */
    public function isConfigured() {
        return $this->getConfiguration('zipcode_id') != ''
            && $this->getConfiguration('street_id') != ''
            && $this->getConfiguration('house_number') != '';
    }

    /* ================================================================ COMMANDES */

    /* Crée les commandes manquantes sans jamais écraser la personnalisation. */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new hygeabeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        /*
         * La table cmd impose l'unicité du couple (eqLogic_id, name) : un nom déjà
         * pris par une autre commande ferait échouer l'enregistrement de tout
         * l'équipement. On suffixe plutôt que de laisser planter.
         */
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 1);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);
        if (isset($_options['order'])) {
            $cmd->setOrder($_options['order']);
        }
        if (isset($_options['unite'])) {
            $cmd->setUnite($_options['unite']);
        }
        if (isset($_options['icon'])) {
            $cmd->setDisplay('icon', '<i class="' . $_options['icon'] . '"></i>');
        }
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    /* Les commandes communes à toute adresse. */
    private function createCommands() {
        $order = 0;
        $this->addCmdIfMissing('next', 'Prochaine collecte', 'info', 'string', array(
            'order'    => $order++,
            'template' => 'hygeabe::hygeabe',
        ));
        /* Pas d'historisation : history.value est un varchar(127) et une journée
         * à sept fractions dépasse la limite. L'historique utile est celui de
         * « Jours avant la prochaine collecte ». */
        $this->addCmdIfMissing('summary', 'Résumé', 'info', 'string', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('next_date', 'Date de la prochaine collecte', 'info', 'string', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('next_fractions', 'Déchets de la prochaine collecte', 'info', 'string', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        /*
         * Tout est masqué sauf la tuile : celle-ci dit déjà la date, l'urgence et
         * les déchets concernés. Quatorze widgets empilés dans 230 px, c'est ce
         * qui rendait le dashboard illisible, pas le dessin de la tuile. Les
         * commandes restent disponibles pour les scénarios et les graphiques ;
         * l'utilisateur en réaffiche une s'il la veut.
         */
        $days = $this->addCmdIfMissing('next_days', 'Jours avant la prochaine collecte', 'info', 'numeric', array(
            'isVisible'    => 0,
            'isHistorized' => 1,
            'unite'        => 'j',
            'order'        => $order++,
        ));
        /* Les seuils du coeur colorent le widget et remontent une pastille
         * d'alerte sur la tuile, sans une ligne de gabarit. -1 veut dire
         * « calendrier inconnu » : il reste en dehors, sinon une panne du
         * service passerait pour une collecte du jour. */
        if ($days->getAlert('warningif') == '' && $days->getAlert('dangerif') == '') {
            $days->setAlert('warningif', '#value# == 1');
            $days->setAlert('dangerif', '#value# == 0');
            $days->save();
        }
        $this->addCmdIfMissing('today', 'Collecte aujourd\'hui', 'info', 'binary', array(
            'isVisible' => 0,
            'template'  => 'hygeabe::binLine',
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('tomorrow', 'Collecte demain', 'info', 'binary', array(
            'isVisible'    => 0,
            'isHistorized' => 1,
            'template'     => 'hygeabe::binLine',
            'order'        => $order++,
        ));
        $this->addCmdIfMissing('tomorrow_fractions', 'Déchets à sortir ce soir', 'info', 'string', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('operator', 'Intercommunale', 'info', 'string', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array(
            'order' => $order++,
        ));
    }

    /* Les commandes propres à un type de déchet, créées au vu du calendrier. */
    private function createFractionCommands($_fractions) {
        $order = 20;
        foreach ($_fractions as $slug => $fraction) {
            $this->addCmdIfMissing('fraction::' . $slug . '::date', $fraction['name'] . ' : date', 'info', 'string', array(
                'isVisible' => 0,
                'order'     => $order++,
                'icon'      => $fraction['icon'],
            ));
            $this->addCmdIfMissing('fraction::' . $slug . '::days', $fraction['name'] . ' : jours restants', 'info', 'numeric', array(
                'isVisible'    => 0,
                'isHistorized' => 1,
                'unite'        => 'j',
                'order'        => $order++,
                'icon'         => $fraction['icon'],
            ));
            $this->addCmdIfMissing('fraction::' . $slug . '::tomorrow', $fraction['name'] . ' : demain', 'info', 'binary', array(
                'isVisible' => 0,
                'template'  => 'hygeabe::binLine',
                'order'     => $order++,
                'icon'      => $fraction['icon'],
            ));
        }
    }

    /* Les identifiants de fraction pour lesquels des commandes existent déjà. */
    private function existingFractionSlugs() {
        $slugs = array();
        foreach ($this->getCmd() as $cmd) {
            if (strpos($cmd->getLogicalId(), 'fraction::') !== 0) {
                continue;
            }
            $parts = explode('::', $cmd->getLogicalId());
            if (isset($parts[1]) && !in_array($parts[1], $slugs)) {
                $slugs[] = $parts[1];
            }
        }
        return $slugs;
    }

    /*
     * Supprime toutes les commandes de fractions. Réservé au décochage de
     * l'option : une fraction absente du calendrier du moment ne doit JAMAIS
     * être supprimée, sans quoi les collectes saisonnières (sapins, encombrants)
     * disparaîtraient hors saison et reviendraient avec un nouvel identifiant,
     * laissant muets les scénarios bâtis dessus.
     */
    private function removeFractionCommands() {
        foreach ($this->getCmd() as $cmd) {
            if (strpos($cmd->getLogicalId(), 'fraction::') === 0) {
                $cmd->remove();
            }
        }
    }

    /* =============================================================== CALENDRIER */

    private function cacheKey() {
        return 'hygeabe::calendar::' . $this->getId();
    }

    /* Clé posée après un échec, qui expire d'elle-même : tant qu'elle existe, on
     * ne retente pas. */
    private function retryKey() {
        return 'hygeabe::retry::' . $this->getId();
    }

    /* De quoi reconnaître que l'adresse a changé. */
    private function addressSignature() {
        return $this->getConfiguration('zipcode_id') . '|' . $this->getConfiguration('street_id')
             . '|' . $this->getConfiguration('house_number');
    }

    private function clearCalendar() {
        cache::delete($this->cacheKey());
    }

    /* Le calendrier en cache, ou un tableau vide s'il n'y en a pas encore. */
    public function getCalendar() {
        $value = cache::byKey($this->cacheKey())->getValue('');
        if ($value === '') {
            return array();
        }
        $calendar = json_decode($value, true);
        return is_array($calendar) ? $calendar : array();
    }

    /*
     * Point d'entrée unique du rafraîchissement. Le calendrier n'est relu au
     * service que s'il est absent ou périmé, ou si l'appel est forcé ; les
     * commandes, elles, sont toujours recalculées, car « demain » change de sens
     * à chaque minuit.
     */
    public function update($_force = false) {
        $this->_refreshError = '';

        if (!$this->isConfigured()) {
            $this->_refreshError = __('Adresse incomplète : renseignez la localité, la rue et le numéro.', __FILE__);
            $this->reportProblem($this->_refreshError);
            return array();
        }

        $calendar = $this->getCalendar();
        $age = isset($calendar['fetchedAt']) ? (time() - (int) $calendar['fetchedAt']) : PHP_INT_MAX;

        if ($_force && $age <= self::FORCE_MIN_INTERVAL) {
            // Le calendrier a moins de cinq minutes : le relire n'apprendrait rien.
            $_force = false;
        }
        $waiting = (cache::byKey($this->retryKey())->getValue('') !== '');

        if ($_force || ($age > self::CALENDAR_TTL && !$waiting)) {
            try {
                $fresh = $this->fetchCalendar($calendar);

                if (count($fresh['collections']) == 0 && isset($calendar['collections']) && count($calendar['collections']) > 0) {
                    /*
                     * Le service a répondu, mais sans aucune collecte. Cela arrive
                     * quand l'intercommunale n'a pas encore publié l'année
                     * suivante. Écraser un calendrier valide ferait disparaître la
                     * prochaine collecte du dashboard sans la moindre explication.
                     */
                    $this->reportProblem(__('Le service ne publie plus de collecte pour cette adresse ; le calendrier précédent reste affiché.', __FILE__));
                    cache::set($this->retryKey(), time(), self::RETRY_DELAY);
                } else {
                    $calendar = $fresh;
                    cache::set($this->cacheKey(), json_encode($calendar), 0);
                    cache::delete($this->retryKey());
                    $this->clearProblem();
                }
            } catch (Throwable $e) {
                /*
                 * Le calendrier déjà connu reste affiché : une panne du service ne
                 * doit pas vider les commandes ni faire disparaître la prochaine
                 * collecte du dashboard.
                 */
                $this->_refreshError = $e->getMessage();
                $this->reportProblem($e->getMessage());
                cache::set($this->retryKey(), time(), self::RETRY_DELAY);
                if (empty($calendar)) {
                    throw $e;
                }
            }
        }

        $this->refreshCommands($calendar);
        return $calendar;
    }


    /* Ce qui a empêché la dernière lecture, ou une chaîne vide si tout s'est
     * bien passé. Le contrôleur ajax s'en sert pour ne pas annoncer un succès
     * là où rien n'a été relu. */
    public function getRefreshError() {
        return $this->_refreshError;
    }

    /* Interroge le service et range le calendrier par date. */
    /* Interroge le service et range le calendrier par date. */
    private function fetchCalendar($_previous = array()) {
        $from  = new DateTimeImmutable('today', self::timezone());
        /* Le plancher couvre le rappel le plus lointain. Avec un horizon de sept
         * jours, une collecte n'entrerait au calendrier que le matin du jour où
         * son rappel « une semaine avant » aurait dû partir : celui-ci ne
         * trouverait jamais sa collecte, et se tairait sans une trace. */
        $until = $from->modify('+' . max(self::REMINDER_MAX_DAYS + 7, (int) config::byKey('days_ahead', __CLASS__, 60)) . ' days');

        $items = self::requestAll('/collections', array(
            'zipcodeId'   => $this->getConfiguration('zipcode_id'),
            'streetId'    => $this->getConfiguration('street_id'),
            'houseNumber' => (int) $this->getConfiguration('house_number'),
            'fromDate'    => $from->format('Y-m-d'),
            'untilDate'   => $until->format('Y-m-d'),
        ));

        $parsed = self::parseCollections($items);

        /* Le nom de l'intercommunale ne change jamais pour une adresse donnée :
         * le redemander à chaque lecture doublerait le trafic pour rien. */
        $operator = isset($_previous['operator']) ? $_previous['operator'] : '';
        if ($operator == '') {
            $operator = $this->fetchOperator();
        }

        /*
         * Les déchets desservis, et pas seulement ceux qui tombent dans la
         * fenêtre du calendrier : sapins de Noël, encombrants et verre n'y
         * figurent qu'une fois l'an, ou jamais. Sans eux, leurs commandes
         * n'existeraient qu'à partir du jour de la collecte — impossible d'écrire
         * le scénario à l'avance, et la commande apparaîtrait sans prévenir.
         */
        $fractions = array_merge($this->fetchServedFractions(), $parsed['fractions']);
        ksort($fractions);

        return array(
            'fetchedAt'   => time(),
            'address'     => $this->addressSignature(),
            'operator'    => $operator,
            'collections' => $parsed['collections'],
            'fractions'   => $fractions,
        );
    }

    /*
     * Range les lignes renvoyées par le service en un calendrier par date. Une
     * seule implémentation pour le cron et pour le bouton de test : deux
     * lectures divergentes finiraient par annoncer deux dates différentes.
     */
    public static function parseCollections($_items) {
        $lang  = self::language();
        $dates = array();
        $seen  = array();

        foreach ($_items as $item) {
            if (!isset($item['type']) || $item['type'] != 'collection') {
                continue;
            }
            if (!isset($item['fraction']) || !is_array($item['fraction']) || !isset($item['timestamp'])) {
                continue;
            }
            /* Une collecte annulée pointe la collecte qui la remplace : l'afficher
             * ferait sortir les poubelles un jour où le camion ne passe pas. */
            if (!empty($item['exception']['replacedBy'])) {
                continue;
            }

            $date = self::collectionDate($item['timestamp']);
            if ($date === '') {
                continue;
            }
            $fraction = self::describeFraction($item['fraction'], $lang);

            if (!isset($dates[$date])) {
                $dates[$date] = array();
            }
            // Le service renvoie une ligne par fraction : deux variantes d'un même
            // déchet le même jour ne doivent pas produire deux étiquettes.
            if (isset($dates[$date][$fraction['slug']])) {
                continue;
            }
            $dates[$date][$fraction['slug']] = $fraction;
            $seen[$fraction['slug']] = $fraction;
        }
        // Le service rend ses lignes dans l'ordre, mais rien ne l'y oblige et
        // requestAll concatène plusieurs pages : trier est le seul moyen sûr
        // d'annoncer la bonne « prochaine » collecte.
        ksort($dates);

        $collections = array();
        foreach ($dates as $date => $fractions) {
            /* Ordre alphabétique plutôt que l'ordre de réponse du service : une
               permutation d'un appel à l'autre réécrirait la commande et
               peuplerait l'historique de faux changements. */
            uasort($fractions, function ($_a, $_b) {
                return strcasecmp($_a['name'], $_b['name']);
            });
            $collections[] = array('date' => $date, 'fractions' => array_values($fractions));
        }
        ksort($seen);
        return array('collections' => $collections, 'fractions' => $seen);
    }

    /*
     * La date d'une collecte. Le service date toujours à minuit UTC, mais lire
     * les dix premiers caractères se tromperait d'un jour le jour où il
     * publierait une heure réelle.
     */
    public static function collectionDate($_timestamp) {
        try {
            $date = new DateTimeImmutable($_timestamp);
        } catch (Throwable $e) {
            return '';
        }
        return $date->setTimezone(self::timezone())->format('Y-m-d');
    }

    /*
     * Les types de déchets desservis à l'adresse, calendrier ou non. Le service
     * les décrit exactement comme dans une collecte : même identifiant de
     * pictogramme, donc mêmes identifiants de commande, qu'une fraction vienne
     * d'ici ou du calendrier.
     */
    private function fetchServedFractions() {
        $lang = self::language();
        $served = array();
        try {
            $items = self::requestAll('/fractions', array(
                'zipcodeId'   => $this->getConfiguration('zipcode_id'),
                'streetId'    => $this->getConfiguration('street_id'),
                'houseNumber' => (int) $this->getConfiguration('house_number'),
            ));
            foreach ($items as $item) {
                if (!isset($item['name'])) {
                    continue;
                }
                $fraction = self::describeFraction($item, $lang);
                $served[$fraction['slug']] = $fraction;
            }
        } catch (Throwable $e) {
            // Information de confort : le calendrier vaut d'être enregistré même
            // sans elle, les fractions qu'il contient suffisent à fonctionner.
            log::add(__CLASS__, 'debug', __('Déchets desservis inconnus :', __FILE__) . ' ' . $e->getMessage());
        }
        return $served;
    }

    /* Le nom de l'intercommunale, pour vérifier que l'adresse relève bien d'Hygea. */
    private function fetchOperator() {
        return self::operatorName($this->getConfiguration('zipcode_id'));
    }

    public static function operatorName($_zipcodeId) {
        try {
            $organisation = self::request('/organisations/' . rawurlencode($_zipcodeId));
            return isset($organisation['name']) ? $organisation['name'] : '';
        } catch (Throwable $e) {
            // Information de confort : son absence ne justifie pas de faire
            // échouer une adresse par ailleurs valide.
            log::add(__CLASS__, 'debug', __('Intercommunale inconnue :', __FILE__) . ' ' . $e->getMessage());
            return '';
        }
    }


    /* ========================================================= MISE À JOUR DES CMD */

    private function refreshCommands($_calendar) {
        $collections = isset($_calendar['collections']) ? $_calendar['collections'] : array();
        $today = new DateTimeImmutable('today', self::timezone());

        /*
         * L'heure de bascule évite d'annoncer encore « prochaine collecte :
         * aujourd'hui » à 18 h, alors que le camion est passé le matin. Elle ne
         * vaut que pour « la prochaine » : la commande « Collecte aujourd'hui »,
         * elle, doit rester vraie toute la journée, c'est sur elle que se
         * déclenche le scénario qui fait rentrer les poubelles.
         */
        $rollover = (int) $this->getConfiguration('rollover_hour', 0);
        $passed = ($rollover > 0 && (int) (new DateTimeImmutable('now', self::timezone()))->format('G') >= $rollover);

        $next = null;
        $tomorrow = null;
        $today_collection = null;
        foreach ($collections as $collection) {
            $days = self::daysUntil($collection['date'], $today);
            if ($days < 0) {
                continue;
            }
            if ($days === 0) {
                $today_collection = $collection;
            }
            if ($days === 1) {
                $tomorrow = $collection;
            }
            if ($next === null && !($days === 0 && $passed)) {
                $next = $collection;
                $next['days'] = $days;
            }
        }

        if ($next === null) {
            $this->publishCmd('next', json_encode(array('label' => __('Aucune collecte connue', __FILE__), 'fractions' => array())));
            $this->publishCmd('summary', __('Aucune collecte connue', __FILE__));
            $this->publishCmd('next_date', '');
            $this->publishCmd('next_fractions', '');
            /*
             * -1 et non la chaîne vide : le coeur convertit une valeur vide en 0
             * sur une commande numérique, c'est-à-dire exactement « collecte
             * aujourd'hui ». Un scénario se déclencherait tous les jours.
             */
            $this->publishCmd('next_days', self::UNKNOWN_DAYS);
        } else {
            $names = array();
            $badges = array();
            foreach ($next['fractions'] as $fraction) {
                $names[] = $fraction['name'];
                $badges[] = array(
                    'name'  => $fraction['name'],
                    'color' => $fraction['color'],
                    'text'  => $fraction['textColor'],
                    'icon'  => $fraction['icon'],
                );
            }
            $label = self::humanDate($next['date'], $next['days']);
            /*
             * La tuile reçoit aussi le nombre de jours, pour changer d'aspect à
             * l'approche de la collecte, et la phrase toute faite : un gabarit de
             * plugin ne sait pas traduire ses {{…}}, le coeur les cherche dans son
             * propre catalogue (cmd::toHtml). Le texte doit donc venir d'ici.
             */
            $this->publishCmd('next', json_encode(array(
                'label'     => $label,
                'days'      => $next['days'],
                'countdown' => self::countdownLabel($next['days']),
                'fractions' => $badges,
            )));
            $this->publishCmd('summary', $label . ' : ' . implode(', ', $names));
            $this->publishCmd('next_date', $next['date']);
            $this->publishCmd('next_fractions', implode(', ', $names));
            $this->publishCmd('next_days', $next['days']);
        }

        $this->publishCmd('today', ($today_collection !== null) ? 1 : 0);
        $this->publishCmd('tomorrow', ($tomorrow !== null) ? 1 : 0);
        $this->publishCmd('tomorrow_fractions', ($tomorrow === null) ? '' : implode(', ', array_column($tomorrow['fractions'], 'name')));
        $this->publishCmd('operator', isset($_calendar['operator']) ? $_calendar['operator'] : '');

        $this->refreshFractionCommands($collections, $today, $passed, isset($_calendar['fractions']) ? $_calendar['fractions'] : array());
    }

    private function refreshFractionCommands($_collections, $_today, $_passed, $_fractions) {
        if ($this->getConfiguration('per_fraction') != 1) {
            // L'option vient d'être retirée : les commandes qu'elle avait créées
            // resteraient sinon à l'écran, figées sur leur dernière valeur.
            $this->removeFractionCommands();
            return;
        }
        $this->createFractionCommands($_fractions);

        /*
         * On traite aussi les fractions qui ont une commande mais ne figurent
         * plus au calendrier : leurs valeurs repassent à « inconnu » au lieu de
         * rester bloquées sur le dernier chiffre vu. Les commandes, elles,
         * survivent — voir removeFractionCommands().
         */
        $slugs = array_unique(array_merge(array_keys($_fractions), $this->existingFractionSlugs()));

        foreach ($slugs as $slug) {
            $date = '';
            $days = self::UNKNOWN_DAYS;
            foreach ($_collections as $collection) {
                $remaining = self::daysUntil($collection['date'], $_today);
                if ($remaining < 0 || ($remaining === 0 && $_passed)) {
                    continue;
                }
                if (!in_array($slug, array_column($collection['fractions'], 'slug'))) {
                    continue;
                }
                $date = $collection['date'];
                $days = $remaining;
                break;
            }
            $this->publishCmd('fraction::' . $slug . '::date', $date);
            $this->publishCmd('fraction::' . $slug . '::days', $days);
            $this->publishCmd('fraction::' . $slug . '::tomorrow', ($days === 1) ? 1 : 0);
        }
    }


    /*
     * Écrit une commande, sauf quand elle est déjà vide et le reste. Le coeur
     * traite « valeur vide » comme un changement systématique
     * (eqLogic::checkAndUpdateCmd) : sans ce filtre, une commande vide six jours
     * sur sept — « Déchets à sortir ce soir » — émettrait un évènement toutes les
     * heures et déclencherait vingt-quatre fois par jour le scénario qui
     * l'écoute.
     */
    /*
     * Surtout pas nommée setCmd() : en enregistrant un équipement, le coeur
     * passe le formulaire à utils::a2o(), qui transforme chaque clé reçue en un
     * appel « set » + clé (utils.class.php, vers la ligne 117). La page envoie
     * une clé « cmd » portant les lignes du tableau des commandes : le coeur
     * appelle donc setCmd() sur la classe du plugin, et une méthode privée de ce
     * nom fait mourir l'enregistrement sur une erreur fatale, avant que rien ne
     * soit écrit.
     */
    private function publishCmd($_logicalId, $_value) {
        if ($_value === '') {
            $cmd = $this->getCmd(null, $_logicalId);
            if (is_object($cmd) && $cmd->execCmd() === '') {
                return;
            }
        }
        $this->checkAndUpdateCmd($_logicalId, $_value);
    }

    /* ================================================================== RAPPELS */

    /*
     * Un rappel dit quand prévenir, pour quels déchets, et par quoi. Le « par
     * quoi » est une liste d'actions au format du coeur : le sélecteur de
     * l'interface est celui des scénarios, et scenarioExpression::createAndExec
     * les joue, qu'il s'agisse d'une notification, d'une lampe ou d'un bloc
     * message. Le plugin n'a donc rien à savoir du moyen de prévenir.
     */

    /*
     * Remet la liste des rappels en forme. L'identifiant est ce qui compte : il
     * sert de clé à la mémoire anti-doublon, si bien que déplacer un rappel dans
     * la liste ne doit pas le faire repartir. Il est posé ici, une fois, et ne
     * change plus.
     */
    public static function cleanReminders($_reminders) {
        if (!is_array($_reminders)) {
            return array();
        }
        $clean = array();
        foreach ($_reminders as $reminder) {
            if (!is_array($reminder)) {
                continue;
            }
            $id = isset($reminder['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', (string) $reminder['id']) : '';
            if ($id === '') {
                $id = substr(str_replace('-', '', self::uuid()), 0, 12);
            }

            /* Une heure illisible vaut mieux remplacée que rejetée : preSave ne
             * doit jamais empêcher l'enregistrement, sans quoi la page se
             * rafraîchit et toute la saisie disparaît. */
            $time = isset($reminder['time']) ? trim((string) $reminder['time']) : '';
            if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                $time = self::REMINDER_DEFAULT_TIME;
            }

            $fractions = array();
            if (isset($reminder['fractions']) && is_array($reminder['fractions'])) {
                foreach ($reminder['fractions'] as $slug) {
                    $slug = trim((string) $slug);
                    if ($slug !== '' && !in_array($slug, $fractions)) {
                        $fractions[] = $slug;
                    }
                }
            }

            $actions = array();
            if (isset($reminder['actions']) && is_array($reminder['actions'])) {
                foreach ($reminder['actions'] as $action) {
                    if (!is_array($action) || trim((string) (isset($action['cmd']) ? $action['cmd'] : '')) === '') {
                        // Une ligne d'action vide est une ligne que l'utilisateur
                        // a ajoutée sans la remplir : la garder n'apporte rien.
                        continue;
                    }
                    $actions[] = array(
                        'cmd'     => trim((string) $action['cmd']),
                        'options' => (isset($action['options']) && is_array($action['options'])) ? $action['options'] : array(),
                    );
                }
            }

            $clean[] = array(
                'id'        => $id,
                'enable'    => (isset($reminder['enable']) && $reminder['enable'] == 1) ? 1 : 0,
                'days'      => min(self::REMINDER_MAX_DAYS, max(0, (int) (isset($reminder['days']) ? $reminder['days'] : 1))),
                'time'      => $time,
                'fractions' => $fractions,
                'actions'   => $actions,
            );
        }
        return $clean;
    }

    /* Les rappels enregistrés, remis en forme. */
    public function reminders() {
        return self::cleanReminders($this->getConfiguration('reminders'));
    }

    /* La collecte pour laquelle ce rappel est déjà parti. */
    private function reminderKey($_id) {
        return 'hygeabe::reminded::' . $this->getId() . '::' . $_id;
    }

    private function forgetReminders() {
        foreach ($this->reminders() as $reminder) {
            cache::delete($this->reminderKey($reminder['id']));
            message::removeAll(__CLASS__, $this->reminderKey($reminder['id']));
        }
    }

    /* « la veille à 19:00 » — pour le journal et pour l'interface. */
    public static function reminderLabel($_reminder) {
        if ($_reminder['days'] == 0) {
            $when = __('le jour même', __FILE__);
        } elseif ($_reminder['days'] == 1) {
            $when = __('la veille', __FILE__);
        } else {
            $when = $_reminder['days'] . ' ' . __('jours avant', __FILE__);
        }
        return $when . ' ' . __('à', __FILE__) . ' ' . $_reminder['time'];
    }

    /* Le moment où un rappel doit partir pour une collecte donnée. */
    public static function reminderTarget($_reminder, $_date) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $_date, self::timezone());
        if ($date === false) {
            return null;
        }
        $parts = explode(':', $_reminder['time']);
        return $date->modify('-' . (int) $_reminder['days'] . ' day')
                    ->setTime((int) $parts[0], (int) $parts[1]);
    }

    /*
     * Les déchets de cette collecte que le rappel concerne. Un rappel sans filtre
     * les prend tous ; un rappel filtré qui n'en retient aucun se tait, plutôt
     * que d'annoncer une sortie de poubelle sans dire laquelle.
     */
    private static function reminderFractions($_reminder, $_collection) {
        if (count($_reminder['fractions']) == 0) {
            return $_collection['fractions'];
        }
        $kept = array();
        foreach ($_collection['fractions'] as $fraction) {
            if (in_array($fraction['slug'], $_reminder['fractions'])) {
                $kept[] = $fraction;
            }
        }
        return $kept;
    }

    /* Décrit une collecte pour le gabarit de message et pour l'affichage. */
    private static function describeDue($_collection, $_fractions, $_today) {
        return array(
            'date'      => $_collection['date'],
            'days'      => self::daysUntil($_collection['date'], $_today),
            'fractions' => $_fractions,
        );
    }

    /* La collecte qu'un rappel doit annoncer maintenant, ou null s'il n'y a rien
     * à dire. */
    private function dueCollection($_reminder, $_collections, $_now, $_today) {
        foreach ($_collections as $collection) {
            $fractions = self::reminderFractions($_reminder, $collection);
            if (count($fractions) == 0) {
                continue;
            }
            $target = self::reminderTarget($_reminder, $collection['date']);
            if ($target === null || $_now < $target) {
                continue;
            }
            /*
             * Passé la fenêtre de rattrapage, on laisse tomber : les collectes
             * sont rangées par date, et une échéance vieille de plusieurs jours
             * est celle d'une collecte déjà faite.
             */
            if ($_now->getTimestamp() - $target->getTimestamp() > self::REMINDER_GRACE) {
                continue;
            }
            return self::describeDue($collection, $fractions, $_today);
        }
        return null;
    }

    /*
     * Envoie les rappels dont l'heure est venue. Rien n'est demandé au service :
     * tout se joue sur le calendrier déjà en cache, ce qui rend le rappel
     * insensible à une panne — un calendrier de la veille annonce la même
     * collecte que celui d'aujourd'hui.
     */
    public function checkReminders() {
        $reminders = $this->reminders();
        if (count($reminders) == 0) {
            return;
        }
        $calendar = $this->getCalendar();
        $collections = isset($calendar['collections']) ? $calendar['collections'] : array();
        if (count($collections) == 0) {
            return;
        }
        $now = new DateTimeImmutable('now', self::timezone());
        $today = new DateTimeImmutable('today', self::timezone());

        foreach ($reminders as $reminder) {
            if ($reminder['enable'] != 1 || count($reminder['actions']) == 0) {
                continue;
            }
            $due = $this->dueCollection($reminder, $collections, $now, $today);
            if ($due === null) {
                continue;
            }
            $key = $this->reminderKey($reminder['id']);
            if (cache::byKey($key)->getValue('') === $due['date']) {
                continue;
            }
            /*
             * Marqué avant d'être joué : une action qui meurt sur une erreur
             * fatale ne doit pas faire repartir tout le lot toutes les cinq
             * minutes jusqu'à la collecte.
             */
            cache::set($key, $due['date'], self::REMINDER_MEMORY);
            log::add(__CLASS__, 'info', $this->getHumanName() . ' ' . __('rappel', __FILE__) . ' '
                   . self::reminderLabel($reminder) . ' : ' . self::dateLabel($due['date'])
                   . ' — ' . implode(', ', array_column($due['fractions'], 'name')));
            $this->runReminderActions($reminder, $due);
        }
    }

    /*
     * Joue les actions d'un rappel. scenarioExpression::createAndExec est le
     * primitif du coeur pour une action configurée par l'utilisateur : il accepte
     * une commande comme un bloc, honore les cases « désactivée » et « en tâche
     * de fond » posées par le sélecteur, et retrouve sa cible même après un
     * renommage, la configuration étant enregistrée sous forme d'identifiant
     * (jeedom::fromHumanReadable, appelé par eqLogic.ajax.php).
     *
     * Rend la liste des échecs, pour que le bouton de test puisse les montrer.
     */
    private function runReminderActions($_reminder, $_due, $_report = true) {
        $tags = $this->reminderTags($_due);
        $errors = array();

        foreach ($_reminder['actions'] as $action) {
            $options = is_array($action['options']) ? $action['options'] : array();
            // Le coeur lit la case « désactivée » de la même façon, et la valeur
            // par défaut est « activée ».
            if (isset($options['enable']) && $options['enable'] == 0) {
                continue;
            }
            /*
             * Les jetons du coeur d'abord — variable(), date(), une commande
             * insérée dans le texte — les nôtres ensuite. Dans l'autre sens, un
             * nom de déchet serait relu comme une expression au passage :
             * « Encombrants (sur rendez-vous) » n'a rien à faire dans un
             * évaluateur.
             */
            $scenario = null;
            foreach ($options as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $value = scenarioExpression::setTags($value, $scenario);
                $options[$key] = str_replace(array_keys($tags), array_values($tags), $value);
            }
            try {
                $this->runReminderAction($action['cmd'], $options);
            } catch (Throwable $e) {
                // Une action en échec ne doit pas retenir les suivantes : une
                // notification cassée ne doit pas empêcher la lampe de s'allumer.
                $errors[] = $action['cmd'] . ' — ' . $e->getMessage();
                log::add(__CLASS__, 'error', $this->getHumanName() . ' '
                       . __('action de rappel en échec :', __FILE__) . ' ' . $action['cmd']
                       . ' — ' . $e->getMessage());
            }
        }

        if (!$_report) {
            return $errors;
        }
        /*
         * Un rappel qui ne part plus doit se voir : le journal ne suffit pas, il
         * n'est pas relu par celui qui attendait la notification. Le message
         * disparaît de lui-même au premier rappel qui repasse.
         */
        $key = $this->reminderKey($_reminder['id']);
        message::removeAll(__CLASS__, $key);
        if (count($errors) > 0) {
            message::add(__CLASS__, $this->getHumanName() . ' '
                       . __('rappel en échec :', __FILE__) . ' ' . implode(' ; ', $errors), '', $key);
        }
        return $errors;
    }

    /*
     * Joue une action, et laisse remonter ce qui ne va pas.
     *
     * scenarioExpression::createAndExec est le primitif du coeur pour une action
     * configurée par l'utilisateur, mais il avale ses erreurs quand aucun
     * scénario n'est là pour les recevoir : son catch final passe par setLog(),
     * qui ne fait rien d'un scénario nul, et une commande supprimée n'y lève
     * même pas — elle produit une ligne de journal qui ne va nulle part. Un
     * rappel devenu muet serait alors indétectable, et le bouton « Tester »
     * annoncerait un succès sans avoir rien vérifié.
     *
     * Une commande est donc résolue et jouée ici, où execCmd() lève pour de bon.
     * Les blocs du coeur — message, scénario, variable — restent confiés à
     * createAndExec, seul à savoir les exécuter.
     */
    private function runReminderAction($_expression, $_options) {
        $expression = trim((string) $_expression);

        if (in_array($expression, self::REMINDER_REFUSED)) {
            throw new Exception(__('bloc inutilisable dans un rappel :', __FILE__) . ' ' . $expression);
        }

        /*
         * Une commande est enregistrée sous forme d'identifiant : la forme
         * humaine posée par le sélecteur est convertie par le coeur à
         * l'enregistrement (jeedom::fromHumanReadable, eqLogic.ajax.php). Tout
         * ce qui n'a pas cette forme est un bloc.
         */
        if (!preg_match('/^#(\d+)#$/', $expression, $matches)) {
            // 'source' sert de libellé d'origine au bloc « message » du coeur,
            // qui en fait le nom affiché au centre de messages.
            $_options['source'] = $this->getHumanName();
            scenarioExpression::createAndExec('action', $expression, $_options);
            return;
        }

        $cmd = cmd::byId($matches[1]);
        if (!is_object($cmd)) {
            throw new Exception(__('commande introuvable :', __FILE__) . ' ' . $expression);
        }
        if ($cmd->getType() != 'action') {
            throw new Exception(__('ce n\'est pas une commande d\'action :', __FILE__) . ' ' . $cmd->getHumanName());
        }

        /* En tâche de fond, le coeur relance l'action dans un autre processus :
         * on ne saura rien de son sort, mais c'est exactement ce que
         * l'utilisateur a demandé en cochant la case. */
        if (isset($_options['background']) && $_options['background'] == 1) {
            scenarioExpression::createAndExec('action', $expression, $_options);
            return;
        }

        /* Le coeur retire la case « désactivée » avant d'exécuter ; la case
         * « en tâche de fond » n'a plus d'objet ici. Les substitutions, elles,
         * ont déjà eu lieu — dans le bon ordre. */
        unset($_options['enable'], $_options['background']);
        $cmd->execCmd($_options);
    }

    /* Ce que l'utilisateur peut écrire dans le titre ou le message d'une action. */
    private function reminderTags($_due) {
        $calendar = $this->getCalendar();
        return array(
            '#dechets#'        => implode(', ', array_column($_due['fractions'], 'name')),
            '#collecte#'       => self::humanDate($_due['date'], $_due['days']),
            '#jour#'           => self::dateLabel($_due['date']),
            '#jours#'          => (string) $_due['days'],
            '#adresse#'        => $this->addressLabel(),
            '#equipement#'     => $this->getName(),
            '#intercommunale#' => isset($calendar['operator']) ? $calendar['operator'] : '',
        );
    }

    /* L'adresse en une ligne, telle que l'interface l'affiche. */
    public function addressLabel() {
        $street = trim((string) $this->getConfiguration('street_label'));
        $number = trim((string) $this->getConfiguration('house_number'));
        $zipcode = trim((string) $this->getConfiguration('zipcode_label'));

        $parts = array();
        if ($street !== '') {
            $parts[] = ($number === '') ? $street : $street . ' ' . $number;
        }
        if ($zipcode !== '') {
            $parts[] = $zipcode;
        }
        return implode(', ', $parts);
    }

    /*
     * Ce que chaque rappel enverra, et quand. C'est le seul moyen de vérifier
     * depuis l'interface qu'un rappel est bien réglé : mal réglé, il ne produit
     * aucune erreur — il ne part simplement jamais.
     */
    public function nextReminders() {
        $calendar = $this->getCalendar();
        $collections = isset($calendar['collections']) ? $calendar['collections'] : array();
        $now = new DateTimeImmutable('now', self::timezone());
        $next = array();

        foreach ($this->reminders() as $reminder) {
            /* Des phrases entières, et non des bouts à recoller côté
             * navigateur : « Prochain envoi : aucune action » se lisait plus mal
             * que le défaut qu'il était censé signaler. */
            if ($reminder['enable'] != 1) {
                $next[$reminder['id']] = __('Ce rappel est désactivé.', __FILE__);
                continue;
            }
            if (count($reminder['actions']) == 0) {
                $next[$reminder['id']] = __('Aucune action : ce rappel n\'enverra rien.', __FILE__);
                continue;
            }

            $next[$reminder['id']] = __('Aucune collecte connue ne concerne ce rappel.', __FILE__);
            foreach ($collections as $collection) {
                $fractions = self::reminderFractions($reminder, $collection);
                if (count($fractions) == 0) {
                    continue;
                }
                $target = self::reminderTarget($reminder, $collection['date']);
                if ($target === null || $target < $now) {
                    continue;
                }
                $next[$reminder['id']] = __('Prochain envoi :', __FILE__) . ' '
                    . self::dateLabel($target->format('Y-m-d')) . ' '
                    . __('à', __FILE__) . ' ' . $target->format('H:i') . ' — '
                    . implode(', ', array_column($fractions, 'name'));
                break;
            }
        }
        return $next;
    }

    /*
     * Deux ménages à faire après chaque enregistrement.
     *
     * Enregistrer un rappel ne doit pas le faire partir pour une échéance déjà
     * passée : la fenêtre de rattrapage est là pour une box éteinte, pas pour un
     * rappel qu'on vient d'écrire — ni pour la copie d'un équipement, qui repart
     * avec une mémoire vierge. L'échéance en cours est donc marquée comme
     * traitée, sans rien envoyer.
     *
     * Et le message d'échec d'un rappel supprimé dans l'interface n'a plus
     * personne pour l'effacer : il resterait au centre de messages avec un
     * identifiant qu'aucun code ne peut plus faire correspondre.
     */
    private function settleReminders() {
        $reminders = $this->reminders();
        $calendar = $this->getCalendar();
        $collections = isset($calendar['collections']) ? $calendar['collections'] : array();
        $now = new DateTimeImmutable('now', self::timezone());
        $today = new DateTimeImmutable('today', self::timezone());

        $known = array();
        foreach ($reminders as $reminder) {
            $key = $this->reminderKey($reminder['id']);
            $known[] = $key;
            $due = $this->dueCollection($reminder, $collections, $now, $today);
            if ($due !== null) {
                cache::set($key, $due['date'], self::REMINDER_MEMORY);
            }
        }

        $prefix = 'hygeabe::reminded::' . $this->getId() . '::';
        foreach (message::byPlugin(__CLASS__) as $message) {
            $logicalId = $message->getLogicalId();
            if (strpos($logicalId, $prefix) === 0 && !in_array($logicalId, $known)) {
                $message->remove();
            }
        }
    }

    /*
     * Joue un rappel tout de suite, sur la prochaine collecte qui le concerne.
     * La mémoire anti-doublon n'est pas touchée : essayer un rappel ne doit pas
     * empêcher le vrai de partir le soir venu.
     */
    public function testReminder($_id) {
        foreach ($this->reminders() as $reminder) {
            if ($reminder['id'] !== $_id) {
                continue;
            }
            if (count($reminder['actions']) == 0) {
                throw new Exception(__('Ce rappel ne déclenche aucune action.', __FILE__));
            }

            $calendar = $this->getCalendar();
            $today = new DateTimeImmutable('today', self::timezone());
            foreach (isset($calendar['collections']) ? $calendar['collections'] : array() as $collection) {
                if (self::daysUntil($collection['date'], $today) < 0) {
                    continue;
                }
                $fractions = self::reminderFractions($reminder, $collection);
                if (count($fractions) == 0) {
                    continue;
                }
                $due = self::describeDue($collection, $fractions, $today);
                $errors = $this->runReminderActions($reminder, $due, false);
                if (count($errors) > 0) {
                    throw new Exception(implode(' ; ', $errors));
                }
                return self::dateLabel($due['date']) . ' — ' . implode(', ', array_column($fractions, 'name'));
            }
            throw new Exception(__('Aucune collecte connue ne concerne ce rappel : il n\'y a rien à envoyer.', __FILE__));
        }
        throw new Exception(__('Rappel introuvable : enregistrez l\'équipement avant de le tester.', __FILE__));
    }

    /* ================================================================= MESSAGES */

    private function reportProblem($_text) {
        $text = $this->getHumanName() . ' ' . $_text;
        /*
         * Le cron horaire repasse sur une adresse en panne — ou jamais
         * renseignée — toutes les heures : sans ce contrôle, vingt-quatre
         * erreurs par jour pour une seule cause. Déjà affichée, elle ne
         * descend plus qu'en debug. Le coeur enregistre le texte passé par
         * secureXSS() : la comparaison doit l'être aussi.
         */
        $existing = message::byPluginLogicalId(__CLASS__, 'address' . $this->getId());
        foreach (is_array($existing) ? $existing : array() as $message) {
            if ($message->getMessage() === secureXSS($text)) {
                log::add(__CLASS__, 'debug', $text);
                return;
            }
        }
        log::add(__CLASS__, 'error', $text);
        /*
         * message::save() ne met à jour que la date et le compteur d'un message
         * existant, jamais son texte : sans cet effacement préalable, la
         * première cause resterait affichée pour toujours — « adresse
         * incomplète » longtemps après que l'adresse a été complétée.
         */
        message::removeAll(__CLASS__, 'address' . $this->getId());
        // log::add ne publie rien dans le centre de messages : sans ce message,
        // une adresse en panne reste invisible tant qu'on n'ouvre pas les logs.
        message::add(__CLASS__, $text, '', 'address' . $this->getId());
    }

    private function clearProblem() {
        message::removeAll(__CLASS__, 'address' . $this->getId());
    }

    /* ==================================================================== OUTILS */

    public static function timezone() {
        return new DateTimeZone(config::byKey('timezone', 'core', 'Europe/Brussels'));
    }

    public static function language() {
        return config::byKey('lang', __CLASS__, 'fr');
    }

    /* Nombre de jours pleins entre aujourd'hui et une date ISO. */
    public static function daysUntil($_date, $_today = null) {
        $today = ($_today === null) ? new DateTimeImmutable('today', self::timezone()) : $_today;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $_date, self::timezone());
        if ($date === false) {
            return -1;
        }
        return (int) $today->diff($date)->format('%r%a');
    }

    /* « jeudi 17/09 ». */
    public static function dateLabel($_date) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $_date, self::timezone());
        if ($date === false) {
            return $_date;
        }
        return __(self::$_days[(int) $date->format('w')], __FILE__) . ' ' . $date->format('d/m');
    }

    /* La même date, mais « aujourd'hui » et « demain » quand c'est plus parlant. */
    public static function humanDate($_date, $_days) {
        if ($_days === 0) {
            return __('aujourd\'hui', __FILE__);
        }
        if ($_days === 1) {
            return __('demain', __FILE__);
        }
        return self::dateLabel($_date);
    }

    /* La phrase sous la date. Vide quand la date se suffit à elle-même. */
    public static function countdownLabel($_days) {
        if ($_days === 1) {
            return __('à sortir ce soir', __FILE__);
        }
        if ($_days < 2) {
            return '';
        }
        return __('dans', __FILE__) . ' ' . $_days . ' ' . __('jours', __FILE__);
    }

    /* Ramène une fraction du service à ce dont le plugin a besoin. */
    public static function describeFraction($_fraction, $_lang = 'fr') {
        $names = isset($_fraction['name']) && is_array($_fraction['name']) ? $_fraction['name'] : array();
        $name = '';
        foreach (array($_lang, 'fr', 'nl', 'en', 'de') as $candidate) {
            if (isset($names[$candidate]) && $names[$candidate] != '') {
                $name = $names[$candidate];
                break;
            }
        }
        if ($name == '' && count($names) > 0) {
            $name = reset($names);
        }

        $logo = isset($_fraction['logo']['id']) ? $_fraction['logo']['id'] : '';
        if (isset(self::$_fractions[$logo])) {
            $slug = self::$_fractions[$logo]['slug'];
            $icon = self::$_fractions[$logo]['icon'];
        } else {
            $slug = self::slugify($name);
            $icon = 'fas fa-dumpster';
        }

        return array(
            'slug'      => $slug,
            'name'      => ($name == '') ? $slug : $name,
            'color'     => isset($_fraction['colors']['base']) ? $_fraction['colors']['base'] : (isset($_fraction['color']) ? $_fraction['color'] : '#777777'),
            /* Vide plutôt que blanc quand le service se tait : l'affichage calcule
             * alors une couleur lisible sur le fond. Un blanc d'office rendait ce
             * calcul inopérant, et le jaune des papiers-cartons illisible. */
            'textColor' => isset($_fraction['colors']['text']) ? $_fraction['colors']['text'] : '',
            'icon'      => $icon,
        );
    }

    /* Identifiant de commande lisible et stable tiré d'un libellé. */
    public static function slugify($_text) {
        $text = strtr(mb_strtolower($_text, 'UTF-8'), array(
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y',
        ));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return ($text == '') ? 'fraction' : $text;
    }

    /* ================================================================= API HTTP */

    /* Adresse de base du service, relue depuis le site si elle a changé. */
    public static function apiBase($_refresh = false) {
        $cache = cache::byKey('hygeabe::apiBase');
        $base = ($_refresh || !is_object($cache)) ? '' : $cache->getValue('');

        if ($base == '') {
            $base = self::API_HOST;
            $settings = self::httpGet(self::API_SETTINGS, 5);
            $decoded = ($settings === false) ? null : json_decode($settings, true);

            if (isset($decoded['API']) && strpos($decoded['API'], 'https://') === 0) {
                $base = rtrim($decoded['API'], '/');
                cache::set('hygeabe::apiBase', $base, 604800);
            } else {
                /*
                 * Le site n'a pas répondu : l'adresse par défaut n'est retenue que
                 * quelques minutes. Assez pour qu'une tentative en panne ne
                 * redemande pas trois fois le même fichier, trop peu pour
                 * neutraliser la réparation automatique le jour où l'API déménage
                 * pendant que recycleapp.be est indisponible.
                 */
                cache::set('hygeabe::apiBase', $base, 300);
            }
        }
        return $base . self::API_PATH;
    }

    /*
     * Un appel au service. Une erreur qui peut venir d'un déménagement de l'API
     * déclenche une seule relecture de l'adresse de base, puis un second essai :
     * le plugin se répare tout seul sans intervention.
     */
    public static function call($_path, $_params = array(), $_retry = true) {
        $url = self::apiBase() . $_path;
        if (count($_params) > 0) {
            $url .= '?' . http_build_query($_params);
        }

        $code = 0;
        $body = self::httpGet($url, (int) config::byKey('api_timeout', __CLASS__, 10), $code);

        /*
         * Seules l'absence de réponse et une panne serveur peuvent signer un
         * déménagement de l'API. Les autres codes sont des réponses en bonne et
         * due forme — les confondre avec une panne masquerait une adresse
         * erronée derrière un message de service indisponible.
         */
        if ($body === false || $code >= 500) {
            if ($_retry) {
                log::add(__CLASS__, 'debug', __('Nouvelle lecture de l\'adresse du service après un échec sur :', __FILE__) . ' ' . $_path);
                self::apiBase(true);
                return self::call($_path, $_params, false);
            }
            throw new Exception(__('Le service de collecte ne répond pas', __FILE__)
                . ' (' . (($code == 0) ? __('aucune réponse', __FILE__) : 'HTTP ' . $code) . ').');
        }

        $decoded = ($body === '') ? array() : json_decode($body, true);
        return array('code' => $code, 'body' => is_array($decoded) ? $decoded : array());
    }

    /* Un appel dont on attend une réponse exploitable : tout code inattendu
     * devient une exception au message compréhensible. */
    public static function request($_path, $_params = array()) {
        $response = self::call($_path, $_params);
        $code = $response['code'];

        if ($code == 400) {
            // Le détail du service distingue une adresse fautive d'un paramètre
            // que le plugin envoie mal : sans lui, l'utilisateur irait casser une
            // configuration correcte.
            throw new Exception(__('Le service a refusé la demande : vérifiez la localité, la rue et le numéro.', __FILE__)
                . self::serviceDetail($response['body']));
        }
        if ($code == 404) {
            throw new Exception(__('Adresse inconnue du service : cette rue n\'appartient pas à cette localité.', __FILE__));
        }
        if ($code != 200 && $code != 204) {
            throw new Exception(__('Réponse inattendue du service de collecte :', __FILE__) . ' HTTP ' . $code
                . self::serviceDetail($response['body']));
        }
        return $response['body'];
    }

    /* Le message technique renvoyé par le service, entre parenthèses. */
    private static function serviceDetail($_body) {
        if (!isset($_body['message']) || !is_string($_body['message'])) {
            return '';
        }
        return ' (' . __('réponse du service :', __FILE__) . ' ' . $_body['message'] . ')';
    }

    /*
     * Le service pagine par tranches de 20 par défaut et n'accepte pas plus de
     * 200 : sans cette boucle, un calendrier de deux mois perdrait silencieusement
     * la moitié de ses collectes.
     */
    public static function requestAll($_path, $_params = array()) {
        $items = array();
        $page = 1;
        do {
            $response = self::request($_path, array_merge($_params, array('size' => 200, 'page' => $page)));
            if (!isset($response['items']) || !is_array($response['items'])) {
                // Une page illisible rendrait un calendrier tronqué qui passerait
                // pour complet : le dire, sinon la cause est introuvable.
                log::add(__CLASS__, 'warning', __('Page de résultats illisible, calendrier possiblement incomplet :', __FILE__)
                       . ' ' . $_path . ' (page ' . $page . ')');
                break;
            }
            $items = array_merge($items, $response['items']);
            $pages = isset($response['pages']) ? (int) $response['pages'] : 1;
            $page++;
        } while ($page <= $pages && $page <= 20);

        return $items;
    }

    /* Requête HTTP brute. Renvoie le corps, ou false si rien n'est arrivé. */
    private static function httpGet($_url, $_timeout = 10, &$_code = null) {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL            => $_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => max(3, $_timeout),
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => array(
                'x-consumer: ' . self::API_CONSUMER,
                'x-correlation-id: ' . self::uuid(),
                'Accept: application/json, text/plain, */*',
            ),
            // Une requête anonyme serait indistinguable d'un robot : le service
            // demande de pouvoir identifier ses appelants.
            CURLOPT_USERAGENT      => 'JeedomHygea/' . self::pluginVersion() . ' (+https://github.com/replicatorbe/jeedom-plugin-hygeabe)',
        ));
        $body = curl_exec($curl);
        $_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            log::add(__CLASS__, 'debug', __('Requête en échec :', __FILE__) . ' ' . $_url . ' (' . $error . ')');
            return false;
        }
        return $body;
    }

    public static function pluginVersion() {
        $info = json_decode(file_get_contents(__DIR__ . '/../../plugin_info/info.json'), true);
        return isset($info['pluginVersion']) ? $info['pluginVersion'] : '0';
    }

    /* Identifiant de corrélation, attendu par le service sur chaque appel. */
    public static function uuid() {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /* ============================================================== RECHERCHES */

    /* Les localités portant un code postal. */
    public static function searchZipcodes($_query) {
        $response = self::request('/zipcodes', array('q' => $_query, 'size' => 50));
        $items = isset($response['items']) ? $response['items'] : array();
        $lang = self::language();

        $result = array();
        foreach ($items as $item) {
            $name = '';
            if (isset($item['names'][0]) && is_array($item['names'][0])) {
                $names = $item['names'][0];
                $name = isset($names[$lang]) ? $names[$lang] : reset($names);
            }
            $result[] = array(
                'id'   => isset($item['id']) ? $item['id'] : '',
                'name' => trim((isset($item['code']) ? $item['code'] . ' ' : '') . $name),
            );
        }
        return $result;
    }

    /* Les rues d'une localité dont le nom contient la recherche. */
    public static function searchStreets($_query, $_zipcodeId) {
        $response = self::request('/streets', array('q' => $_query, 'zipcodes' => $_zipcodeId, 'size' => 100));
        $items = isset($response['items']) ? $response['items'] : array();
        $lang = self::language();

        $result = array();
        foreach ($items as $item) {
            $names = isset($item['names']) && is_array($item['names']) ? $item['names'] : array();
            $name = isset($names[$lang]) ? $names[$lang] : (count($names) > 0 ? reset($names) : '');
            $result[] = array(
                'id'   => isset($item['id']) ? $item['id'] : '',
                'name' => $name,
            );
        }
        return $result;
    }

    /*
     * Vérifie une adresse avant de l'enregistrer. Le service accepte n'importe
     * quel numéro sans broncher : le seul contrôle utile est la cohérence du
     * couple localité / rue, puis la présence réelle de collectes.
     */
    public static function testAddress($_zipcodeId, $_streetId, $_houseNumber) {
        if ($_zipcodeId == '' || $_streetId == '' || (int) $_houseNumber < 1) {
            throw new Exception(__('Renseignez la localité, la rue et le numéro avant de tester.', __FILE__));
        }

        /*
         * Le numéro est obligatoire ici : sans lui le service répond 409 même
         * pour une adresse parfaitement valide. Le 409 sert justement à
         * distinguer les causes, il ne faut donc pas le traiter comme une panne.
         */
        $validation = self::call('/streets/validate', array(
            'zipcodeId'   => $_zipcodeId,
            'streetId'    => $_streetId,
            'houseNumber' => (int) $_houseNumber,
        ));
        if ($validation['code'] == 409 && isset($validation['body']['zipcodeStreetValid'])
            && $validation['body']['zipcodeStreetValid'] === false) {
            throw new Exception(__('Cette rue n\'appartient pas à cette localité : refaites la recherche après avoir choisi la bonne localité.', __FILE__));
        }
        if ($validation['code'] != 204 && $validation['code'] != 200) {
            throw new Exception(__('Le service n\'a pas reconnu cette adresse.', __FILE__)
                . ' (HTTP ' . $validation['code'] . ')');
        }

        $from = new DateTimeImmutable('today', self::timezone());
        $items = self::requestAll('/collections', array(
            'zipcodeId'   => $_zipcodeId,
            'streetId'    => $_streetId,
            'houseNumber' => (int) $_houseNumber,
            'fromDate'    => $from->format('Y-m-d'),
            'untilDate'   => $from->modify('+60 days')->format('Y-m-d'),
        ));

        $parsed = self::parseCollections($items);
        if (count($parsed['collections']) == 0) {
            throw new Exception(__('Adresse valide, mais aucune collecte publiée pour les deux mois à venir. Vérifiez le numéro de maison.', __FILE__));
        }

        $first = $parsed['collections'][0];
        $days = self::daysUntil($first['date']);
        $operator = self::operatorName($_zipcodeId);
        if ($operator == '') {
            $operator = __('inconnue', __FILE__);
        }

        return array(
            'operator' => $operator,
            'summary'  => __('Intercommunale :', __FILE__) . ' ' . $operator . '. '
                        . __('Prochaine collecte le', __FILE__) . ' ' . self::humanDate($first['date'], $days)
                        . ' : ' . implode(', ', array_column($first['fractions'], 'name')) . '.',
        );
    }
}


class hygeabeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'refresh':
                /*
                 * Pas de forçage : un scénario qui appellerait cette commande en
                 * boucle contournerait la politique d'une lecture par jour. Les
                 * commandes sont recomposées, et le calendrier relu s'il a vieilli.
                 */
                $eqLogic->update();
                /*
                 * update() ne lève pas quand un calendrier est déjà en cache :
                 * sans ce relais, un scénario appelant cette commande croirait
                 * son calendrier relu alors que le service est en panne.
                 */
                if ($eqLogic->getRefreshError() != '') {
                    throw new Exception($eqLogic->getRefreshError());
                }
                return true;
        }
        return true;
    }
}
