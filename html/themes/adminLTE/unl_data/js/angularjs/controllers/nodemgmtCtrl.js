angular.module("unlMainApp").controller('nodemgmtController', function nodemgmtController($scope, $http, $rootScope, $uibModal, $log, $location) {
    $scope.testAUTH("/nodemgmt"); // Проверка авторизации

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
        $scope.nodedata = [];
        $scope.sortColumn = 'lab';
        $scope.reverseSort = false;

        // Сортировка колонок
        $scope.sortData = function (column) {
            if ($scope.sortColumn === column) {
                $scope.reverseSort = !$scope.reverseSort;
            } else {
                $scope.sortColumn = column;
                $scope.reverseSort = false;
            }
        };

        // Применяем стили
        $('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');

        // Получение всех нод
        $scope.getAllLabNodes = function () {
            $.blockUI();

            $http.get('/api/nodes').then(function (response) {
                if (response.data.status === "success") {
                    $scope.nodedata = response.data.data;
                } else {
                    console.error("Ошибка при получении нод:", response.data.message);
                    $scope.nodedata = [];
                }
                $.unblockUI();
            }).catch(function (error) {
                console.error("Ошибка запроса:", error);
                $.unblockUI();
            });
        };

        $scope.deleteNode = function (nodeId, labPath) {
            const node = $scope.nodedata.find(n => n.id === nodeId && n.lab === labPath);
            if (!node) {
                alert("Нода не найдена.");
                return;
            }

            const modalInstance = $uibModal.open({
                animation: true,
                template: `
                    <div class="modal-header">
                        <h4 class="modal-title text-danger">Подтверждение удаления</h4>
                    </div>
                    <div class="modal-body">
                        <p><strong>Вы уверены, что хотите удалить следующую ноду?</strong></p>
                        <ul class="list-unstyled">
                            <li><strong>Имя:</strong> {{node.name}}</li>
                            <li><strong>Тип:</strong> {{node.type}}</li>
                            <li><strong>Шаблон:</strong> {{node.template}}</li>
                            <li><strong>Лаборатория:</strong> {{node.lab}}</li>
                            <li><strong>ID:</strong> {{node.id}}</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-danger" ng-click="$close(true)">Удалить</button>
                        <button class="btn btn-default" ng-click="$dismiss()">Отмена</button>
                    </div>
                `,
                controller: function ($scope) {
                    $scope.node = node;
                },
                size: 'md',
                backdrop: 'static',
                keyboard: false
            });

            modalInstance.result.then(function (confirmed) {
                if (confirmed) {
                    $.blockUI();
                    $http.delete('/api/labs' + labPath + '/nodes/' + nodeId).then(
                        function successCallback(response) {
                            $scope.getAllLabNodes();
                            $.unblockUI();
                        },
                        function errorCallback(response) {
                            console.error("Ошибка при удалении ноды:", response);
                            alert("Не удалось удалить ноду.");
                            $.unblockUI();
                        }
                    );
                }
            });
        };

        $scope.startNode = function (node) {
            $.blockUI();
            const labPath = '/' + node.lab.replace(/^\/+/, '');
            const url = `/api/labs${labPath}/nodes/${node.id}/start`;

            $http.get(url)
                .then(() => $scope.getAllLabNodes())
                .catch(err => {
                    console.error("Ошибка запуска:", err);
                    alert("Не удалось запустить ноду.");
                })
                .finally(() => $.unblockUI());
        };

        $scope.stopNode = function (node) {
            const modalInstance = $uibModal.open({
                animation: true,
                template: `
                    <div class="modal-header">
                        <h4 class="modal-title">Подтверждение остановки</h4>
                    </div>
                    <div class="modal-body">
                        <p><strong>Остановить ноду "{{node.name}}"?</strong></p>
                        <p>Остановка может привести к удалению несохраненной конфигурации.</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-warning" ng-click="$close(true)">Остановить</button>
                        <button class="btn btn-default" ng-click="$dismiss()">Отмена</button>
                    </div>
                `,
                controller: function($scope) {
                    $scope.node = node;
                },
                size: 'md',
                backdrop: 'static',
                keyboard: false
            });
        
            modalInstance.result.then(function(confirmed) {
                if (confirmed) {
                    $.blockUI();
                    const labPath = '/' + node.lab.replace(/^\/+/, '');
                    const url = `/api/labs${labPath}/nodes/${node.id}/stop`;
        
                    $http.get(url)
                        .then(() => $scope.getAllLabNodes())
                        .catch(err => {
                            console.error("Ошибка остановки ноды:", err);
                            alert("Не удалось остановить ноду.");
                        })
                        .finally(() => $.unblockUI());
                }
            });
        };        

        $scope.wipeNode = function (node) {
            const modalInstance = $uibModal.open({
                animation: true,
                template: `
                    <div class="modal-header">
                        <h4 class="modal-title text-danger">Подтверждение очистки</h4>
                    </div>
                    <div class="modal-body">
                        <p><strong>Вы уверены, что хотите очистить ноду "{{node.name}}"?</strong></p>
                        <p>Операция очистки приведет к утере конфигурации.</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-danger" ng-click="$close(true)">Очистить</button>
                        <button class="btn btn-default" ng-click="$dismiss()">Отмена</button>
                    </div>
                `,
                controller: function($scope) {
                    $scope.node = node;
                },
                size: 'md',
                backdrop: 'static',
                keyboard: false
            });
        
            modalInstance.result.then(function(confirmed) {
                if (confirmed) {
                    $.blockUI();
                    const labPath = '/' + node.lab.replace(/^\/+/, '');
                    const url = `/api/labs${labPath}/nodes/${node.id}/wipe`;
        
                    $http.get(url)
                        .then(() => $scope.getAllLabNodes())
                        .catch(err => {
                            console.error("Ошибка очистки ноды:", err);
                            alert("Не удалось очистить ноду.");
                        })
                        .finally(() => $.unblockUI());
                }
            });
        };

        $scope.stopAllNodes = function () {
            var modalInstance = $uibModal.open({
                animation: true,
                template: `
                    <div class="modal-header">
                        <h4 class="modal-title text-danger">Подтверждение остановки всех нод</h4>
                    </div>
                    <div class="modal-body">
                        <p><strong>Внимание!</strong> При остановке всех нод конфигурация может быть утеряна. Вы уверены, что хотите продолжить?</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-danger" ng-click="$close(true)">Остановить все</button>
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
                    // Запрос к API для остановки всех нод
                    $http.delete('/api/status')
                        .then(function (response) {
                            // При успешном выполнении запроса можно, например, обновить список нод
                            $scope.getAllLabNodes();
                        })
                        .catch(function (error) {
                            console.error("Ошибка при остановке всех нод:", error);
                            alert("Не удалось остановить все ноды.");
                        })
                        .finally(function () {
                            $.unblockUI();
                        });
                }
            });
        };
        
        $scope.getAllLabNodes();
    }
});
