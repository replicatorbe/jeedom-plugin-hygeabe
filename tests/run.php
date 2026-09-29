<?php
/* Jeu d'essai hors ligne des rappels et de la confirmation « C’est fait ».
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base, ni service Recycle!. Les remplaçants
 * du coeur sont dans tests/stub.php.
 *
 * Le scénario rejoué est celui qui a motivé la fonction, repris d'une
 * configuration Home Assistant : la veille à 17 h une notification avec un
 * bouton « C’est fait » ; à 18 h, 19 h et 19 h 30 un affichage TV, à 19 h 45
 * une annonce vocale, SEULEMENT si personne n'a encore confirmé ; le lendemain
 * tout repart de zéro. Aucune de ces erreurs ne lève quoi que ce soit en
 * production — un rappel qui parle à tort, ou qui se tait à tort, ne se voit
 * que le soir où il fallait sortir la poubelle. D'où ce fichier.
 *
 * Les dates sont calculées à partir d'aujourd'hui : le bouton « Tester » lit
 * l'horloge réelle, et un calendrier écrit en dur finirait par être passé. */

require_once __DIR__ . '/stub.php';

/* La classe charge le coeur de Jeedom en première ligne ; hors installation, on
 * la recopie sans ce require. La copie va dans le dossier temporaire et non à
 * côté de l'originale : un déploiement lancé au même moment l'emporterait dans
 * Jeedom. Elle est effacée en sortant, même sur une erreur fatale. */
$original = __DIR__ . '/../core/class/hygeabe.class.php';
$copy = tempnam(sys_get_temp_dir(), 'hygeabe') . '.php';
$source = preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '', file_get_contents($original));
file_put_contents($copy, $source);
register_shutdown_function(function () use ($copy) {
    if (file_exists($copy)) { unlink($copy); }
    if (file_exists(substr($copy, 0, -4))) { unlink(substr($copy, 0, -4)); }
});
require_once $copy;

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    if ($_actual === $_expected) {
        $passed++;
        printf("  ok     %s\n", $_label);
        return;
    }
    $failed++;
    printf("  ECHEC  %s\n         obtenu  : %s\n         attendu : %s\n", $_label,
        var_export($_actual, true), var_export($_expected, true));
}

function section($_title) {
    echo "\n" . $_title . "\n";
}

/* Donne accès aux méthodes privées : on teste la logique, pas la visibilité. */
function invoke($_object, $_method, $_args = array()) {
    $m = new ReflectionMethod('hygeabe', $_method);
    $m->setAccessible(true);
    return $m->invokeArgs($_object, $_args);
}

/* Les commandes d'action jouées depuis le dernier appel, par identifiant. */
function sent() {
    $ids = array_map(function ($_s) { return $_s['id']; }, cmd::$sent);
    cmd::$sent = array();
    return $ids;
}

/* Un instant, à partir d'un jour et d'une heure. */
function at($_day, $_time) {
    list($h, $m) = explode(':', $_time);
    return $_day->setTime((int) $h, (int) $m);
}

function lastLog() {
    return (count(log::$lines) > 0) ? log::$lines[count(log::$lines) - 1] : '';
}

/* Une ligne du journal contenant ce texte a-t-elle été écrite depuis $_from ? */
function logged($_text, $_from) {
    foreach (array_slice(log::$lines, $_from) as $line) {
        if (strpos($line, $_text) !== false) { return true; }
    }
    return false;
}

$tz = new DateTimeZone('Europe/Brussels');
$today = new DateTimeImmutable('today', $tz);
/* La collecte étudiée, dans dix jours, et sa veille. */
$collecte = $today->modify('+10 days');
$veille = $collecte->modify('-1 day');
$collecte2 = $collecte->modify('+7 days');
$lointaine = $collecte2->modify('+14 days');

function fraction($_slug, $_name) {
    return array('slug' => $_slug, 'name' => $_name, 'color' => '#777777', 'textColor' => '', 'icon' => 'fas fa-trash');
}

