<?php

error_reporting(E_ERROR | E_WARNING | E_PARSE);

// creating a new user item
function default_user_item_factory($idUser, $item, $insertId) {
   $array = array(
      'ID'     => $insertId,
      'idUser' => $idUser,
      'idItem' => $item['data']->ID,
      'iScore' => 0,
      'iScoreComputed' => 0,
      'iScoreDiffManual' => 0,
      'sScoreDiffComment' => '',
      'nbSubmissionsAttempts' => 0,
      'nbTasksTried' => 0,
      'nbTasksSolved' => 0,
      'nbChildrenValidated' => 0,
      'bValidated' => 0,
      'bFinished' => 0,
      'nbTasksWithHelp' => 0,
      'nbHintsCached' => 0,
      'nbCorrectionsRead' => 0,
      'iPrecision' => 0,
      'iAutonomy' => 0,
      'sStartDate' => NULL,
      'sValidationDate' => NULL,
      'sFinishDate' => NULL,
      'sLastActivityDate' => NULL,
      'bRanked' => 0,
      'sAllLangProg' => '*',
      'sType' => $item['data']->sType,
      'sSupportedLangProg' => $item['data']->sSupportedLangProg,
      'bHintsAllowed' => $item['data']->bHintsAllowed,
      'bAccessSolutions' => $item['data']->bAccessSolutions,
      'bGrayedAccess' => $item['data']->bGrayedAccess,
      'iVersion' => 0 // TODO: really?
   );
   return array(
     'data' => (object) $array
   );
}

// type is "inserted" or "updated"
function createMissingUserItems($db, &$serverChanges, $type) {
   $userId = $_SESSION['login']['ID'];
   $items_ids = array_keys((array) $serverChanges['items'][$type]);
   $users_items_ids = array();
   if (isset($serverChanges['users_items']) && isset($serverChanges['users_items'][$type]) && count($serverChanges['users_items'][$type])) {
      foreach($serverChanges['users_items'][$type] as $id => $values) {
         $users_items_ids[] = $values['data']->idItem;
      }
   }
   $diff = array_diff($items_ids, $users_items_ids);
   // one big insert request with multiple values
   if (count($diff)) {
      $request = "INSERT IGNORE INTO `users_items` (`ID`, `idUser`, `idItem`) VALUES";
      $first = true;
      foreach ($diff as $nothing => $idItem) {
         if (!$first) {$request .= ",";}
         $ID = getRandomID();
         $first = false;
         $request .= " ('".$ID."', '".$userId."', '".$idItem."')";
         $serverChanges['users_items'][$type][$ID] = default_user_item_factory($userId, $serverChanges['items'][$type][$idItem], $ID);
      }
      $request .= ";";
      $db->exec($request);
   }
}

function generateUserItemToken(&$userItem, $tokenGenerator, $item) {
   static $token_fields = array(
      'bHasAccessCorrection' => null,
      'bSubmissionPossible'  => null,
      'bHintsAllowed'        => null, // from item
      'bHasSolvedTask'       => null,
      'nbHintsGiven'         => null,
      'idItem'               => null,
      'itemUrl'              => null,
      'idUser'               => null,
      'bIsAdmin'             => null,
      'bIsDefault'           => null,
      'sSupportedLangProg'   => null,
      'sLogin'               => null,
   );
   if ($item['data']->bUsesAPI && ($item['data']->sType == 'Task' || $item['data']->sType == 'Course'|| $item['data']->sType == 'Presentation')) {
      if (!isset($item['data']->bGrayedAccess) || $item['data']->bGrayedAccess) {
         $userItem['data']->sToken = ''; return;
      }
      $params = array_replace($_SESSION['login'], (array)$userItem['data'], (array)$item['data']);
      $params = array_intersect_key($params, $token_fields);
      $params['idItem'] = $item['data']->sTextId;
      $params['itemUrl'] = $item['data']->sUrl;
      $params['idItemLocal'] = $item['data']->ID;
      $params['idUser'] = $_SESSION['login']['ID'];
      $params['nbHintsGiven'] = $userItem['data']->nbHintsCached;
      $params['bHintPossible'] = true;
      // platform needs idTask:
      $params['id'.$item['data']->sType] = $params['idItem'];
      $params['bHasAccessCorrection'] = $item['data']->bAccessSolutions;
      $params['bReadAnswers'] = true;
      $token = $tokenGenerator->encodeJWS($params);
      $userItem['data']->sToken = $token;
   } else {
      $userItem['data']->sToken = '';
   }
}

