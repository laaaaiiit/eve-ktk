angular.module("unlMainApp").controller('loginController', function loginController($scope, $http, $location, $rootScope, $cookies) {
		$scope.eveversion = $rootScope.EVE_VERSION + "-Community";
		if ($scope.html5 == null) { $scope.html5 = -1; }
		if ($cookies.get('unetlab_session')) {
			$scope.testAUTH("/main");
		}
	$('body').removeClass().addClass('hold-transition login-page');
		$scope.tryLogin = function () {
			$scope.loginMessageInfo = "";
			if (!$scope.username || !$scope.password) {
				$scope.loginMessageInfo = 'Введите имя пользователя и пароль.';
				return;
			}
			$http({
				method: 'POST',
				url: '/api/auth/login',
				data: { "username": $scope.username, "password": $scope.password, "html5": $scope.html5 }
			})
				.then(
					function successCallback(response) {
						if (response.data && response.data.code === 200) {
							blockUI();
							$scope.testAUTH("/main");
						} else {
							var message = 'Не удалось выполнить вход. Попробуйте позже или обратитесь к администратору.';
							if (response.data && response.data.message) {
								message = response.data.message;
							}
							$scope.loginMessageInfo = message;
						}
					},
					function errorCallback(response) {
						var message = 'Не удалось выполнить вход. Попробуйте позже или обратитесь к администратору.';
						if (response.status == 0) {
							message = 'Сервер авторизации недоступен. Проверьте подключение и повторите попытку.';
						} else if (response.status == 400 || response.status == 401) {
							message = 'Неверное имя пользователя или пароль.';
						} else if (response.data && response.data.message) {
							message = response.data.message;
						}
						$scope.loginMessageInfo = message;
					}
				);
		}
	});