$calendar = array(
    'fetchedAt'   => time(),
    'operator'    => 'HYGEA',
    'collections' => array(
        array('date' => $collecte->format('Y-m-d'),  'fractions' => array(fraction('pmc', 'PMC'), fraction('residuel', 'Déchets résiduels'))),
        array('date' => $collecte2->format('Y-m-d'), 'fractions' => array(fraction('organique', 'Déchets organiques'))),
        array('date' => $lointaine->format('Y-m-d'), 'fractions' => array(fraction('papier', 'Papiers-cartons'))),
    ),
    'fractions'   => array(),
);
cache::set('hygeabe::calendar::21', json_encode($calendar));

function reminder($_id, $_days, $_time, $_cmd, $_skip = null) {
    $r = array('id' => $_id, 'enable' => 1, 'days' => $_days, 'time' => $_time,
               'fractions' => array(), 'actions' => array(array('cmd' => '#' . $_cmd . '#', 'options' => array())));
    // null : un rappel écrit avant que la case existe, la clé est absente.
    if ($_skip !== null) {
        $r['skip_if_done'] = $_skip;
    }
    return $r;
}

$eq = new hygeabe();
$eq->configuration = array(
    'rollover_hour' => 0,
    'per_fraction'  => 0,
    'reminders'     => array(
        reminder('r17',   1, '17:00', 100, 0),  // notification avec le bouton « C’est fait »
        reminder('r18',   1, '18:00', 200, 1),  // TV
        reminder('r19',   1, '19:00', 201, 1),  // TV
        reminder('r1930', 1, '19:30', 202, 1),  // TV
        reminder('r1945', 1, '19:45', 300, 1),  // annonce vocale
        reminder('rOld',  1, '18:00', 400),     // écrit avant la case
        reminder('rMatin', 0, '07:00', 500, 1), // le jour même, au réveil
    ),
    'done_actions'  => array(
        array('cmd' => '#900#', 'options' => array('title' => 'Hygea', 'message' => 'Merci : #dechets# pour #jour#, rappels coupés.')),
    ),
);

/* ========================================================== NORMALISATION */
section('Normalisation de la case « Ne pas envoyer si c\'est déjà fait »');

$clean = hygeabe::cleanReminders(array(
    array('id' => 'a', 'time' => '18:00'),
    array('id' => 'b', 'time' => '18:00', 'skip_if_done' => '1'),
    array('id' => 'c', 'time' => '18:00', 'skip_if_done' => 1),
    array('id' => 'd', 'time' => '18:00', 'skip_if_done' => '0'),
    array('id' => 'e', 'time' => '18:00', 'skip_if_done' => 'oui'),
));
check('absente (ancien rappel) : 0', $clean[0]['skip_if_done'], 0);
check('« 1 » du formulaire : 1', $clean[1]['skip_if_done'], 1);
check('entier 1 : 1', $clean[2]['skip_if_done'], 1);
check('« 0 » : 0', $clean[3]['skip_if_done'], 0);
check('valeur fantaisiste : 0', $clean[4]['skip_if_done'], 0);

check('cleanActions : pas un tableau → vide', hygeabe::cleanActions(''), array());
check('cleanActions : lignes vides retirées', hygeabe::cleanActions(array(
    array('cmd' => '  ', 'options' => array()),
    'n\'importe quoi',
    array('cmd' => ' #12# ', 'options' => 'pas un tableau'),
)), array(array('cmd' => '#12#', 'options' => array())));

$form = new hygeabe();
$form->configuration = array('done_actions' => array(array('cmd' => ''), array('cmd' => '#7#', 'options' => array('message' => 'ok'))));
$form->preSave();
check('preSave remet en forme les actions de confirmation', $form->getConfiguration('done_actions'),
      array(array('cmd' => '#7#', 'options' => array('message' => 'ok'))));
$form = new hygeabe();
$form->preSave();
check('preSave sans actions de confirmation : liste vide', $form->getConfiguration('done_actions'), array());

/* ================================================================ LA VEILLE */
section('La veille au soir');

$eq->checkReminders(at($veille, '16:55'));
check('16 h 55 : rien encore', sent(), array());