// returns all items corresponding to users items in serverChanges
function fetchItemsIfMissing($serverChanges, $db) {
   if (!isset($serverChanges['items'])) {
      $items = array();
   } else {
      $items = array_replace($serverChanges['items']['updated'], $serverChanges['items']['inserted']);
   }
   if (count($items) !=
         ((isset($serverChanges['users_items']['updated'])
            ? count($serverChanges['users_items']['updated']) : 0)
         + (isset($serverChanges['users_items']['inserted'])
            ? count($serverChanges['users_items']['inserted']) : 0)
         )) {
      if (!isset($serverChanges['items'])) {
         $items_ids = array();
      } else {
         $items_ids = array_keys((array) $serverChanges['items']['updated']);
         $items_ids = array_merge($items_ids, array_keys((array) $serverChanges['items']['inserted']));
      }
      $users_items_ids = array();
      if (isset($serverChanges['users_items']) && count($serverChanges['users_items']['updated'])) {
         foreach($serverChanges['users_items']['updated'] as $id => $values) {
            $users_items_ids[] = $values['data']->idItem;
         }
      }
      if (isset($serverChanges['users_items']) && count($serverChanges['users_items']['inserted'])) {
         foreach($serverChanges['users_items']['inserted'] as $id => $values) {
            $users_items_ids[] = $values['data']->idItem;
         }
      }
      $missing_item_ids = array_diff($users_items_ids, $items_ids);
      if (count($missing_item_ids)) {
         $query = 'select `items`.`ID`, `items`.`sUrl`, `items`.`bUsesAPI`, `items`.`bHintsAllowed`, `items`.`sSupportedLangProg`, `items`.`sTextId`, MAX(`groups_items`.`bCachedAccessSolutions`) as `bAccessSolutions`, IF (MAX(`groups_items`.`bCachedFullAccess` + `groups_items`.`bCachedPartialAccess`) = 0, 1, 0) as `bGrayedAccess`, `items`.`sType` from `items` join `groups_items` on `groups_items`.`idItem` = `items`.`ID` join `groups_ancestors` on `groups_ancestors`.`idGroupAncestor` = `groups_items`.`idGroup` where `groups_ancestors`.`idGroupChild` = '.$_SESSION['login']['idGroupSelf'].' AND (';
         $first = true;
         foreach ($missing_item_ids as $id) {
            if (!$first) {
               $query .= ' OR ';
            }
            $first = false;
            $query .= '`items`.`ID` = \''.$id.'\'';
         }
         $query .= ') GROUP BY `items`.`ID`;';
         $sth = $db->prepare($query);
         $sth->execute();
         $results = $sth->fetchAll(PDO::FETCH_OBJ);
         foreach($results as $res) {
            $items[$res->ID] = array('data' => $res);
         }
      }
   }
   return $items;
}

function hasMissingUserItems($serverChanges, $mode) {
   if (!isset($serverChanges['items']) || !isset($serverChanges['items'][$mode])) {
      return false;
   }
   if (!isset($serverChanges['users_items']) || !isset($serverChanges['users_items'][$mode])) {
      return true;
   }
   // first implementation used count, but in some cases the request for users_items also fetches
   // users_items for items to which the user doesn't have access anymore, and in vicious cases,
   // the count was the same, although some users_items were missing for actually fetched items
   $users_items_items = [];
   foreach ((array) $serverChanges['users_items']['inserted'] as $userItem) {
      $users_items_items[$userItem['data']->idItem] = true;
   }
   foreach ((array) $serverChanges['items'][$mode] as $item) {
      if (!isset($users_items_items[$item['data']->ID])) {
         return true;
      }
   }
   return false;
}

// returns an array containing the idItem of all the missing user_items
function handleUserItems($db, $minServerVersion, &$serverChanges, &$serverCounts, $params) {
   global $config;
   if (hasMissingUserItems($serverChanges, 'inserted')) {
      createMissingUserItems($db, $serverChanges, 'inserted');
   }
   if (hasMissingUserItems($serverChanges, 'updated')) {
      createMissingUserItems($db, $serverChanges, 'updated');
   }
   // no need for tokens when fetching levels
   if (!isset($serverChanges['users_items']) || ! isset($params["requests"]["algorea"]['type']) || $params["requests"]["algorea"]['type'] == 'getAllLevels') {
      return;
   }
   $items = fetchItemsIfMissing($serverChanges, $db);
   // then we generate tokens for the user items corresponding to tasks and courses
   require_once(dirname(__FILE__)."/TokenGenerator.php");
   $tokenGenerator = new TokenGenerator($config->platform->private_key, $config->platform->name);
   if (isset($serverChanges['users_items']) && isset($serverChanges['users_items']['updated'])) {
      foreach ((array) $serverChanges['users_items']['updated'] as $userItem) {
         generateUserItemToken($userItem, $tokenGenerator, $items[$userItem['data']->idItem]);
      }
   }
   if (isset($serverChanges['users_items']) && isset($serverChanges['users_items']['inserted'])) {
      foreach ((array) $serverChanges['users_items']['inserted'] as $userItem) {
         generateUserItemToken($userItem, $tokenGenerator, $items[$userItem['data']->idItem]);
      }
   }
}

function checkExpandedItemResults(&$serverChanges) {
   /* specific to ExpandedItems request: we have marked the records with either
    * modelName (from the request handling expanded items requested at curent
    * version) or modelName_zero (requested at version 0). This function removes
    * records inserted by requests at version 0 that have been deleted by
    * request at current version.
   */
   $tables_to_check = array(
      "items" => true,
      "items_items" => true,
      "items_strings" => true,
      "groups_items" => true
   );
   foreach ($serverChanges as $modelName => $modelServerChanges) {
      if ($tables_to_check[$modelName]) {
         $deleted_records = array();
         foreach ($modelServerChanges["deleted"] as $recordID => $value) {
            if (property_exists($value['data'], 'requestName') && $value['data']->requestName == $modelName) {
               if (isset($modelServerChanges["inserted"][$recordID]) && 
                     property_exists($modelServerChanges["inserted"][$recordID]['data'], 'requestName') &&
                     $modelServerChanges["inserted"][$recordID]['data']->requestName == $modelName.'_zero') {
                  unset($modelServerChanges["inserted"][$recordID]);
               }
            }
         }
      }
   }
}

function syncAddCustomServerChanges($db, $minServerVersion, &$serverChanges, &$serverCounts, $params) {
   if (!empty($_SESSION) && !empty($_SESSION['login'])) {
      $serverChanges['loginData'] = $_SESSION['login'];
   } else {
      $serverChanges['loginData'] = null;
   }
   if (empty($_SESSION) || empty($_SESSION['login']) || !isset($_SESSION['login']['ID']) || empty($serverChanges) || (isset($params["requests"]["algorea"]['admin']) && $params["requests"]["algorea"]['admin'] == true)) {
      checkExpandedItemResults($serverChanges);
      return;
   }
   handleUserItems($db, $minServerVersion, $serverChanges, $serverCounts, $params);
   if (!isset($_SESSION['postLoginSyncDone']) || !$_SESSION['postLoginSyncDone']) {
      $_SESSION['postLoginSyncDone'] = true;
   }
}

