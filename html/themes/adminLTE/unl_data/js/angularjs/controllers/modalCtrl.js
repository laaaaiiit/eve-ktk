function ModalCtrl($scope, $uibModal, $log) {

	$scope.modalActions = {
		'addfile': { 'path': '/themes/adminLTE/unl_data/pages/modals/addfile.html', 'controller': 'AddElModalCtrl' },
		'editfile': { 'path': '/themes/adminLTE/unl_data/pages/modals/addfile.html', 'controller': 'AddElModalCtrl' },
		'editLab': { 'path': '/themes/adminLTE/unl_data/pages/modals/addfile.html', 'controller': 'AddElModalCtrl' },
		'adduser': { 'path': '/themes/adminLTE/unl_data/pages/modals/adduser.html', 'controller': 'AddUserModalCtrl' },
		'edituser': { 'path': '/themes/adminLTE/unl_data/pages/modals/edituser.html', 'controller': 'EditUserModalCtrl' },
		'moveto': { 'path': '/themes/adminLTE/unl_data/pages/modals/moveto.html', 'controller': 'MoveToModalCtrl' },
		'default': { 'path': '/themes/adminLTE/unl_data/pages/modals/wtf.html', 'controller': 'ModalInstanceCtrl' },
		'addcloud': { 'path': '/themes/adminLTE/unl_data/pages/modals/addcloud.html', 'controller': 'AddCloudModalCtrl' },
		'editcloud': { 'path': '/themes/adminLTE/unl_data/pages/modals/editcloud.html', 'controller': 'EditCloudModalCtrl' }
	};

	$scope.animationsEnabled = true;

	$scope.openModal = function (action, modalData, size) {
		$scope.modalData = modalData;
		var pathToModal = (action === undefined) ? 'default' : action;
		var modalInstance = $uibModal.open({
			animation: $scope.animationsEnabled,
			templateUrl: $scope.modalActions[pathToModal]['path'],
			controller: $scope.modalActions[pathToModal]['controller'],
			windowTopClass: "fade in out",
			size: size,
			scope: $scope,
			backdrop: (size == 'megalg') ? false : true,
			resolve: {
				data: function () {
					switch (action) {
						case 'addfile':
							return { 'name': $scope.newElementName, 'path': $scope.path };
						case 'editfile':
							$scope.labInfo.fullPathToFile = $scope.fullPathToFile;
							return { 'info': $scope.labInfo, 'path': $scope.path, 'mode': 'edit' };
						case 'editLab':
							$scope.labInfo.fullPathToFile = $scope.fullPathToFile;
							return { 'info': $scope.labInfo, 'path': $scope.path, 'mode': 'edit' };
						case 'adduser':
							return { 'currentUserData': $scope.userdata };
						case 'edituser':
							return { 'username': $scope.modalData };
						case 'moveto':
							return { 'foldersArray': $scope.folderArrayToMove, 'filesArray': $scope.fileArrayToMove, 'path': $scope.path };
						case 'addcloud':
							return { 'currentCloudData': $scope.clouddata };
						case 'editcloud':
							return { 'currentCloudData': $scope.modalData };
						default:
							return { 'wtf': $scope.newElementName, 'path': $scope.path };
					}
				}
			}
		});
		switch (action) {
			case 'addfile':
				modalInstance.result.then(function (result) {
					if (result) {
						$scope.newElementName = '';
						$scope.newElementToggle = false;
						$scope.fileMngDraw($scope.path);
					} else {
						toastr["error"]("Server has error", "Error");
					}
				}, function () {
					//function if user just close modal
					//$log.info('Modal dismissed at: ' + new Date());
				});
				break;
			case 'editfile':
				modalInstance.result.then(function (result) {
					if (result.result) {
						$scope.newElementName = '';
						$scope.newElementToggle = false;
						$scope.getLabInfo(result.name)
						$scope.fileMngDraw($scope.path);
					} else {
						toastr["error"]("Server has error", "Error");
					}
				}, function () {
					//function if user just close modal
					//$log.info('Modal dismissed at: ' + new Date());
				});
				break;
			case 'editLab':
				modalInstance.result.then(function (result) {
					if (result.result) {
						$scope.newElementName = '';
						$scope.newElementToggle = false;
						$scope.getLabInfo(result.name)
						$scope.fileMngDraw($scope.path);
					} else {
						toastr["error"]("Server has error", "Error");
					}
				}, function () {
					//function if user just close modal
					//$log.info('Modal dismissed at: ' + new Date());
				});
				break;
			case 'adduser':
				modalInstance.result.then(function (result) {
					if (result) {
						$scope.getUsersInfo()
					} else {
						toastr["error"]("Server has error", "Error");
					}
				}, function () {
					//function if user just close modal
					//$log.info('Modal dismissed at: ' + new Date());
				});
				break;
			case 'edituser':
				modalInstance.result.then(function (result) {
					if (result) {
						$scope.getUsersInfo()
					} else {
						toastr["error"]("Server has error", "Error");
					}
				}, function () {
					//function if user just close modal
					//$log.info('Modal dismissed at: ' + new Date());
				});
				break;
			case 'moveto':
				modalInstance.result.then(function (result) {
					if (result) {
						$scope.fileMngDraw($scope.pathBeforeMove);
					} else {
						$scope.fileMngDraw($scope.pathBeforeMove);
					}
				}, function () {
					//function if user just close modal
					//$log.info('Modal dismissed at: ' + new Date());
					//$scope.selectAll();
					$scope.allCheckedFlag = false;
					$scope.fileMngDraw($scope.pathBeforeMove);
				});
				break;
			case 'addcloud':
				modalInstance.result.then(function (result) {
					if (result) {
						$scope.getCloudsInfo()
					} else {
						toastr["error"]("Server has error", "Error");
					}
				}, function () {
					//function if user just close modal
					//$log.info('Modal dismissed at: ' + new Date());
				});
				break;
			case 'editcloud':
				modalInstance.result.then(function (result) {
					if (result) {
						$scope.getCloudsInfo();
					} else {
						toastr["error"]("Server has error", "Error");
					}
				}, function () {
					$log.info('Modal dismissed at: ' + new Date());
				});
				break;
			default:
				modalInstance.result.then(function () {
				}, function () {
					$log.info('Modal dismissed at: ' + new Date());
				});
		}
	};
};

