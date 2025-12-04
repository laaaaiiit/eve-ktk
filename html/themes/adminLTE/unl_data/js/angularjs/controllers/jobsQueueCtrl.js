angular.module("unlMainApp").controller('jobsQueueController', function jobsQueueController($scope, $http, $rootScope, $cookies, $timeout, $interval, themeService) {
	var translations = {
		en: {
			heroEyebrow: 'Automation',
			heroTitle: 'Job queue',
			heroDescription: 'Track every asynchronous task in EVE-NG, filter by status, user, or target node, and inspect payloads in seconds.',
			searchLabel: 'Search',
			searchPlaceholder: 'Job ID, lab path, action, or error text',
			nodeLabel: 'Node filter',
			nodePlaceholder: 'Node name or ID',
			userLabel: 'User filter',
			userPlaceholder: 'username',
		perPageLabel: 'Rows per page',
		refreshButton: 'Refresh',
		resetButton: 'Reset filters',
		clearAllButton: 'Clear all jobs',
		clearAllBusy: 'Clearing…',
		clearAllConfirm: 'Clear every job from the queue? Running tasks will also be removed.',
		clearAllSuccess: 'Job queue cleared',
		clearAllError: 'Failed to clear jobs',
		statusToggleLabel: 'Statuses',
			statusLabels: {
				pending: 'Pending',
				running: 'Running',
				success: 'Completed',
				failed: 'Failed',
				cancelled: 'Cancelled'
			},
			summaryTitle: 'Live counters',
			tableId: 'ID',
			tableAction: 'Action',
			tableTargets: 'Targets',
			tableStatus: 'Status',
			tableProgress: 'Progress',
			tableUser: 'User',
			tableLab: 'Lab path',
			tableCreated: 'Created',
			tableUpdated: 'Updated',
			tableActions: 'Actions',
			targetFallback: 'No targets reported',
			jobResultLabel: 'Result',
			rangeLabel: 'Showing {{start}}–{{end}} of {{total}} jobs',
			rangeEmpty: 'No jobs match the filters',
			emptyState: 'No jobs found for the current filters.',
			loading: 'Loading queue…',
			errorTitle: 'Error',
			refreshing: 'Refreshing…',
			limitTitle: 'Parallel start limit',
			limitDescription: 'Start nodes in small batches to avoid CPU spikes on the hypervisor.',
			limitHelper: 'Maximum nodes started at once per lab (1–20).',
			limitSaveButton: 'Save limit',
			limitSavedToast: 'Limit updated',
			limitSaveError: 'Failed to update limit',
			cancelButton: 'Stop',
			cancelRequested: 'Cancellation requested…',
			deleteButton: 'Delete',
			deleteForceButton: 'Force delete',
			deleteConfirm: 'Delete this job? This cannot be undone.',
			deleteForceConfirm: 'Force delete this job? The entry will be removed even if it is still running.',
			toastCancelRequested: 'Cancellation requested',
			toastCancelDone: 'Job cancelled',
			toastCancelError: 'Failed to cancel job',
			toastDeleteSuccess: 'Job deleted',
			toastDeleteError: 'Failed to delete job',
			toastDeleteForceSuccess: 'Job forcibly removed',
			toastDeleteForceError: 'Failed to force delete job'
		},
		ru: {
			heroEyebrow: 'Автоматизация',
			heroTitle: 'Очередь задач',
			heroDescription: 'Следите за всеми асинхронными задачами EVE-NG, фильтруйте по статусу, пользователю или узлу и изучайте детали за секунды.',
			searchLabel: 'Поиск',
			searchPlaceholder: 'ID задачи, лаба, действие или текст ошибки',
			nodeLabel: 'Фильтр по узлу',
			nodePlaceholder: 'Имя или ID узла',
			userLabel: 'Фильтр по пользователю',
			userPlaceholder: 'имя пользователя',
		perPageLabel: 'Строк на странице',
		refreshButton: 'Обновить',
		resetButton: 'Сбросить фильтры',
		clearAllButton: 'Очистить задачи',
		clearAllBusy: 'Очищаем…',
		clearAllConfirm: 'Удалить все задачи из очереди? Записи будут убраны даже если они ещё выполняются.',
		clearAllSuccess: 'Очередь очищена',
		clearAllError: 'Не удалось очистить очередь',
		statusToggleLabel: 'Статусы',
			statusLabels: {
				pending: 'В ожидании',
				running: 'Выполняется',
				success: 'Завершено',
				failed: 'Ошибка',
				cancelled: 'Отменено'
			},
			summaryTitle: 'Сводка',
			tableId: 'ID',
			tableAction: 'Действие',
			tableTargets: 'Цели',
			tableStatus: 'Статус',
			tableProgress: 'Прогресс',
			tableUser: 'Пользователь',
			tableLab: 'Путь к лабе',
			tableCreated: 'Создана',
			tableUpdated: 'Обновлена',
			tableActions: 'Действия',
			targetFallback: 'Цели не указаны',
			jobResultLabel: 'Результат',
			rangeLabel: 'Показаны {{start}}–{{end}} из {{total}} задач',
			rangeEmpty: 'Нет задач по выбранным фильтрам',
			emptyState: 'Задачи не найдены.',
			loading: 'Загружаем очередь…',
			errorTitle: 'Ошибка',
			refreshing: 'Обновляем…',
			limitTitle: 'Лимит параллельного запуска',
			limitDescription: 'Запускайте узлы небольшими партиями, чтобы не перегружать гипервизор.',
			limitHelper: 'Максимум одновременно запускаемых узлов в лаборатории (1–20).',
			limitSaveButton: 'Сохранить',
			limitSavedToast: 'Лимит обновлён',
			limitSaveError: 'Не удалось обновить лимит',
			cancelButton: 'Остановить',
			cancelRequested: 'Отмена запрошена…',
			deleteButton: 'Удалить',
			deleteForceButton: 'Удалить принудительно',
			deleteConfirm: 'Удалить эту задачу? Действие необратимо.',
			deleteForceConfirm: 'Принудительно удалить задачу? Запись будет удалена даже если она ещё выполняется.',
			toastCancelRequested: 'Отмена запрошена',
			toastCancelDone: 'Задача остановлена',
			toastCancelError: 'Не удалось остановить задачу',
			toastDeleteSuccess: 'Задача удалена',
			toastDeleteError: 'Не удалось удалить задачу',
			toastDeleteForceSuccess: 'Задача принудительно удалена',
			toastDeleteForceError: 'Не удалось принудительно удалить задачу'
		}
	};
	var operationLabelDictionary = {
		connect_nodes_bridge: { en: 'Connect', ru: 'Connect' },
		connect_nodes_serial: { en: 'Connect', ru: 'Connect' },
		connect_node_network: { en: 'Connect', ru: 'Connect' }
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

	function formatOperationLabel(raw) {
		if (!raw) {
			return '';
		}
		var key = raw.toString().toLowerCase();
		var dict = operationLabelDictionary[key];
		if (dict) {
			return dict[$scope.lang] || dict.en || 'Connect';
		}
		return raw.toString().toUpperCase();
	}

	$scope.$watch(function () { return $rootScope.lang; }, function (newVal, oldVal) {
		if (newVal && newVal !== oldVal) {
			refreshTranslations();
			$scope.describeCache = {};
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

	$scope.testAUTH("/jobs");
	$scope.role = null;
	$scope.$watch(function () { return $rootScope.role; }, function (role) {
		if (role) {
			$scope.role = role;
		}
	});

	$('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');

	var statusOrder = ['running', 'pending', 'failed', 'cancelled', 'success'];
	var statusRank = {};
	$scope.statusFilters = {};
	statusOrder.forEach(function (key, index) {
		statusRank[key] = index;
		$scope.statusFilters[key] = true;
	});
	$scope.statusDefinitions = statusOrder.map(function (key) {
		return { key: key };
	});

	$scope.maxParallelLimit = 3;
	$scope.maxParallelInput = 3;
	$scope.savingParallelLimit = false;

	var statusMeta = {
		running: {
			buttonDark: 'border border-emerald-500/30 bg-emerald-500/20 text-emerald-100',
			buttonLight: 'border border-emerald-200 bg-emerald-50 text-emerald-800',
			badgeDark: 'border border-emerald-500/40 bg-emerald-500/10 text-emerald-200',
			badgeLight: 'border border-emerald-300 bg-emerald-100 text-emerald-900'
		},
		pending: {
			buttonDark: 'border border-amber-500/30 bg-amber-500/15 text-amber-100',
			buttonLight: 'border border-amber-200 bg-amber-50 text-amber-800',
			badgeDark: 'border border-amber-500/40 bg-amber-500/10 text-amber-200',
			badgeLight: 'border border-amber-300 bg-amber-100 text-amber-900'
		},
		success: {
			buttonDark: 'border border-sky-500/30 bg-sky-500/15 text-sky-100',
			buttonLight: 'border border-sky-200 bg-sky-50 text-sky-800',
			badgeDark: 'border border-sky-500/40 bg-sky-500/10 text-sky-200',
			badgeLight: 'border border-sky-300 bg-sky-100 text-sky-900'
		},
		failed: {
			buttonDark: 'border border-rose-500/30 bg-rose-500/15 text-rose-100',
			buttonLight: 'border border-rose-200 bg-rose-50 text-rose-800',
			badgeDark: 'border border-rose-500/40 bg-rose-500/10 text-rose-200',
			badgeLight: 'border border-rose-300 bg-rose-100 text-rose-900'
		},
		cancelled: {
			buttonDark: 'border border-slate-500/30 bg-slate-500/15 text-slate-100',
			buttonLight: 'border border-slate-200 bg-slate-50 text-slate-800',
			badgeDark: 'border border-slate-500/40 bg-slate-500/10 text-slate-200',
			badgeLight: 'border border-slate-300 bg-slate-100 text-slate-900'
		},
		default: {
			buttonDark: 'border border-white/20 bg-white/5 text-white',
			buttonLight: 'border border-slate-200 bg-slate-50 text-slate-800',
			badgeDark: 'border border-white/20 bg-white/5 text-white',
			badgeLight: 'border border-slate-200 bg-slate-50 text-slate-800'
		}
	};

	$scope.counts = {
		pending: 0,
		running: 0,
		success: 0,
		failed: 0,
		cancelled: 0
	};

	$scope.pagination = {
		page: 1,
		perPage: 25,
		total: 0,
		totalPages: 1
	};
	$scope.pageSizeOptions = [25, 50, 100, 150, 200];
	$scope.filters = {
		search: '',
		node: '',
		username: ''
	};
	$scope.jobs = [];
	$scope.loading = false;
	$scope.errorMessage = null;
	$scope.describeCache = {};
	$scope.clearingAll = false;

	var searchDebounce = null;
	var nodeDebounce = null;
	var userDebounce = null;
	var activeRequest = 0;
	var autoRefreshPromise = null;
	var AUTO_REFRESH_INTERVAL = 3000;

	function selectedStatuses() {
		return statusOrder.filter(function (key) {
			return $scope.statusFilters[key];
		});
	}

	function scheduleAutoRefresh() {
		if (autoRefreshPromise) {
			return;
		}
		autoRefreshPromise = $interval(function () {
			if ($scope.loading) {
				return;
			}
			$scope.loadJobs(true);
		}, AUTO_REFRESH_INTERVAL);
	}

	function stopAutoRefresh() {
		if (!autoRefreshPromise) {
			return;
		}
		$interval.cancel(autoRefreshPromise);
		autoRefreshPromise = null;
	}

	$scope.isAdmin = function () {
		return $scope.role === 'admin';
	};

	$scope.statusButtonClass = function (key) {
		var meta = statusMeta[key] || statusMeta.default;
		return $scope.themeClass(meta.buttonDark, meta.buttonLight);
	};

	$scope.statusBadgeClass = function (status) {
		var meta = statusMeta[status] || statusMeta.default;
		return $scope.themeClass(meta.badgeDark, meta.badgeLight);
	};

	$scope.statusActive = function (key) {
		return !!$scope.statusFilters[key];
	};

	$scope.toggleStatus = function (key) {
		if (!$scope.statusFilters.hasOwnProperty(key)) {
			return;
		}
		var currentlyActive = selectedStatuses();
		var nextState = !$scope.statusFilters[key];
		if (!nextState && currentlyActive.length <= 1) {
			return;
		}
		$scope.statusFilters[key] = nextState;
		$scope.applyFilters(true);
	};

	$scope.clearFilters = function () {
		$scope.filters.search = '';
		$scope.filters.node = '';
		if ($scope.isAdmin()) {
			$scope.filters.username = '';
		}
		statusOrder.forEach(function (key) { $scope.statusFilters[key] = true; });
		$scope.applyFilters(true);
	};

	$scope.clearAllJobs = function () {
		if ($scope.clearingAll) {
			return;
		}
		if (typeof window.confirm === 'function' && !window.confirm($scope.t.clearAllConfirm)) {
			return;
		}
		$scope.clearingAll = true;
		var endpoint = '/api/jobs?force=1';
		if ($scope.isAdmin() && $scope.filters.username) {
			endpoint += '&username=' + encodeURIComponent($scope.filters.username);
		}
		$http.delete(endpoint).then(function () {
			if (window.toastr) {
				toastr.success($scope.t.clearAllSuccess, 'OK');
			}
			$scope.loadJobs();
		}, function (error) {
			var message = (error.data && error.data.message) ? error.data.message : $scope.t.clearAllError;
			if (window.toastr) {
				toastr.error(message, $scope.t.errorTitle);
			}
		}).finally(function () {
			$scope.clearingAll = false;
		});
	};

	function buildQueryParams() {
		var params = {
			page: $scope.pagination.page,
			per_page: $scope.pagination.perPage,
			_ts: Date.now()
		};
		var statuses = selectedStatuses();
		if (statuses.length > 0 && statuses.length < statusOrder.length) {
			params.status = statuses.join(',');
		}
		if ($scope.filters.search) {
			params.q = $scope.filters.search;
		}
		if ($scope.filters.node) {
			params.node = $scope.filters.node;
		}
		if ($scope.isAdmin() && $scope.filters.username) {
			params.username = $scope.filters.username;
		}
		return params;
	}

	function serializeQuery(params) {
		var segments = [];
		Object.keys(params).forEach(function (key) {
			var value = params[key];
			if (value === undefined || value === null || value === '') {
				return;
			}
			segments.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
		});
		return segments.join('&');
	}

	function normalizeJob(job) {
		job.payload = job.payload || {};
		job.result = job.result || {};
		job.operationLabel = formatOperationLabel(job.payload.operation || job.action || '');
		job.targetPreview = buildTargetPreview(job.payload);
		job.cancel_requested = !!job.cancel_requested;
		return job;
	}

	function buildTargetPreview(payload) {
		if (!payload || typeof payload !== 'object') {
			return '';
		}
		var operationKey = (payload.operation || '').toString().toLowerCase();
		if (operationKey === 'add_nodes') {
			var creationDescription = describeNodeCreationTargets(operationKey, payload);
			if (creationDescription) {
				return creationDescription;
			}
		}
		var ids = Array.isArray(payload.ids) ? payload.ids : null;
		if (ids && ids.length) {
			return summarizeList(ids);
		}
		if (Array.isArray(payload.nodes) && payload.nodes.length) {
			return summarizeList(payload.nodes);
		}
		if (payload.node && operationKey !== 'add_nodes') {
			return Array.isArray(payload.node) ? summarizeList(payload.node) : String(payload.node);
		}
		if (payload.lab_path) {
			return payload.lab_path;
		}
		var connectDescription = describeConnectTargets(operationKey, payload);
		if (connectDescription) {
			return connectDescription;
		}
		return '';
	}

	function describeConnectTargets(operationKey, payload) {
		switch (operationKey) {
			case 'connect_nodes_bridge':
			case 'connect_nodes_serial':
				return describeNodeToNode(payload);
			case 'connect_node_network':
				return describeNodeToNetwork(payload);
			default:
				return '';
		}
	}

	function describeNodeToNode(payload) {
		var source = formatNodeEndpoint(payload.src_node_id, payload.src_interface_id);
		var target = formatNodeEndpoint(payload.dst_node_id, payload.dst_interface_id);
		if (source && target) {
			return source + ' ↔ ' + target;
		}
		return source || target || '';
	}

	function describeNodeToNetwork(payload) {
		var endpoint = formatNodeEndpoint(payload.node_id, payload.interface_id);
		var networkLabel = '';
		if (payload.network && payload.network.name) {
			networkLabel = payload.network.name;
		} else if (payload.network_id) {
			networkLabel = 'Network #' + payload.network_id;
		}
		if (!endpoint && !networkLabel) {
			return '';
		}
		if (endpoint && networkLabel) {
			return endpoint + ' → ' + networkLabel;
		}
		return endpoint || networkLabel;
	}

	function formatNodeEndpoint(nodeId, interfaceId) {
		if (!nodeId) {
			return '';
		}
		var label = 'Node #' + nodeId;
		if (interfaceId) {
			label += ' (' + interfaceId + ')';
		}
		return label;
	}

	function describeNodeCreationTargets(operationKey, payload) {
		if (operationKey !== 'add_nodes') {
			return '';
		}
		var nodePayload = payload.node;
		if (!nodePayload) {
			return '';
		}
		var list = Array.isArray(nodePayload) ? nodePayload : [nodePayload];
		var total = list.reduce(function (acc, entry) {
			var count = parseInt(entry && (entry.count !== undefined ? entry.count : entry.numberNodes), 10);
			if (isNaN(count) || count < 1) {
				count = 1;
			}
			return acc + count;
		}, 0);
		if (total <= 0) {
			total = list.length || 1;
		}
		return total + ' node' + (total === 1 ? '' : 's');
	}

	function summarizeList(list) {
		var clone = list.slice(0, 3).map(function (item) { return String(item); });
		var extra = list.length - clone.length;
		return extra > 0 ? (clone.join(', ') + ' +' + extra) : clone.join(', ');
	}

	function applySettingsPayload(payload) {
		if (!payload || !payload.settings) {
			return;
		}
		var limit = parseInt(payload.settings.max_parallel_nodes, 10);
		if (!isNaN(limit) && limit > 0) {
			$scope.maxParallelLimit = limit;
			if (!$scope.savingParallelLimit) {
				$scope.maxParallelInput = limit;
			}
		}
	}

	$scope.describeTargets = function (job) {
		if (!job || !job.targetPreview) {
			return $scope.t.targetFallback;
		}
		return job.targetPreview;
	};

	$scope.describeOperation = function (job) {
		if (!job) {
			return '';
		}
		if (job.payload && job.payload.operation) {
			return job.payload.operation;
		}
		return job.action || '';
	};

	$scope.jobStatusLabel = function (status) {
		return ($scope.t.statusLabels[status] || status || '').toUpperCase();
	};

	$scope.formatRange = function () {
		var total = $scope.pagination.total || 0;
		if (!total || !$scope.jobs.length) {
			return $scope.t.rangeEmpty;
		}
		var start = ($scope.pagination.page - 1) * $scope.pagination.perPage + 1;
		var end = start + $scope.jobs.length - 1;
		return $scope.t.rangeLabel.replace('{{start}}', start).replace('{{end}}', end).replace('{{total}}', total);
	};

	$scope.changePage = function (delta) {
		var target = $scope.pagination.page + delta;
		target = Math.max(1, Math.min(target, $scope.pagination.totalPages || 1));
		if (target === $scope.pagination.page) {
			return;
		}
		$scope.pagination.page = target;
		$scope.loadJobs();
	};

	$scope.setPage = function (page) {
		page = Math.max(1, Math.min(page, $scope.pagination.totalPages || 1));
		if (page === $scope.pagination.page) {
			return;
		}
		$scope.pagination.page = page;
		$scope.loadJobs();
	};

	$scope.canCancelJob = function (job) {
		if (!job || job._canceling) {
			return false;
		}
		if (job.cancel_requested) {
			return false;
		}
		return job.status === 'running' || job.status === 'pending';
	};

	$scope.cancelJob = function (job) {
		if (!$scope.canCancelJob(job)) {
			return;
		}
		job._canceling = true;
		$http.post('/api/jobs/' + job.id + '/cancel').then(function (response) {
			job._canceling = false;
			if (response.data && response.data.data) {
				var updated = normalizeJob(response.data.data);
				Object.keys(updated).forEach(function (key) {
					job[key] = updated[key];
				});
			}
			if (window.toastr) {
				var message = (job.status === 'cancelled') ? $scope.t.toastCancelDone : $scope.t.toastCancelRequested;
				toastr.success(message, 'OK');
			}
			$scope.loadJobs();
		}, function (error) {
			job._canceling = false;
			var message = (error.data && error.data.message) ? error.data.message : $scope.t.toastCancelError;
			if (window.toastr) {
				toastr.error(message, $scope.t.errorTitle);
			}
		});
	};

	$scope.canDeleteJob = function (job) {
		if (!job || job._deleting) {
			return false;
		}
		return ['success', 'failed', 'cancelled'].indexOf(job.status) !== -1;
	};

	$scope.deleteJob = function (job) {
		if (!$scope.canDeleteJob(job)) {
			return;
		}
		if (typeof window.confirm === 'function' && !window.confirm($scope.t.deleteConfirm)) {
			return;
		}
		job._deleting = true;
		$http.delete('/api/jobs/' + job.id).then(function () {
			job._deleting = false;
			if (window.toastr) {
				toastr.success($scope.t.toastDeleteSuccess, 'OK');
			}
			$scope.loadJobs();
		}, function (error) {
			job._deleting = false;
			var message = (error.data && error.data.message) ? error.data.message : $scope.t.toastDeleteError;
			if (window.toastr) {
				toastr.error(message, $scope.t.errorTitle);
			}
		});
	};

	$scope.canForceDelete = function (job) {
		if (!job || job._forceDeleting || job._deleting) {
			return false;
		}
		return ! $scope.canDeleteJob(job);
	};

	$scope.forceDelete = function (job) {
		if (!$scope.canForceDelete(job)) {
			return;
		}
		if (typeof window.confirm === 'function' && !window.confirm($scope.t.deleteForceConfirm)) {
			return;
		}
		job._forceDeleting = true;
		$http.delete('/api/jobs/' + job.id + '?force=1').then(function () {
			job._forceDeleting = false;
			if (window.toastr) {
				toastr.success($scope.t.toastDeleteForceSuccess, 'OK');
			}
			$scope.loadJobs();
		}, function (error) {
			job._forceDeleting = false;
			var message = (error.data && error.data.message) ? error.data.message : $scope.t.toastDeleteForceError;
			if (window.toastr) {
				toastr.error(message, $scope.t.errorTitle);
			}
		});
	};

	$scope.saveParallelLimit = function () {
		if (!$scope.isAdmin()) {
			return;
		}
		var value = parseInt($scope.maxParallelInput, 10);
		if (isNaN(value)) {
			if (window.toastr) {
				toastr.error($scope.t.limitSaveError, $scope.t.errorTitle);
			}
			return;
		}
		value = Math.max(1, Math.min(20, value));
		$scope.savingParallelLimit = true;
		$http.post('/api/jobs/settings', { max_parallel_nodes: value }).then(function (response) {
			$scope.savingParallelLimit = false;
			var payload = response.data && response.data.data ? response.data.data : null;
			if (payload && payload.max_parallel_nodes !== undefined) {
				$scope.maxParallelLimit = payload.max_parallel_nodes;
				$scope.maxParallelInput = payload.max_parallel_nodes;
			}
			if (window.toastr) {
				toastr.success($scope.t.limitSavedToast, 'OK');
			}
		}, function (error) {
			$scope.savingParallelLimit = false;
			var message = (error.data && error.data.message) ? error.data.message : $scope.t.limitSaveError;
			if (window.toastr) {
				toastr.error(message, $scope.t.errorTitle);
			}
		});
	};

	$scope.changePerPage = function () {
		$scope.pagination.page = 1;
		$scope.loadJobs();
	};

	$scope.refresh = function () {
		$scope.loadJobs();
	};

	$scope.applyFilters = function (resetPage) {
		if (resetPage) {
			$scope.pagination.page = 1;
		}
		$scope.loadJobs();
	};

	$scope.loadJobs = function (silent) {
		var params = buildQueryParams();
		var query = serializeQuery(params);
		if (!silent) {
			$scope.loading = true;
		}
		$scope.errorMessage = null;
		var currentRequest = ++activeRequest;
		$http.get('/api/jobs' + (query ? ('?' + query) : '')).then(function (response) {
			if (currentRequest !== activeRequest) {
				return;
			}
			var payload = response.data && response.data.data ? response.data.data : {};
			var items = payload.items || [];
			var normalized = items.map(normalizeJob);
			normalized.sort(function (a, b) {
				var rankA = statusRank[a.status] !== undefined ? statusRank[a.status] : statusOrder.length;
				var rankB = statusRank[b.status] !== undefined ? statusRank[b.status] : statusOrder.length;
				if (rankA !== rankB) {
					return rankA - rankB;
				}
				return (b.id || 0) - (a.id || 0);
			});
			$scope.jobs = normalized;
			$scope.counts = payload.counts || angular.copy($scope.counts);
			applySettingsPayload(payload);
			if (payload.pagination) {
				$scope.pagination.total = payload.pagination.total || 0;
				$scope.pagination.totalPages = payload.pagination.total_pages || 1;
			} else {
				$scope.pagination.total = $scope.jobs.length;
				$scope.pagination.totalPages = 1;
			}
		}, function (error) {
			if (currentRequest !== activeRequest) {
				return;
			}
			$scope.jobs = [];
			$scope.pagination.total = 0;
			$scope.pagination.totalPages = 1;
			var message = (error.data && error.data.message) ? error.data.message : 'Unable to load job queue.';
			$scope.errorMessage = message;
			if (window.toastr) {
				toastr.error(message, $scope.t.errorTitle);
			}
		}).finally(function () {
			if (currentRequest === activeRequest && !silent) {
				$scope.loading = false;
			}
		});
	};

	$scope.$watch('filters.search', function (newVal, oldVal) {
		if (oldVal === undefined) return;
		if (searchDebounce) $timeout.cancel(searchDebounce);
		searchDebounce = $timeout(function () {
			$scope.applyFilters(true);
		}, 400);
	});

	$scope.$watch('filters.node', function (newVal, oldVal) {
		if (oldVal === undefined) return;
		if (nodeDebounce) $timeout.cancel(nodeDebounce);
		nodeDebounce = $timeout(function () {
			$scope.applyFilters(true);
		}, 400);
	});

	$scope.$watch('filters.username', function (newVal, oldVal) {
		if (!$scope.isAdmin() || oldVal === undefined) return;
		if (userDebounce) $timeout.cancel(userDebounce);
		userDebounce = $timeout(function () {
			$scope.applyFilters(true);
		}, 400);
	});

	$scope.loadJobs();
	scheduleAutoRefresh();

	$scope.$on('$destroy', function () {
		stopAutoRefresh();
	});
});