function handleClientUserItems($db, $minServerVersion, &$clientChanges) {
   $queryCondition = '';
   $first = true;
   foreach($clientChanges['users_items']['updated'] as $id => &$data) {
      if (!$first) {
         $queryCondition .= ' OR ';
      }
      $first = false;
      //unset($data['data']['sToken']);
      if ($_SESSION['login']['ID'] != $data['data']['idUser']) {
         //die ('error: asked to change user_item with idUser='.$data['data']['idUser'].' while session contains idUser='.$_SESSION['login']['ID']);
      }
   }
}


function syncAddCustomClientChanges($db, $minServerVersion, &$clientChanges) {
   if (!isset($_SESSION)) {
      session_start();
   }
   if (empty($_SESSION) || empty($_SESSION['login']) || !$_SESSION['login']['ID'] || empty($clientChanges)) {
      return;
   }
   if (!isset($clientChanges['users_items']) || empty($clientChanges['users_items']['updated'])) {
      return;
   } else {
      handleClientUserItems($db, $minServerVersion, $clientChanges);
   }
}

function getAncestorsCondition($db, $params, $prefix, $field) {
   $ancestors_condition = "(";
   $first = true;
   if ($params["requests"]["algorea"]['ancestors']) {
      foreach ($params["requests"]["algorea"]["ancestors"] as $ID => $item) {
         if ($ID == 'minVersion' || $ID == 'resetMinVersion') { continue; }
         if (!$first) {
            $ancestors_condition .= ' OR ';
         }
         $first = false;
         $ancestors_condition .= '`'.$prefix.'items_ancestors`.`idItemAncestor` = '.$db->quote($ID).' OR '.$field.' = '.$db->quote($ID);
      }
      $ancestors_condition .= ')';
   }
   return $ancestors_condition;
}

// returns true if a new sync is needed on groups_items, items, items_strings and users_items
//         false if no sync is needed on any of these
//         'user_items' if only a sync on users_items is needed
function reallyNeedsMainClientSync($db, $params, $minServerVersion) {
   if ($minServerVersion == 0) {
      return true;
   }
   $stmt = $db->prepare('select max(iVersion) from groups_ancestors where idGroupChild = :idGroupSelf');
   $stmt->execute(array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));
   $newAncestorVersion = $stmt->fetchColumn();
   if ($newAncestorVersion > $minServerVersion) {
      return true;
   }
   $ancestors_condition = getAncestorsCondition($db, $params, '', '`groups_items`.`idItem`');
   $stmt = $db->prepare('select max(groups_items.iVersion) from groups_items join groups_ancestors on groups_ancestors.idGroupAncestor = groups_items.idGroup join items_ancestors on items_ancestors.idItemChild = groups_items.idItem where groups_ancestors.idGroupChild = :idGroupSelf and '.$ancestors_condition);
   $stmt->execute(array('idGroupSelf' => $minServerVersion));
   $newGroupItemsVersion = $stmt->fetchColumn();
   if ($newGroupItemsVersion > $minServerVersion) {
      return true;
   }
   $stmt = $db->prepare('select max(users_items.iVersion) from users_items where idUser = :idUser');
   $stmt->execute(array('idUser' => $_SESSION['login']['ID']));
   $newUsersItemsVersion = $stmt->fetchColumn();
   if ($newUsersItemsVersion > $minServerVersion) {
      return 'users_items';
   }
   return false;
}

