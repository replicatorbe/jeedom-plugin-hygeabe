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
     * pas le solliciter davantage. */
    const CALENDAR_TTL = 72000;

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

        // Le service refuse un numéro qui n'est pas un entier positif : « 3A »
        // ferait échouer chaque rafraîchissement avec une erreur 400 obscure.
        $number = trim((string) $this->getConfiguration('house_number'));
        if ($number !== '') {
            $this->setConfiguration('house_number', max(1, (int) $number));
        }
        $this->setConfiguration('street_id', trim((string) $this->getConfiguration('street_id')));
        $this->setConfiguration('zipcode_id', trim((string) $this->getConfiguration('zipcode_id')));

        /*
         * Aucune exception ici : le coeur crée l'équipement avec son seul nom,
         * toute validation rendrait le bouton « Ajouter » inutilisable. Une
         * adresse incomplète est signalée par un message dans le centre de
         * messages au moment du rafraîchissement.
         */
    }

    public function postSave() {
        $this->createCommands();

        if (!$this->isConfigured()) {
            return;
        }
        try {
            $this->update(true);
        } catch (Throwable $e) {
            // L'enregistrement ne doit pas échouer parce que le service est
            // indisponible : l'adresse est valide, le cron réessaiera.
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        /*
         * DB::remove() met l'id à null avant postRemove : le cache du calendrier
         * doit donc être nettoyé tant que l'identifiant est encore lisible.
         */
        $this->clearCalendar();
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
        if (isset($_options['generic_type'])) {
            $cmd->setGeneric_type($_options['generic_type']);
        }
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
        $this->addCmdIfMissing('summary', 'Résumé', 'info', 'string', array(
            'isVisible'    => 0,
            'isHistorized' => 1,
            'order'        => $order++,
        ));
        $this->addCmdIfMissing('next_date', 'Date de la prochaine collecte', 'info', 'string', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('next_fractions', 'Déchets de la prochaine collecte', 'info', 'string', array(
            'isVisible' => 0,
            'order'     => $order++,
        ));
        $this->addCmdIfMissing('next_days', 'Jours avant la prochaine collecte', 'info', 'numeric', array(
            'isHistorized' => 1,
            'unite'        => 'j',
            'order'        => $order++,
        ));
        $this->addCmdIfMissing('today', 'Collecte aujourd\'hui', 'info', 'binary', array(
            'order' => $order++,
        ));
        $this->addCmdIfMissing('tomorrow', 'Collecte demain', 'info', 'binary', array(
            'isHistorized' => 1,
            'order'        => $order++,
        ));
        $this->addCmdIfMissing('tomorrow_fractions', 'Déchets à sortir ce soir', 'info', 'string', array(
            'order' => $order++,
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
                'isHistorized' => 1,
                'unite'        => 'j',
                'order'        => $order++,
                'icon'         => $fraction['icon'],
            ));
            $this->addCmdIfMissing('fraction::' . $slug . '::tomorrow', $fraction['name'] . ' : demain', 'info', 'binary', array(
                'order' => $order++,
                'icon'  => $fraction['icon'],
            ));
        }
    }

    /*
     * Supprime les commandes de fractions qui ne figurent plus au calendrier.
     * Appelée uniquement avec un calendrier non vide : une liste vide prise pour
     * argent comptant effacerait toutes les commandes de l'équipement le jour où
     * le service ne répond pas.
     */
    private function removeStaleFractionCommands($_slugs) {
        foreach ($this->getCmd() as $cmd) {
            if (strpos($cmd->getLogicalId(), 'fraction::') !== 0) {
                continue;
            }
            $parts = explode('::', $cmd->getLogicalId());
            if (!isset($parts[1]) || in_array($parts[1], $_slugs)) {
                continue;
            }
            $cmd->remove();
        }
    }

    /* =============================================================== CALENDRIER */

    private function cacheKey() {
        return 'hygeabe::calendar::' . $this->getId();
    }

    private function clearCalendar() {
        $cache = cache::byKey($this->cacheKey());
        if (is_object($cache)) {
            $cache->remove();
        }
    }

    /* Le calendrier en cache, ou un tableau vide s'il n'y en a pas encore. */
    public function getCalendar() {
        $cache = cache::byKey($this->cacheKey());
        $value = is_object($cache) ? $cache->getValue('') : '';
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
        if (!$this->isConfigured()) {
            $this->reportProblem(__('Adresse incomplète : renseignez la localité, la rue et le numéro.', __FILE__));
            return array();
        }

        $calendar = $this->getCalendar();
        $stale = !isset($calendar['fetchedAt']) || (time() - (int) $calendar['fetchedAt']) > self::CALENDAR_TTL;

        if ($_force || $stale) {
            try {
                $calendar = $this->fetchCalendar();
                cache::set($this->cacheKey(), json_encode($calendar), 0);
                $this->clearProblem();
            } catch (Throwable $e) {
                /*
                 * Le calendrier déjà connu reste affiché : une panne du service ne
                 * doit pas vider les commandes ni faire disparaître la prochaine
                 * collecte du dashboard.
                 */
                $this->reportProblem($e->getMessage());
                if (empty($calendar)) {
                    throw $e;
                }
            }
        }

        $this->refreshCommands($calendar);
        return $calendar;
    }

    /* Interroge le service et range le calendrier par date. */
    private function fetchCalendar() {
        $lang     = self::language();
        $from     = new DateTimeImmutable('today', self::timezone());
        $until    = $from->modify('+' . max(7, (int) config::byKey('days_ahead', __CLASS__, 60)) . ' days');
        $zipcode  = $this->getConfiguration('zipcode_id');
        $street   = $this->getConfiguration('street_id');

        $items = self::requestAll('/collections', array(
            'zipcodeId'   => $zipcode,
            'streetId'    => $street,
            'houseNumber' => (int) $this->getConfiguration('house_number'),
            'fromDate'    => $from->format('Y-m-d'),
            'untilDate'   => $until->format('Y-m-d'),
        ));

        $dates = array();
        $seen  = array();
        foreach ($items as $item) {
            if (!isset($item['type']) || $item['type'] != 'collection' || !isset($item['fraction'])) {
                continue;
            }
            /* Une collecte annulée pointe la collecte qui la remplace : l'afficher
             * ferait sortir les poubelles un jour où le camion ne passe pas. */
            if (!empty($item['exception']['replacedBy'])) {
                continue;
            }
            if (!isset($item['timestamp'])) {
                continue;
            }
            $date = substr($item['timestamp'], 0, 10);
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
        ksort($dates);

        $collections = array();
        foreach ($dates as $date => $fractions) {
            $collections[] = array('date' => $date, 'fractions' => array_values($fractions));
        }

        return array(
            'fetchedAt'   => time(),
            'operator'    => $this->fetchOperator(),
            'collections' => $collections,
            'fractions'   => $seen,
        );
    }

    /* Le nom de l'intercommunale, pour vérifier que l'adresse relève bien d'Hygea. */
    private function fetchOperator() {
        try {
            $organisation = self::request('/organisations/' . rawurlencode($this->getConfiguration('zipcode_id')));
            return isset($organisation['name']) ? $organisation['name'] : '';
        } catch (Throwable $e) {
            // Information de confort : son absence ne justifie pas de tout arrêter.
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
         * aujourd'hui » à 18 h, alors que le camion est passé le matin.
         */
        $rollover = (int) $this->getConfiguration('rollover_hour', 0);
        $passed = ($rollover > 0 && (int) (new DateTimeImmutable('now', self::timezone()))->format('G') >= $rollover);

        $next = null;
        $tomorrow = null;
        foreach ($collections as $collection) {
            $days = self::daysUntil($collection['date'], $today);
            if ($days < 0 || ($days === 0 && $passed)) {
                continue;
            }
            if ($next === null) {
                $next = $collection;
                $next['days'] = $days;
            }
            if ($days === 1) {
                $tomorrow = $collection;
            }
        }

        if ($next === null) {
            $this->checkAndUpdateCmd('next', json_encode(array('label' => __('Aucune collecte connue', __FILE__), 'fractions' => array())));
            $this->checkAndUpdateCmd('summary', __('Aucune collecte connue', __FILE__));
            $this->checkAndUpdateCmd('next_date', '');
            $this->checkAndUpdateCmd('next_fractions', '');
            $this->checkAndUpdateCmd('next_days', '');
        } else {
            $names = array();
            $badges = array();
            foreach ($next['fractions'] as $fraction) {
                $names[] = $fraction['name'];
                $badges[] = array('name' => $fraction['name'], 'color' => $fraction['color'], 'text' => $fraction['textColor']);
            }
            $label = self::humanDate($next['date'], $next['days']);
            $this->checkAndUpdateCmd('next', json_encode(array('label' => $label, 'fractions' => $badges)));
            $this->checkAndUpdateCmd('summary', $label . ' : ' . implode(', ', $names));
            $this->checkAndUpdateCmd('next_date', $next['date']);
            $this->checkAndUpdateCmd('next_fractions', implode(', ', $names));
            $this->checkAndUpdateCmd('next_days', $next['days']);
        }

        $this->checkAndUpdateCmd('today', ($next !== null && $next['days'] === 0) ? 1 : 0);
        $this->checkAndUpdateCmd('tomorrow', ($tomorrow !== null) ? 1 : 0);
        $this->checkAndUpdateCmd('tomorrow_fractions', ($tomorrow === null) ? '' : implode(', ', array_column($tomorrow['fractions'], 'name')));
        $this->checkAndUpdateCmd('operator', isset($_calendar['operator']) ? $_calendar['operator'] : '');

        $this->refreshFractionCommands($collections, $today, $passed, isset($_calendar['fractions']) ? $_calendar['fractions'] : array());
    }

    private function refreshFractionCommands($_collections, $_today, $_passed, $_fractions) {
        /*
         * Un calendrier vide ne prouve rien : c'est peut-être le service qui
         * n'a pas répondu. On ne supprime donc jamais de commande sur cette base.
         */
        if (empty($_fractions)) {
            return;
        }
        if ($this->getConfiguration('per_fraction') != 1) {
            // L'option vient d'être retirée : les commandes qu'elle avait créées
            // resteraient sinon à l'écran, figées sur leur dernière valeur.
            $this->removeStaleFractionCommands(array());
            return;
        }
        $this->createFractionCommands($_fractions);
        $this->removeStaleFractionCommands(array_keys($_fractions));

        foreach ($_fractions as $slug => $fraction) {
            $date = '';
            $days = '';
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
            $this->checkAndUpdateCmd('fraction::' . $slug . '::date', $date);
            $this->checkAndUpdateCmd('fraction::' . $slug . '::days', $days);
            $this->checkAndUpdateCmd('fraction::' . $slug . '::tomorrow', ($days === 1) ? 1 : 0);
        }
    }

    /* ================================================================= MESSAGES */

    private function reportProblem($_text) {
        $text = $this->getHumanName() . ' ' . $_text;
        log::add(__CLASS__, 'error', $text);
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
            'textColor' => isset($_fraction['colors']['text']) ? $_fraction['colors']['text'] : '#FFFFFF',
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
            if ($settings !== false) {
                $decoded = json_decode($settings, true);
                if (isset($decoded['API']) && strpos($decoded['API'], 'https://') === 0) {
                    $base = rtrim($decoded['API'], '/');
                }
            }
            cache::set('hygeabe::apiBase', $base, 604800);
        }
        return $base . self::API_PATH;
    }

    /*
     * Un appel au service. Une erreur qui peut venir d'un déménagement de l'API
     * déclenche une seule relecture de l'adresse de base, puis un second essai :
     * le plugin se répare tout seul sans intervention.
     */
    public static function request($_path, $_params = array(), $_retry = true) {
        $url = self::apiBase() . $_path;
        if (count($_params) > 0) {
            $url .= '?' . http_build_query($_params);
        }

        $code = 0;
        $body = self::httpGet($url, (int) config::byKey('api_timeout', __CLASS__, 10), $code);

        /*
         * Seules l'absence de réponse et une panne serveur peuvent signer un
         * déménagement de l'API. Un 404 est une réponse en bonne et due forme —
         * le confondre avec une panne masquerait une adresse erronée derrière un
         * message de service indisponible.
         */
        if ($body === false || $code >= 500) {
            if ($_retry) {
                log::add(__CLASS__, 'debug', __('Nouvelle lecture de l\'adresse du service après un échec sur :', __FILE__) . ' ' . $_path);
                self::apiBase(true);
                return self::request($_path, $_params, false);
            }
            throw new Exception(__('Le service de collecte ne répond pas', __FILE__)
                . ' (' . (($code == 0) ? __('aucune réponse', __FILE__) : 'HTTP ' . $code) . ').');
        }
        if ($code == 400) {
            throw new Exception(__('Le service a refusé la demande : vérifiez la localité, la rue et le numéro.', __FILE__));
        }
        if ($code == 404) {
            throw new Exception(__('Adresse inconnue du service : cette rue n\'appartient pas à cette localité.', __FILE__));
        }
        if ($code != 200 && $code != 204) {
            throw new Exception(__('Réponse inattendue du service de collecte :', __FILE__) . ' HTTP ' . $code);
        }

        $decoded = json_decode($body, true);
        if ($code == 204 || $body === '') {
            return array();
        }
        if (!is_array($decoded)) {
            throw new Exception(__('Réponse illisible du service de collecte.', __FILE__));
        }
        return $decoded;
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
        if ($_zipcodeId == '' || $_streetId == '' || $_houseNumber == '') {
            throw new Exception(__('Renseignez la localité, la rue et le numéro avant de tester.', __FILE__));
        }
        self::request('/streets/validate', array('zipcodeId' => $_zipcodeId, 'streetId' => $_streetId));

        $from = new DateTimeImmutable('today', self::timezone());
        $items = self::requestAll('/collections', array(
            'zipcodeId'   => $_zipcodeId,
            'streetId'    => $_streetId,
            'houseNumber' => (int) $_houseNumber,
            'fromDate'    => $from->format('Y-m-d'),
            'untilDate'   => $from->modify('+60 days')->format('Y-m-d'),
        ));
        if (count($items) == 0) {
            throw new Exception(__('Adresse valide, mais aucune collecte publiée pour les deux mois à venir. Vérifiez le numéro de maison.', __FILE__));
        }

        $organisation = self::request('/organisations/' . rawurlencode($_zipcodeId));
        $operator = isset($organisation['name']) ? $organisation['name'] : __('inconnue', __FILE__);

        $lang = self::language();
        $first = null;
        $names = array();
        foreach ($items as $item) {
            if (!isset($item['type']) || $item['type'] != 'collection' || !isset($item['timestamp'])) {
                continue;
            }
            $date = substr($item['timestamp'], 0, 10);
            if ($first === null) {
                $first = $date;
            }
            if ($date != $first) {
                continue;
            }
            $fraction = self::describeFraction($item['fraction'], $lang);
            $names[$fraction['slug']] = $fraction['name'];
        }

        return array(
            'operator' => $operator,
            'summary'  => __('Intercommunale :', __FILE__) . ' ' . $operator . '. '
                        . __('Prochaine collecte le', __FILE__) . ' ' . self::humanDate($first, self::daysUntil($first))
                        . ' : ' . implode(', ', $names) . '.',
        );
    }
}

class hygeabeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'refresh':
                // update() lève une exception détaillant la cause d'un échec.
                $eqLogic->update(true);
                return true;
        }
        return true;
    }
}
