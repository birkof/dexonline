<?php

require_once("../phplib/util.php");
util_assertModerator(PRIV_WOTD);
util_assertNotMirror();

$defId = Request::get('defId');

$status = WordOfTheDay::getStatus($defId);

if (is_null($status)) {
  $wotd = Model::factory('WordOfTheDay')->create();
  $wotd->userId = session_getUserId();
  $wotd->priority = 0;
  $wotd->save();

  $wotdr = Model::factory('WordOfTheDayRel')->create();
  $wotdr->refId = $defId;
  $wotdr->refType = 'Definition';
  $wotdr->wotdId = $wotd->id;
  $wotdr->save();

  $d = Definition::get_by_id($defId);
  
  Log::info("Added WotD, ID = {$wotd->id}, definition ID = {$d->id}, lexicon = {$d->lexicon}");
}

$where_to_go = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';
header("Location: {$where_to_go}");
?>
