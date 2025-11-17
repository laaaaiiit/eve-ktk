angular.module("unlMainApp").controller('cloudmgmtController', function cloudmgmtController($scope, $http, $rootScope, $uibModal, $log, $location) {
    $scope.testAUTH("/cloudmgmt"); // Проверка авторизации

    const waitForRole = $scope.$watch(function () {
        return $rootScope.role;
    }, function (newRole) {
        if (!newRole) return; // Ждём, пока роль появится

        if (newRole !== 'admin') {
            showAccessDeniedModal();
            return;
        }

        // ✅ Если роль admin, инициализируем
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
            $scope.$apply(); // Обновляем Angular после $location.path
        });
    }

    function init() {
        $scope.clouddata = [];
        $scope.cloudId = 0;

        $scope.sortColumn = 'cloudname'; // начальная колонка для сортировки
        $scope.reverseSort = false;

        // Функция сортировки
        $scope.sortData = function(column) {
            if ($scope.sortColumn === column) {
                $scope.reverseSort = !$scope.reverseSort;
            } else {
                $scope.sortColumn = column;
                $scope.reverseSort = false;
            }
        };

        // Получение списка cloud
        $scope.getCloudsInfo = function () {
            $http.get('/api/clouds').then(
                function successCallback(response) {
                    $scope.clouddata = response.data.data;

                    if ($scope.clouddata.length > 0) {
                        $scope.cloudId = $scope.clouddata[0].id;  // Устанавливаем cloudId первого облака
                    }

                    $.unblockUI();
                },
                function errorCallback(response) {
                    $.unblockUI();
                    console.log("Unknown Error. Why did API doesn't respond?");
                    $location.path("/login");
                }
            );
        };
        $scope.getCloudsInfo();

        $scope.deleteCloud = function (cloudId) {
            const modalInstance = $uibModal.open({
                animation: true,
                template: `
                    <div class="modal-header">
                        <h4 class="modal-title text-danger">Подтверждение удаления</h4>
                    </div>
                    <div class="modal-body">
                        <p><strong>Вы уверены, что хотите удалить это облако?</strong></p>
                        <p>Операция необратима.</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-danger" ng-click="$close(true)">Удалить</button>
                        <button class="btn btn-default" ng-click="$dismiss()">Отмена</button>
                    </div>
                `,
                size: 'md',
                backdrop: 'static',
                keyboard: false
            });

            modalInstance.result.then(function (confirmed) {
                if (confirmed) {
                    $.blockUI();
                    $http.delete('/api/clouds/' + cloudId)
                        .then(() => $scope.getCloudsInfo())
                        .catch(err => {
                            console.error("Ошибка удаления:", err);
                            alert("Не удалось удалить облако.");
                        })
                        .finally(() => $.unblockUI());
                }
            });
        };

        // Применяем стили
        $('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');

        // Подключение модальных контроллеров
        ModalCtrl($scope, $uibModal, $log);
    }
});