function getItemsFromAncestors ($params, &$requests, $db, $minServerVersion){
   $requests["threads"]['model']['fields']['sUserCreatedLogin'] = array('sql' => '`users`.`sLogin`', 'tableName' => 'users');
   array_push($requests["threads"]['fields'], 'sUserCreatedLogin');
   $requests["items_items"]["model"]["joins"]["items_ancestors"] = array("srcTable" => "items_items", "srcField" => "idItemChild", "dstField" => "idItemChild");
   $requests["items_items"]["model"]["fields"]["sType"]["groupBy"] = "`items_items`.`ID`"; // Could be added to any field. TODO : fix group by system
   $requests["items_items"]["filters"]["accessible"] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));

   $requests["items"]["model"]["joins"]["items_ancestors"] = array("srcTable" => "items", "srcField" => "ID", "dstField" => "idItemChild");
   $requests["items"]["model"]["fields"]["sType"]["groupBy"] = "`items`.`ID`"; // Could be added to any field. TODO : fix group by system
   $requests["items"]["filters"]["accessible"] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));

   $requests["groups_items"]["model"]["joins"]["items_ancestors"] = array("srcTable" => "groups_items", "srcField" => "idItem", "dstField" => "idItemChild");
   $requests["groups_items"]["model"]["fields"]["sType"]["groupBy"] = "`groups_items`.`ID`"; // Could be added to any field. TODO : fix group by system

   $requests["messages"]["model"]["joins"]["threads"] = array("srcTable" => "messages", "srcField" => "idThread", "dstField" => "ID");
   $requests["messages"]["model"]["joins"]["users"] = array("srcTable" => "messages", "srcField" => "idUser", "dstField" => "ID");
   $requests["messages"]["model"]["joins"]["users_items"] = array("srcTable" => "threads", "srcField" => "idItem", "dstField" => "idItem");
   $requests["messages"]["model"]["filters"]["idUser"] = array(
      "joins" => array("users_items", "threads", "users"),
      "condition"  => "((`[PREFIX]users_items`.`idUser` = :[PREFIX_FIELD]idUser and `[PREFIX]users_items`.`bValidated` = 1) or `[PREFIX]threads`.`idUserCreated` = :[PREFIX_FIELD]idUser)",
      "readOnly" => true
   );
   $requests["messages"]["filters"]["idUser"] = $_SESSION['login']['ID'];
   array_push($requests["messages"]["fields"], 'sLogin');
   $requests["messages"]['model']['fields']['sLogin'] = array('sql' => '`users`.`sLogin`', 'readOnly' => true);
   $requests["messages"]["debug"] = true;

   array_push($requests["items"]["fields"], 'bGrayedAccess');
   $requests["items"]['model']['fields']['bGrayedAccess'] = array('sql' => 'IF (MAX(`groups_items`.`bCachedFullAccess` + `groups_items`.`bCachedPartialAccess`) = 0, 1, 0)');
   array_push($requests["items"]["fields"], 'bAccessSolutions');
   $requests["items"]['model']["fields"]['bAccessSolutions'] = array('sql' => 'MAX(`groups_items`.`bCachedAccessSolutions`)');

   $requests["items_strings"]["model"]["joins"]["items_ancestors"] = array("srcTable" => "items_strings", "srcField" => "idItem", "dstField" => "idItemChild");
   $requests["items_strings"]["model"]["fields"]["idItem"]["groupBy"] = "`items_strings`.`ID`"; // Could be added to any field. TODO : fix group by system
   $requests["items_strings"]["filters"]["accessible"] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));

   if (!isset($requests["users_items"])) {
      $users_items_model = createViewModelFromTable("users_items", true);
      $requests["users_items"] = array(
         "modelName" => "users_items",
         "model" => $users_items_model,
         "fields" => $users_items_model['fields'],
      );
   }
   $requests["users_items"]["model"]["joins"]["items_ancestors"] = array("srcTable" => "users_items", "srcField" => "idItem", "dstField" => "idItemChild");
   $requests["users_items"]["model"]["fields"]["idItem"]["groupBy"] = "`users_items`.`ID`"; // Could be added to any field. TODO : fix group by system
   $requests["users_items"]["model"]["filters"]["idUser"] = array(
      "condition" => "`[PREFIX]users_items`.`idUser` = :[PREFIX_FIELD]idUser"
   );
   $requests["users_items"]["filters"]["idUser"] = $_SESSION['login']['ID'];

   $ancestors_condition = getAncestorsCondition($db, $params, '[PREFIX]', '[FIELD]');

   $tables = array('items_items' => 'idItemParent', 'items' => 'ID', 'items_strings' => 'idItem', 'users_items' => 'idItem', 'groups_items' => 'idItem');
   foreach($tables as $table => $field) {
      $requests[$table]["model"]["filters"]["idItemParent"] = array(
         "joins" => array("items_ancestors"),
         "condition" => str_replace('[FIELD]', '`[PREFIX]'.$table.'`.`'.$field.'`', $ancestors_condition),
         "ignoreValue" => true
      );
      $requests[$table]["filters"]["idItemParent"] = true;
   }
   $needsChanges = reallyNeedsMainClientSync($db, $params, $minServerVersion);
   if (!$needsChanges || $needsChanges === 'users_items') {
      foreach($tables as $table => $_) {
         if (!$needsChanges || $table != 'users_items') {
            $requests[$table]['getChanges'] = false;
         }
      }
   }
   // groups_items slows everything down here, no idea why
   unset($requests['groups_items']);
}

function getMyGroupsItems ($params, &$requests) {
   if ($_SESSION['login']['tempUser']) {
      $requests["groups_items"]["readOnly"] = true;
      $requests["groups_items"]["filters"]["myGroupSelf"] = array("values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));
      return;
   }
   $requests["groups_items"]["filters"]["descendantsAndAncestorsRead"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));
   $requests["groups_items"]["filters"]["descendantsWrite"] = array('modes' => array('insert' => true, 'update' => true, 'delete' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));
   //$requests["groups_items"]["debugLogFunction"] = myDebugFunction;
}

function myDebugFunction($query, $values, $moment = '') {
   global $db;
   $res = $query;
   foreach ($values as $valueName => $value) {
      $res = str_replace(':'.$valueName, $db->quote($value), $res);
   }
   file_put_contents(__DIR__.'/../logs/groups_items.log', date(DATE_RFC822).'  '.$moment.' '.$res.";\n", FILE_APPEND);
}

