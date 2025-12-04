angular.module("unlMainApp").controller('usermgmtController', function usermgmtController($scope, $http, $rootScope, $uibModal, $log, $location, $cookies, themeService) {
    var translations = {
        en: {
            heroEyebrow: 'Management',
            heroTitle: 'User management',
            heroDescription: 'Manage access, audit active sessions, and keep POD assignments aligned with your labs.',
            addUserButton: 'Add user',
            tableTitle: 'Database of users',
            tableSubtitle: 'Live directory of active accounts',
            colUsername: 'Username',
            colRole: 'Role',
            colSession: 'Last session',
            colIP: 'IP',
            colLab: 'Lab',
            colPod: 'POD',
            colActions: 'Actions',
            hint: 'Need to reset a lab? Edit a user, clear the session, and assign a new POD.',
            editLabel: 'Edit',
            deleteLabel: 'Delete',
            modalEditTitle: 'Edit user',
            modalEditSubtitle: 'Update credentials, role, and POD assignment.',
            modalAddTitle: 'Add new user',
            modalAddSubtitle: 'Provision access for a new collaborator.',
            modalUsernameLabel: 'Username',
            modalPasswordLabel: 'Password',
            modalPasswordConfirmLabel: 'Password confirmation',
            modalRoleLabel: 'Role',
            modalPodLabel: 'POD',
            modalPasswordMismatch: "Passwords don't match",
            modalLanguageLabel: 'Preferred language',
            modalRequiredHint: 'All highlighted fields are required.',
            modalCancel: 'Cancel',
            modalSave: 'Save',
            modalCreate: 'Create',
            deleteConfirmTitle: 'Delete confirmation',
            deleteConfirmBody: 'Are you sure you want to remove this user?',
            deleteConfirmUserLabel: 'Username',
            deleteConfirmRoleLabel: 'Role',
            deleteConfirmConfirm: 'Delete',
            deleteConfirmCancel: 'Cancel',
            userNotFound: 'User not found.',
            deleteFailed: 'Failed to delete user.',
            validationUsernameRequired: 'Username can\'t be empty!',
            validationPasswordRequired: 'Password can\'t be empty!',
            validationPasswordMismatch: 'Passwords don\'t match',
            validationPodUnique: 'Please set unique POD value',
            toastUserCreated: 'User created successfully',
            toastUserUpdated: 'User updated successfully',
            toastUserDeleted: 'User deleted successfully'
        },
        ru: {
            heroEyebrow: 'Управление',
            heroTitle: 'Управление пользователями',
            heroDescription: 'Управляйте доступом, проверяйте активные сессии и контролируйте назначения POD для лабораторий.',
            addUserButton: 'Добавить пользователя',
            tableTitle: 'База пользователей',
            tableSubtitle: 'Актуальный список всех учетных записей',
            colUsername: 'Логин',
            colRole: 'Роль',
            colSession: 'Последняя сессия',
            colIP: 'IP',
            colLab: 'Лаборатория',
            colPod: 'POD',
            colActions: 'Действия',
            hint: 'Нужно сбросить лабораторию? Отредактируйте пользователя, очистите сессию и назначьте новый POD.',
            editLabel: 'Редактировать',
            deleteLabel: 'Удалить',
            modalEditTitle: 'Редактирование пользователя',
            modalEditSubtitle: 'Измените пароль, роль и назначение POD.',
            modalAddTitle: 'Добавление пользователя',
            modalAddSubtitle: 'Создайте учетную запись для нового участника.',
            modalUsernameLabel: 'Логин',
            modalPasswordLabel: 'Пароль',
            modalPasswordConfirmLabel: 'Подтверждение пароля',
            modalRoleLabel: 'Роль',
            modalPodLabel: 'POD',
            modalPasswordMismatch: 'Пароли не совпадают',
            modalLanguageLabel: 'Язык интерфейса',
            modalRequiredHint: 'Все выделенные поля обязательны.',
            modalCancel: 'Отмена',
            modalSave: 'Сохранить',
            modalCreate: 'Создать',
            deleteConfirmTitle: 'Подтверждение удаления',
            deleteConfirmBody: 'Вы уверены, что хотите удалить этого пользователя?',
            deleteConfirmUserLabel: 'Имя пользователя',
            deleteConfirmRoleLabel: 'Роль',
            deleteConfirmConfirm: 'Удалить',
            deleteConfirmCancel: 'Отмена',
            userNotFound: 'Пользователь не найден.',
            deleteFailed: 'Не удалось удалить пользователя.',
            validationUsernameRequired: 'Имя пользователя не может быть пустым!',
            validationPasswordRequired: 'Пароль не может быть пустым!',
            validationPasswordMismatch: 'Пароли не совпадают',
            validationPodUnique: 'Укажите уникальное значение POD',
            toastUserCreated: 'Пользователь успешно создан',
            toastUserUpdated: 'Пользователь успешно обновлен',
            toastUserDeleted: 'Пользователь успешно удалён'
        }
    };

    function resolveLanguage() {
        var cookieLang = ($cookies && $cookies.get('eve_login_lang')) || null;
        var lang = cookieLang || $rootScope.lang || 'ru';
        if (!translations[lang]) {
            lang = 'ru';
        }
        return lang;
    }

    function refreshTranslations() {
        $scope.lang = resolveLanguage();
        $scope.t = translations[$scope.lang];
        $rootScope.lang = $scope.lang;
    }

    refreshTranslations();

    $scope.theme = themeService.sync($rootScope.username);
    $scope.themeClass = function (darkClasses, lightClasses) {
        return ($scope.theme === 'light') ? (lightClasses || '') : (darkClasses || '');
    };

    $scope.$watch(function () { return $rootScope.lang; }, function (newVal, oldVal) {
        if (newVal && newVal !== oldVal) {
            refreshTranslations();
        }
    });
    $scope.$watch(function () { return $rootScope.theme; }, function (val) {
        if (val) { $scope.theme = val; }
    });

	$scope.testAUTH("/usermgmt"); // Проверка авторизации
	init();

    function init() {
        $scope.userdata = '';
        $scope.sessionTime = false;
        $scope.sessionIP = false;
        $scope.currentFolder = false;
        $scope.currentLab = false;
        $scope.edituser = '';
        $scope.sortConfig = { key: 'username', asc: true };
        $scope.sortedUsers = [];

        $('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');

        // Получение списка пользователей
        $scope.getUsersInfo = function() {
            $http.get('/api/users/').then(
                function successCallback(response) {
                    $scope.userdata = response.data.data;
                    updateSortedUsers();
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

        $scope.$watchCollection(function () { return $scope.userdata; }, function () {
            updateSortedUsers();
        });

        $scope.setSort = function(key) {
            if ($scope.sortConfig.key === key) {
                $scope.sortConfig.asc = !$scope.sortConfig.asc;
            } else {
                $scope.sortConfig.key = key;
                $scope.sortConfig.asc = true;
            }
            updateSortedUsers();
        };

        function getComparableValue(user, key) {
            switch (key) {
                case 'username':
                    return (user.username || '').toLowerCase();
                case 'role':
                    return (user.role || '').toLowerCase();
                case 'session':
                    return user.session === null || user.session === undefined ? -1 : Number(user.session);
                case 'ip':
                    return (user.ip || '').toLowerCase();
                case 'lab':
                    return (user.lab || '').toLowerCase();
                case 'pod':
                    var podVal = (user.pod === null || user.pod === undefined) ? -1 : parseInt(user.pod, 10);
                    return isNaN(podVal) ? -1 : podVal;
                default:
                    return '';
            }
        }

        function updateSortedUsers() {
            if (!$scope.userdata || typeof $scope.userdata !== 'object') {
                $scope.sortedUsers = [];
                return;
            }
            var items = Object.keys($scope.userdata).map(function(username) {
                var entry = angular.copy($scope.userdata[username]);
                entry.username = username;
                return entry;
            });

            items.sort(function(a, b) {
                var key = $scope.sortConfig.key;
                var valA = getComparableValue(a, key);
                var valB = getComparableValue(b, key);
                if (valA < valB) return $scope.sortConfig.asc ? -1 : 1;
                if (valA > valB) return $scope.sortConfig.asc ? 1 : -1;
                return 0;
            });
            $scope.sortedUsers = items;
        }

        $scope.sortIcon = function(key) {
            if ($scope.sortConfig.key !== key) {
                return 'fa fa-sort text-slate-500';
            }
            return $scope.sortConfig.asc ? 'fa fa-sort-asc text-blue-300' : 'fa fa-sort-desc text-blue-300';
        };

        $scope.rolePillClass = function (role) {
            var base = 'inline-flex items-center rounded-full px-3 py-1 text-xs uppercase font-semibold';
            var adminClass = $scope.themeClass('bg-blue-500/30 text-blue-100', 'bg-blue-100 text-blue-800');
            var userClass = $scope.themeClass('bg-white/10 text-slate-100', 'bg-slate-200 text-slate-800');
            return base + ' ' + (role === 'admin' ? adminClass : userClass);
        };

        // Удаление пользователя
        $scope.deleteUser = function(username) {
            var t = $scope.t || translations[resolveLanguage()];
            const user = $scope.userdata[username];
            if (!user) {
                alert(t.userNotFound);
                return;
            }
        
            const modalInstance = $uibModal.open({
                animation: true,
                template: `
                    <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                        <div class="absolute inset-0 theme-overlay transition-colors duration-300"></div>
                        <div class="relative w-full max-w-md rounded-3xl border shadow-2xl p-8 space-y-6" ng-class="themeClass('border-white/10 bg-gradient-to-br from-slate-950 via-blue-950/70 to-slate-900 text-slate-100','bg-white border-slate-200 text-slate-900')">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-full flex items-center justify-center" ng-class="themeClass('bg-red-500/20 text-red-300','bg-red-100 text-red-600')">
                                    <i class="fa fa-trash" aria-hidden="true"></i>
                                </div>
                                <div>
                                    <h4 class="text-xl font-semibold" ng-class="themeClass('text-red-300','text-red-600')">` + t.deleteConfirmTitle + `</h4>
                                    <p class="text-sm" ng-class="themeClass('text-slate-400','text-slate-600')">` + t.deleteConfirmBody + `</p>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.35em]" ng-class="themeClass('text-slate-400','text-slate-500')">` + t.deleteConfirmUserLabel + `</p>
                                    <p class="text-lg font-semibold" ng-class="themeClass('text-slate-100','text-slate-900')">{{user.username}}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-[0.35em]" ng-class="themeClass('text-slate-400','text-slate-500')">` + t.deleteConfirmRoleLabel + `</p>
                                    <p class="text-base" ng-class="themeClass('text-slate-100','text-slate-900')">{{user.role}}</p>
                                </div>
                            </div>
                            <div class="flex items-center justify-end gap-3">
                                <button class="px-4 py-2 rounded-xl border transition cursor-pointer" ng-class="themeClass('border-white/20 text-slate-200 hover:bg-white/10','border-slate-200 text-slate-800 hover:bg-slate-100')" ng-click="$dismiss()">` + t.deleteConfirmCancel + `</button>
                                <button class="px-5 py-2 rounded-xl uppercase tracking-[0.25em] shadow-lg transition cursor-pointer" ng-class="themeClass('bg-red-600 text-white hover:bg-red-500','bg-red-600 text-white hover:bg-red-500')" ng-click="$close(true)">` + t.deleteConfirmConfirm + `</button>
                            </div>
                        </div>
                    </div>
                `,
                controller: function($scope) {
                    $scope.user = { ...user, username }; // добавляем username отдельно
				},
                scope: $scope,
				size: 'md',
				backdrop: false,
                windowTemplateUrl: '/themes/adminLTE/unl_data/pages/modals/tailwind-modal-window.html',
                windowClass: 'tailwind-modal-window',
				keyboard: false
			});
		
			modalInstance.result.then(function (confirmed) {
				if (confirmed) {
					$.blockUI();
					$http.delete('/api/users/' + username).then(
						function successCallback(response) {
							$scope.getUsersInfo();
							$.unblockUI();
                            toastr["success"](t.toastUserDeleted || 'User deleted successfully', 'OK');
						},
						function errorCallback(response) {
							console.error("Ошибка при удалении пользователя:", response);
							alert(t.deleteFailed);
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