$eq->checkReminders(at($veille, '17:02'));
check('17 h : la notification part', sent(), array(100));

invoke($eq, 'refreshCommands', array($calendar, at($veille, '17:05')));
check('avant confirmation, « Poubelles sorties » vaut 0', $eq->published['sortie_faite_etat'], 0);

check('« C’est fait » à 17 h 10 accepté', $eq->confirmDone(at($veille, '17:10')), true);
check('la date de la collecte est retenue, pas un booléen', $eq->doneDate(), $collecte->format('Y-m-d'));
check('« Poubelles sorties » passe à 1', $eq->published['sortie_faite_etat'], 1);
$thanks = cmd::$sent;
check('l\'action de confirmation est jouée', sent(), array(900));
check('ses jetons sont remplacés', $thanks[0]['options']['message'],
      'Merci : PMC, Déchets résiduels pour ' . hygeabe::dateLabel($collecte->format('Y-m-d')) . ', rappels coupés.');

$next = $eq->nextReminders(at($veille, '17:15'));
check('Prochain envoi d\'un rappel coché : « ne partira pas »', strpos($next['r18'], 'ne partira pas') !== false, true);
check('Prochain envoi d\'un rappel non coché : rien de tel', strpos($next['rOld'], 'ne partira pas'), false);

$from = count(log::$lines);
$eq->checkReminders(at($veille, '18:02'));
check('18 h : la TV se tait, l\'ancien rappel sans la case part', sent(), array(400));
check('le silence est journalisé', logged('non envoyé : poubelles déjà sorties', $from), true);

$count = count(log::$lines);
$eq->checkReminders(at($veille, '18:07'));
check('18 h 05 : pas de nouvelle ligne de journal pour le même silence', count(log::$lines), $count);

check('deuxième « C’est fait » : accepté', $eq->confirmDone(at($veille, '18:30')), true);
check('mais l\'action de confirmation n\'est pas rejouée', sent(), array());

$eq->checkReminders(at($veille, '19:02'));
check('19 h : silence', sent(), array());

/* ================================================================ ANNULATION */
section('Annulation (« Pas encore sorties »)');

$eq->cancelDone();
check('la date est oubliée', $eq->doneDate(), '');
check('« Poubelles sorties » repasse à 0', $eq->published['sortie_faite_etat'], 0);

$eq->checkReminders(at($veille, '19:32'));
check('19 h 30 : la TV reparle, 18 h et 19 h ne repartent pas en rafale', sent(), array(202));

$eq->cancelDone();
check('annuler sans confirmation : rien ne casse', $eq->doneDate(), '');

check('nouvelle confirmation à 19 h 40', $eq->confirmDone(at($veille, '19:40')), true);
check('après une annulation, l\'action de confirmation est rejouée', sent(), array(900));

$eq->checkReminders(at($veille, '19:47'));
check('19 h 45 : l\'annonce vocale se tait', sent(), array());

/* ================================================================== TESTER */
section('Bouton Tester');

$summary = $eq->testReminder('r1945');
check('Tester envoie pour de vrai, case ou pas', sent(), array(300));
check('et le dit', strpos($summary, 'le test ignore la case') !== false, true);
$summary = $eq->testReminder('r17');
sent();
check('pas de mention sur un rappel sans la case', strpos($summary, 'ignore'), false);

/* ================================================================ LENDEMAIN */
section('Le jour de la collecte, puis la retombée');

$eq->checkReminders(at($collecte, '07:02'));
check('le jour même à 7 h, coché : silence', sent(), array());

invoke($eq, 'refreshCommands', array($calendar, at($collecte, '10:00')));
check('heure de bascule 0 : toujours 1 le jour de la collecte', $eq->published['sortie_faite_etat'], 1);

invoke($eq, 'refreshCommands', array($calendar, at($collecte->modify('+1 day'), '00:05')));
check('le lendemain à minuit : retombée à 0', $eq->published['sortie_faite_etat'], 0);
check('et la date est oubliée, sans cron de remise à zéro', $eq->doneDate(), '');