function getGroups ($params, &$requests) {
   global $config;
   $idRootSelf = $config->shared->RootSelfGroupId;
   $idRootOwned = $config->shared->RootAdminGroupId;
   $requests['groups_invitations'] = $requests['groups'];
   $requests['groups']["model"]["fields"]["sType"]["groupBy"] = "`groups`.`ID`";
   $requests["groups"]["model"]["filters"]["MyGroupsWrite"] = array(
      "joins" => array("myInvitationsLeft", "myGroupDescendantsLeft"),
      "condition"  => "`[PREFIX]groups`.`sType` != 'UserSelf' AND `[PREFIX]groups`.`sType` != 'UserAdmin' AND `[PREFIX]groups`.`sType` != 'Root' AND `[PREFIX]groups`.`sType` != 'RootSelf'  AND `[PREFIX]groups`.`sType` != 'RootAdmin' AND (`[PREFIX]myInvitationsLeft`.`idGroupChild` = :[PREFIX_FIELD]idGroupSelf OR `[PREFIX]myGroupDescendantsLeft`.`idGroupAncestor` = :[PREFIX_FIELD]idGroupOwned)",
   );
   $requests["groups"]["filters"]["MyGroupsWrite"] = array(
      'values' => array(
         'idGroupOwned' => $_SESSION['login']['idGroupOwned'], // TODO: vérifier pour les tempUsers
         'idGroupSelf'  => $_SESSION['login']['idGroupSelf'],
      ),
      'modes' => array('insert' => true, 'update' => true, 'delete' => true),
   );
   $requests["groups"]['model']['fields']['idUser'] = array('readOnly' => true, 'modes' => array('select' => true), 'joins' => array('users'), 'sql' => '`users`.`ID`');
   $requests["groups"]["model"]["filters"]["Mine"] = array(
      "joins" => array("myInvitationsLeft"),
      "condition"  => "(`[PREFIX]myInvitationsLeft`.`idGroupChild` = :[PREFIX_FIELD]idGroupSelf OR `[PREFIX]groups`.`ID` = :[PREFIX_FIELD]idGroupOwned OR `[PREFIX]groups`.`ID` = :[PREFIX_FIELD]idGroupSelf)",
   );
   $requests["groups"]["filters"]["Mine"] = array(
      'values' => array(
         'idGroupOwned' => $_SESSION['login']['idGroupOwned'], // TODO: vérifier pour les tempUsers
         'idGroupSelf'  => $_SESSION['login']['idGroupSelf'],
      ),
      'modes' => array('select' => true),
   );

   $requests["groups_invitations"]['filters']['addUserID'] = array('modes' => array('select' => true));
   array_push($requests["groups_invitations"]["fields"], 'idUser');
   $requests["groups_invitations"]['model']['fields']['idUser'] = array('readOnly' => true, 'modes' => array('select' => true), 'joins' => array('users'), 'sql' => '`users`.`ID`');
   $requests["groups_invitations"]["model"]["filters"]["Invitations"] = array(
      "joins" => array("myInvitations"),
      "condition"  => "(`[PREFIX]myInvitations`.`idGroupChild` = :[PREFIX_FIELD]idGroupSelf)",
   );
   $requests["groups_invitations"]["filters"]["Invitations"] = array(
      'values' => array(
         'idGroupSelf'  => $_SESSION['login']['idGroupSelf'],
      ),
      'modes' => array('select' => true),
   );

   $requests['groups_groups_invitations'] = $requests['groups_groups'];
   $requests["groups_groups_invitations"]['filters']['addLogin'] = array('modes' => array('select' => true));
   array_push($requests["groups_groups_invitations"]["fields"], 'sChildLogin');
   $requests["groups_groups_invitations"]['model']['fields']['sChildLogin'] = array('readOnly' => true, 'modes' => array('select' => true), 'joins' => array('users'), 'sql' => '`users`.`sLogin`');
   array_push($requests["groups_groups_invitations"]["fields"], 'sUserInvitingLogin');
   $requests["groups_groups_invitations"]['model']['fields']['sUserInvitingLogin'] = array('readOnly' => true, 'modes' => array('select' => true), 'joins' => array('users'), 'sql' => '`userInviting`.`sLogin`');
   //$requests['groups_groups']["model"]["fields"]["idGroupParent"]["groupBy"] = "`groups_groups`.`ID`";
   if (!$_SESSION['login']['tempUser']) {
      $requests['groups_groups']['filters']['descendantsRead'] = array('modes' => array('select' => true), 'values' => array('idGroupOwned' => $_SESSION['login']['idGroupOwned']));
      $requests['groups_groups_invitations']['filters']['invitationsRead'] = array('modes' => array('select' => true), 'values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));
      //$requests['groups_groups']['filters']['invitationsAndDescendantsWrite'] = array('modes' => array('insert' => true, 'update' => true, 'delete' => true), 'values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf'], 'idRootSelf' => $idRootSelf, 'idGroupOwned' => $_SESSION['login']['idGroupOwned']));
      // TODO: find a working write filter (commented one can't work due to triggers)
   } else {
      $requests["groups_groups"]['readOnly'] = true;
   }
}

function getAllLevels ($params, &$requests){
   global $config;
   $idRootItem = $config->shared->domains['current']->OfficialProgressItemId;
   $idRootIndexItem = $config->shared->domains['current']->DiscoverRootItemId;
   $idRootCustomItem = $config->shared->domains['current']->CustomProgressItemId;
   unset($requests['messages']);
   //unset($requests['threads']);
#   $requests['threads']['fields']
   $requests["threads"]['model']['fields']['sUserCreatedLogin'] = array('sql' => '`users`.`sLogin`', 'tableName' => 'users');
   array_push($requests["threads"]['fields'], 'sUserCreatedLogin');
   $requests["items_items"]["model"]["filters"]["getAllLevels"] = array(
      "joins" => array(),
      "condition"  => "(`[PREFIX]items_items`.`idItemParent` = ".$idRootItem." OR `[PREFIX]items_items`.`idItemParent` = ".$idRootIndexItem.")",
      "ignoreValue" => true,
      "readOnly" => true,
   );
   $requests["items_items"]["model"]["fields"]["idItem"]["groupBy"] = "`items_items`.`ID`";
   $requests["items_items"]["filters"]["accessible"] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));
   $requests["items_items"]["readOnly"] = true;

   $requests["groups_items"]["model"]["filters"]["getAllLevels"] = array(
      "joins" => array(),
      "condition"  => "(`[PREFIX]groups_items`.`idItem` = ".$idRootItem." OR `[PREFIX]groups_items`.`idItem` = ".$idRootIndexItem.")",
      "ignoreValue" => true,
      "readOnly" => true,
   );
   //$requests["groups_items"]["model"]["fields"]["idItem"]["groupBy"] = "`groups_items`.`ID`";
   $requests["items_items"]["filters"]["accessible"] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));
   $requests["items_items"]["readOnly"] = true;

   $requests["items_strings"]["model"]["joins"]["items_items"] =  array("type" => "LEFT", "srcTable" => "items_strings", "srcField" => "idItem", "dstField" => "idItemChild");
   $requests["items_strings"]["model"]["fields"]["idItem"]["groupBy"] = "`items_strings`.`ID`";
   $requests["items_strings"]["model"]["filters"]["getAllLevels"] = array(
      "joins" => array("items_items"),
      "condition"  => "(`[PREFIX]items_items`.`idItemParent` = ".$idRootItem." OR `[PREFIX]items_strings`.`idItem` = ".$idRootItem." OR `[PREFIX]items_items`.`idItemParent` = ".$idRootIndexItem." OR `[PREFIX]items_strings`.`idItem` = ".$idRootIndexItem.")",
      "readOnly" => true,
      "ignoreValue" => true,
   );
   $requests["items_strings"]["filters"]["accessible"] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));;
   $requests["items_strings"]["readOnly"] = true;
   
   $requests["users_items"]["model"]["joins"]["items_items"] =  array("type" => "LEFT", "srcTable" => "users_items", "srcField" => "idItem", "dstField" => "idItemChild");
   $requests["users_items"]["model"]["filters"]["getAllLevels"] = array(
      "joins" => array("items_items"),
      "condition"  => "(`[PREFIX]items_items`.`idItemParent` = ".$idRootItem." OR `[PREFIX]users_items`.`idItem` = ".$idRootItem." OR `[PREFIX]items_items`.`idItemParent` = ".$idRootIndexItem." OR `[PREFIX]users_items`.`idItem` = ".$idRootIndexItem.")",
      "readOnly" => true,
      "ignoreValue" => true,
   );
   $requests["users_items"]["model"]["filters"]["idUser"] = array(
      "condition" => "`[PREFIX]users_items`.`idUser` = :[PREFIX_FIELD]idUser",
   );
   $requests["users_items"]["model"]["fields"]["idItem"]["groupBy"] = "`users_items`.`ID`";
   $requests["users_items"]["readOnly"] = true;

   $requests["items"]["model"]["joins"]["items_items"] =  array("type" => "LEFT", "srcTable" => "items", "srcField" => "ID", "dstField" => "idItemChild");
   $requests["items"]["model"]["fields"]["idItem"]["groupBy"] = "`items`.`ID`";
   $requests["items"]["model"]["filters"]["getAllLevels"] = array(
      "joins" => array("items_items"),
      "condition"  => "(`[PREFIX]items_items`.`idItemParent` = ".$idRootItem." OR `[PREFIX]items`.`ID` = ".$idRootItem." OR `[PREFIX]items_items`.`idItemParent` = ".$idRootIndexItem." OR `[PREFIX]items`.`ID` = ".$idRootIndexItem.")",
      "readOnly" => true,
      "ignoreValue" => true,
   );
   $requests["items"]["filters"]["accessible"] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));;
   $requests["items"]['model']['fields']['bGrayedAccess'] = array('sql' => 'IF (MAX(`groups_items`.`bCachedFullAccess` + `groups_items`.`bCachedPartialAccess`) = 0, 1, 0)');
   array_push($requests["items"]['fields'], 'bGrayedAccess');
   $requests["items"]['model']['fields']['bAccessSolutions'] = array('sql' => 'MAX(`groups_items`.`bCachedAccessSolutions`)');
   array_push($requests["items"]['fields'], 'bAccessSolutions');
   $requests["items"]["readOnly"] = true;

   $requests["items_items"]["filters"]["getAllLevels"] = true;
   $requests["groups_items"]["filters"]["getAllLevels"] = true;
   $requests["users_items"]["filters"]["getAllLevels"] = true;
   $requests["users_items"]["filters"]["idUser"] = $_SESSION['login']['ID'];
   $requests["items_strings"]["filters"]["getAllLevels"] = true;
   $requests["items"]["filters"]["getAllLevels"] = true;
   // groups_items slows everything down
   unset($requests['groups_items']);
}

