<?php

class groupsGroupsAncestors {
   public static function getSyncRequests() {
      $request = syncGetTablesRequests(array('groups_groups' => true), false);
      $request = $request['groups_groups'];
      $request["model"]["filters"]["myGroupAncestors"] = array(
         "joins" => array("myGroupAncestors"),
         "condition"  => '`[PREFIX]myGroupAncestors`.`idGroupChild` = :[PREFIX_FIELD]idGroupSelf',
      );
      $request["filters"]["myGroupAncestors"] = array(
         "values" => array(
            'idGroupSelf' => $_SESSION['login']['idGroupSelf']
         ),
         "mode" => array("select" => true)
      );
      $request["debug"] = true;
      $request["requestSet"] = array("name" => "groupsGroupsAncestors");
      return array("groupsGroupsAncestors" => $request);
   }
}