$eq->checkReminders(at($collecte2->modify('-1 day'), '17:02'));
check('collecte suivante, 17 h : la notification', sent(), array(100));
$eq->checkReminders(at($collecte2->modify('-1 day'), '18:02'));
check('collecte suivante, 18 h : la TV parle de nouveau', sent(), array(200, 400));

/* L'heure de bascule fait retomber plus tôt. */
$eq->configuration['rollover_hour'] = 9;
cache::set('hygeabe::done::21', $collecte2->format('Y-m-d'));
invoke($eq, 'refreshCommands', array($calendar, at($collecte2, '08:30')));
check('bascule à 9 h : encore 1 à 8 h 30', $eq->published['sortie_faite_etat'], 1);
invoke($eq, 'refreshCommands', array($calendar, at($collecte2, '09:05')));
check('bascule à 9 h : 0 à 9 h 05', $eq->published['sortie_faite_etat'], 0);
check('bascule à 9 h : date oubliée', $eq->doneDate(), '');

/* Après la bascule, « C’est fait » vise la collecte suivante, comme la tuile. */
check('après la bascule : « C’est fait » vise la collecte suivante ?',
      $eq->confirmDone(at($collecte2, '10:00')), false);
check('… qui est à plus d\'une semaine : ignoré et journalisé',
      strpos(lastLog(), 'aucun rappel ne la vise encore') !== false, true);
check('… sans rien retenir', $eq->doneDate(), '');
$eq->configuration['rollover_hour'] = 0;

/* ======================================================= RIEN À CONFIRMER */
section('Rien à confirmer');

$empty = new hygeabe();
$empty->configuration = array('reminders' => array());
cache::delete('hygeabe::calendar::21');
check('sans calendrier : « C’est fait » refusé sans exception', $empty->confirmDone(at($veille, '17:00')), false);
check('et journalisé', strpos(lastLog(), 'aucune collecte connue') !== false, true);
check('rien n\'est retenu', $empty->doneDate(), '');
check('aucune action jouée', sent(), array());
cache::set('hygeabe::calendar::21', json_encode($calendar));

/* ============================================= ACTIONS DE CONFIRMATION */
section('Actions de confirmation en échec');

$eq->configuration['done_actions'] = array(
    array('cmd' => '#404#', 'options' => array()),
    array('cmd' => 'wait', 'options' => array('duration' => 60)),
    array('cmd' => '#901#', 'options' => array()),
);
cache::delete('hygeabe::done::21');
message::$messages = array();
check('confirmation malgré des actions cassées', $eq->confirmDone(at($veille, '17:10')), true);
check('une action cassée ne retient pas la suivante', sent(), array(901));
check('l\'échec va au centre de messages', isset(message::$messages['hygeabe::doneActions::21']), true);
check('le bloc « Attendre » est refusé', strpos(message::$messages['hygeabe::doneActions::21'], 'bloc inutilisable') !== false, true);
check('la confirmation tient quand même', $eq->doneDate(), $collecte->format('Y-m-d'));

/* ======================================================= BASCULE ET CIBLE */
section('Ce que vise « C’est fait » autour de la bascule');

$eq->configuration['done_actions'] = array();
$eq->configuration['rollover_hour'] = 9;
cache::delete('hygeabe::done::21');
check('jour de collecte, 8 h, bascule à 9 h : vise la collecte du jour',
      $eq->confirmDone(at($collecte, '08:00')) && $eq->doneDate() === $collecte->format('Y-m-d'), true);
cache::delete('hygeabe::done::21');
check('jour de collecte, 10 h : vise la suivante, comme la tuile',
      $eq->confirmDone(at($collecte, '10:00')) && $eq->doneDate() === $collecte2->format('Y-m-d'), true);
$eq->configuration['rollover_hour'] = 0;
cache::delete('hygeabe::done::21');
check('sans bascule, le jour même à 20 h : vise encore la collecte du jour',
      $eq->confirmDone(at($collecte, '20:00')) && $eq->doneDate() === $collecte->format('Y-m-d'), true);
sent();

echo "\n" . $passed . ' essai(s) réussi(s), ' . $failed . " en échec.\n";
exit($failed === 0 ? 0 : 1);