// only fetch public infos if not self
function filterUsers(&$requests) {
   $requests['users']['model']['filters']['me'] = array(
      "joins" => array(),
      "condition"  => "(`[PREFIX]users`.`ID` = :[PREFIX_FIELD]me)",
      "readOnly" => true
   );
   $requests['users']['filters']['me'] = $_SESSION['login']['ID'];
# Uncomment if you want all users but keeping private things private
#   static $privateFields = array('sEmail', 'sCountryCode', 'sTimeZone', 'sBirthDate', 'iGraduationYear', 'sSex', 'sAddress', 'sZipcode', 'sCity', 'sLandLineNumber', 'sCellPhoneNumber', 'sDefaultLanguage', 'sFreeText', 'sWebSite', 'idUserGodfather');
#   foreach($requests['users']['model']['fields'] as $fieldName => &$field) {
#      if ($fieldName == 'sFirstName') {
#            $field = array('sql' => 'IF (`users`.`ID` = '.$_SESSION['login']['ID'].' OR `bPublicFirstName`, `sFirstName`, NULL)');
#      } elseif ($fieldName == 'sLastName') {
#            $field = array('sql' => 'IF (`users`.`ID` = '.$_SESSION['login']['ID'].' OR `bPublicLastName`, `sLastName`, NULL)');
#      } elseif(in_array($fieldName, $privateFields)) {
#            $field = array('sql' => 'IF (`users`.`ID` = '.$_SESSION['login']['ID'].', `'.$fieldName.'`, NULL)');
#      }
#   }
}

function checkInitialUsersItems($db) {
   $stmt = $db->prepare('select count(ID) from users_items where idUser = :idUser;');
   $stmt->execute(array('idUser' => $_SESSION['login']['ID']));
   $countUserItems = $stmt->fetchColumn();
   if ($countUserItems) {
      return;
   }
   $stmt = $db->prepare('insert into users_items (idUser, idItem, iVersion) select :idUser, idItem, 0 from groups_items join groups_ancestors on groups_items.idGroup = groups_ancestors.idGroupAncestor where ((`groups_items`.`bCachedGrayedAccess` = 1 OR `groups_items`.`bCachedPartialAccess` = 1 OR `groups_items`.`bCachedFullAccess` = 1) AND groups_ancestors.`idGroupChild` = :idGroupSelf);');
   $stmt->execute(array('idGroupSelf' => $_SESSION['login']['idGroupSelf'], 'idUser' => $_SESSION['login']['ID']));
}

