<?php

class Entry extends BaseObject implements DatedObject {
  public static $_table = 'Entry';

  private $lexems = null;
  private $trees = null;

  const STRUCT_STATUS_NEW = 1;
  const STRUCT_STATUS_IN_PROGRESS = 2;
  const STRUCT_STATUS_UNDER_REVIEW = 3;
  const STRUCT_STATUS_DONE = 4;

  const STRUCTURIST_ID_ANY = -1;
  const STRUCTURIST_ID_NONE = 0;

  public static $STRUCT_STATUS_NAMES = [
    self::STRUCT_STATUS_NEW => 'neîncepută',
    self::STRUCT_STATUS_IN_PROGRESS => 'în lucru',
    self::STRUCT_STATUS_UNDER_REVIEW => 'așteaptă moderarea',
    self::STRUCT_STATUS_DONE => 'terminată',
  ];

  static function createAndSave($description) {
    $e = Model::factory('Entry')->create();
    $e->description = $description;
    $e->structStatus = self::STRUCT_STATUS_NEW;
    $e->save();
    return $e;
  }

  function _clone($cloneDefinitions, $cloneLexems, $cloneTrees) {
    $e = $this->parisClone();
    $e->structStatus = self::STRUCT_STATUS_NEW;
    $e->structuristId = 0;
    $e->save();

    if ($cloneDefinitions) {
      EntryDefinition::copy($this->id, $e->id, 1);
    }

    if ($cloneLexems) {
      EntryLexem::copy($this->id, $e->id, 1);
    }

    if ($cloneTrees) {
      TreeEntry::copy($this->id, $e->id, 2);
    }

    return $e;
  }

  function getLexems() {
    if ($this->lexems === null) {
      $this->lexems = Model::factory('Lexem')
                    ->table_alias('l')
                    ->select('l.*')
                    ->join('EntryLexem', ['l.id', '=', 'el.lexemId'], 'el')
                    ->where('el.entryId', $this->id)
                    ->order_by_asc('el.id')
                    ->find_many();
    }
    return $this->lexems;
  }

  function getLexemIds() {
    $result = [];
    foreach ($this->getLexems() as $l) {
      $result[] = $l->id;
    }
    return $result;
  }

  function getTrees() {
    if ($this->trees === null) {
      $this->trees = Model::factory('Tree')
                   ->table_alias('t')
                   ->select('t.*')
                   ->join('TreeEntry', ['te.treeId', '=', 't.id'], 'te')
                   ->where('te.entryId', $this->id)
                   ->order_by_asc('te.id')
                   ->find_many();
    }
    return $this->trees;
  }

  function getTreeIds() {
    $result = [];
    foreach ($this->getTrees() as $t) {
      $result[] = $t->id;
    }
    return $result;
  }

  function loadMeanings() {
    foreach ($this->getTrees() as $t) {
      $t->getMeanings();
    }
  }

  // Returns the description up to the first parenthesis (if any).
  function getShortDescription() {
    return preg_split('/\s+\(/', $this->description)[0];
  }

  /**
   * Returns the list of lexemes sorted with main lexems first. Excludes duplicate lexems
   * and lexemes that have a form equal to the entry's description.
   **/
  function getPrintableLexems() {
    $map = [];
    foreach ($this->getLexems() as $l) {
      $map[$l->formNoAccent] = $l;
    }

    unset($map[$this->getShortDescription()]);

    usort($map, function($a, $b) {
      return ($a->main < $b->main);
    });

    return $map;
  }

  public static function loadUnassociated() {
    $query = 'select * from Entry ' .
           'where id not in (select entryId from EntryLexem) ' .
           'or id not in (select entryId from EntryDefinition)';
    return Model::factory('Entry')
      ->raw_query($query)
      ->find_many();
  }

  /**
   * For every set of entries having the same case-sensitive description, load one of them at random.
   */
  public static function loadAmbiguous() {
    // The key here is to create a subquery of all the case-insensitiv descriptions
    // appearing at least twice.
    $query = 'select * from Entry ' .
           'join (select description d from Entry group by description having count(*) > 1) dup ' .
           'on description = d ' .
           'group by binary description ' .
           'having count(*) > 1 ' .
           'order by description';
    return Model::factory('Entry')->raw_query($query)->find_many();
  }
  
