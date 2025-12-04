angular.module("unlMainApp").controller('nodemgmtController', function nodemgmtController($scope, $http, $rootScope, $uibModal, $log, $location, $cookies, $q, $timeout, themeService) {
    var translations = {
        en: {
            heroEyebrow: 'Operations',
            heroTitle: 'Node management',
            heroDescription: 'Break down every running node by owner and lab before you pull the plug.',
            stopAllButton: 'Stop all nodes',
            stopAllHint: 'Force-shutdown every running node on the platform.',
            cardsTitle: 'Users and their running nodes',
            cardsSubtitle: 'Each card aggregates the sysstat categories per user.',
            labCountLabel: 'Labs',
            viewLabs: 'View labs',
            hideLabs: 'Hide labs',
            stopUserButton: 'Stop user nodes',
            startLabButton: 'Start lab nodes',
            stopLabButton: 'Stop lab nodes',
            labSectionTitle: 'Labs breakdown',
            labTotalLabel: 'Running nodes',
            labAllNodesLabel: 'Total nodes',
            toggleNodesOpen: 'Show nodes',
            toggleNodesClose: 'Hide nodes',
            emptyState: 'No nodes found across labs.',
            noLabs: 'No labs found for this user.',
            unknownLabName: 'Unnamed lab',
            totalRunningLabel: 'Total running nodes',
            totalNodesLabel: 'Total nodes',
            modalUserLabel: 'User',
            modalLabLabel: 'Lab',
            stopAllConfirmTitle: 'Stop all nodes',
            stopAllConfirmBody: 'Stopping every node may lead to lost configuration. Continue?',
            stopAllConfirmConfirm: 'Stop all',
            stopAllConfirmCancel: 'Cancel',
            stopUserConfirmTitle: 'Stop user nodes',
            stopUserConfirmBody: 'Stop every node that belongs to this user? All running labs under this owner will be halted.',
            stopUserConfirmConfirm: 'Stop',
            stopUserConfirmCancel: 'Cancel',
            stopUserNoLabs: 'This user does not have labs to stop.',
            stopUserFailed: 'Failed to stop user nodes.',
            stopLabConfirmTitle: 'Stop lab nodes',
            stopLabConfirmBody: 'Stop every node in this lab? Active sessions will be interrupted.',
            stopLabConfirmConfirm: 'Stop',
            stopLabConfirmCancel: 'Cancel',
            startLabConfirmTitle: 'Start lab nodes',
            startLabConfirmBody: 'Start every node in this lab? Nodes with snapshots will boot from their configured images.',
            startLabConfirmConfirm: 'Start',
            startLabConfirmCancel: 'Cancel',
            stopLabNoNodes: 'This lab does not contain nodes to stop.',
            stopLabFailed: 'Failed to stop lab nodes.',
            wipeLabButton: 'Wipe lab',
            wipeLabConfirmTitle: 'Wipe lab nodes',
            wipeLabConfirmBody: 'Reset every node in this lab? Current configs will be lost.',
            wipeLabConfirmConfirm: 'Wipe lab',
            wipeLabConfirmCancel: 'Cancel',
            wipeLabFailed: 'Failed to wipe lab nodes.',
            startLabFailed: 'Failed to start lab nodes.',
            loadingTitle: 'Loading data',
            loadingSubtitle: 'Collecting nodes and labs from every user.',
            modalCancelLabel: 'Cancel',
            nodeStopTitle: 'Stop node',
            nodeStopBody: 'Stop node "{{name}}"?',
            nodeStopWarning: 'Stopping may remove unsaved configuration.',
            nodeStopConfirm: 'Stop node',
            nodeWipeTitle: 'Wipe node',
            nodeWipeBody: 'Wipe node "{{name}}"?',
            nodeWipeWarning: 'Wipe operation removes the current configuration.',
            nodeWipeConfirm: 'Wipe node',
            nodeStartFailed: 'Failed to start node.',
            nodeStopFailed: 'Failed to stop node.',
            nodeWipeFailed: 'Failed to wipe node.',
            nodeDeleteTitle: 'Delete node',
            nodeDeleteBody: 'Are you sure you want to delete this node?',
            nodeDeleteConfirm: 'Delete node',
            refreshButton: 'Refresh data',
            refreshLabButton: 'Refresh lab',
            refreshingLabel: 'Refreshing…',
            categoryOther: 'Other',
            tableName: 'Name',
            tableType: 'Type',
            tableTemplate: 'Template',
            tableImage: 'Image',
            tableCpuCount: 'CPU count',
            tableRam: 'RAM',
            tableNvram: 'NVRAM',
            tableEth: 'Eth',
            tableSer: 'Ser',
            tableConsole: 'Console',
            tablePort: 'Port',
            tableStatus: 'Status',
            tableActions: 'Actions',
            jobQueuedTitle: 'Queued',
            jobQueuedMessage: 'Batch queued (Job #{{id}})'
        },
        ru: {
            heroEyebrow: 'Операции',
            heroTitle: 'Управление нодами',
            heroDescription: 'Посмотрите, кто запускает ноды и в каких лабораториях, прежде чем всё останавливать.',
            stopAllButton: 'Остановить все ноды',
            stopAllHint: 'Принудительно остановить каждую запущенную ноду на платформе.',
            cardsTitle: 'Пользователи и их запущенные ноды',
            cardsSubtitle: 'Каждая карточка агрегирует категории из sysstat по пользователю.',
            labCountLabel: 'Лабораторий',
            viewLabs: 'Показать лаборатории',
            hideLabs: 'Скрыть лаборатории',
            stopUserButton: 'Остановить ноды пользователя',
            startLabButton: 'Запустить ноды лаборатории',
            stopLabButton: 'Остановить ноды лаборатории',
            labSectionTitle: 'Разбивка по лабораториям',
            labTotalLabel: 'Запущенные ноды',
            labAllNodesLabel: 'Всего нод',
            toggleNodesOpen: 'Показать список нод',
            toggleNodesClose: 'Скрыть список нод',
            emptyState: 'Ни одной ноды не найдено.',
            noLabs: 'Для этого пользователя не найдено лабораторий.',
            unknownLabName: 'Безымянная лаборатория',
            totalRunningLabel: 'Всего запущено',
            totalNodesLabel: 'Всего нод',
            modalUserLabel: 'Пользователь',
            modalLabLabel: 'Лаборатория',
            stopAllConfirmTitle: 'Остановить все ноды',
            stopAllConfirmBody: 'Остановка всех нод может привести к потере конфигураций. Продолжить?',
            stopAllConfirmConfirm: 'Остановить',
            stopAllConfirmCancel: 'Отмена',
            stopUserConfirmTitle: 'Остановить ноды пользователя',
            stopUserConfirmBody: 'Остановить все ноды этого пользователя? Все активные лаборатории будут выключены.',
            stopUserConfirmConfirm: 'Остановить',
            stopUserConfirmCancel: 'Отмена',
            stopUserNoLabs: 'У пользователя нет лабораторий для остановки.',
            stopUserFailed: 'Не удалось остановить ноды пользователя.',
            stopLabConfirmTitle: 'Остановить ноды лаборатории',
            stopLabConfirmBody: 'Остановить все ноды в выбранной лаборатории? Активные сессии будут завершены.',
            stopLabConfirmConfirm: 'Остановить',
            stopLabConfirmCancel: 'Отмена',
            startLabConfirmTitle: 'Запустить ноды лаборатории',
            startLabConfirmBody: 'Запустить все ноды в лаборатории? Машины загрузятся из назначенных образов.',
            startLabConfirmConfirm: 'Запустить',
            startLabConfirmCancel: 'Отмена',
            stopLabNoNodes: 'В лаборатории нет нод для остановки.',
            stopLabFailed: 'Не удалось остановить ноды лаборатории.',
            wipeLabButton: 'Очистить лабораторию',
            wipeLabConfirmTitle: 'Очистка лаборатории',
            wipeLabConfirmBody: 'Сбросить все ноды в лаборатории? Текущие конфигурации будут потеряны.',
            wipeLabConfirmConfirm: 'Очистить',
            wipeLabConfirmCancel: 'Отмена',
            wipeLabFailed: 'Не удалось очистить лабораторию.',
            startLabFailed: 'Не удалось запустить ноды лаборатории.',
            loadingTitle: 'Загрузка данных',
            loadingSubtitle: 'Собираем список пользователей, лабораторий и нод.',
            modalCancelLabel: 'Отмена',
            nodeStopTitle: 'Подтверждение остановки',
            nodeStopBody: 'Остановить ноду «{{name}}»?',
            nodeStopWarning: 'Остановка может привести к удалению несохраненной конфигурации.',
            nodeStopConfirm: 'Остановить',
            nodeWipeTitle: 'Подтверждение очистки',
            nodeWipeBody: 'Вы уверены, что хотите очистить ноду «{{name}}»?',
            nodeWipeWarning: 'Очистка приведёт к потере текущей конфигурации.',
            nodeWipeConfirm: 'Очистить',
            nodeStartFailed: 'Не удалось запустить ноду.',
            nodeStopFailed: 'Не удалось остановить ноду.',
            nodeWipeFailed: 'Не удалось очистить ноду.',
            nodeDeleteTitle: 'Подтверждение удаления',
            nodeDeleteBody: 'Удалить выбранную ноду?',
            nodeDeleteConfirm: 'Удалить',
            refreshButton: 'Обновить данные',
            refreshLabButton: 'Обновить лабораторию',
            refreshingLabel: 'Обновляем…',
            categoryOther: 'Прочие',
            tableName: 'Имя',
            tableType: 'Тип',
            tableTemplate: 'Шаблон',
            tableImage: 'Образ',
            tableCpuCount: 'Кол-во CPU',
            tableRam: 'RAM',
            tableNvram: 'NVRAM',
            tableEth: 'Eth',
            tableSer: 'Serial',
            tableConsole: 'Консоль',
            tablePort: 'Порт',
            tableStatus: 'Статус',
            tableActions: 'Действия',
            jobQueuedTitle: 'В очереди',
            jobQueuedMessage: 'Задача поставлена в очередь (Job #{{id}})'
        }
    };

    function openTailwindModal(config) {
        return $uibModal.open({
            animation: true,
            backdrop: false,
            windowTemplateUrl: '/themes/adminLTE/unl_data/pages/modals/tailwind-modal-window.html',
            windowClass: 'tailwind-modal-window',
            template: `
                <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 theme-overlay transition-colors duration-300"></div>
                    <div class="relative w-full max-w-md rounded-3xl border text-slate-100 shadow-2xl p-8 space-y-6"
                         ng-class="modal.surfaceClasses">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full shrink-0 flex items-center justify-center" ng-class="modal.iconClasses">
                                <i class="{{modal.icon}}"></i>
                            </div>
                            <div>
                                <h4 class="text-xl font-semibold" ng-bind="modal.title"></h4>
                                <p class="text-sm" ng-class="modal.mutedClasses" ng-bind="modal.body"></p>
                            </div>
                        </div>
                        <div class="space-y-3" ng-if="modal.items && modal.items.length">
                            <div ng-repeat="item in modal.items">
                                <p class="text-xs uppercase tracking-[0.3em]" ng-class="modal.mutedClasses" ng-bind="item.label"></p>
                                <p class="text-base font-semibold" ng-class="modal.textClasses" ng-bind="item.value"></p>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3">
                            <button class="px-4 py-2 rounded-xl border transition cursor-pointer"
                                    ng-class="modal.cancelClasses"
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
                var defaults = {
                    icon: 'fa fa-info-circle',
                    iconClasses: $scope.themeClass('bg-blue-500/20 text-blue-200', 'bg-blue-500 text-white'),
                    confirmClasses: 'bg-blue-600 hover:bg-blue-500',
                    surfaceClasses: $scope.themeClass('border-white/10 bg-gradient-to-br from-slate-950 via-blue-950/70 to-slate-900 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                    cancelClasses: $scope.themeClass('border-white/20 text-slate-200 hover:bg-white/10', 'border-slate-200 text-slate-800 hover:bg-slate-100'),
                    mutedClasses: $scope.themeClass('text-slate-400', 'text-slate-600'),
                    textClasses: $scope.themeClass('text-slate-100', 'text-slate-900'),
                    cancelLabel: config.cancelLabel || 'Cancel',
                    confirmLabel: config.confirmLabel || 'Confirm',
                    items: []
                };
                $scope.modal = angular.extend(defaults, config || {});
            }],
            size: 'md',
            keyboard: false
        }).result;
    }

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

    var CATEGORY_CONFIG = [
        { key: 'iol', label: 'IOL', gradient: 'from-blue-900/80 via-blue-800/70 to-slate-900/60 border-blue-500/30' },
        { key: 'dynamips', label: 'Dynamips', gradient: 'from-emerald-900/80 via-emerald-800/60 to-slate-900/50 border-emerald-500/30' },
        { key: 'qemu', label: 'QEMU', gradient: 'from-indigo-900/80 via-indigo-800/60 to-slate-900/50 border-indigo-500/30' },
        { key: 'docker', label: 'Docker', gradient: 'from-cyan-900/70 via-cyan-800/60 to-slate-900/50 border-cyan-500/30' },
        { key: 'vpcs', label: 'VPCS', gradient: 'from-rose-900/70 via-rose-800/60 to-slate-900/50 border-rose-500/30' }
    ];
    var OTHER_CATEGORY = { key: 'other', label: function () { return ($scope.t && $scope.t.categoryOther) || translations[resolveLanguage()].categoryOther; }, gradient: 'from-slate-900/70 via-slate-800/60 to-slate-900/50 border-white/10' };
    var CATEGORY_KEYS = CATEGORY_CONFIG.map(function (c) { return c.key; });

    function getOtherLabel() {
        return ($scope.t && $scope.t.categoryOther) || translations[resolveLanguage()].categoryOther;
    }

    function createEmptyCounters() {
        var counters = {};
        CATEGORY_KEYS.forEach(function (key) {
            counters[key] = 0;
        });
        counters.other = 0;
        return counters;
    }

    function normalizeType(type) {
        var normalized = (type || '').toString().toLowerCase();
        return CATEGORY_KEYS.indexOf(normalized) !== -1 ? normalized : 'other';
    }

    function isNodeRunning(node) {
        var status = parseInt(node.status, 10);
        return status === 2 || status === 3;
    }

    function formatLabName(path) {
        if (!path) {
            return ($scope.t && $scope.t.unknownLabName) || translations[resolveLanguage()].unknownLabName;
        }
        var cleanPath = path.replace(/\\+/g, '/');
        var segments = cleanPath.split('/').filter(function (s) { return s && s.length; });
        if (!segments.length) {
            return ($scope.t && $scope.t.unknownLabName) || translations[resolveLanguage()].unknownLabName;
        }
        var last = segments[segments.length - 1];
        return last.replace(/\.unl$/i, '') || last;
    }

    var nodeIndex = {};
    var labRefreshState = {};
    $scope.labRefreshState = labRefreshState;

    function ensureUserEntry(usersMap, username) {
        if (!usersMap[username]) {
            usersMap[username] = {
                username: username,
                counts: createEmptyCounters(),
                totalRunning: 0,
                totalNodes: 0,
                labs: {}
            };
        }
        return usersMap[username];
    }

    function ensureLabEntry(userEntry, labKey) {
        if (!userEntry.labs[labKey]) {
            userEntry.labs[labKey] = {
                path: labKey,
                displayName: formatLabName(labKey),
                counts: createEmptyCounters(),
                totalRunning: 0,
                nodes: []
            };
        }
        return userEntry.labs[labKey];
    }

    function trackLabProgress(labPath) {
        if (!incrementalState) {
            return;
        }
        var normalized = normalizeLabPath(labPath || '');
        if (!normalized || incrementalState.labsSeen[normalized]) {
            return;
        }
        incrementalState.labsSeen[normalized] = true;
        incrementalState.labsProcessed += 1;
        $scope.loadingState.labProcessed = incrementalState.labsProcessed;
    }

    function appendNodeToMap(usersMap, node) {
        if (!node) {
            return;
        }
        var username = node.user || '—';
        var userEntry = ensureUserEntry(usersMap, username);
        userEntry.totalNodes += 1;

        var labKey = node.lab || '';
        var labEntry = ensureLabEntry(userEntry, labKey);
        trackLabProgress(labKey);
        labEntry.nodes.push(node);

        if (isNodeRunning(node)) {
            var categoryKey = normalizeType(node.type);
            userEntry.counts[categoryKey] += 1;
            userEntry.totalRunning += 1;
            labEntry.counts[categoryKey] += 1;
            labEntry.totalRunning += 1;
        }
    }

    function appendNodesRange(usersMap, nodes, startIndex, endIndex) {
        if (!nodes || !nodes.length) {
            return;
        }
        var start = Math.max(0, startIndex || 0);
        var end = Math.min(nodes.length, endIndex == null ? nodes.length : endIndex);
        for (var idx = start; idx < end; idx++) {
            appendNodeToMap(usersMap, nodes[idx]);
        }
    }

    function convertUsersMapToSummaries(usersMap) {
        return Object.keys(usersMap).sort(function (a, b) {
            return a.localeCompare(b);
        }).map(function (username) {
            var userEntry = usersMap[username];
            var labsArray = Object.keys(userEntry.labs).sort(function (a, b) {
                var nameA = (userEntry.labs[a].displayName || '').toLowerCase();
                var nameB = (userEntry.labs[b].displayName || '').toLowerCase();
                return nameA.localeCompare(nameB);
            }).map(function (key) {
                var lab = userEntry.labs[key];
                lab.nodes.sort(function (nodeA, nodeB) {
                    return (nodeA.name || '').localeCompare(nodeB.name || '');
                });
                return lab;
            });

            return {
                username: username,
                counts: userEntry.counts,
                totalRunning: userEntry.totalRunning,
                totalNodes: userEntry.totalNodes,
                labs: labsArray,
                labCount: labsArray.length
            };
        });
    }

    function buildUserSummaries(nodes) {
        var usersMap = {};
        appendNodesRange(usersMap, nodes, 0, nodes ? nodes.length : 0);
        $scope.userSummaries = convertUsersMapToSummaries(usersMap);
    }

    var incrementalState = null;
    var incrementalTimer = null;

    function cancelIncrementalProcessing() {
        if (incrementalTimer) {
            $timeout.cancel(incrementalTimer);
            incrementalTimer = null;
        }
        incrementalState = null;
    }

    function scheduleChunkProcessing() {
        if (!incrementalState) {
            return;
        }
        var nodes = incrementalState.nodes;
        var start = incrementalState.index;
        var end = Math.min(start + incrementalState.chunkSize, nodes.length);

        appendNodesRange(incrementalState.usersMap, nodes, start, end);
        incrementalState.index = end;
        $scope.userSummaries = convertUsersMapToSummaries(incrementalState.usersMap);
        $scope.loadingState.processed = end;

        if (end >= nodes.length) {
            $scope.loadingState.active = false;
            if (incrementalState) {
                $scope.loadingState.labProcessed = incrementalState.totalLabs || $scope.loadingState.labProcessed;
            }
            cancelIncrementalProcessing();
            return;
        }

        incrementalTimer = $timeout(scheduleChunkProcessing, 0);
    }

    function startIncrementalSummaries(nodes, meta) {
        cancelIncrementalProcessing();
        var list;
        if (angular.isArray(nodes)) {
            list = nodes.slice();
        } else if (nodes && typeof nodes === 'object') {
            list = [];
            angular.forEach(nodes, function (value) {
                list.push(value);
            });
        } else {
            list = [];
        }
        var providedMeta = meta || {};
        var labsSet = {};
        var sourceForLabs = list.length ? list : nodes;
        angular.forEach(sourceForLabs, function (node, key) {
            var normalizedPath;
            if (node && node.lab) {
                normalizedPath = normalizeLabPath(node.lab);
            } else if (node && node.path) {
                normalizedPath = normalizeLabPath(node.path);
            } else if (typeof key === 'string' && key.indexOf('/') !== -1) {
                normalizedPath = normalizeLabPath(key);
            }
            if (normalizedPath) {
                labsSet[normalizedPath] = true;
            }
        });
        var totalLabs = providedMeta.lab_count || Object.keys(labsSet).length;
        var totalNodes = providedMeta.node_count || list.length || Object.keys(sourceForLabs || {}).length;
        $scope.loadingState = {
            active: true,
            processed: 0,
            total: totalNodes,
            labProcessed: 0,
            labTotal: totalLabs
        };
        if (!list.length) {
            $scope.userSummaries = [];
            $scope.loadingState.labProcessed = $scope.loadingState.labTotal;
            $scope.loadingState.processed = $scope.loadingState.total;
            $scope.loadingState.active = false;
            return;
        }
        incrementalState = {
            nodes: list,
            index: 0,
            usersMap: {},
            chunkSize: Math.max(50, Math.floor(list.length / 40)),
            labsSeen: {},
            labsProcessed: 0,
            totalLabs: totalLabs || providedMeta.lab_count || 0
        };
        scheduleChunkProcessing();
    }

    function makeNodeKey(labPath, nodeId) {
        if (!labPath && labPath !== '') {
            return null;
        }
        var normalized = normalizeLabPath(labPath || '');
        var parsedId = parseInt(nodeId, 10);
        if (!normalized || isNaN(parsedId)) {
            return null;
        }
        return normalized + '::' + parsedId;
    }

    function hydrateNodeIndex(nodes) {
        nodeIndex = {};
        angular.forEach(nodes || [], function (node) {
            if (!node) {
                return;
            }
            var key = makeNodeKey(node.lab, node.id);
            if (key) {
                nodeIndex[key] = angular.copy(node);
            }
        });
    }

    function normalizeNodeRecord(raw, username, labPath, fallback) {
        fallback = fallback || {};
        var base = angular.extend({}, raw || {});
        var normalizedPath = labPath ? normalizeLabPath(labPath) : (fallback.lab || '/');
        var idValue = base.id !== undefined ? base.id : fallback.id;
        var parsedId = parseInt(idValue, 10);
        var cpuCount = base.cpucount;
        if (cpuCount === undefined && base.cpu !== undefined) {
            cpuCount = base.cpu;
        } else if (cpuCount === undefined) {
            cpuCount = fallback.cpucount;
        }
        var ethernetCount = base.eth;
        if (ethernetCount === undefined && base.ethernet !== undefined) {
            ethernetCount = base.ethernet;
        } else if (ethernetCount === undefined) {
            ethernetCount = fallback.eth;
        }
        var serialCount = base.ser;
        if (serialCount === undefined && base.serial !== undefined) {
            serialCount = base.serial;
        } else if (serialCount === undefined) {
            serialCount = fallback.ser;
        }
        return {
            id: !isNaN(parsedId) ? parsedId : (fallback.id || 0),
            lab: normalizedPath,
            user: username || fallback.user || '—',
            name: base.name || fallback.name || '',
            type: base.type || fallback.type || '',
            template: base.template || fallback.template || '',
            status: base.status !== undefined ? parseInt(base.status, 10) : (fallback.status || 0),
            cpulimit: base.cpulimit !== undefined ? base.cpulimit : (fallback.cpulimit || 0),
            cpucount: cpuCount !== undefined ? cpuCount : 0,
            image: base.image || fallback.image || '',
            nvram: base.nvram !== undefined ? base.nvram : (fallback.nvram || 0),
            ram: base.ram !== undefined ? base.ram : (fallback.ram || 0),
            eth: ethernetCount !== undefined ? ethernetCount : 0,
            ser: serialCount !== undefined ? serialCount : 0,
            console: base.console || fallback.console || '',
            url: base.url || fallback.url || '',
            port: base.port !== undefined ? base.port : (fallback.port || null),
            icon: base.icon || fallback.icon || '',
            delay: base.delay !== undefined ? base.delay : (fallback.delay || 0)
        };
    }

    function upsertNodeRecord(record) {
        if (!record) {
            return;
        }
        var normalizedPath = normalizeLabPath(record.lab || '');
        record.lab = normalizedPath;
        var key = makeNodeKey(normalizedPath, record.id);
        if (!key) {
            return;
        }
        var copy = angular.copy(record);
        nodeIndex[key] = copy;
        var replaced = false;
        for (var i = 0; i < $scope.nodedata.length; i++) {
            var current = $scope.nodedata[i];
            if (makeNodeKey(current.lab, current.id) === key) {
                $scope.nodedata[i] = copy;
                replaced = true;
                break;
            }
        }
        if (!replaced) {
            $scope.nodedata.push(copy);
        }
    }

    function replaceLabNodesInCache(labPath, nodes) {
        if (!labPath) {
            return;
        }
        var normalized = normalizeLabPath(labPath);
        var prefix = normalized + '::';
        var retained = [];
        angular.forEach($scope.nodedata, function (node) {
            if (normalizeLabPath(node.lab) === normalized) {
                return;
            }
            retained.push(node);
        });
        $scope.nodedata = retained;
        Object.keys(nodeIndex).forEach(function (key) {
            if (key.indexOf(prefix) === 0) {
                delete nodeIndex[key];
            }
        });
        angular.forEach(nodes || [], function (node) {
            upsertNodeRecord(node);
        });
    }

    function rebuildSummariesImmediate() {
        buildUserSummaries($scope.nodedata);
    }

    function setLabRefreshing(labPath, state) {
        if (!labPath) {
            return;
        }
        var normalized = normalizeLabPath(labPath);
        labRefreshState[normalized] = !!state;
    }

    $scope.isLabRefreshing = function (labPath) {
        if (!labPath) {
            return false;
        }
        var normalized = normalizeLabPath(labPath);
        return !!labRefreshState[normalized];
    };

    $scope.categoryLayout = CATEGORY_CONFIG;
    $scope.otherCategoryGradient = OTHER_CATEGORY.gradient;
    $scope.getOtherCategoryLabel = function () {
        return getOtherLabel();
    };
    $scope.hasOtherCategory = function (counts) {
        return counts && counts.other > 0;
    };

    function normalizeLabPath(path) {
        var sanitized = (path || '').replace(/^\/+/, '');
        return '/' + sanitized;
    }

    function collectLabNodeIds(lab) {
        if (!lab || !lab.nodes || !lab.nodes.length) {
            return [];
        }
        var seen = {};
        var ids = [];
        lab.nodes.forEach(function (node) {
            var rawId = (node && node.id !== undefined) ? node.id : null;
            var parsed = parseInt(rawId, 10);
            if (!isNaN(parsed) && !seen[parsed]) {
                seen[parsed] = true;
                ids.push(parsed);
            }
        });
        return ids;
    }

    function queueBatchNodeAction(labPath, action, ids) {
        if (!labPath) {
            return $q.reject('lab path missing');
        }
        if (!ids || !ids.length) {
            return $q.reject('no node ids');
        }
        var normalizedPath = normalizeLabPath(labPath);
        return $http.post('/api/labs' + normalizedPath + '/nodes/actions', {
            action: action,
            ids: ids
        });
    }

    function queueLabNodes(lab, action) {
        if (!lab || !lab.path) {
            return $q.reject('lab info missing');
        }
        var ids = collectLabNodeIds(lab);
        if (!ids.length) {
            return $q.reject('no node ids');
        }
        return queueBatchNodeAction(lab.path, action, ids);
    }

    function queueSingleNode(node, action) {
        if (!node || node.id === undefined || !node.lab) {
            return $q.reject('node info missing');
        }
        var id = parseInt(node.id, 10);
        if (isNaN(id)) {
            return $q.reject('invalid node id');
        }
        return queueBatchNodeAction(node.lab, action, [id]);
    }

    function notifyJobQueued(response) {
        if (!response || !response.data || !window.toastr) {
            return;
        }
        var jobId = response.data.data && response.data.data.job_id;
        if (!jobId) {
            return;
        }
        var t = $scope.t || translations[resolveLanguage()];
        var template = t.jobQueuedMessage || 'Batch queued (Job #{{id}})';
        var message = template.replace('{{id}}', jobId);
        var title = t.jobQueuedTitle || 'Queued';
        toastr.info(message, title);
    }

    function refreshLabSnapshot(user, lab) {
        if (!user || !lab || !lab.path) {
            return $q.reject('missing lab info');
        }
        var labPath = normalizeLabPath(lab.path);
        setLabRefreshing(labPath, true);
        return $http.get('/api/labs' + labPath + '/nodes').then(function (response) {
            if (response.data.status !== 'success') {
                return $q.reject(response.data.message || 'Failed to load lab nodes');
            }
            var payload = response.data.data || {};
            var nodes = [];
            angular.forEach(payload, function (value, key) {
                var candidate = angular.extend({ id: key }, value);
                var fallback = nodeIndex[makeNodeKey(labPath, candidate.id)];
                nodes.push(normalizeNodeRecord(candidate, user.username, labPath, fallback));
            });
            replaceLabNodesInCache(labPath, nodes);
            rebuildSummariesImmediate();
            return nodes;
        }).catch(function (err) {
            console.error('Failed to refresh lab snapshot', labPath, err);
            return $q.reject(err);
        }).finally(function () {
            setLabRefreshing(labPath, false);
        });
    }

    function refreshLabsCollection(user, labs) {
        if (!labs || !labs.length) {
            return $q.when();
        }
        var promises = labs.map(function (lab) {
            return refreshLabSnapshot(user, lab);
        });
        return $q.all(promises);
    }

    function refreshNodeSnapshot(node) {
        if (!node || !node.id || !node.lab) {
            return $q.reject('missing node details');
        }
        var labPath = normalizeLabPath(node.lab);
        var nodeId = parseInt(node.id, 10);
        if (isNaN(nodeId)) {
            return $q.reject('invalid node id');
        }
        return $http.get('/api/labs' + labPath + '/nodes/' + nodeId).then(function (response) {
            if (response.data.status !== 'success') {
                return $q.reject(response.data.message || 'Failed to load node');
            }
            var fallback = nodeIndex[makeNodeKey(labPath, nodeId)] || node;
            var normalized = normalizeNodeRecord(
                angular.extend({ id: nodeId }, response.data.data || {}),
                node.user || (fallback && fallback.user) || '—',
                labPath,
                fallback
            );
            upsertNodeRecord(normalized);
            rebuildSummariesImmediate();
            return normalized;
        }).catch(function (err) {
            console.error('Failed to refresh node snapshot', node, err);
            return $q.reject(err);
        });
    }

    $scope.expandedUsers = {};
    $scope.expandedLabs = {};

    $scope.toggleUserCard = function (username) {
        $scope.expandedUsers[username] = !$scope.expandedUsers[username];
    };

    $scope.isUserExpanded = function (username) {
        return !!$scope.expandedUsers[username];
    };

    $scope.toggleLabDetails = function (username, labPath) {
        var key = username + '::' + labPath;
        $scope.expandedLabs[key] = !$scope.expandedLabs[key];
    };

    $scope.isLabExpanded = function (username, labPath) {
        var key = username + '::' + labPath;
        return !!$scope.expandedLabs[key];
    };

    $scope.refreshAllNodes = function () {
        if ($scope.loadingState && $scope.loadingState.active) {
            return;
        }
        $scope.getAllLabNodes();
    };

    $scope.refreshLabNodes = function (user, lab) {
        refreshLabSnapshot(user, lab);
    };

    $scope.loadingState = {
        active: false,
        processed: 0,
        total: 0,
        labProcessed: 0,
        labTotal: 0
    };

	$scope.testAUTH("/nodemgmt"); // Проверка авторизации
	init();

    function init() {
        $scope.nodedata = [];
        $scope.sortColumn = 'lab';
        $scope.reverseSort = false;
        $scope.userSummaries = [];

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
            cancelIncrementalProcessing();
            $scope.loadingState = {
                active: true,
                processed: 0,
                total: 0,
                labProcessed: 0,
                labTotal: 0
            };
            $http.get('/api/nodes').then(function (response) {
                if (response.data.status === "success") {
                    $scope.nodedata = response.data.data || [];
                    hydrateNodeIndex($scope.nodedata);
                    Object.keys(labRefreshState).forEach(function (key) { delete labRefreshState[key]; });
                    var incomingMeta = response.data.meta || {};
                    if (incomingMeta.node_count) {
                        $scope.loadingState.total = incomingMeta.node_count;
                    } else {
                        $scope.loadingState.total = $scope.nodedata.length;
                    }
                    if (incomingMeta.lab_count) {
                        $scope.loadingState.labTotal = incomingMeta.lab_count;
                    } else {
                        var labsSeen = {};
                        angular.forEach($scope.nodedata, function (node) {
                            var normalized = normalizeLabPath(node && node.lab);
                            if (normalized) {
                                labsSeen[normalized] = true;
                            }
                        });
                        $scope.loadingState.labTotal = Object.keys(labsSeen).length;
                    }
                    startIncrementalSummaries($scope.nodedata, incomingMeta);
                } else {
                    console.error("Ошибка при получении нод:", response.data.message);
                    $scope.nodedata = [];
                    $scope.userSummaries = [];
                    $scope.loadingState.active = false;
                    $scope.loadingState.labProcessed = 0;
                    $scope.loadingState.labTotal = 0;
                }
            }).catch(function (error) {
                console.error("Ошибка запроса:", error);
                $scope.nodedata = [];
                $scope.userSummaries = [];
                $scope.loadingState.active = false;
                $scope.loadingState.labProcessed = 0;
                $scope.loadingState.labTotal = 0;
            });
        };

        $scope.startLabNodes = function (user, lab) {
            var t = $scope.t || translations[resolveLanguage()];
            if (!lab || !lab.path || !lab.nodes || !lab.nodes.length) {
                alert(t.stopLabNoNodes);
                return;
            }

            openTailwindModal({
                icon: 'fa fa-play',
                iconClasses: $scope.themeClass('bg-emerald-500/20 text-emerald-200', 'bg-emerald-500 text-white'),
                title: t.startLabConfirmTitle,
                body: t.startLabConfirmBody,
                surfaceClasses: $scope.themeClass('from-emerald-950/70 via-emerald-900/40 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                items: [
                    { label: t.modalUserLabel, value: user.username },
                    { label: t.modalLabLabel, value: lab.displayName }
                ],
                confirmLabel: t.startLabConfirmConfirm,
                cancelLabel: t.startLabConfirmCancel,
                confirmClasses: 'bg-emerald-500 hover:bg-emerald-400 text-slate-900'
            }).then(function () {
                $.blockUI();
                queueLabNodes(lab, 'start')
                    .then(function (response) {
                        notifyJobQueued(response);
                        return refreshLabSnapshot(user, lab);
                    })
                    .catch(function (error) {
                        console.error('Ошибка запуска лаборатории:', error);
                        alert(t.startLabFailed);
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };

        $scope.stopLabNodes = function (user, lab) {
            var t = $scope.t || translations[resolveLanguage()];
            if (!lab || !lab.path) {
                alert(t.stopLabNoNodes);
                return;
            }
            if (!lab.nodes || !lab.nodes.length) {
                alert(t.stopLabNoNodes);
                return;
            }

            openTailwindModal({
                icon: 'fa fa-stop',
                iconClasses: $scope.themeClass('bg-amber-500/20 text-amber-200', 'bg-amber-500 text-white'),
                title: t.stopLabConfirmTitle,
                body: t.stopLabConfirmBody,
                surfaceClasses: $scope.themeClass('from-amber-950/80 via-amber-900/40 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                items: [
                    { label: t.modalUserLabel, value: user.username },
                    { label: t.modalLabLabel, value: lab.displayName }
                ],
                confirmLabel: t.stopLabConfirmConfirm,
                cancelLabel: t.stopLabConfirmCancel,
                confirmClasses: 'bg-amber-500 hover:bg-amber-400 text-slate-900'
            }).then(function () {
                $.blockUI();
                queueLabNodes(lab, 'stop')
                    .then(function (response) {
                        notifyJobQueued(response);
                        return refreshLabSnapshot(user, lab);
                    })
                    .catch(function (error) {
                        console.error('Ошибка остановки лаборатории:', error);
                        alert(t.stopLabFailed);
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };

        $scope.wipeLabNodes = function (user, lab) {
            var t = $scope.t || translations[resolveLanguage()];
            if (!lab || !lab.path || !lab.nodes || !lab.nodes.length) {
                alert(t.stopLabNoNodes);
                return;
            }

            openTailwindModal({
                icon: 'fa fa-eraser',
                iconClasses: $scope.themeClass('bg-cyan-500/20 text-cyan-200', 'bg-cyan-500 text-white'),
                title: t.wipeLabConfirmTitle,
                body: t.wipeLabConfirmBody,
                surfaceClasses: $scope.themeClass('from-cyan-950/70 via-slate-950 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                items: [
                    { label: t.modalUserLabel, value: user.username },
                    { label: t.modalLabLabel, value: lab.displayName }
                ],
                confirmLabel: t.wipeLabConfirmConfirm,
                cancelLabel: t.wipeLabConfirmCancel,
                confirmClasses: 'bg-cyan-500 hover:bg-cyan-400 text-slate-900'
            }).then(function () {
                $.blockUI();
                queueLabNodes(lab, 'wipe')
                    .then(function (response) {
                        notifyJobQueued(response);
                        return refreshLabSnapshot(user, lab);
                    })
                    .catch(function (error) {
                        console.error('Ошибка очистки лаборатории:', error);
                        alert(t.wipeLabFailed);
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };

        $scope.stopUserNodes = function (user) {
            var t = $scope.t || translations[resolveLanguage()];
            if (!user || !user.labs || !user.labs.length) {
                alert(t.stopUserNoLabs);
                return;
            }

            openTailwindModal({
                icon: 'fa fa-stop',
                iconClasses: $scope.themeClass('bg-amber-500/20 text-amber-200', 'bg-amber-500 text-white'),
                title: t.stopUserConfirmTitle,
                body: t.stopUserConfirmBody,
                surfaceClasses: $scope.themeClass('from-amber-950/80 via-amber-900/40 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                items: [
                    { label: t.modalUserLabel, value: user.username }
                ],
                confirmLabel: t.stopUserConfirmConfirm,
                cancelLabel: t.stopUserConfirmCancel,
                confirmClasses: 'bg-amber-500 hover:bg-amber-400 text-slate-900'
            }).then(function () {
                var labsWithNodes = (user.labs || []).filter(function (lab) {
                    return lab && lab.path && lab.nodes && lab.nodes.length;
                });

                if (!labsWithNodes.length) {
                    alert(t.stopUserNoLabs);
                    return;
                }

                $.blockUI();
                var promises = labsWithNodes.map(function (lab) {
                    return queueLabNodes(lab, 'stop').then(function (response) {
                        notifyJobQueued(response);
                        return response;
                    });
                });

                $q.all(promises)
                    .then(function () {
                        return refreshLabsCollection(user, labsWithNodes);
                    })
                    .catch(function (error) {
                        console.error('Ошибка остановки нод пользователя:', error);
                        alert(t.stopUserFailed);
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };

        $scope.deleteNode = function (node) {
            var t = $scope.t || translations[resolveLanguage()];
            const nodeRef = node && node.id ? node : null;
            if (!nodeRef) {
                alert((($scope.t && $scope.t.tableName) || 'Node') + ' not found.');
                return;
            }
            const nodeData = $scope.nodedata.find(n => n.id === nodeRef.id && n.lab === nodeRef.lab) || nodeRef;
            if (!nodeData || !nodeData.lab) {
                alert((($scope.t && $scope.t.tableName) || 'Node') + ' not found.');
                return;
            }
            const labPath = nodeData.lab;

            openTailwindModal({
                icon: 'fa fa-trash',
                iconClasses: $scope.themeClass('bg-rose-500/20 text-rose-200', 'bg-rose-600 text-white'),
                title: t.nodeDeleteTitle,
                body: t.nodeDeleteBody,
                surfaceClasses: $scope.themeClass('from-rose-950/80 via-rose-900/40 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                items: [
                    { label: t.tableName, value: nodeData.name },
                    { label: t.tableType, value: nodeData.type },
                    { label: t.tableTemplate, value: nodeData.template },
                    { label: t.modalLabLabel, value: nodeData.lab },
                    { label: 'ID', value: nodeData.id }
                ],
                confirmLabel: t.nodeDeleteConfirm,
                cancelLabel: t.modalCancelLabel,
                confirmClasses: 'bg-rose-600 hover:bg-rose-500'
            }).then(function () {
                $.blockUI();
                queueSingleNode(nodeData, 'delete')
                    .then(function (response) {
                        notifyJobQueued(response);
                        return refreshLabSnapshot({ username: nodeData.user || '—' }, { path: labPath, displayName: nodeData.lab });
                    })
                    .catch(function (error) {
                        console.error("Ошибка при удалении ноды:", error);
                        alert("Не удалось удалить ноду.");
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };

        $scope.startNode = function (node) {
            var t = $scope.t || translations[resolveLanguage()];
            $.blockUI();
            queueSingleNode(node, 'start')
                .then(function (response) {
                    notifyJobQueued(response);
                    return refreshNodeSnapshot(node);
                })
                .catch(function (err) {
                    console.error("Ошибка запуска:", err);
                    alert(t.nodeStartFailed);
                })
                .finally(function () {
                    $.unblockUI();
                });
        };

        $scope.stopNode = function (node) {
            var t = $scope.t || translations[resolveLanguage()];
            var body = (t.nodeStopBody || '').replace('{{name}}', node.name);
            var combinedBody = body + ' ' + (t.nodeStopWarning || '');
            openTailwindModal({
                icon: 'fa fa-stop',
                iconClasses: $scope.themeClass('bg-amber-500/20 text-amber-200', 'bg-amber-500 text-white'),
                title: t.nodeStopTitle,
                body: combinedBody,
                surfaceClasses: $scope.themeClass('from-amber-950/80 via-amber-900/40 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                confirmLabel: t.nodeStopConfirm,
                cancelLabel: t.modalCancelLabel,
                confirmClasses: 'bg-amber-500 hover:bg-amber-400 text-slate-900'
            }).then(function () {
                $.blockUI();
                queueSingleNode(node, 'stop')
                    .then(function (response) {
                        notifyJobQueued(response);
                        return refreshNodeSnapshot(node);
                    })
                    .catch(function (err) {
                        console.error("Ошибка остановки ноды:", err);
                        alert(t.nodeStopFailed);
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };        

        $scope.wipeNode = function (node) {
            var t = $scope.t || translations[resolveLanguage()];
            var body = (t.nodeWipeBody || '').replace('{{name}}', node.name);
            var combinedBody = body + ' ' + (t.nodeWipeWarning || '');
            openTailwindModal({
                icon: 'fa fa-eraser',
                iconClasses: $scope.themeClass('bg-cyan-500/20 text-cyan-200', 'bg-cyan-500 text-white'),
                title: t.nodeWipeTitle,
                body: combinedBody,
                surfaceClasses: $scope.themeClass('from-cyan-950/70 via-slate-950 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                confirmLabel: t.nodeWipeConfirm,
                cancelLabel: t.modalCancelLabel,
                confirmClasses: 'bg-cyan-500 hover:bg-cyan-400 text-slate-900'
            }).then(function () {
                $.blockUI();
                queueSingleNode(node, 'wipe')
                    .then(function (response) {
                        notifyJobQueued(response);
                        return refreshNodeSnapshot(node);
                    })
                    .catch(function (err) {
                        console.error("Ошибка очистки ноды:", err);
                        alert(t.nodeWipeFailed);
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };

        $scope.stopAllNodes = function () {
            var t = $scope.t || translations[resolveLanguage()];
            openTailwindModal({
                icon: 'fa fa-exclamation-triangle',
                iconClasses: $scope.themeClass('bg-rose-500/20 text-rose-200', 'bg-rose-600 text-white'),
                title: t.stopAllConfirmTitle,
                body: t.stopAllConfirmBody,
                surfaceClasses: $scope.themeClass('from-rose-950/80 via-rose-900/40 to-slate-900 border-white/10 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                confirmLabel: t.stopAllConfirmConfirm,
                cancelLabel: t.stopAllConfirmCancel,
                confirmClasses: 'bg-rose-600 hover:bg-rose-500'
            }).then(function () {
                $.blockUI();
                $http.delete('/api/status')
                    .then(function () {
                        $scope.getAllLabNodes();
                    })
                    .catch(function (error) {
                        console.error("Ошибка при остановке всех нод:", error);
                        alert("Не удалось остановить все ноды.");
                    })
                    .finally(function () {
                        $.unblockUI();
                    });
            });
        };
        
        $scope.getAllLabNodes();

        $scope.$on('$destroy', function () {
            cancelIncrementalProcessing();
        });
    }
});