function algoreaCustomRequest($params, &$requests, $db, $minServerVersion) {
   global $config;
   if (!isset($_SESSION)) {
      session_start();
   }
   if (!count($_SESSION) || !isset($_SESSION['login']) || !isset($_SESSION['login']['ID'])) {
      $requests = array();
      return;
   }
   if (isset($params["requests"]) && isset($params["requests"]["algorea"]) && isset($params["requests"]["algorea"]['type'])) {
      $admin = (isset($params["requests"]["algorea"]['admin']) && $params["requests"]["algorea"]['admin']);
      filterUsers($requests);
      // TODO: real check on user right
      //unset($requests["groups_items"]);
      checkInitialUsersItems($db);
      if ( ! $admin) {
         setupGroupsItemsRequests($requests);
         //unset($requests["groups_items"]);
         $requests["filters"]["model"]["filters"]["idUser"] = array(
            "condition"  => "`[PREFIX]filters`.`idUser` = :[PREFIX_FIELD]idUser",
         );
         $requests["filters"]["filters"]["idUser"] = $_SESSION['login']['ID'];
         $requests["users_threads"]["model"]["filters"]["idUser"] = array(
            "condition"  => "`[PREFIX]users_threads`.`idUser` = :[PREFIX_FIELD]idUser",
         );
         $requests["users_threads"]["filters"]["idUser"] = $_SESSION['login']['ID'];
         if(isset($requests['users_answers'])) {
            $requests["users_answers"]["model"]["filters"]["idUser"] = array(
               "condition"  => "`[PREFIX]users_answers`.`idUser` = :[PREFIX_FIELD]idUser",
            );
            $requests["users_answers"]["filters"]["idUser"] = $_SESSION['login']['ID'];
         }
         $requests['users_answers']['readOnly'] = true;
         //getMyGroupsItems($params, $requests);
      } else {
         //unset($requests["users_items"]);
         unset($requests["filters"]);
         unset($requests["users_answers"]);
         unset($requests["users_threads"]);
         //unset($requests["threads"]);
         unset($requests["messages"]);
      }
      $requests['users_items']['readOnly'] = true;
      //$requests['threads']['debug'] = true;
      //$requests["threads"]["debugLogFunction"] = myDebugFunction;
      //$requests['users_answers']['readOnly'] = true;
      unset($requests["items_ancestors"]);
      //$requests['items_ancestors']['readOnly'] = true;
      //$requests['items_ancestors']['filters']['accessible'] = array('values' => array('idGroupSelf' => $_SESSION['login']['idGroupSelf']));
      unset($requests["groups_ancestors"]);
      if (!$_SESSION['login']['tempUser']) {
         getGroups($params, $requests);
      } else {
         unset($requests['groups']);
         unset($requests['groups_groups']);
      }
      switch ($params["requests"]["algorea"]['type']) {
         case 'getItemsFromAncestors':
            // temporary
            unset($requests['groups']);
            unset($requests['groups_groups']);
            getItemsFromAncestors($params, $requests, $db, $minServerVersion);
            break;
         case 'expandedItems':
            setupExpandedItemsRequests($params, $requests);
            break;
         default:
            //setupExpandedItemsRequests($params, $requests);
            getAllLevels($params, $requests);
            break;
      }
      if (!$config->shared->domains['current']->usesForum) {
         unset($requests['users_threads']);
         unset($requests['threads']);
         unset($requests['messages']);
         unset($requests["filters"]);
      }
   }
}