  static function searchInflectedForms($cuv, $hasDiacritics, $oldOrthography, $useMemcache = true) {
    if ($useMemcache) {
      $key = sprintf("inflected_%d_%d_%s", (int)$hasDiacritics, (int)$oldOrthography, $cuv);
      $result = mc_get($key);
      if ($result) {
        return $result;
      }
    }

    $field = $hasDiacritics ? 'formNoAccent' : 'formUtf8General';
    if ($oldOrthography) {
      $cuv = StringUtil::convertOrthography($cuv);
    }

    $entries = Model::factory('Entry')
             ->table_alias('e')
             ->select('e.*')
             ->distinct()
             ->join('EntryLexem', 'e.id = el.entryId', 'el')
             ->join('InflectedForm', 'el.lexemId = f.lexemId', 'f')
             ->where("f.$field", $cuv)
             ->order_by_asc('e.description')
             ->find_many();

    if ($useMemcache) {
      mc_set($key, $entries);
    }
    return $entries;
  }

  // Returns the first main lexeme (or the first lexeme if none of them are main).
  function getMainLexem() {
    return Model::factory('Lexem')
      ->table_alias('l')
      ->select('l.*')
      ->join('EntryLexem', ['l.id', '=', 'el.lexemId'], 'el')
      ->where('el.entryId', $this->id)
      ->order_by_desc('l.main')
      ->order_by_asc('el.id')
      ->find_one();
  }

  /**
   * Validates an entry for correctness. Returns an array of { field => array of errors }.
   * $original: the original, unmodified entry
   **/
  function validate($original) {
    $errors = [];

    if (!mb_strlen($this->description)) {
      $errors['description'][] = 'Descrierea nu poate fi vidă.';
    }

    if (($this->structStatus == Entry::STRUCT_STATUS_DONE) &&
        ($original->structStatus != Entry::STRUCT_STATUS_DONE) &&
        !util_isModerator(PRIV_EDIT)) {
      $errors['structStatus'][] = 'Doar moderatorii pot marca structurarea drept terminată. ' .
                                'Vă rugăm să folosiți valoarea „așteaptă moderarea”.';
    }

    if ($this->structuristId != $original->structuristId) {
      if (util_isModerator(PRIV_ADMIN)) {
        // Admins can modify this field
      } else if (($original->structuristId == session_getUserId()) &&
                 !$this->structuristId) {
        // Structurists can remove themselves
      } else if (!$original->structuristId &&
                 ($this->structuristId == session_getUserId()) &&
                 ($original->structStatus == Entry::STRUCT_STATUS_NEW) &&
                 ($this->structStatus == Entry::STRUCT_STATUS_IN_PROGRESS)) {
        // The system silently assigns structurists when they start the process
      } else if (!$original->structuristId &&
                 ($this->structuristId == session_getUserId()) &&
                 ($original->structStatus == Entry::STRUCT_STATUS_IN_PROGRESS) &&
                 ($this->structStatus == Entry::STRUCT_STATUS_IN_PROGRESS)) {
        // Structurists can claim orphan entries
      } else {
        $errors['structuristId'] = 'Nu puteți modifica structuristul, dar puteți (1) revendica ' .
                                 'o intrare în lucru fără structurist sau (2) renunța la ' .
                                 'o intrare dacă vi se pare prea greu de structurat.';
      }
    }

    return $errors;
  }

  public function deleteEmptyTrees() {
    foreach ($this->getTrees() as $t) {
      $meaning = Meaning::get_by_treeId($t->id);
      if (!$meaning) {
        $t->delete();
      }
    }
  }

  public function mergeInto($otherId) {
    $this->deleteEmptyTrees();

    EntryDefinition::copy($this->id, $otherId, 1);
    EntryLexem::copy($this->id, $otherId, 1);
    TreeEntry::copy($this->id, $otherId, 2);

    $visuals = Visual::get_all_by_entryId($this->id);
    foreach ($visuals as $v) {
      $v->entryId = $otherId;
      $v->save();
    }

    $vts = VisualTag::get_all_by_entryId($this->id);
    foreach ($vts as $vt) {
      $vt->entryId = $otherId;
      $vt->save();
    }

    $this->delete();
  }

  public function delete() {
    EntryDefinition::delete_all_by_entryId($this->id);
    EntryLexem::delete_all_by_entryId($this->id);
    TreeEntry::delete_all_by_entryId($this->id);

    // orphan Visuals and VisualTags
    $visuals = Visual::get_all_by_entryId($this->id);
    foreach ($visuals as $v) {
      $v->entryId = 0;
      $v->save();
    }

    $vts = VisualTag::get_all_by_entryId($this->id);
    foreach ($vts as $vt) {
      $vt->entryId = 0;
      $vt->save();
    }

    Log::warning("Deleted entry {$this->id} ({$this->description})");
    parent::delete();
  }

  public function __toString() {
    return $this->description;
  }

}

?>
