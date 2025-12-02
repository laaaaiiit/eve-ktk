angular.module("unlMainApp").controller('jobsQueueController', function jobsQueueController($scope, $http, $rootScope, $cookies, $timeout, themeService) {
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
			statusToggleLabel: 'Statuses',
			statusLabels: {
				pending: 'Pending',
				running: 'Running',
				success: 'Completed',
				failed: 'Failed'
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
			targetFallback: 'No targets reported',
			jobResultLabel: 'Result',
			rangeLabel: 'Showing {{start}}–{{end}} of {{total}} jobs',
			rangeEmpty: 'No jobs match the filters',
			emptyState: 'No jobs found for the current filters.',
			loading: 'Loading queue…',
			errorTitle: 'Error',
			refreshing: 'Refreshing…'
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
			statusToggleLabel: 'Статусы',
			statusLabels: {
				pending: 'В ожидании',
				running: 'Выполняется',
				success: 'Завершено',
				failed: 'Ошибка'
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
			targetFallback: 'Цели не указаны',
			jobResultLabel: 'Результат',
			rangeLabel: 'Показаны {{start}}–{{end}} из {{total}} задач',
			rangeEmpty: 'Нет задач по выбранным фильтрам',
			emptyState: 'Задачи не найдены.',
			loading: 'Загружаем очередь…',
			errorTitle: 'Ошибка',
			refreshing: 'Обновляем…'
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

	var statusOrder = ['running', 'pending', 'failed', 'success'];
	var statusRank = {};
	$scope.statusFilters = {};
	statusOrder.forEach(function (key, index) {
		statusRank[key] = index;
		$scope.statusFilters[key] = true;
	});
	$scope.statusDefinitions = statusOrder.map(function (key) {
		return { key: key };
	});

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
		failed: 0
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

	var searchDebounce = null;
	var nodeDebounce = null;
	var userDebounce = null;
	var activeRequest = 0;

	function selectedStatuses() {
		return statusOrder.filter(function (key) {
			return $scope.statusFilters[key];
		});
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

	function buildQueryParams() {
		var params = {
			page: $scope.pagination.page,
			per_page: $scope.pagination.perPage
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
		job.operationLabel = (job.payload.operation || job.action || '').toString().toUpperCase();
		job.targetPreview = buildTargetPreview(job.payload);
		return job;
	}

	function buildTargetPreview(payload) {
		if (!payload || typeof payload !== 'object') {
			return '';
		}
		var ids = Array.isArray(payload.ids) ? payload.ids : null;
		if (ids && ids.length) {
			return summarizeList(ids);
		}
		if (Array.isArray(payload.nodes) && payload.nodes.length) {
			return summarizeList(payload.nodes);
		}
		if (payload.node) {
			return Array.isArray(payload.node) ? summarizeList(payload.node) : String(payload.node);
		}
		if (payload.lab_path) {
			return payload.lab_path;
		}
		return '';
	}

	function summarizeList(list) {
		var clone = list.slice(0, 3).map(function (item) { return String(item); });
		var extra = list.length - clone.length;
		return extra > 0 ? (clone.join(', ') + ' +' + extra) : clone.join(', ');
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

	$scope.loadJobs = function () {
		var params = buildQueryParams();
		var query = serializeQuery(params);
		$scope.loading = true;
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
			if (currentRequest === activeRequest) {
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
});
