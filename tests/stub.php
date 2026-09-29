<?php
/* Remplaçants minimaux du coeur de Jeedom, pour rejouer la classe du plugin
 * hors d'une installation : ni base de données, ni cache partagé, ni
 * notification envoyée pour de vrai.
 *
 * Ils ne simulent que ce dont les rappels et la confirmation « C’est fait »
 * ont besoin. Le but n'est pas de tester Jeedom, mais de pouvoir rejouer une
 * soirée de collecte heure par heure et vérifier qui parle et qui se tait. */

/* Le coeur pose le fuseau dans core.inc.php ; PHP en ligne de commande est en
 * UTC. Sans cela, minuit à Bruxelles se relit la veille à 22 h et les essais
 * de date échouent pour une raison qui n'a rien à voir avec le plugin. */
date_default_timezone_set('Europe/Brussels');

function __($_text, $_file = null) { return $_text; }
function secureXSS($_text) { return $_text; }

class config {
    public static $values = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        $k = $_plugin . '::' . $_key;
        return isset(self::$values[$k]) ? self::$values[$k] : $_default;
    }
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message, $_logicalId = '') {
        self::$lines[] = $_level . ' : ' . $_message;
    }
}

class cacheItem {
    public $value = null;
    public function getValue($_default = null) { return $this->value === null ? $_default : $this->value; }
    public function remove() { $this->value = null; }
}

class cache {
    public static $store = array();
    public static function byKey($_key) {
        if (!isset(self::$store[$_key])) { self::$store[$_key] = new cacheItem(); }
        return self::$store[$_key];
    }
    public static function set($_key, $_value, $_lifetime = 0) {
        self::byKey($_key)->value = $_value;
    }
    public static function delete($_key) {
        unset(self::$store[$_key]);
    }
}

class message {
    public static $messages = array();
    public static function add($_plugin, $_message, $_action = '', $_logicalId = '') {
        self::$messages[$_logicalId] = $_message;
    }
    public static function removeAll($_plugin, $_logicalId = '') {
        unset(self::$messages[$_logicalId]);
    }
    public static function byPlugin($_plugin) { return array(); }
    public static function byPluginLogicalId($_plugin, $_logicalId) { return array(); }
}

/* Les blocs du coeur (message, scénario...) : on note qu'ils ont été joués. */
class scenarioExpression {
    public static $blocks = array();
    public static function setTags($_value, $_scenario = null) { return $_value; }
    public static function createAndExec($_type, $_expression, $_options) {
        self::$blocks[] = array('expression' => $_expression, 'options' => $_options);
    }
}

class cmd {
    public $id = 0;
    public $type = 'action';
    /* Tout ce que les commandes d'action ont reçu : c'est lui qu'on interroge
     * pour savoir si un rappel est parti. */
    public static $sent = array();
    public function getType() { return $this->type; }
    public function getHumanName() { return '#' . $this->id . '#'; }
    public function execCmd($_options = array()) {
        self::$sent[] = array('id' => $this->id, 'options' => $_options);
        return true;
    }
    public static function byId($_id) {
        // 404 : une commande supprimée depuis l'écriture du rappel.
        if ((int) $_id === 404) { return null; }
        $c = new static();
        $c->id = (int) $_id;
        return $c;
    }
    public static function byEqLogicIdCmdName($_id, $_name) { return null; }
}

class eqLogic {
    public $published = array();
    public $configuration = array();
    public function getId() { return 21; }
    public function getHumanName() { return '[Maison][Collectes]'; }
    public function getName() { return 'Collectes'; }
    public function getIsEnable() { return 1; }
    public function getConfiguration($_key = '', $_default = '') {
        return isset($this->configuration[$_key]) ? $this->configuration[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) {
        $this->configuration[$_key] = $_value;
        return $this;
    }
    public $display = array();
    public function getDisplay($_key = '', $_default = '') {
        return isset($this->display[$_key]) ? $this->display[$_key] : $_default;
    }
    public function setDisplay($_key, $_value) { $this->display[$_key] = $_value; }
    public function getCmd($_type = null, $_logicalId = null) {
        return ($_logicalId === null) ? array() : null;
    }
    public function checkAndUpdateCmd($_logicalId, $_value, $_when = null) {
        $this->published[$_logicalId] = $_value;
    }
    public static function byType($_type, $_onlyEnable = false) { return array(); }
}