function setupExpandedItemsRequests($params, &$requests) {
   global $config, $db;
   $requests["items"]["model"]["joins"]["items_items"] = array("srcTable" => "items", "srcField" => "ID", "dstField" => "idItemChild");
   $requests["items"]["model"]["fields"]["sType"]["groupBy"] = "`items`.`ID`"; // Could be added to any field. TODO : fix group by system
   $requests["items"]["filters"]["accessible"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));
   $requests["items"]["filters"]["accessibleWrite"] = array('modes' => array('insert' => true, 'update' => true, 'delete' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));
   //$requests["items"]['debugLogFunction'] = myDebugFunction;

   $requests["items_strings"]["model"]["joins"]["items_items"] = array("srcTable" => "items_strings", "srcField" => "idItem", "dstField" => "idItemChild");
   $requests["items_strings"]["model"]["fields"]["idItem"]["groupBy"] = "`items_strings`.`ID`"; // Could be added to any field. TODO : fix group by system
   $requests["items_strings"]["filters"]["accessible"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));
   $requests["items_strings"]["filters"]["accessibleWrite"] = array('modes' => array('update' => true, 'delete' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));// TODO: write insert filter

   $requests["items_items"]["model"]["fields"]["idItemParent"]["groupBy"] = "`items_items`.`ID`"; // Could be added to any field. TODO : fix group by system
   $requests["items_items"]["filters"]["accessible"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));
   $requests["items_items"]["filters"]["accessibleWrite"] = array('modes' => array('update' => true, 'delete' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));// TODO: write insert filter

   if ($_SESSION['login']['tempUser']) {
      $requests["groups_items"]["readOnly"] = true;
      $requests["groups_items"]["filters"]["myGroupSelf"] = array("values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));
   } else {
      $requests["groups_items"]["model"]["joins"]["items_items"] = array("srcTable" => "groups_items", "srcField" => "idItem", "dstField" => "idItemChild");
      $requests["groups_items"]["filters"]["descendantsRead"] = array('modes' => array('select' => true), "values" => array("idGroupOwned" => $_SESSION['login']['idGroupOwned']));
      //$requests["groups_items"]["filters"]["descendantsWrite"] = array('modes' => array('update' => true, 'delete' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));// TODO: proper filter for write
      $requests["groups_items"]["model"]["fields"]["sType"]["groupBy"] = "`groups_items`.`ID`";
      //$requests['debugLogFunction'] = myDebugFunction;
      //$requests["groups_items"]["debugLogFunction"] = myDebugFunction;
      $requests["users_items"]["model"]["joins"]["items_items"] = array("srcTable" => "groups_items", "srcField" => "idItem", "dstField" => "idItemChild");
      $requests["users_items"]["filters"]["accessible"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));
      $requests["users_items"]["model"]["fields"]["sType"]["groupBy"] = "`users_items`.`ID`";
      //$requests["users_items"]["debugLogFunction"] = myDebugFunction;
/*      $requests["users_answers"]["model"]["joins"]["items_items"] = array("srcTable" => "groups_items", "srcField" => "idItem", "dstField" => "idItemChild");
      $requests["users_answers"]["filters"]["accessible"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));
      $requests["users_answers"]["model"]["fields"]["ID"]["groupBy"] = "`users_answers`.`ID`";*/
      $requests["threads"]["model"]["joins"]["items_items"] = array("srcTable" => "threads", "srcField" => "idItem", "dstField" => "idItemChild");
      $requests["threads"]["filters"]["accessible"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));
      //$requests["users_answers"]["debugLogFunction"] = myDebugFunction;
   }

   $expanded_items = array();
   $expanded_items_zero = array();
   // all these items are fetched in the first sync to make user experience smoother
   // TODO: do not sync them after first sync
   $default_expanded_items = array($config->shared->RootItemId);
   foreach ($config->shared->domains as $_ => $domainData) {
      $default_expanded_items[] = $domainData->ProgressRootItemId;
      $default_expanded_items[] = $domainData->PlatformItemId;
      $default_expanded_items[] = $domainData->OfficialProgressItemId;
      $default_expanded_items[] = $domainData->CustomProgressItemId;
      $default_expanded_items[] = $domainData->ContestRootItemId;
      $default_expanded_items[] = $domainData->CustomContestRootItemId;
      $default_expanded_items[] = $domainData->OfficialContestRootItemId;
   }

   if (isset($params["requests"]) && isset($params["requests"]["expandedItems"])) {
      foreach ($params["requests"]["expandedItems"] as $ID => $expandedItem) {
         if ($ID != 'minVersion' || $ID != 'resetMinVersion') {
            if (intval($expandedItem['minVersion']) == 0) {
               $expanded_items_zero[$ID] = true;
            } else {
               $expanded_items[$ID] = true;
            }
         }
      }
   }

   foreach ($default_expanded_items as $ID) {
      if (count($expanded_items) && $expanded_items_zero[$ID] != true) {
         $expanded_items[$ID] = true;
      } else {
         $expanded_items_zero[$ID] = true;
      }
   }

   // handling first sync correctly
   if (!count($expanded_items)) {
      $expanded_items = $expanded_items_zero;
      $expanded_items_zero = array();
   }

   $parents_condition = "(";
   $first = true;
   foreach ($expanded_items as $ID => $nothing) {
      if (!$first) {
         $parents_condition .= ' OR ';
      }
      $first = false;
      $parents_condition .= '`[PREFIX]items_items`.`idItemParent` = '.$db->quote($ID);
   }
   $parents_condition .= ')';

   $parents_condition_zero = "(";
   if (count($expanded_items_zero)) { // not first sync
      $first = true;
      foreach ($expanded_items_zero as $ID => $nothing) {
         if (!$first) {
            $parents_condition_zero .= ' OR ';
         }
         $first = false;
         $parents_condition_zero .= '`[PREFIX]items_items`.`idItemParent` = '.$db->quote($ID);
      }
      $parents_condition_zero .= ')';
   }
   //print_r($expanded_items);
   $tables = array('items_items' => 'idItemParent', 'items' => 'ID', 'items_strings' => 'idItem', 'groups_items' => 'idItem', 'users_items' => 'idItem'/*, 'users_answers' => 'idItem'*/, 'threads' => 'idItem');
   foreach($tables as $table => $field) {
      $requests[$table]["model"]["filters"]["idItemParent"] = array(
         "joins" => array($table == 'items_items' ? null : "items_items"),
         "condition" => $parents_condition,
      );
      $requests[$table]["debug"] = true;
      $requests[$table]["markRequest"] = true;
      $requests[$table]["filters"]["idItemParent"] = array('modes' => array('select' => true), 'values' => array());
      if (count($expanded_items_zero)) {
         $requests['zero_'.$table] = $requests[$table];
         $requests['zero_'.$table]["model"]["filters"]["idItemParent"]["condition"] = $parents_condition_zero;
         $requests['zero_'.$table]["minVersion"] = 0;
         //$requests['zero_'.$table]["debug"] = true;
      }
   }
   //file_put_contents(__DIR__.'/../logs/groups_items.log', date(DATE_RFC822).'  '.json_encode($requests['groups_items'])."\n", FILE_APPEND);
}

function setupGroupsItemsRequests(&$requests) {
   if ($_SESSION['login']['tempUser']) {
      $requests["groups_items"]["readOnly"] = true;
      $requests["groups_items"]["filters"]["myGroupSelf"] = array("values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf']));
   } else {
      $requests["groups_items"]["model"]["joins"]["items_items"] = array("srcTable" => "groups_items", "srcField" => "idItem", "dstField" => "idItemChild");
      $requests["groups_items"]["filters"]["descendantsAndAncestorsRead"] = array('modes' => array('select' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));
      $requests["groups_items"]["filters"]["descendantsWrite"] = array('modes' => array('insert' => true, 'update' => true, 'delete' => true), "values" => array("idGroupSelf" => $_SESSION['login']['idGroupSelf'], "idGroupOwned" => $_SESSION['login']['idGroupOwned']));
   }
}

function getSyncRequests($params, $minServerVersion) {
   global $db;
   $requests = syncGetTablesRequests(null, false);
   algoreaCustomRequest($params, $requests, $db, $minServerVersion);

   return $requests;
}
