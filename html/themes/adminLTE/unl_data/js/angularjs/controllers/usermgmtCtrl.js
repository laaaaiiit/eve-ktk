angular.module("unlMainApp").controller('usermgmtController', function usermgmtController($scope, $http, $rootScope, $uibModal, $log, $location) {
    $scope.testAUTH("/usermgmt"); // Проверка авторизации

    const waitForRole = $scope.$watch(function() {
        return $rootScope.role;
    }, function(newRole) {
        if (!newRole) return; // Ждём, пока роль появится

        if (newRole !== 'admin') {
            showAccessDeniedModal();
            return;
        }

        // ✅ Если админ, продолжаем инициализацию
        init();
        waitForRole(); // Отключаем watch
    });

    function showAccessDeniedModal() {
        $uibModal.open({
            animation: true,
            template: `
                <div class="modal-header">
                    <h4 class="modal-title">Доступ запрещён</h4>
                </div>
                <div class="modal-body">
                    У вас нет доступа к этой странице. Вы будете перенаправлены на главную.
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" ng-click="$close()">Ок</button>
                </div>
            `,
            size: 'sm',
            backdrop: 'static',
            keyboard: false
        }).result.finally(function () {
            $location.path('/');
            $scope.$apply();
        });
    }

    function init() {
        $scope.userdata = '';
        $scope.sessionTime = false;
        $scope.sessionIP = false;
        $scope.currentFolder = false;
        $scope.currentLab = false;
        $scope.edituser = '';

        $('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');

        // Получение списка пользователей
        $scope.getUsersInfo = function() {
            $http.get('/api/users/').then(
                function successCallback(response) {
                    $scope.userdata = response.data.data;
                    $.unblockUI();
                },
                function errorCallback(response) {
                    $.unblockUI();
                    console.log("Unknown Error. Why did API doesn't respond?");
                    $location.path("/login");
                }
            );
        };
        $scope.getUsersInfo();

        // Удаление пользователя
		$scope.deleteUser = function(username) {
			const user = $scope.userdata[username];
			if (!user) {
				alert("Пользователь не найден.");
				return;
			}
		
			const modalInstance = $uibModal.open({
				animation: true,
				template: `
					<div class="modal-header">
						<h4 class="modal-title text-danger">Подтверждение удаления</h4>
					</div>
					<div class="modal-body">
						<p><strong>Вы уверены, что хотите удалить этого пользователя?</strong></p>
						<ul class="list-unstyled">
							<li><strong>Имя пользователя:</strong> {{user.username}}</li>
							<li><strong>Роль:</strong> {{user.role}}</li>
						</ul>
					</div>
					<div class="modal-footer">
						<button class="btn btn-danger" ng-click="$close(true)">Удалить</button>
						<button class="btn btn-default" ng-click="$dismiss()">Отмена</button>
					</div>
				`,
				controller: function($scope) {
					$scope.user = { ...user, username }; // добавляем username отдельно
				},
				size: 'md',
				backdrop: 'static',
				keyboard: false
			});
		
			modalInstance.result.then(function (confirmed) {
				if (confirmed) {
					$.blockUI();
					$http.delete('/api/users/' + username).then(
						function successCallback(response) {
							$scope.getUsersInfo();
							$.unblockUI();
						},
						function errorCallback(response) {
							console.error("Ошибка при удалении пользователя:", response);
							alert("Не удалось удалить пользователя.");
							$.unblockUI();
						}
					);
				}
			});
		};		

        // Конвертация времени
        $scope.timeConverter = function(UNIX_timestamp) {
            var a = new Date(UNIX_timestamp * 1000);
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var year = a.getFullYear();
            var month = months[a.getMonth()];
            var date = a.getDate();
            var hour = a.getHours();
            var min = a.getMinutes();
            var sec = a.getSeconds();
            return `${date} ${month} ${year} ${hour}:${min}:${sec}`;
        };

        // Подключение модальных контроллеров
        ModalCtrl($scope, $uibModal, $log);
    }
});