// Please note that $uibModalInstance represents a modal window (instance) dependency.
// It is not the same as the $uibModal service used above.
angular.module("unlMainApp").controller('ModalInstanceCtrl', function ModalInstanceCtrl($scope, $uibModalInstance) {

	$scope.closeModal = function () {
		$uibModalInstance.dismiss('cancel');
	};

	$scope.saveLab = $scope.addNewLab;
});
angular.module("unlMainApp").controller('AddElModalCtrl', function AddElModalCtrl($scope, $uibModalInstance, data, $http, $rootScope, $timeout) {

	$scope.blockButtons = false;
	$scope.blockButtonsClass = '';
	$scope.result = false;
	var isEdit = !!(data && data.info);
	$scope.isEdit = isEdit;
	$scope.author = isEdit ? data.info.author : ($rootScope.username || '');
	$scope.description = isEdit ? data.info.description : '';
	$scope.version = isEdit ? data.info.version : 1;
	$scope.body = isEdit ? data.info.body : '';
	$scope.scripttimeout = isEdit ? data.info.scripttimeout : 300;
	$scope.labName = isEdit ? data.info.name : data.name;
	$scope.labPath = data.path;
	$scope.oldName = isEdit ? data.info.name : '';
	$scope.errorClass = '';
	$scope.errorMessage = '';
	$scope.restrictTest = '\\d+';
	$scope.restrictNumber = '^[a-zA-Z0-9-]+$';
	$scope.shared = isEdit ? data.info.shared : false;
	$scope.sharedWith = isEdit ? data.info.sharedWith : '';
	$scope.collaborateAllowed = isEdit ? data.info.collaborateAllowed : false;
	$scope.sharedWithUsers = ($scope.sharedWith || '').split(',').map(function (u) { return u.trim(); }).filter(function (u) { return u.length; });
	$scope.sharedWithInput = '';
	$scope.sharedWithSuggestions = [];
	$scope.sharedUsersLoaded = false;
	$scope.availableUsernames = [];
	$scope.sharedWithError = '';
	$scope.sharedWithFocused = false;
	var sharedWithBlurPromise = null;

	function syncSharedWithString() {
		$scope.sharedWith = $scope.sharedWithUsers.join(',');
	}

	function normalizeSuggestions() {
		var term = ($scope.sharedWithInput || '').toLowerCase();
		$scope.sharedWithSuggestions = $scope.availableUsernames.filter(function (user) {
			if ($scope.sharedWithUsers.indexOf(user) !== -1) {
				return false;
			}
			if (!term) {
				return true;
			}
			return user.toLowerCase().indexOf(term) !== -1;
		});
	}

	$scope.loadSharedUsers = function () {
		if ($scope.sharedUsersLoaded) {
			return;
		}
		$http.get('/api/users/').then(function (response) {
			var list = (response.data && response.data.data) ? response.data.data : [];
			if (!Array.isArray(list) && typeof list === 'object') {
				list = Object.keys(list).map(function (key) { return list[key]; });
			}
			if (!Array.isArray(list)) {
				list = [];
			}
			$scope.availableUsernames = list.map(function (u) { return u.username; }).filter(function (u) { return !!u; });
			$scope.sharedUsersLoaded = true;
			normalizeSuggestions();
		}).catch(function () {
			$scope.availableUsernames = [];
			$scope.sharedUsersLoaded = true;
			normalizeSuggestions();
		});
	};

	$scope.$watch('shared', function (newVal) {
		if (newVal) {
			$scope.loadSharedUsers();
		}
	});

	$scope.addSharedWithUser = function (username) {
		if (!username) { return; }
		if ($scope.sharedWithUsers.indexOf(username) !== -1) { return; }
		if ($scope.sharedUsersLoaded && $scope.availableUsernames.indexOf(username) === -1) {
			$scope.sharedWithError = ($scope.t && $scope.t.validationUserNotFound) || 'User not found';
			return;
		}
		$scope.sharedWithError = '';
		$scope.sharedWithUsers.push(username);
		syncSharedWithString();
		$scope.sharedWithInput = '';
		normalizeSuggestions();
	};

	$scope.removeSharedWithUser = function (username) {
		var idx = $scope.sharedWithUsers.indexOf(username);
		if (idx !== -1) {
			$scope.sharedWithUsers.splice(idx, 1);
			syncSharedWithString();
			normalizeSuggestions();
		}
	};

	$scope.handleSharedWithKey = function (event) {
		if (event.key === 'Enter' || event.key === ',' || event.key === 'Tab') {
			event.preventDefault();
			var candidate = ($scope.sharedWithInput || '').replace(',', '').trim();
			if (candidate !== '') {
				$scope.addSharedWithUser(candidate);
			}
		}
	};

	$scope.onSharedWithInput = function () {
		$scope.sharedWithError = '';
		normalizeSuggestions();
	};

	$scope.onSharedWithFocus = function () {
		if (sharedWithBlurPromise) {
			$timeout.cancel(sharedWithBlurPromise);
			sharedWithBlurPromise = null;
		}
		$scope.sharedWithFocused = true;
		$scope.loadSharedUsers();
		normalizeSuggestions();
	};

	$scope.onSharedWithBlur = function () {
		sharedWithBlurPromise = $timeout(function () {
			$scope.sharedWithFocused = false;
		}, 150);
	};

	$scope.$on('$destroy', function () {
		$scope.sharedWithFocused = false;
		if (sharedWithBlurPromise) {
			$timeout.cancel(sharedWithBlurPromise);
		}
	});

	$scope.addNewLab = function () {

		$scope.path = ($scope.labPath === '/') ? $scope.labPath : $scope.labPath + '/';

		$scope.labName = ($scope.labName || '').replace(/[\',#,$,\",\\,/,%,\*,\,,\.,!]/g, '')
		//$scope.labName = $scope.labName.replace(/[\s]+/g, '_');

		var resolvedAuthor = $scope.author || $rootScope.username || '';

		if (resolvedAuthor === '') {
			$scope.errorMessage = "Author can't be empty";
			$scope.errorClass = 'has-error author';
			return;
		}

		$scope.newdata = {
			'author': resolvedAuthor,
			'description': $scope.description,
			'scripttimeout': $scope.scripttimeout,
			'version': $scope.version,
			'name': $scope.labName,
			'body': $scope.body,
			'path': $scope.path,
			'shared': $scope.shared,
			'sharedWith': $scope.sharedWith,
			'collaborateAllowed': $scope.collaborateAllowed
		}

		if ($scope.labName === '') {
			$scope.errorMessage = "Name can't be empty!";
			$scope.errorClass = 'has-error';
			return;
		}

		if ($scope.shared && $scope.sharedUsersLoaded && $scope.sharedWithUsers.length && $scope.sharedWithError !== '') {
			$scope.errorClass = 'has-error sharedWith';
			return;
		}

		syncSharedWithString();

		$scope.blockButtons = true;
		$scope.blockButtonsClass = 'm-progress';

		var requestConfig = $scope.isEdit ? {
			method: 'PUT',
			url: '/api/labs' + $scope.path + $scope.oldName + '.unl',
			data: $scope.newdata
		} : {
			method: 'POST',
			url: 'api/labs',
			data: $scope.newdata
		};

		$http(requestConfig)
			.then(
				function successCallback(response) {
					$scope.blockButtons = false;
					$scope.blockButtonsClass = '';
					$scope.result = {
						'result': true,
						'name': $scope.path + $scope.labName + '.unl'
					};
					if (!$scope.isEdit) {
						var lab_name = $scope.newdata.name + '.unl';
						$scope.$parent.legacylabopen($scope.newdata.path + lab_name);
					}
					$uibModalInstance.close($scope.result);
				},
				function errorCallback(response) {
					$scope.blockButtons = false;
					$scope.blockButtonsClass = '';
					$scope.result = false;
					if (response.status == 400 && response.data.status == 'fail') {
						$scope.errorMessage = "Lab with the same name found";
						$scope.errorClass = 'has-error';
						return;
					}
					if (response.status == 412 && response.data.status == "unauthorized") {
						console.log("Unauthorized user.")
						$uibModalInstance.dismiss('cancel');
						toastr["error"]("Unauthorized user", "Error");
					}
					console.log(response)
					console.log("Unknown Error. Why did API doesn't respond?")
					//$uibModalInstance.close($scope.result);
					toastr["error"](response.data.message, "Error");
			}
		);
	}

	// Expose handler expected by the template button
	$scope.saveLab = $scope.addNewLab;

	$scope.opacity = function () {
		$(".modal-content").toggleClass("modal-content_opacity");
	};

	$scope.closeModal = function () {
		$uibModalInstance.dismiss('cancel');
	};
});

angular.module("unlMainApp").controller('AddUserModalCtrl', function AddUserModalCtrl($scope, $uibModalInstance, $http, data) {
	var trDict = $scope.t || ($scope.$parent && $scope.$parent.t) || {};
	var tr = function (key, fallback) { return trDict[key] || fallback; };

	$scope.roles = '';
	$scope.selectRole = '';
	$scope.roleArray = [];
	$scope.username = '';
	$scope.name = '';
	$scope.email = '';
	$scope.passwd = '';
	$scope.passwdConfirm = '';
	$scope.role = '';
	$scope.languageOptions = [
		{ key: 'en', label: 'English' },
		{ key: 'ru', label: 'Русский' }
	];
	$scope.selectedLanguage = ($scope.$parent && $scope.$parent.lang) || 'en';
	$scope.podArray = [];
	$scope.expiration = '-1';
	$scope.restrictNumber = '^[a-zA-Z0-9-]+$';
	$scope.patternEmail = '[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,3}$';
	//Generate unique POD //START
	var podArrayIndex = 0;
	for (var key in data.currentUserData) {
		$scope.podArray[podArrayIndex] = parseInt(data.currentUserData[key].pod)
		podArrayIndex++
	}
	$scope.pod = 0;
	for (i = 0; i < $scope.podArray.length + 10; i++) {
		$scope.pod++;
		if ($scope.podArray.indexOf($scope.pod) == -1) {
			break;
		}
	}
	//Generate unique POD //END
	$scope.pexpiration = '-1';
	$scope.errorClass = '';
	$scope.errorMessage = '';
	$scope.result = false;
	$scope.blockButtons = false;
	$scope.blockButtonsClass = '';

	$scope.closeModal = function () {
		$uibModalInstance.dismiss('cancel');
	};
	$http({
		method: 'GET',
		url: '/api/list/roles'
	})
		.then(
			function successCallback(response) {
				//console.log(response.data.data)
				$scope.roles = response.data.data;
				//$scope.roleArray = 
				$.map($scope.roles, function (value, index) {
					$scope.roleArray[value] = index;
				});
				//console.log($scope.roleArray)
			},
			function errorCallback(response) {
				console.log(response)
				console.log("Unknown Error. Why did API doesn't respond?")
				$uibModalInstance.close($scope.result);
			}
		);

	$scope.addNewUser = function () {
		$scope.errorClass = '';
		$scope.errorMessage = "";
		$scope.podError = false;
		$scope.username = $scope.username.replace(/[\',#,$,@,\",\\,/,%,\*,\,,\.,(,),:,;,^,&,\[,\],|]/g, '')
		if ($scope.passwdConfirm != $scope.passwd) { $scope.errorClass = 'has-error passwdConfirm'; $scope.errorMessage = tr('validationPasswordMismatch', "Passwords don't match"); }
		if ($scope.passwdConfirm == '') { $scope.errorClass = 'has-error passwdConfirm'; $scope.errorMessage = tr('validationPasswordRequired', "Password can't be empty!"); }
		if ($scope.passwd == '') { $scope.errorClass = 'has-error passwd'; $scope.errorMessage = tr('validationPasswordRequired', "Password can't be empty!"); }
		if ($scope.username == '') { $scope.errorClass = 'has-error username'; $scope.errorMessage = tr('validationUsernameRequired', "Username can't be empty!"); }
		if ($scope.passwd == 'whereismypassword?') { $scope.passwd = ''; }
		if ($scope.errorClass != '') { return; }

		$http.get('/api/users/').then(function (response) {
			//Compare unique POD //START
			for (var key in response.data.data) {
				if (parseInt(response.data.data[key].pod) == parseInt($scope.pod) && response.data.data[key].username != $scope.username) {
					$scope.podError = true;
					break;
				}
			}
			//Compare unique POD //END

		}).then(function (response) {

			if ($scope.podError) {
				toastr["error"](tr('validationPodUnique', "Please set unique POD value"), "Error");
				return;
			}

			$scope.blockButtons = true;
			$scope.blockButtonsClass = 'm-progress';

			var displayName = $scope.name || $scope.username;
			var emailValue = $scope.email || '';

			$scope.newdata = {
				"username": $scope.username,
				"name": displayName,
				"email": emailValue,
				"password": $scope.passwd,
				"role": $scope.roleArray[$scope.selectRole],
				"expiration": $scope.expiration,
				"pod": parseInt($scope.pod, 10),
				//"pod": -1,
				"lang": $scope.selectedLanguage,
				"pexpiration": $scope.pexpiration,
			}

			$http({
				method: 'POST',
				url: '/api/users',
				data: $scope.newdata
			})
				.then(
					function successCallback(response) {
						toastr["success"](tr('toastUserCreated', "User created successfully"), "OK");
						$scope.result = true;
						$uibModalInstance.close($scope.result);
					},
					function errorCallback(response) {
						console.log(response)
						console.log("Unknown Error. Why did API doesn't respond?")
						if (response.status == 412 && response.data.status == "unauthorized") {
							console.log("Unauthorized user.")
							$uibModalInstance.dismiss('cancel');
							toastr["error"]("Unauthorized user", "Error");
						}
						toastr["error"](response.data.message, "Error");
					}).finally(function () {
						$scope.blockButtons = false;
						$scope.blockButtonsClass = '';
					});
		});
	}

});

angular.module("unlMainApp").controller('AddCloudModalCtrl', function ($scope, $uibModalInstance, $http) {
	$scope.cloud = {
		cloudname: '',
		username: '',
		pnet: ''
	};

	$scope.result = false;

	$scope.closeModal = function () {
		$uibModalInstance.dismiss('cancel');
	};

	$scope.addCloud = function (cloud) {
		if (!cloud.cloudname || !cloud.username || !cloud.pnet) {
			toastr["error"]("Please fill in all required fields", "Validation Error");
			return;
		}

		$http({
			method: 'POST',
			url: '/api/clouds',
			data: {
				cloudname: cloud.cloudname,
				username: cloud.username,
				pnet: cloud.pnet
			}
		}).then(
			function successCallback(response) {
				toastr["success"]("Cloud successfully created", "Success");
				$scope.result = true;
				$uibModalInstance.close($scope.result);
			},
			function errorCallback(response) {
				console.error("Error adding cloud", response);
				toastr["error"](response.data?.message || "Failed to add cloud", "Error");
			}
		);
	};
});

angular.module("unlMainApp").controller('EditCloudModalCtrl', function ($scope, $uibModalInstance, $http, data) {
	$scope.cloud = angular.copy(data.currentCloudData);
	$scope.result = false;
	$scope.errorMessage = '';

	$scope.closeModal = function () {
		$uibModalInstance.dismiss('cancel');
	};

	$scope.editCloud = function () {
		if (!$scope.cloud.cloudname || !$scope.cloud.username || !$scope.cloud.pnet) {
			$scope.errorMessage = "Please fill in all required fields";
			return;
		}

		$http({
			method: 'PUT',
			url: '/api/clouds/' + $scope.cloud.id,
			data: {
				cloudname: $scope.cloud.cloudname,
				username: $scope.cloud.username,
				pnet: $scope.cloud.pnet
			}
		}).then(
			function successCallback(response) {
				toastr["success"]("Cloud successfully updated", "Success");
				$scope.result = true;
				$uibModalInstance.close($scope.result);
			},
			function errorCallback(response) {
				console.error("Error updating cloud", response);
				$scope.errorMessage = response.data?.message || "Failed to update cloud";
				toastr["error"]($scope.errorMessage, "Error");
			}
		);
	};
});

angular.module("unlMainApp").controller('EditUserModalCtrl', function EditUserModalCtrl($scope, $uibModalInstance, data, $http) {

	var trDictEdit = $scope.t || ($scope.$parent && $scope.$parent.t) || {};
	var trEdit = function (key, fallback) { return trDictEdit[key] || fallback; };

	$scope.roles = '';
	$scope.selectRole = '';
	$scope.roleArray = [];
	$scope.username = data.username;
	$scope.name = '';
	$scope.email = '';
	$scope.passwd = '';
	$scope.passwdConfirm = '';
	$scope.role = '';
	$scope.languageOptions = [
		{ key: 'en', label: 'English' },
		{ key: 'ru', label: 'Русский' }
	];
	$scope.selectedLanguage = ($scope.$parent && $scope.$parent.lang) || 'en';
	$scope.expiration = '-1';
	$scope.pod = 1;
	$scope.pexpiration = '-1';
	$scope.errorClass = '';
	$scope.errorMessage = '';
	$scope.podError = false;
	$scope.result = false;
	$scope.blockButtons = false;
	$scope.blockButtonsClass = '';
	$scope.restrictNumber = '^[a-zA-Z0-9-]+$';
	$scope.patternEmail = '[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+[\.][a-zA-Z]{2,3}$';

	$http({
		method: 'GET',
		url: '/api/list/roles'
	})
		.then(
			function successCallback(response) {
				//console.log(response.data.data['admin'])
				$scope.roles = response.data.data;
				//$scope.roleArray = 
				$.map($scope.roles, function (value, index) {
					$scope.roleArray[value] = index;
				});
				$scope.getuserInfo();
			},
			function errorCallback(response) {
				console.log(response)
				console.log("Unknown Error. Why did API doesn't respond?")
			}
		);

	$scope.getuserInfo = function () {
		$http({
			method: 'GET',
			url: '/api/users/' + data.username
		})
			.then(
				function successCallback(response) {
					//console.log(response.data.data)
					$scope.userinfo = response.data.data;
					$scope.username = data.username;
					$scope.name = $scope.userinfo.name;
					$scope.email = $scope.userinfo.email;
					$scope.passwd = 'whereismypassword?';
					$scope.passwdConfirm = 'whereismypassword?';
					$scope.role = $scope.userinfo.role;
					$scope.expiration = '-1';
					var parsedPod = parseInt($scope.userinfo.pod, 10);
					$scope.pod = isNaN(parsedPod) ? 0 : parsedPod;
					$scope.selectedLanguage = $scope.userinfo.lang || 'en';
					$scope.pexpiration = '-1';
					$scope.selectRole = $scope.roles[$scope.role]
				},
				function errorCallback(response) {
					console.log(response)
					console.log("Unknown Error. Why did API doesn't respond?")
				}
			).finally(function () { $scope.selectRole = $scope.roles[$scope.role] });
	}

	$scope.editUser = function () {

		$scope.errorClass = '';
		$scope.errorMessage = "";
		if ($scope.passwdConfirm != $scope.passwd) { $scope.errorClass = 'has-error passwdConfirm'; $scope.errorMessage = trEdit('validationPasswordMismatch', "Passwords don't match"); }
		if ($scope.passwdConfirm == '') { $scope.errorClass = 'has-error passwdConfirm'; $scope.errorMessage = trEdit('validationPasswordRequired', "Password can't be empty!"); }
		if ($scope.passwd == '') { $scope.errorClass = 'has-error passwd'; $scope.errorMessage = trEdit('validationPasswordRequired', "Password can't be empty!"); }
		if ($scope.passwd == 'whereismypassword?') { $scope.passwd = ''; }
		if ($scope.errorClass != '') { return; }

		$http.get('/api/users/').then(function (response) {

			//Compare unique POD //START
			for (var key in response.data.data) {
				var existingPod = parseInt(response.data.data[key].pod, 10);
				if (existingPod == parseInt($scope.pod, 10) && response.data.data[key].username != $scope.username) {
					$scope.podError = true; break;
				}
			}
			//Compare unique POD //END
		}).then(function (response) {
			if ($scope.podError) { toastr["error"](trEdit('validationPodUnique', "Please set unique POD value"), "Error"); return; }

			$scope.blockButtons = true;
			$scope.blockButtonsClass = 'm-progress';

			$scope.newdata = {
				"username": $scope.username,
				"name": $scope.name,
				"email": $scope.email,
				"password": $scope.passwd,
				"role": $scope.roleArray[$scope.selectRole],
				"expiration": $scope.expiration,
				"pod": parseInt($scope.pod, 10),
				"lang": $scope.selectedLanguage,
				"pexpiration": $scope.pexpiration,
			}

			$http({
				method: 'PUT',
				url: '/api/users/' + data.username,
				data: $scope.newdata
			})
				.then(
					function successCallback(response) {
						toastr["success"](trEdit('toastUserUpdated', "User updated successfully"), "OK");
						$scope.result = true;
						$uibModalInstance.close($scope.result);
					},
					function errorCallback(response) {
						console.log(response)
						console.log("Unknown Error. Why did API doesn't respond?")
						if (response.status == 412 && response.data.status == "unauthorized") {
							console.log("Unauthorized user.")
							$uibModalInstance.dismiss('cancel');
							toastr["error"]("Unauthorized user", "Error");
						}
						$uibModalInstance.close($scope.result);
						toastr["error"](response.data.message, "Error");
					}).finally(function () {
						$scope.blockButtons = false;
						$scope.blockButtonsClass = '';
					});
		});
	}


	$scope.closeModal = function () {
		$uibModalInstance.dismiss('cancel');
	};

});

angular.module("unlMainApp").controller('MoveToModalCtrl', function MoveToModalCtrl($scope, $uibModalInstance, data, $http, $location, $interval, $rootScope, themeService) {

	$scope.filedata = data.filesArray
	$scope.folderdata = data.foldersArray
	$scope.path = data.path
	$scope.pathForTest = ($scope.path === '/') ? $scope.path : $scope.path + '/';
	$scope.errorMessage = "";
	$scope.folderSearchList = [];
	$scope.currentSearchPath = '';
	$scope.newpath = "";
	$scope.openDropdown = "";
	$scope.pathDeeper = 0;
	$scope.pathDeeperCheck = 0;
	$scope.apiSearch = false;
	$scope.localSearch = "";
	$scope.blockButtons = false;
	$scope.blockButtonsClass = '';
	$scope.theme = themeService.sync($rootScope.username);
	$scope.themeClass = function (darkClasses, lightClasses) {
		return ($scope.theme === 'light') ? (lightClasses || '') : (darkClasses || '');
	};
	$scope.$watch(function () { return $rootScope.theme; }, function (val) {
		if (val) { $scope.theme = val; }
	});
	$scope.$watch(function () { return $rootScope.username; }, function (val, oldVal) {
		if (val && val !== oldVal) {
			$scope.theme = themeService.sync(val);
		}
	});
	// $scope.inputSlash=$('#newPathInput');
	//$("#newPathInput").dropdown();
	console.log($scope.filedata)
	console.log($scope.folderdata)

	// $scope.inputSlash = function(){
	// 	$('#newPathInput').focus();
	// 	var inputSlash = $('#newPathInput').val();
	// 	inputSlash.val('/');
	// 	inputSlash.val(inputSlash);
	// }

	$scope.fastSearch = function (pathInput) {
		$scope.errorMessage = "";
		var re = /^\//;
		//console.log($scope.newpath.search(re))
		if (pathInput == "" || pathInput.search(re) == -1) { $scope.openDropdown = ""; return; }
		var fullPathSplit = $scope.newpath.split('/')
		var pathSearch = '';
		console.log(fullPathSplit)
		$scope.localSearch = fullPathSplit[fullPathSplit.length - 1];
		console.log(fullPathSplit.length)
		if ($scope.pathDeeperCheck > fullPathSplit.length - 1) $scope.pathDeeper = fullPathSplit.length - 2
		$scope.pathDeeperCheck = fullPathSplit.length - 1
		for (z = 0; z < (fullPathSplit.length - 1); z++) {
			pathSearch += fullPathSplit[z] + '/'
		}
		console.log(pathSearch)
		if ($scope.pathDeeper < fullPathSplit.length - 1) {
			$scope.localSearch = '';
			$scope.apiSearch = true;
			$scope.openDropdown = "open";
			$scope.pathDeeper = fullPathSplit.length - 1
			console.log('API search')
			$scope.currentSearchPath = pathSearch;
			if (pathSearch != '/') pathSearch = pathSearch.replace(/\/$/, '');
			$http.get('/api/folders' + pathSearch).then(
				function successCallback(response) {
					$scope.folderSearchList = response.data.data.folders
					if ($scope.folderSearchList.length == 1) $scope.openDropdown = "";
					console.log(response)
					$scope.apiSearch = false;
				},
				function errorCallback(response) {
					console.log(response)
					$scope.apiSearch = false;
					if (response.status == 412 && response.data.status == "unauthorized") {
						console.log("Unauthorized user.")
						$uibModalInstance.dismiss('cancel');
						toastr["error"]("Unauthorized user", "Error");
					}
					//console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");
				}
			);
		} else {
			if ($scope.localSearch == '') { $scope.openDropdown = ""; return; }
			$scope.openDropdown = "open";
			console.log('Local Search');
			console.log($scope.localSearch)

		}
	}
	$scope.fastSearchFast = function (foldername) {
		var fastPath = $scope.currentSearchPath + foldername + '/';
		$scope.newpath = fastPath;
		$scope.fastSearch(fastPath);
		$("#newPathInput").focus();

	}

	$scope.deselect = function () {

	}

	$scope.move = function () {
		$scope.openDropdown = "";
		$scope.folderfound = true;
		var re = /^\/.*\/$/;
		$scope.newpath = "/" + $scope.newpath
		console.log($scope.newpath.search(re))
		$scope.errorMessage = "";
		if ($scope.newpath == "") { $scope.errorMessage = "New path can't be empty"; return; }
		if ($scope.newpath.search(re) == -1 && $scope.newpath != "/") { $scope.errorMessage = "Unknown path format, be sure that you added '/' to the end"; return; }
		if ($scope.pathForTest == $scope.newpath) { $scope.errorMessage = "Path can't be the same"; return; }

		for (i = 0; i < $scope.folderdata.length; i++) {
			if ($scope.pathForTest + $scope.folderdata[i] + '/' == $scope.newpath) { $scope.errorMessage = "You can't select this directory"; return; }
			//console.log($scope.pathForTest+$scope.folderdata[i][0]+'/')
		}
		$http.get('/api/folders' + $scope.newpath.replace(/\/$/, '')).then(
			function successCallback(response) {
				console.log(response)
			},
			function errorCallback(response) {
				console.log(response)
				console.log(response.status)
				console.log(response.statusText)
				if (response.status == 404 && response.statusText == 'Not Found') { $scope.errorMessage = "You set incorrect path. Folder no found!"; $scope.folderfound = false; return; }
				//console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");
			}
		).finally(function () {
			if ($scope.folderfound) {
				$scope.blockButtons = true;
				$scope.blockButtonsClass = 'm-progress';
				var folderTester = $scope.folderdata.length
				var fileTester = $scope.filedata.length
				var stopTester = 5;
				if ($scope.folderdata.length > 0)
					for (fo = 0; fo < $scope.folderdata.length; fo++) {
						///Move Folders///START
						$http({
							method: 'PUT',
							url: '/api/folders' + $scope.pathForTest + $scope.folderdata[fo],
							data: { "path": $scope.newpath + $scope.folderdata[fo] }
						})
							.then(
								function successCallback(response) {
									console.log(response)
									folderTester--
								},
								function errorCallback(response) {
									console.log(response)
									if (response.status == 412 && response.data.status == "unauthorized") {
										console.log("Unauthorized user.")
										$uibModalInstance.dismiss('cancel');
										toastr["error"]("Unauthorized user", "Error");
									}
									if (response.data.message == "Destination folder already exists (60046).") { folderTester--; toastr["error"]("Destination folder already exists", "Error"); }
									console.log("Unknown Error. Why did API doesn't respond?");
									//$location.path("/login");
								}
							);
					}
				///Move Folders///END
				/////////////////////
				//Edit APPLY for File //START
				if ($scope.filedata.length > 0)
					for (fi = 0; fi < $scope.filedata.length; fi++) {
						var tempPathNew = ($scope.newpath == '/') ? $scope.newpath : $scope.newpath.replace(/\/$/, '');
						$http({
							method: 'PUT',
							url: '/api/labs' + $scope.pathForTest + $scope.filedata[fi] + '/move',
							data: { "path": tempPathNew }
						})
							.then(
								function successCallback(response) {
									console.log(response)
									fileTester--
								},
								function errorCallback(response) {
									console.log(response)
									if (response.data.message == "Lab already exists (60016).") { fileTester--; toastr["error"]("Lab already exists", "Error"); }
									if (response.status == 412 && response.data.status == "unauthorized") {
										console.log("Unauthorized user.")
										$uibModalInstance.dismiss('cancel');
										toastr["error"]("Unauthorized user", "Error");
									}
									toastr["error"](response.data.message, "Error");
									console.log("Unknown Error. Why did API doesn't respond?");
									$uibModalInstance.dismiss('cancel');
								}
							);
						//Edit APPLY for File //END
					}
				$interval(function () {
					console.log
					if ((folderTester <= 0 && fileTester <= 0) || stopTester == 0) {
						$scope.result = true; $uibModalInstance.close($scope.result);
						$scope.blockButtons = false;
						$scope.blockButtonsClass = '';
						return;
					}
					else stopTester--
				}, 1000);
			}
		})

		console.log($scope.errorMessage)
	}

	$scope.closeModal = function () {
		$uibModalInstance.dismiss('cancel');
	};

});
