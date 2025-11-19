angular.module("unlMainApp").controller('cloudmgmtController', function cloudmgmtController($scope, $http, $rootScope, $uibModal, $log, $location, $cookies) {
    var translations = {
        en: {
            heroEyebrow: 'Integration',
            heroTitle: 'Cloud management',
            heroDescription: 'Bind a user account to a Linux bridge (pnet) declared in /etc/network/interfaces and expose it as a named cloud.',
            heroHint: 'Each record links the username, pnet interface, and friendly cloud alias used inside labs.',
            addCloudButton: 'Add cloud mapping',
            statMappingsLabel: 'Cloud mappings',
            statMappingsHint: 'Named bridges available to lab editors.',
            statUsersLabel: 'Users bound',
            statUsersHint: 'Unique accounts linked to pnets.',
            statPnLabel: 'PNET bridges',
            statPnHint: 'Distinct interfaces referenced in mappings.',
            tableTitle: 'Directory of clouds',
            tableSubtitle: 'Sort by alias, user, or bridge to keep assignments clean.',
            colCloudName: 'Cloud name',
            colCloudDesc: 'Visible label in the lab UI.',
            colUser: 'User',
            colPnet: 'PNET bridge',
            colActions: 'Actions',
            editAction: 'Edit',
            deleteAction: 'Delete',
            emptyStateTitle: 'No clouds are configured yet.',
            emptyStateHint: 'Create your first mapping to expose pnets from /etc/network/interfaces to the topology builder.',
            sidebarMappingsTitle: 'User ↔️ PNET overview',
            sidebarMappingsSubtitle: 'Review who owns which bridges.',
            sidebarUserLabel: 'User',
            sidebarCloudCount: 'clouds',
            sidebarPnetLabel: 'PNET',
            sidebarNoMappings: 'Add mappings to inspect assignments per user.',
            guidelinesTitle: 'PNET checklist',
            guidelinesSubtitle: 'Prepare bridges once inside /etc/network/interfaces and reuse them here.',
            guidelines: [
                'Define auto/iface blocks for every pnet you want to expose (pnet1, pnet2, etc.).',
                'Use descriptive cloud names so lab builders instantly understand where each bridge lands.',
                'Keep usernames unique per mapping to avoid ambiguous bridge ownership.'
            ],
            interfacesSampleTitle: 'Example /etc/network/interfaces',
            interfacesSampleSnippet: 'auto pnet1\niface pnet1 inet manual\n    bridge_ports none\n    bridge_stp off\n    bridge_fd 0\n    bridge_maxwait 0',
            deleteConfirmTitle: 'Delete cloud mapping',
            deleteConfirmBody: 'Remove cloud "{{name}}" and detach {{pnet}} from the selected user?',
            deleteConfirmWarning: 'This action cannot be undone.',
            deleteConfirmConfirm: 'Delete mapping',
            deleteConfirmCancel: 'Cancel',
            deleteFailed: 'Failed to delete cloud mapping.',
            accessDeniedTitle: 'Access denied',
            accessDeniedBody: 'You do not have access to this page. You will be redirected to the dashboard.',
            accessDeniedButton: 'OK',
            modalAddTitle: 'Add cloud mapping',
            modalAddSubtitle: 'Bind a user to a bridge that already exists in /etc/network/interfaces.',
            modalEditTitle: 'Edit cloud mapping',
            modalEditSubtitle: 'Adjust the alias, user, or bridge reference.',
            modalCloudNameLabel: 'Cloud name',
            modalCloudNameHint: 'Allowed symbols: letters, numbers, dash, underscore.',
            modalUserLabel: 'User',
            modalUserHint: 'Use an existing platform username.',
            modalPnetLabel: 'PNET bridge',
            modalPnetHint: 'Use literal names such as pnet1, pnet23, etc.',
            modalValidationCloudName: 'Only A-Z, a-z, 0-9, _ and - are allowed.',
            modalValidationPnet: 'Use pnet01 … pnet9999 defined on the OS.',
            modalCancel: 'Cancel',
            modalCreate: 'Create',
            modalSave: 'Save changes'
        },
        ru: {
            heroEyebrow: 'Интеграция',
            heroTitle: 'Управление облаками',
            heroDescription: 'Свяжите учетную запись пользователя с мостом (pnet), объявленным в /etc/network/interfaces, и используйте его как облако.',
            heroHint: 'Каждая запись объединяет имя пользователя, интерфейс pnet и понятное название облака.',
            addCloudButton: 'Добавить облако',
            statMappingsLabel: 'Связей облаков',
            statMappingsHint: 'Доступные именованные мосты для редакторов лабораторий.',
            statUsersLabel: 'Пользователей привязано',
            statUsersHint: 'Уникальные аккаунты, связанные с pnet.',
            statPnLabel: 'Интерфейсов PNET',
            statPnHint: 'Различные мосты, используемые в связках.',
            tableTitle: 'Каталог облаков',
            tableSubtitle: 'Сортируйте по названию, пользователю или мосту.',
            colCloudName: 'Имя облака',
            colCloudDesc: 'Метка, видимая в интерфейсе лаборатории.',
            colUser: 'Пользователь',
            colPnet: 'Мост PNET',
            colActions: 'Действия',
            editAction: 'Редактировать',
            deleteAction: 'Удалить',
            emptyStateTitle: 'Облака ещё не настроены.',
            emptyStateHint: 'Создайте первую связку, чтобы открыть мосты из /etc/network/interfaces в редакторе топологий.',
            sidebarMappingsTitle: 'Обзор связок',
            sidebarMappingsSubtitle: 'Контроль кто использует какой мост.',
            sidebarUserLabel: 'Пользователь',
            sidebarCloudCount: 'обл.',
            sidebarPnetLabel: 'PNET',
            sidebarNoMappings: 'Добавьте связки, чтобы увидеть распределение по пользователям.',
            guidelinesTitle: 'Чек-лист PNET',
            guidelinesSubtitle: 'Опишите мосты в /etc/network/interfaces и повторно используйте их здесь.',
            guidelines: [
                'Создайте блоки auto/iface для каждого нужного pnet (pnet1, pnet2 и т.д.).',
                'Даёте облакам говорящие названия, чтобы было ясно куда ведёт мост.',
                'Следите, чтобы у пользователя не было дублирующих мостов, иначе возникнет путаница.'
            ],
            interfacesSampleTitle: 'Пример /etc/network/interfaces',
            interfacesSampleSnippet: 'auto pnet1\niface pnet1 inet manual\n    bridge_ports none\n    bridge_stp off\n    bridge_fd 0\n    bridge_maxwait 0',
            deleteConfirmTitle: 'Удаление облака',
            deleteConfirmBody: 'Удалить облако «{{name}}» и отвязать {{pnet}} от пользователя?',
            deleteConfirmWarning: 'Действие необратимо.',
            deleteConfirmConfirm: 'Удалить',
            deleteConfirmCancel: 'Отмена',
            deleteFailed: 'Не удалось удалить облако.',
            accessDeniedTitle: 'Доступ запрещён',
            accessDeniedBody: 'У вас нет прав на эту страницу. Сейчас произойдёт переход на главную.',
            accessDeniedButton: 'Ок',
            modalAddTitle: 'Добавление облака',
            modalAddSubtitle: 'Привяжите пользователя к мосту из /etc/network/interfaces.',
            modalEditTitle: 'Редактирование облака',
            modalEditSubtitle: 'Измените название, пользователя или мост.',
            modalCloudNameLabel: 'Имя облака',
            modalCloudNameHint: 'Допустимы буквы, цифры, дефис и подчёркивание.',
            modalUserLabel: 'Пользователь',
            modalUserHint: 'Укажите существующий логин платформы.',
            modalPnetLabel: 'Мост PNET',
            modalPnetHint: 'Используйте реальные имена, например pnet1, pnet23 и т.п.',
            modalValidationCloudName: 'Допустимы только A-Z, a-z, 0-9, _ и -.',
            modalValidationPnet: 'Используйте pnet01 … pnet9999, описанные в системе.',
            modalCancel: 'Отмена',
            modalCreate: 'Создать',
            modalSave: 'Сохранить'
        }
    };

    $scope.testAUTH("/cloudmgmt");

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

    $scope.$watch(function () { return $rootScope.lang; }, function (newVal, oldVal) {
        if (newVal && newVal !== oldVal) {
            refreshTranslations();
        }
    });

    const waitForRole = $scope.$watch(function () {
        return $rootScope.role;
    }, function (newRole) {
        if (!newRole) return;

        if (newRole !== 'admin') {
            showAccessDeniedModal();
            return;
        }

        init();
        waitForRole();
    });

    function openTailwindModal(config) {
        return $uibModal.open({
            animation: true,
            template: `
                <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm"></div>
                    <div class="relative w-full max-w-md rounded-3xl border border-white/10 bg-gradient-to-br from-slate-950 via-blue-950/70 to-slate-900 text-slate-100 shadow-2xl p-8 space-y-6">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full shrink-0 flex items-center justify-center" ng-class="modal.iconClasses">
                                <i class="{{modal.icon}}"></i>
                            </div>
                            <div>
                                <h4 class="text-xl font-semibold" ng-bind="modal.title"></h4>
                                <p class="text-slate-300 text-sm" ng-bind="modal.body"></p>
                                <p class="text-rose-300 text-xs mt-1" ng-if="modal.warning" ng-bind="modal.warning"></p>
                            </div>
                        </div>
                        <div class="space-y-3" ng-if="modal.items && modal.items.length">
                            <div ng-repeat="item in modal.items">
                                <p class="text-xs uppercase tracking-[0.3em] text-slate-400" ng-bind="item.label"></p>
                                <p class="text-base font-semibold" ng-bind="item.value"></p>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3">
                            <button class="px-4 py-2 rounded-xl border border-white/20 text-slate-200 hover:bg-white/10 transition cursor-pointer"
                                    ng-click="$dismiss()"
                                    ng-bind="modal.cancelLabel"></button>
                            <button class="px-5 py-2 rounded-xl text-white uppercase tracking-[0.2em] shadow-lg transition cursor-pointer"
                                    ng-class="modal.confirmClasses"
                                    ng-click="$close(true)"
                                    ng-bind="modal.confirmLabel"></button>
                        </div>
                    </div>
                </div>
            `,
            controller: ['$scope', function ($scope) {
                var parentScope = $scope.$parent || {};
                var defaults = {
                    icon: 'fa fa-info-circle',
                    iconClasses: 'bg-blue-500/30 text-blue-100',
                    confirmClasses: 'bg-blue-600 hover:bg-blue-500',
                    cancelLabel: (parentScope.t && parentScope.t.deleteConfirmCancel) || 'Cancel',
                    confirmLabel: 'OK',
                    items: []
                };
                $scope.modal = angular.extend(defaults, config || {});
            }],
            size: 'md',
            backdrop: 'static',
            keyboard: false
        }).result;
    }

    function showAccessDeniedModal() {
        openTailwindModal({
            icon: 'fa fa-lock',
            iconClasses: 'bg-rose-500/30 text-rose-200',
            confirmClasses: 'bg-rose-500 hover:bg-rose-400',
            title: ($scope.t && $scope.t.accessDeniedTitle) || 'Access denied',
            body: ($scope.t && $scope.t.accessDeniedBody) || 'Access denied',
            cancelLabel: ($scope.t && $scope.t.accessDeniedButton) || 'OK',
            confirmLabel: ($scope.t && $scope.t.accessDeniedButton) || 'OK'
        }).finally(function () {
            $location.path('/');
            $scope.$applyAsync();
        });
    }

    function init() {
        $scope.clouddata = [];
        $scope.sortedClouds = [];
        $scope.userCloudMap = [];
        $scope.cloudStats = { total: 0, users: 0, pnets: 0 };
        $scope.sortColumn = 'cloudname';
        $scope.reverseSort = false;

        $('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');

        $scope.getCloudsInfo = function () {
            $.blockUI();
            $http.get('/api/clouds').then(
                function successCallback(response) {
                    $scope.clouddata = response.data.data || [];
                    $.unblockUI();
                },
                function errorCallback() {
                    $.unblockUI();
                    console.log("Unknown Error. Why did API doesn't respond?");
                    $location.path("/login");
                }
            );
        };
        $scope.getCloudsInfo();

        $scope.setSort = function (column) {
            if ($scope.sortColumn === column) {
                $scope.reverseSort = !$scope.reverseSort;
            } else {
                $scope.sortColumn = column;
                $scope.reverseSort = false;
            }
            updateCloudViews();
        };

        $scope.sortIcon = function (column) {
            if ($scope.sortColumn !== column) {
                return 'fa fa-sort text-slate-500';
            }
            return $scope.reverseSort ? 'fa fa-arrow-down text-white' : 'fa fa-arrow-up text-white';
        };

        $scope.confirmDeleteCloud = function (cloud) {
            openTailwindModal({
                icon: 'fa fa-trash',
                iconClasses: 'bg-rose-500/30 text-rose-100',
                confirmClasses: 'bg-rose-500 hover:bg-rose-400',
                title: ($scope.t && $scope.t.deleteConfirmTitle) || 'Delete cloud',
                body: formatTemplate(($scope.t && $scope.t.deleteConfirmBody) || 'Delete "{{name}}"?', cloud),
                warning: ($scope.t && $scope.t.deleteConfirmWarning) || '',
                cancelLabel: ($scope.t && $scope.t.deleteConfirmCancel) || 'Cancel',
                confirmLabel: ($scope.t && $scope.t.deleteConfirmConfirm) || 'Delete',
                items: [
                    { label: ($scope.t && $scope.t.colCloudName) || 'Cloud', value: cloud.cloudname },
                    { label: ($scope.t && $scope.t.colUser) || 'User', value: cloud.username },
                    { label: ($scope.t && $scope.t.colPnet) || 'PNET', value: cloud.pnet }
                ]
            }).then(function () {
                deleteCloud(cloud.id);
            });
        };

        function deleteCloud(cloudId) {
            $.blockUI();
            $http.delete('/api/clouds/' + cloudId)
                .then(function () {
                    $scope.getCloudsInfo();
                })
                .catch(function (err) {
                    console.error("Ошибка удаления:", err);
                    toastr["error"](($scope.t && $scope.t.deleteFailed) || "Failed to delete cloud", "Error");
                })
                .finally(function () {
                    $.unblockUI();
                });
        }

        function updateCloudViews() {
            var data = angular.copy($scope.clouddata || []);
            data.sort(function (a, b) {
                return compareByColumn(a, b, $scope.sortColumn, $scope.reverseSort);
            });

            $scope.sortedClouds = data;
            $scope.cloudStats = buildStats(data);
            $scope.userCloudMap = buildUserMap(data);
        }

        function compareByColumn(a, b, column, desc) {
            var valA = normalizeValue(a[column]);
            var valB = normalizeValue(b[column]);
            if (valA < valB) {
                return desc ? 1 : -1;
            }
            if (valA > valB) {
                return desc ? -1 : 1;
            }
            return 0;
        }

        function normalizeValue(value) {
            if (value === null || value === undefined) {
                return '';
            }
            return value.toString().toLowerCase();
        }

        function buildStats(items) {
            var users = {};
            var pnets = {};
            angular.forEach(items, function (item) {
                if (item.username) {
                    users[item.username] = true;
                }
                if (item.pnet) {
                    pnets[item.pnet] = true;
                }
            });
            return {
                total: items.length,
                users: Object.keys(users).length,
                pnets: Object.keys(pnets).length
            };
        }

        function buildUserMap(items) {
            var grouped = {};
            angular.forEach(items, function (item) {
                var username = item.username || '—';
                if (!grouped[username]) {
                    grouped[username] = [];
                }
                grouped[username].push(item);
            });

            return Object.keys(grouped).sort().map(function (username) {
                var clouds = grouped[username].slice().sort(function (a, b) {
                    return normalizeValue(a.cloudname).localeCompare(normalizeValue(b.cloudname));
                });
                return { username: username, clouds: clouds };
            });
        }

        function formatTemplate(template, cloud) {
            if (!template) {
                return '';
            }
            return template.replace('{{name}}', cloud.cloudname || '')
                .replace('{{pnet}}', cloud.pnet || '');
        }

        $scope.$watchCollection(function () { return $scope.clouddata; }, function () {
            updateCloudViews();
        });

        ModalCtrl($scope, $uibModal, $log);
    }
});
