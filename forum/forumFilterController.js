'use strict';

angular.module('algorea')
   .controller('forumFilterController', ['$scope', 'itemService', 'loginService', function ($scope, itemService, loginService) {
   var defaultFilter={
      'sName': 'default',
      'bStarred': null,
      'sStartDate': null,
      'sEndDate': null,
      'bArchived': null,
      'bParticipated': null,
      'bUnread': null,
      'idItem': null,
      'idGroup': null,
      'olderThan': null,
      'newerThan': null,
      'sUsersSearch': null,
      'sBodySearch': null,
      'bImportant': null
   };
   $scope.loading = true;
   $scope.showFilters = false;
   $scope.plusActiveVar = false;
   $scope.filters = [];
   $scope.myUserID = null;
   // using object here due to prototypal inheritance / scope mess, see
   // here: https://github.com/angular-ui/bootstrap/issues/2540
   $scope.data = {};
   $scope.data.opened = false;
   $scope.open = function($event, formOpened) {
      $event.preventDefault();
      $event.stopPropagation();
      $scope.data[formOpened] = true;
   };
   $scope.dateOptions = {
      formatYear: 'yy',
      startingDay: 1
   };
   $scope.submitForm = function(form) {
      itemService.saveRecord('filters', $scope.$parent.currentFilter.ID);
   };
   $scope.selectFilter = function(filterID) {
      angular.forEach($scope.filters, function(filter, ID) {
         if (!filter) return; // no idea why, but it happens...
         if (ID && filter.bSelected && ID != filterID) {
            filter.bSelected = false;
            itemService.saveRecord('filters', ID);
         } else if ( (!filter.bSelected) && ID == filterID) {
            filter.bSelected = true;
            itemService.saveRecord('filters', ID);
         }
      });
      $scope.$parent.currentFilter = $scope.filters[filterID];
   };
   $scope.newFilter = function() {
      var newFilter = ModelsManager.createRecord('filters');
      ModelsManager.insertRecord('filters', newFilter);
      newFilter.idUser = $scope.myUserID;
      newFilter.sName = "Nouveau filtre";
      $scope.selectFilter(newFilter.ID);
   };
   $scope.deleteFilter = function(ID) {
      ModelsManager.deleteRecord('filters', ID);
      if (isObjEmpty($scope.filters)) {
         $scope.newFilter();
      }
   };
   function isObjEmpty(obj) {
      for (var key in obj)
         if (obj.hasOwnProperty(key))
            return false;
      return true;
   }
   function getSelectedFilter(filters) {
      var firstFilter = false;
      angular.forEach(filters, function(filter, ID) {
         if (filter.bSelected) {
            return filter;
         }
         if (!firstFilter) {
            firstFilter = filter;
         }
      });
      if (firstFilter) {firstFilter.bSelected = true;}
      return firstFilter;
   }
   // recordID is the ID in items, upRelationID is the ID of the
   // items_items relation in which this ID is the son
   $scope.$on('treeview.recordSelected', function(event, recordID, relationID, id) {
      event.stopPropagation();
      if (id == 'treeview_items') {
         $scope.currentFilter.idItem = recordID;
         $scope.toggleTreePicker('items');
      } else {
         $scope.currentFilter.idGroup = recordID;
         $scope.toggleTreePicker('groups');
      }
   });
   $scope.toggleTreePicker = function(type) {
      $scope.$broadcast('treeview.load', 'treeview_'+type);
      $scope['showTreePicker_'+type] = !$scope['showTreePicker_'+type];
   };
   itemService.onNewLoad(function() {
      $scope.loading = false;
      $scope.myUserID = loginService.getUser();
      $scope.myUserID = $scope.myUserID.userID;
      $scope.filters = ModelsManager.getRecords('filters');
      if (!$scope.filters || isObjEmpty($scope.filters)) {
         var filter = ModelsManager.createRecord('filters');
         filter.bSelected = true;
         filter.sName = "Filtre par défaut";
         filter.idUser = $scope.myUserID;
         $scope.$parent.currentFilter = filter;
         $scope.filters = [filter];
      } else {
         $scope.$parent.currentFilter = getSelectedFilter($scope.filters);
      }
   });
}]).filter('threadFilter', function() {
   function threadOkForFilter(thread, filter) {
      if (!filter) return true;
      if (filter.bArchived && ! thread.bArchived) return false;
      var user_thread = null;
      angular.forEach(thread.user_thread, function(found_user_thread){
         user_thread = found_user_thread;
         return;
      });
      if (filter.bParticipated && ((!user_thread) || (!user_thread.sLastWriteDate)))
         return false;
      if (filter.bUnread && user_thread && user_thread.sLastReadDate)
         return false;
      if (filter.bStarred && ((!user_thread) || (!user_thread.bStarred)))
         return false;
      if (filter.bWithNewMessages && ((!user_thread) || (!user_thread.sLastReadDate) || user_thread.sLastReadDate < thread.sLastActivityDate))
         return false;
      if (filter.sBodySearch && (!thread.sMessage || thread.sMessage.indexOf(filter.sBodySearch) === -1))
         return false;
      if (filter.sStartDate && (thread.sLastActivityDate < filter.sStartDate))
         return false;
      if (filter.sEndDate && (thread.sLastActivityDate > filter.sEndDate))
         return false;
      var today = new Date();
      if (filter.newerThan && Math.floor((today - thread.sLastActivityDate) / 86400000) > filter.newerThan)
         return false;
      if (filter.olderThan && Math.floor((today - thread.sLastActivityDate) / 86400000) < filter.olderThan)
         return false;
      // check if item is descendant of filter.idItem
      if (filter.idItem && (!ModelsManager.indexes.idItemAncestor[filter.idItem] || 
               !ModelsManager.indexes.idItemAncestor[filter.idItem][thread.idItem])) {
         return false;
      }
      // TODO: handle bImportant, sUsersSearch and idGroup
      return true;
   }
   function checkAgainstTab(thread, tab, userID) {
      // TODO: temporary hack, should be done server-side:
      if (thread.idItem) {
         var item = ModelsManager.getRecord('items', thread.idItem);
         if (!item || !item.sType) {
            return false;
         }
      }
      if (tab == 'getHelp')
         return thread.sType === 'Help' && thread.idUserCreated == userID;
      if (tab == 'helpOthers')
         return thread.sType === 'Help' && thread.idUserCreated != userID;
      if (tab == 'general')
         return thread.sType === 'General';
   }
   return function(threads, tab, userID, currentFilter, currentGlobalFilter) {
      if (!currentFilter && !tab)
         return threads;
      var res = [];
      angular.forEach(threads, function(thread) {
         if (checkAgainstTab(thread, tab, userID) && threadOkForFilter(thread, currentFilter) && threadOkForFilter(thread, currentGlobalFilter)) {
            res.push(thread);
         }
      });
      return res;
   };
});

// hack due to https://github.com/angular-ui/bootstrap/issues/2659, remove when
// bug fixed in angular-ui
angular.module('algorea').directive('datepickerPopup', function (){return {restrict: 'EAC',require: 'ngModel',link: function(scope, element, attr, controller) {controller.$formatters.shift();}}});
