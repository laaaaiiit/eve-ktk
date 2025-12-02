function labController($scope, $http, $location, $uibModal, $rootScope, $q, $log, $compile, $timeout, $window, $timeout, $state, $interval) {
	
	//$scope.testAUTH("/lab"); //TEST AUTH
	$('body').removeClass().addClass('skin-blue sidebar-mini sidebar-collapse');
	$('html ').css({'min-height' : '100% !important', 'height': '100%'});
	$('body').css({'min-height' : '100% !important', 'height': '100%'});
	$('.content-wrapper').css({'min-height' : '100% !important', 'height': '100%'});
	$('mainDIV').css({'min-height' : '100% !important', 'height': '100%'});
	
	contextMenuInitConn()
	contextMenuInit()
	
	//console.log()
	
	$scope.node={};
	$scope.text={};
	$scope.networks={};
	$scope.interfList={};
	$scope.interfListCount=false;
	$scope.ready=false;
	$scope.tempConn= new Object();
	$scope.tempNet= new Object();
	$scope.changedCursor="";
	$scope.addNewObject={};
	$scope.fullPathToFile=$rootScope.lab;
	$scope.mainDivHeight = parseInt($('html').height()) - 15;
	$scope.collapseSidebar = function(){
		$('body').removeClass('sidebar-expanded-on-hover').addClass('sidebar-collapse');
	}

	var topologyPoller;
	var lastModified = 0;
	function pollTopology() {
		var params = {
			lastmodified: lastModified
		};

		if ($location.search().mode === 'collaborate') {
			params.mode = 'collaborate';
		}

		console.log('Polling for topology changes with params:', params);
		$http.get('/api/labs' + $rootScope.lab + '/status', { params: params })
			.then(function(response) {
				console.log('Poll response received:', response.data);
				if (response.data.status === 'changed') {
					console.log('Topology has changed, reloading.');
					$scope.topologyRefresh();
				}
				lastModified = response.data.lastmodified;
			}, function(error) {
				console.error('Error during topology poll:', error);
			});
	}

	if ($location.search().mode === 'collaborate') {
		console.log('Starting topology poller.');
		topologyPoller = $interval(pollTopology, 5000);
	}

	$scope.$on('$destroy', function() {
		if (topologyPoller) {
			console.log('Stopping topology poller.');
			$interval.cancel(topologyPoller);
		}
	});
	
	
	// $scope.refreshPage = function(){
	// 	location.reload();
	// }

	$scope.topologyRefresh = function(){
		$state.reload();
		return;
		jsPlumb.detachEveryConnection();
		jsPlumb.reset();
		initFullLab();
		openrightHide();
		// $scope.networkListRefresh();
		// $scope.nodeListRefresh();
		// for (i in $scope.node)
		// {
		// 	console.log('for scope node');
		// 	var node = $scope.node[i];
		// 	if (!$("#nodeID_" + node.id).length)
		// 	{
		// 		nodeInit(node);
		// 		console.log('init node');
		// 	}
		// }
	}
	
	$rootScope.topologyRefresh = function(){
		$scope.topologyRefresh();
	}

	$scope.networkListRefresh = function(){
		$http.get('/api/labs'+$rootScope.lab+'/networks')
		.then(
			function successCallback(response){
				console.log(response);
				$scope.networks=response.data.data
			}
		)
	}
	$scope.networkListRefresh();
	
	$scope.nodeListRefresh = function(){
		$http.get('/api/labs'+$rootScope.lab+'/nodes')
		.then(
			function successCallback(response){
				console.log("nodeListRefresh:",response);
				$scope.node=response.data.data
			}
		)
	}
	$scope.nodeListRefresh();
	
	$scope.mouseOverMainDiv = function($event){
	}
	
	$scope.mainFieldClick = function($event,src){
		$('body').removeClass('sidebar-expanded-on-hover').addClass('sidebar-collapse');
		if (src == undefined) {$scope.addNewObject={}; $scope.addNewObject=$event}
		else {$scope.addNewObject={}; $scope.addNewObject.pageX=$('#'+src).offset().left; $scope.addNewObject.pageY=$('#'+src).offset().top;}
		
		switch ($scope.changedCursor){
			case 'node':
				$scope.changedCursor='';
				$scope.openModal('addNode');
				break;
			case 'network':
				$scope.changedCursor='';
				$scope.openModal('addNet');
				break;
			case 'text':
				$scope.changedCursor='';
				$scope.openModal('addText');
				break;
			case 'shape':
				$scope.changedCursor='';
				$scope.openModal('addShape');
				break;
			case 'image':
				$scope.changedCursor='';
				$scope.openModal('addImage');
				break;
		}
	}
	
	$scope.closeLab = function(){
		var fl = true;
		for (i in $scope.node)
		{
			if ($scope.node[i].status == 2)
				fl = false;
		}
		if (!fl){
			alert('There are running nodes, you need to power off them before closing the lab.')
		}
	else {
		$http({
			method: 'DELETE',
			url: '/api/labs/close'})
			.then(
				function successCallback(response) {
					console.log(response)
					console.log($location.url())
					blockUI();
					if ($rootScope.testAUTH) {
						$rootScope.testAUTH("/main", true);
						$location.path('/main');
					} else {
						$location.path('/main');
					}
				}, 
				function errorCallback(response) {
					console.log(response)
					if ($rootScope.testAUTH) {
						$rootScope.testAUTH("/main", true);
						$location.path('/main');
					} else {
						$location.path('/main');
					}
				}
		);
		jsPlumb.detachEveryConnection();
	}
		//jsPlumb.reset();
		

	}
	$scope.compileNewElement = function(el, id, object){
		$('#canvas').append($compile(el)($scope))
		if (id.search('text') != -1){
			jsPlumbObjectInit($('#'+id), object)
		} else if(id.search('shape') != -1){
			jsPlumbObjectInit($('#'+id), object)
		} else {
			jsPlumbNodeInit($('#'+id))
		}
	}

	///////////////////////////////////////////////
	//// Wipe all nodes //START
	$scope.wipeAllNode = function(){
		closePopUp();
		openrightHide();
		var ids = collectSelectedNodeIds();
		if (!ids.length) {
			ids = collectAllNodeIds();
		}
		if (!ids.length) {
			return;
		}
		if (ids.length === 1) {
			queueSingleNodeAction('wipe', ids[0]);
			return;
		}
		runBatchNodeAction('wipe', ids);
	}

	$scope.wipeNode = function(id){
		closePopUp();
		if (!id)
		{
			id = $("#tempElID").val();
			id = id.replace("nodeID_", "");
		}
		queueSingleNodeAction('wipe', id);
	}
	///////////////////////////////////////////////
	//// Wipe all nodes /END


	///////////////////////////////////////////////
	//// Start/Stop Node //START
	$scope.startThisNode = function(id){
		var id = $("#tempElID").val();
		id = id.replace("nodeID_", "");
		var node = $scope.node[id];
		if(node.status == 0){
			$scope.startstopNode(node.id);
		}
	}

	$scope.stopThisNode = function(){
		var id = $("#tempElID").val();
		id = id.replace("nodeID_", "");
		var node = $scope.node[id];
		if(node.status == 2){
			$scope.startstopNode(node.id);
		}
	}

	function collectNodeIdsByStatus(targetStatus) {
		var ids = [];
		var selectionDetected = false;
		$(".free-selected").each(function(){
			var domId = $(this).attr("id");
			if (domId && domId.indexOf("nodeID_") !== -1) {
				selectionDetected = true;
				var nodeId = domId.replace("nodeID_", "");
				var nodeObj = $scope.node[nodeId];
				if (nodeObj && nodeObj.status == targetStatus) {
					ids.push(nodeId);
				}
			}
		});
		if (!selectionDetected) {
			for (var key in $scope.node) {
				if (!$scope.node.hasOwnProperty(key)) continue;
				var node = $scope.node[key];
				if (node && node.status == targetStatus) {
					ids.push(node.id || key);
				}
			}
		}
		return ids;
	}

	function collectSelectedNodeIds() {
		var ids = [];
		$(".free-selected").each(function(){
			var domId = $(this).attr("id");
			if (domId && domId.indexOf("nodeID_") !== -1) {
				var nodeId = domId.replace("nodeID_", "");
				ids.push(nodeId);
			}
		});
		return ids;
	}

	function collectAllNodeIds() {
		var ids = [];
		for (var key in $scope.node) {
			if (!$scope.node.hasOwnProperty(key)) {
				continue;
			}
			var node = $scope.node[key];
			if (node) {
				ids.push(node.id || key);
			}
		}
		return ids;
	}

	function removeNodeElement(nodeId, elementPrefix) {
		var prefix = elementPrefix || 'node';
		jsPlumb.select({source: prefix + 'ID_' + nodeId}).detach();
		jsPlumb.select({target: prefix + 'ID_' + nodeId}).detach();
		$('#' + prefix + 'ID_' + nodeId).remove();
		if ($scope.node[nodeId]) {
			delete $scope.node[nodeId];
		}
	}

	$scope.activeJob = null;

	function applySingleNodeResult(nodeId, action, entry) {
		var node = $scope.node[nodeId];
		if (!node) {
			return;
		}
		node.loadclassShow = false;
		if (entry.code !== 200) {
			toastr["error"](entry.message || ('Failed to ' + action + ' node #' + nodeId), "Error");
			return;
		}
		if (action === 'start') {
			node.upstatus = true;
			node.status = 2;
		} else if (action === 'stop') {
			node.upstatus = false;
			node.status = 0;
		} else if (action === 'wipe') {
			node.upstatus = false;
			node.status = 0;
		} else if (action === 'delete') {
			removeNodeElement(nodeId);
		}
	}

	function handleBatchResults(action, resultPayload) {
		if (!resultPayload || !resultPayload.data || !resultPayload.data.results) {
			return;
		}
		resultPayload.data.results.forEach(function(entry){
			applySingleNodeResult(entry.id, action, entry);
		});
	}

	function runBatchNodeAction(action, ids) {
		if (!ids || !ids.length) {
			return $q.when();
		}
		ids.forEach(function(nodeId){
			var node = $scope.node[nodeId];
			if (node) {
				node.loadclassShow = true;
			}
		});
		return $scope.applyBatchNodeAction(action, ids, function(data){
			handleBatchResults(action, data);
		}).catch(function(error){
			ids.forEach(function(nodeId){
				var node = $scope.node[nodeId];
				if (node) {
					node.loadclassShow = false;
				}
			});
			return $q.reject(error);
		});
	}

	function queueSingleNodeAction(action, nodeId) {
		return runBatchNodeAction(action, [nodeId]);
	}

	$scope.pollJobStatus = function(jobId) {
		var deferred = $q.defer();
		function check() {
			$http.get('/api/jobs/' + jobId).then(function(response){
				if (!response.data || !response.data.data) {
					deferred.reject({message: 'Malformed job response'});
					return;
				}
				var job = response.data.data;
				if ($scope.activeJob && $scope.activeJob.id == jobId) {
					$scope.activeJob.progress = job.progress || 0;
					$scope.activeJob.status = job.status;
				}
				if (job.status === 'success' || job.status === 'failed') {
					deferred.resolve(job);
					if ($scope.activeJob && $scope.activeJob.id == jobId) {
						$scope.activeJob.progress = 100;
						$scope.activeJob.status = job.status;
					}
				} else {
					$timeout(check, 2000);
				}
			}, function(error){
				deferred.reject(error);
			});
		}
		check();
		return deferred.promise;
	};

	$scope.applyBatchNodeAction = function(action, ids, onComplete) {
		if (!ids || !ids.length) {
			return;
		}
		var jobContext = {
			action: action,
			progress: 0,
			status: 'queued',
			id: null
		};
		$scope.activeJob = jobContext;
		return $http.post('/api/labs' + $rootScope.lab + '/nodes/actions', { action: action, ids: ids })
			.then(function successCallback(response) {
				if ((response.status === 202 || response.data.status === 'accepted') && response.data.data && response.data.data.job_id) {
					var jobId = response.data.data.job_id;
					toastr["info"]('Batch queued (Job #' + jobId + ')', 'Queued');
					jobContext.id = jobId;
					jobContext.status = 'running';
					jobContext.progress = 0;
					return $scope.pollJobStatus(jobId);
				}
				jobContext.progress = 100;
				jobContext.status = response.data.status || 'success';
				return response.data;
			}, function errorCallback(error) {
				var message = (error.data && error.data.message) ? error.data.message : 'Server Error';
				toastr["error"](message, "Error");
				$scope.activeJob = null;
				return $q.reject(error);
			})
			.then(function(resultData){
				if (!resultData) {
					return;
				}
				var finalResult = resultData.result || resultData;
				if (typeof onComplete === 'function') {
					onComplete(finalResult);
				}
				if (resultData.status === 'failed') {
					var failMessage = (finalResult && finalResult.message) ? finalResult.message : 'Job failed';
					toastr["error"](failMessage, "Error");
				} else if (finalResult && finalResult.status === 'partial') {
					toastr["warning"](finalResult.message || 'Batch completed with issues', "Partial");
				} else {
					var successMessage = (finalResult && finalResult.message) ? finalResult.message : 'Batch completed';
					toastr["success"](successMessage, "Success");
				}
				jobContext.status = 'complete';
				jobContext.progress = 100;
				$timeout(function () {
					if ($scope.activeJob === jobContext) {
						$scope.activeJob = null;
					}
				}, 2000);
				return finalResult;
			})
			.catch(function(error){
				var message = (error && error.data && error.data.message) ? error.data.message : 'Job polling failed';
				toastr["error"](message, "Error");
				if ($scope.activeJob === jobContext) {
					$scope.activeJob = null;
				}
				return $q.reject(error);
			});
	};

	$scope.startAllNode = function(){
		closePopUp();
		openrightHide();
		var ids = collectNodeIdsByStatus(0);
		if (!ids.length) {
			return;
		}
		if (ids.length === 1) {
			queueSingleNodeAction('start', ids[0]);
			return;
		}
		runBatchNodeAction('start', ids);
	}

	$scope.stopAllNode = function(){
		closePopUp();
		openrightHide();
		var ids = collectNodeIdsByStatus(2);
		if (!ids.length) {
			return;
		}
		if (ids.length === 1) {
			queueSingleNodeAction('stop', ids[0]);
			return;
		}
		runBatchNodeAction('stop', ids);
	}


	$scope.startstopNode = function(id){
		closePopUp();
		if (!id)
		{
			id = $("#tempElID").val();
			id = id.replace("nodeID_", "");
		}

		if(!$scope.node[id].upstatus){
			queueSingleNodeAction('start', id);
		} else {
			queueSingleNodeAction('stop', id);
		}
	}
	///////////////////////////////////////////////
	//// Start/Stop Node //END

	///////////////////////////////////////////////
	//// Export CFG //START
	$scope.exportCFG = function(id){
		openrightHide();
		if (!id)
		{
			id = $("#tempElID").val();
			id = id.replace("nodeID_", "");
		}
		$http.put('/api/labs'+$rootScope.lab+'/nodes/'+id+'/export')
		.then(
				function successCallback(response){
					console.log(response);
				},
				function errorCallback(response){
					console.log('Server Error');
					console.log(response);
				}
			);
		console.log("s-a transmis export")
	}

	$scope.exportAllCFG = function(id){
		openrightHide();
		var h_flague = false;
		$(".free-selected").each(function(){
		  var id = $(this).attr("id");
		  id = id.replace("nodeID_", "");
		  var node = $scope.node[id];
			$http.put('/api/labs'+$rootScope.lab+'/nodes/' + id + '/export')
				.then(
						function successCallback(response){
							console.log(response);
						},
						function errorCallback(response){
							console.log('Server Error');
							console.log(response);
						}
					);
				console.log("s-a transmis export")
			h_flague = true;
		})
		if (!h_flague)
		{
		for(i in $scope.node)
			{
				$http.put('/api/labs'+$rootScope.lab+'/nodes/' + i, '/export')
				.then(
						function successCallback(response){
							console.log(response);
						},
						function errorCallback(response){
							console.log('Server Error');
							console.log(response);
						}
					);
				console.log("s-a transmis export")
			}
		}
	}
	///////////////////////////////////////////////
	//// Export CFG //END

	///////////////////////////////////////////////
	//// Set all startup-cfg for export //START
	$scope.setAllStartupExport = function(){
		closePopUp();
		openrightHide();
		// console.log("click")
		var h_flague = false;
		$(".free-selected").each(function(){
		  var id = $(this).attr("id");
		  id = id.replace("nodeID_", "");
		  var node = $scope.node[id];
			$http.put('/api/labs'+$rootScope.lab+'/nodes/' + id, {'config' : 1})
				.then(
					function successCallback(response){
						console.log(response);
					},
					function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
			h_flague = true;
		})
		if (!h_flague)
		{
			for (i in $scope.node)
			{
				$http.put('/api/labs'+$rootScope.lab+'/nodes/' + i, {'config' : 1})
				.then(
					function successCallback(response){
						console.log(response);
					},
					function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
			}
		}
	}
	
	$scope.setAllStartupNone = function(){
		closePopUp();
		openrightHide();
		//console.log("click")
		var h_flague = false;
		$(".free-selected").each(function(){
		  var id = $(this).attr("id");
		  id = id.replace("nodeID_", "");
		  var node = $scope.node[id];
			$http.put('/api/labs'+$rootScope.lab+'/nodes/' + id, {'config' : 0})
				.then(
					function successCallback(response){
						console.log(response);
					},
					function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
			h_flague = true;
		})
		if (!h_flague)
		{
			for (i in $scope.node)
			{
				$http.put('/api/labs'+$rootScope.lab+'/nodes/' + i, {'config' : 0})
				.then(
					function successCallback(response){
						console.log(response);
					},
					function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
			}
		}
	}

	$scope.deleteAllStartupConfig = function(){
		closePopUp();
		openrightHide();
		//console.log("click")
		var h_flague = false;
		$(".free-selected").each(function(){
		  var id = $(this).attr("id");
		  id = id.replace("nodeID_", "");
		  var node = $scope.node[id];
			$http.put('/api/labs'+$rootScope.lab+'/configs/' + id, {'data' : ""})
				.then(
					function successCallback(response){
						console.log(response);
					},
					function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
			h_flague = true;
		})
		if (!h_flague)
		{
			for (i in $scope.node)
			{
				$http.put('/api/labs'+$rootScope.lab+'/configs/' + i, {'data' : ""})
				.then(
					function successCallback(response){
						console.log(response);
					},
					function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
			}
		}
	}
	///////////////////////////////////////////////
	//// Set all startup-cfg for export //END

	///////////////////////////////////////////////
	//// Free select //START
	$scope.freeSelect = function(){
		openrightHide();
		// openrightRemove();
		//$("#freeSelect").toggleClass("activeFreeSelect")
		if($("#freeSelect").hasClass('noneActive'))
		{
			$("#freeSelect").removeClass("noneActive").addClass("activeFreeSelect");
			// console.log('clasa active s-a adaugat');
		}
		else if ($("#freeSelect").hasClass('activeFreeSelect'))
		{
			$("#freeSelect").removeClass("activeFreeSelect").addClass("noneActive");
			$(".element-menu").removeClass("free-selected");
			// console.log('clasa active s-a sters');
		}
		
	}
	///////////////////////////////////////////////
	//// Free select //END

	$scope.elemWasMoved = false;
	$scope.nodeTouching = function(node, $event){
		//$event.preventDefault();
		$scope.elemWasMoved = false;
		// setTimeout(function(){
		// 	$scope.nodeDraggingFlag = true;
		// },100)
		// $(".element-menu").addClass("nodeClick");

	}
	$scope.nodeStopTouching = function(node, $event){
		if ($scope.nodeDraggingFlag == true) {
			// $scope.nodeDraggingFlag = false;
			setTimeout(function(){
				// console.log("rem nodeClick")
				$scope.nodeDraggingFlag = false;
				$(".element-menu").removeClass("nodeClick");
			},100)
		}
		else {
			$(".element-menu").removeClass("nodeClick");
		}
	}

	$scope.nodeDragging = function(node, $event){
		$event.preventDefault();
	}

	$scope.openNodeConsole = function(node, e){
		// console.log("open console1", $scope.nodeDraggingFlag)
		// console.log("open console2", $scope.node[node].upstatus)
		// console.log("open console3", $scope.elemWasMoved)
		if (!$scope.node[node].upstatus) {
			console.log("here1")
			if (!$scope.elemWasMoved) {
				console.log('Node down console locked');
		        // e.preventDefault();
		        // e.stopPropagation();
		        if($('#freeSelect').hasClass('activeFreeSelect'))
		        {
		        	if ($(e.target).parents(".element-menu").hasClass('free-selected'))
		        		$(e.target).parents(".element-menu").removeClass('free-selected');
		        	else
		        		$(e.target).parents(".element-menu").addClass('free-selected');
		        }
		        else
		        {
			        var pos = getPosition(e);
			        var elem_id = e.target.parentElement.parentElement.id;
				    $('#tempElID').val(elem_id);
			        var title = $(e.target.parentElement.parentElement).find(".figcaption-text").text();
			        elem_id = elem_id.replace("nodeID_", "");
			        $("#menu_title_left").text(title + " (" + elem_id + ")");
			        $("#context-menu_leftClick").addClass("context-menu_leftClick--active").css("left", pos.x).css("top", pos.y);
			      	$("#context-menu").removeClass("context-menu--active");
			        console.log("open context-meniu_leftClick");
			        $scope.positionMenu(e);	
			        console.log("positionMenu");
		        }
		        
	    	} 
	        // setTimeout(function() {
	        //   menuState_leftClick = 1
	        // }, 100);
	        e.preventDefault();
	        e.stopPropagation();
			console.log("here2")
		}
		if ($scope.elemWasMoved) 
		{
			e.preventDefault(); 
			console.log('Node draged console locked')
		}
		$scope.nodeDraggingFlag=false;
		console.log("$scope.nodeDraggingFlag",$scope.nodeDraggingFlag);
		//$(".element-menu").removeClass("nodeClick");
	}

	$scope.positionMenu = function(e) {
	    var clickCoords = $scope.getPosition(e);
	    var clickCoordsX = clickCoords.x;
	    var clickCoordsY = clickCoords.y;

		var menu_leftClick = document.getElementById("context-menu_leftClick");
	    var menuWidth_left = document.getElementById("context-menu_leftClick").offsetWidth + 4;
	    var menuHeight_left = document.getElementById("context-menu_leftClick").offsetHeight + 4;

	    var windowWidth = window.innerWidth;
	    var windowHeight = window.innerHeight;

	    if ( (windowWidth - clickCoordsX) < menuWidth_left ) {
	      menu_leftClick.style.left = windowWidth - menuWidth_left + "px";
	    } else {
	      menu_leftClick.style.left = clickCoordsX + "px";
	    }

	    if ( (windowHeight - clickCoordsY) < menuHeight_left ) {
	      menu_leftClick.style.top = windowHeight - menuHeight_left + "px";
	    } else {
	      menu_leftClick.style.top = clickCoordsY + "px";
	    }

	  }

	$scope.getPosition = function(e) {
	    var posx = 0;
	    var posy = 0;
	    if (!e) var e = window.event;
	    
	    if (e.pageX || e.pageY) {
	      posx = e.pageX;
	      posy = e.pageY;
	    } else if (e.clientX || e.clientY) {
	      posx = e.clientX + document.body.scrollLeft + document.documentElement.scrollLeft;
	      posy = e.clientY + document.body.scrollTop + document.documentElement.scrollTop;
	    }
	    return {
	      x: posx,
	      y: posy
	    }
	}


	$scope.textClickDown=false;
	$scope.textDraggingFlag=false;
	
	$scope.textTouching = function(textElement, $event){
		//$event.preventDefault();
		$scope.textClickDown=true;
		//console.log($scope.nodeClickDown)
	}
	
	$scope.textDragging = function(textElement, $event){
		if ($scope.textClickDown && !$scope.textDraggingFlag) $scope.textDraggingFlag = true;
	}
	
	$scope.openTextConsole = function(node, $event){
		if (!$scope.text[node].upstatus) {$event.preventDefault(); console.log('Text down console locked')}
		if ($scope.textDraggingFlag) {$event.preventDefault(); console.log('Text draged console locked')}
		$scope.textClickDown=false;
		$scope.textDraggingFlag=false;
	}
	
	$scope.shapeClickDown=false;
	$scope.shapeDraggingFlag=false;
	
	$scope.shapeTouching = function(textElement, $event){
		//$event.preventDefault();
		$scope.shapeClickDown=true;
		//console.log($scope.nodeClickDown)
	}
	
	$scope.shapeDragging = function(textElement, $event){
		if ($scope.shapeClickDown && !$scope.shapeDraggingFlag) $scope.shapeDraggingFlag = true;
	}
	
	$scope.openShapeConsole = function(node, $event){
		if (!$scope.shape[node].upstatus) {$event.preventDefault(); console.log('Shape down console locked')}
		if ($scope.shapeDraggingFlag) {$event.preventDefault(); console.log('Shape draged console locked')}
		$scope.shapeClickDown=false;
		$scope.shapeDraggingFlag=false;
	}
	

	$scope.openAllObjects = function(){
		$http.get('/api/labs'+$rootScope.lab+'/textobjects').then(
			function successCallback(response){
				console.log(response)
			}
		)
	}
	
	$scope.newConnModal = function(conn){
		if ($scope.ready){
			//console.log(conn)
			$scope.addConnSrc=conn.source;
			$scope.addConnDst=conn.target;
			var src = {};
			src.type = ($scope.addConnSrc.id.search('node') != -1) ? 'node' : 'network';
			var dst = {};
			dst.type = ($scope.addConnDst.id.search('node') != -1) ? 'node' : 'network';
			src.eveID = (src.type == 'node') ? $scope.addConnSrc.id.replace('nodeID_','') : '';
			dst.eveID = (dst.type == 'node') ? $scope.addConnDst.id.replace('nodeID_','') : '';
			
			jsPlumb.detach(conn)
			
			if (src.eveID != '') {if ($scope.node[src.eveID].status != 0) {toastr["error"]("Stop nodes which you want to connect", "Error"); return;} } 
			if (dst.eveID != '') {if ($scope.node[dst.eveID].status != 0) {toastr["error"]("Stop nodes which you want to connect", "Error"); return;} } 
			$scope.openModal('addConn');
			$scope.ready=false;
		}
	}
	$scope.addNewCursor = function(type){
		console.log("type", type)
		$scope.changedCursor = '';
		$('body').removeClass('sidebar-expanded-on-hover').addClass('sidebar-collapse');
		openrightHide();
		switch (type) {
			case 'node':
				$scope.changedCursor = 'node';
				break;
			case 'network':
				$scope.changedCursor = 'network';
				break;
			case 'text':
				$scope.changedCursor = 'text';
				break;
			case 'shape':
				$scope.changedCursor = 'shape';
				break;
			case 'image':
				$scope.changedCursor = 'image';
				break;
			}
			
	}
	
	// $scope.tempElID='';
	// $scope.deleteEl = function(){
	// 	$scope.tempElID=$('#tempElID').val()
 //        console.log("#tempElID:",$('#tempElID').val())
	// 	var type = '';
	// 	var element = '';
	// 	if($scope.tempElID.search('node') != -1) type = 'node' 
	// 	if($scope.tempElID.search('net') != -1) type = 'network' 
	// 	if($scope.tempElID.search('conn') != -1) type = 'conn' 
	// 	if($scope.tempElID.search('text') != -1) {
	// 		type = 'textobject';
	// 		element = 'text';
	// 	} 
	// 	if($scope.tempElID.search('shape') != -1) {
	// 		type = 'textobject';
	// 		element = 'shape';	
	// 	} 
	// 	// if($scope.tempElID.search('image') != -1) type = 'picture' 
	// 	var id = $scope.tempElID.replace(element ? element + 'ID_' : type + 'ID_','');
	// 	if (confirm('Are you sure?')){
	// 			console.log("deteling id + type: "+ id+' '+type)
	// 			$http({
	// 				method: 'DELETE',
	// 				url:'/api/labs'+$rootScope.lab+'/'+type+'s/'+id}).then(
	// 				function successCallback(response){
	// 					console.log(response)
	// 					jsPlumb.select({source:type+'ID_'+id}).detach();
	// 					jsPlumb.select({target:type+'ID_'+id}).detach();
	// 					var selector = element ? element : type; 
	// 					console.log($('#'+selector+'ID_'+id))
	// 					$('#' + selector +'ID_'+id).remove()
	// 				}, function errorCallback(response){
	// 					console.log('Server Error');
	// 					console.log(response);
	// 				}
	// 			);
	// 	}
	// }

	//////////////////////////////////////////////////////////////////////////////////////
	/////////////    DELETE node, network,
	//////////////////////////////////////////////////////////////////////////////////////
	$scope.tempElID='';
	$scope.deleteEl = function(){

		closePopUp();
		$scope.tempElID=$('#tempElID').val()
        console.log("#tempElID:",$('#tempElID').val())
		var type = '';
		var element = '';
		if($scope.tempElID.search('node') != -1) type = 'node' 
		if($scope.tempElID.search('net') != -1) type = 'network' 
		if($scope.tempElID.search('conn') != -1) type = 'conn' 
		if($scope.tempElID.search('text') != -1) {
			type = 'textobject';
			element = 'text';
		} 
		if($scope.tempElID.search('shape') != -1) {
			type = 'textobject';
			element = 'shape';	
		}

		console.log('------------------------------------------------------------------------', type);
		// if($scope.tempElID.search('image') != -1) type = 'picture' 
		var id = $scope.tempElID.replace(element ? element + 'ID_' : type + 'ID_','');
		if (confirm('Are you sure?')){
			console.log("deteling id + type: "+ id+' '+type)
			var selectedNodes = collectSelectedNodeIds();
			var handledBatch = false;
			if (type == 'node' && selectedNodes.length > 0) {
				handledBatch = true;
				$scope.applyBatchNodeAction('delete', selectedNodes, function(data){
					if (!data || !data.data || !data.data.results) {
						return;
					}
					data.data.results.forEach(function(entry){
						if (entry.code === 200) {
							removeNodeElement(entry.id, element ? element : type);
						} else {
							toastr["error"](entry.message, "Error");
						}
					});
				}).catch(function(){
					toastr["error"]('Unable to delete selected nodes', "Error");
				});
			}
			if (!handledBatch) {
				var h_flague = false;
				$(".free-selected").each(function(ii){
					console.log("each select")
					var id = $(this).attr("id");
					console.log(id.indexOf("nodeID_"));
					console.log(id.indexOf("networkID_"));
					if (id.indexOf("nodeID_") != -1) 
					{
						id = id.replace("nodeID_", "");
						var node = $scope.node[id];
						setTimeout(function(){
							$http({
								method: 'DELETE',
								url:'/api/labs'+$rootScope.lab+'/'+type+'s/'+id}).then(
								function successCallback(response){
									console.log(response)
									jsPlumb.select({source:type+'ID_'+id}).detach();
									jsPlumb.select({target:type+'ID_'+id}).detach();
									var selector = element ? element : type; 
									console.log($('#'+selector+'ID_'+id))
									$('#' + selector +'ID_'+id).remove()
								}, function errorCallback(response){
									console.log('Server Error');
									console.log(response);
								}
							);
						}, ii * 750 , id, node);
					}
					if (id.indexOf("networkID_") != -1) 
					{
						id = id.replace("networkID_", "");
						var node = $scope.node[id];
						$http({
							method: 'DELETE',
							url:'/api/labs'+$rootScope.lab+'/'+type+'s/'+id}).then(
							function successCallback(response){
								console.log(response)
								jsPlumb.select({source:type+'ID_'+id}).detach();
								jsPlumb.select({target:type+'ID_'+id}).detach();
								var selector = element ? element : type; 
								console.log($('#'+selector+'ID_'+id))
								$('#' + selector +'ID_'+id).remove()
							}, function errorCallback(response){
								console.log('Server Error');
								console.log(response);
							}
						);
					}
					h_flague = true;
				})
				if (!h_flague)
				{
					console.log("aaa");
					$http({
						method: 'DELETE',
					url:'/api/labs'+$rootScope.lab+'/'+type+'s/'+id}).then(
					function successCallback(response){
						console.log(response)

                        console.log('+++++++++++++++++++++++++++++++');
						

						jsPlumb.select({source: type + 'ID_' + id}).detach();
						jsPlumb.select({target: type + 'ID_' + id}).detach();

						var selector = element ? element : type;
						console.log($('#' + selector + 'ID_' + id))
						$('#' + selector + 'ID_' + id).remove()

					}, function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
				}
			}
	}
	}
	//////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////////////////////////////////////////////////////

	$scope.editEl = function(){
		closePopUp();
		$scope.tempElID=$('#tempElID').val();
		var type = ($scope.tempElID.search('node') != -1) ? 'node' : 'network';
		var id = (type == 'node') ? $scope.tempElID.replace('nodeID_','') : $scope.tempElID.replace('networkID_','');
		$scope.tempEldata = {
			'type': type,
			'id': id
		}
		if (type === 'node'){
			console.log('Open edit node modal')
			$scope.openModal('editNode');
		}
		if (type === 'network'){
			console.log('Open edit network modal')
			$scope.openModal('editNet');
		}
	}
	
	$scope.delConn = function(conn){
		var ifs = {};
		var src = {};
		var dst = {};


		if(!conn || !conn.source){
			// alert('Please try again');
            location.reload();
		}

		// console.log(11111);
		// console.log(conn, 1234);
		src.type = (conn.source.id.search('node') != -1) ? 'node' : 'network';
		// console.log(src.type, '-------------------------------------------------------');
		dst.type = (conn.target.id.search('node') != -1) ? 'node' : 'network';
		// console.log(dst.type, '-------------------------------------------------------');
		src.id = (src.type == 'node') ? conn.source.id.replace('nodeID_','') : conn.source.id.replace('networkID_','');
		// console.log(src.id);
		dst.id = (dst.type == 'node') ? conn.target.id.replace('nodeID_','') : conn.target.id.replace('networkID_','');
		// console.log(dst.id);
		var urlCalls = [];
		ifs = conn.getParameters();
		// console.log(ifs);
		if (ifs.type == 'ethernet'){
		if (src.type == 'node' && dst.type == 'node'){

			console.log('++++++++++++++++++++++++++++++++++++++++++++++++++++++');
			$scope.getIntrfInfo(src.id).then(function(something){
				console.log(something)
				console.log(conn.getParameters())
				var network_id = "";
				var finalPrepare = {}
				var tempObj = {}
				for (var key in something.ethernet){
					if (something.ethernet[key].name==ifs.interfSrc) {
						network_id=something.ethernet[key].network_id;
					}
				}
				for (var key in something.serial){
					var tempsrl=something.serial[key].remote_id+':'+something.serial[key].remote_if
					tempObj[key]=(tempsrl == "0:0")? '' : String(tempsrl);
					jQuery.extend(finalPrepare, tempObj)
				}
				console.log(network_id)
				if (network_id !== '') {
					$http.delete('/api/labs'+$rootScope.lab+'/networks/'+network_id)
					.then(
					function successCallback(response){
						console.log(response)
						jsPlumb.detach(conn);
					}, function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
					)
				}
			})
			
			return;
		} else {
			$scope.getIntrfInfo(src.id).then(function(something){
				ifs = conn.getParameters()
				console.log('11111111111111111111111111111111111111111111111111111');
				console.log(ifs);
				var finalPrepare = {}
				var tempObj = {}
				for (var key in something.ethernet){
					if (something.ethernet[key].name == ifs.interfSrc) {something.ethernet[key].network_id="";
						tempObj[''+key+'']=String(something.ethernet[key].network_id)
						jQuery.extend(finalPrepare, tempObj)
					}
				}
				for (var key in something.serial){
					var tempsrl=something.serial[key].remote_id+':'+something.serial[key].remote_if
					tempObj[key]=(tempsrl == "0:0")? '' : String(tempsrl);
					jQuery.extend(finalPrepare, tempObj)
				}
				
				console.log(finalPrepare)
				$http({
					method: 'PUT',
					url:'/api/labs'+$rootScope.lab+'/nodes/'+src.id+'/interfaces',
					data: finalPrepare}).then(
					function successCallback(response){
						console.log(response)
						jsPlumb.detach(conn);
						//$scope.interfList = response.data.data;
					}, function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
				//console.log($scope.node[src.id].ifList)
			})
		}
		} else if (ifs.type == 'serial'){
			console.log('delete serial connection')
			$scope.getIntrfInfo(src.id).then(function(something){
				var finalPrepare = {}
				var tempObj = {}
				for (var key in something.ethernet){
						tempObj[''+key+'']=(something.ethernet[key].network_id == 0) ? '' : String(something.ethernet[key].network_id)
						jQuery.extend(finalPrepare, tempObj)
				}
				for (var key in something.serial){
					if (something.serial[key].name != ifs.interfSrc){
						var tempsrl=something.serial[key].remote_id+':'+something.serial[key].remote_if
						tempObj[key]=(tempsrl == "0:0")? '' : String(tempsrl);
						jQuery.extend(finalPrepare, tempObj)
					} else {
						tempObj[key]="";
						jQuery.extend(finalPrepare, tempObj)
					}
				}
				console.log(finalPrepare)
				console.log(something)
				$http({
					method: 'PUT',
					url:'/api/labs'+$rootScope.lab+'/nodes/'+src.id+'/interfaces',
					data: finalPrepare}).then(
					function successCallback(response){
						console.log(response)
						jsPlumb.detach(conn);
						//$scope.interfList = response.data.data;
					}, function errorCallback(response){
						console.log('Server Error');
						console.log(response);
					}
				);
				//console.log($scope.node[src.id].ifList)
			})
		}
	}
	
	$scope.getIntrfInfo = function(id){
		var deferred = $q.defer();
		$scope.interfList={};
		$scope.interfListCount=true;
		$http.get('/api/labs'+$rootScope.lab+'/nodes/'+id+'/interfaces').then(
			function successCallback(response){
				//console.log(response)
				$scope.interfList = response.data.data;
				deferred.resolve(response.data.data)
				$scope.node[id].ifList = response.data.data;
				$scope.interfListCount=false;
			}, function errorCallback(response){
				$scope.interfListCount=false;
				deferred.reject('Error')
				console.log('Server Error');
				console.log(response.data);
			}
		);
		return deferred.promise;
	}
	
	$scope.setPosition = function(top,left,id,type,object){
		var sendingData = {};
		if(type == 'text'){
			type = 'textobject';
			id = id.substr(7); // 7 == "textID_".length
			if(object.data && typeof(object.data) == "string"){
				object.data = JSON.parse(new TextDecoderLite('utf-8').decode(toByteArray(object.data)));
			}
			object.data.left = left;
			object.data.top  = top;
			sendingData = object;
			sendingData.data = fromByteArray(new TextEncoderLite('utf-8').encode(JSON.stringify(object.data)));
		} if(type == 'shape'){
			type = 'textobject';
			id = id.substr(8); // 8 == "shapeID_".length
			if(object.data && typeof(object.data) == "string"){
				object.data = JSON.parse(new TextDecoderLite('utf-8').decode(toByteArray(object.data)));
			}
			object.data.left = left;
			object.data.top  = top;
			sendingData = object;
			sendingData.data = fromByteArray(new TextEncoderLite('utf-8').encode(JSON.stringify(object.data)));
		} else {
			sendingData = {'left':left, 'top':top }
		}
		var url = '/api/labs'+$rootScope.lab+'/'+type+'s/'+id;
		if ($location.search().mode === 'collaborate') {
			url += '?mode=collaborate';
		}
		$http({
			method: 'PUT',
			url: url,
			data: sendingData}).then(
			function successCallback(response){
				//console.log(response)
				console.log('Position of '+type+' with id '+id+' saved')
				jsPlumb.repaintEverything();
				//$scope.interfList = response.data.data;
			}, function errorCallback(response){
				console.log('setPosition: [Server Error]');
				console.log(response);
			}
		);
	}
	///////////////////////////////
	//More controllers //START
	ModalCtrl($scope, $uibModal, $log, $rootScope, $http,$window)
	//sidebarCtrl($scope)
	//More controllers //END
	//Escape from all//START
	function escapefunction() {
		window.onkeyup = function(e) {
			if ( e.keyCode === 27 ) {
				$scope.addNewObject={};
				console.log('Esc')
				$('body').removeClass('sidebar-expanded-on-hover').addClass('sidebar-collapse');
				
				if ($scope.changedCursor) $scope.changedCursor = '';

				$('#mainDIV').removeClass("router-cursor").removeClass("network-cursor");
				$('.treeview').addClass("openright");
				$state.reload;
				$("#freeSelect").removeClass("activeFreeSelect").addClass("noneActive");
				$(".element-menu").removeClass("free-selected");
			}
		}
	}
	escapefunction();
	//Escape from all//END
	//Close popup from contextmenu and left click //START
	function closePopUp(){
		$('#context-menu_freeSelect').removeClass('context-menu_freeSelect--active');
		$('#context-menu_leftClick').removeClass('context-menu_leftClick--active');
	}
}

function ObjectLength( object ) {
    var length = 0;
    for( var key in object ) {
        if( object.hasOwnProperty(key) ) {
            ++length;
        }
    }
	return length;
}
function openrightHide() {
	var objects = $(".openright");
	objects.removeClass("openright");
	setTimeout(function(){
		objects.addClass("openright");
	}, 100)
}



// function openrightRemove(){
// 	$(document).on("click", ".openright", function(){
// 		$(this).hide(".openright", 0);
// 		setTimeout(function(){
// 			$(".openright").show();
// 		},10)
// 	});
// }
