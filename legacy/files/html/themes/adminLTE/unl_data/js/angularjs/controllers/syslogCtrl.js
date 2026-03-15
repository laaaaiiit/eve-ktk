angular.module("unlMainApp").controller('syslogController', function syslogController($scope, $http, $rootScope, $cookies, $location, $timeout, themeService) {
	var translations = {
		en: {
			heroEyebrow: 'Telemetry',
			heroTitle: 'System log viewer',
			heroDescription: 'Browse every log under /opt/unetlab/data/Logs, filter by filename, trim the line count, and search text instantly.',
			filterFileLabel: 'Select log file',
			filterLinesLabel: 'Number of lines',
			filterSearchLabel: 'Search text',
			outputTitle: 'Log output',
			linesSuffix: 'lines',
			choosePrompt: 'Choose log file',
			emptyPrompt: 'No entries found',
			placeholderLines: 'e.g. 200',
			placeholderSearch: 'pattern',
			fileCountLabel: 'Available files',
			updatedLabel: 'Last modified',
			bytesLabel: 'KB',
			refreshButton: 'Refresh list',
			refreshing: 'Updating...'
		},
		ru: {
			heroEyebrow: 'Телеметрия',
			heroTitle: 'Просмотр системных логов',
			heroDescription: 'Просматривайте все логи в /opt/unetlab/data/Logs, выбирайте файл, ограничивайте количество строк и ищите текст сразу при вводе.',
			filterFileLabel: 'Выберите файл',
			filterLinesLabel: 'Количество строк',
			filterSearchLabel: 'Поиск текста',
			outputTitle: 'Содержимое файла',
			linesSuffix: 'строк',
			choosePrompt: 'Выберите файл',
			emptyPrompt: 'Записей не найдено',
			placeholderLines: 'например, 200',
			placeholderSearch: 'шаблон',
			fileCountLabel: 'Доступные файлы',
			updatedLabel: 'Изменен',
			bytesLabel: 'КБ',
			refreshButton: 'Обновить список',
			refreshing: 'Обновляем...'
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

	$scope.testAUTH("/syslog");
	$scope.role = null;
	$scope.roleResolved = false;
	$scope.$watch(function () { return $rootScope.role; }, function (role) {
		if (role) {
			$scope.role = role;
			$scope.roleResolved = true;
		}
	});

	$('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');

	$scope.fileList = [];
	$scope.state = {
		selectedFile: null, // hold selected file object
		lineCount: 200,
		searchText: ''
	};
	$scope.logInfo = [];
	$scope.blockButtons = false;
	$scope.fileselect = false;
	$scope.filesLoading = false;
	$scope.currentMeta = null;
	$scope.filesReady = false;

	var requestSeq = 0;
	var fileRequestSeq = 0;
	var searchWatcherPromise = null;
	var lastLineCount = $scope.state.lineCount;
	function sanitizedLines(val) {
		var parsed = parseInt(val, 10);
		if (isNaN(parsed) || parsed <= 0) return 200;
		return Math.min(parsed, 5000);
	}

	$scope.formatKb = function (bytes) {
		var num = parseInt(bytes, 10);
		if (isNaN(num) || num < 0) return 0;
		return Math.round(num / 1024);
	};

	function updateMeta() {
		$scope.currentMeta = $scope.selectedFileMeta();
	}

	function ensureSelection() {
		if ($scope.fileList && $scope.fileList.length > 0) {
			var current = ($scope.state.selectedFile && $scope.state.selectedFile.name) || '';
			var match = $scope.fileList.find(function (item) { return item.name === current; });
			$scope.state.selectedFile = match || $scope.fileList[0];
		}
	}

	$scope.fetchFiles = function () {
		$scope.filesLoading = true;
		$scope.filesReady = false;
		var currentReq = ++fileRequestSeq;
		$http.get('/api/logs/files?ts=' + Date.now()).then(
			function success(response) {
				if (currentReq !== fileRequestSeq) return;
				var files = (response.data || []).filter(function (item) {
					return item && item.name;
				}).map(function (item) {
					return {
						name: String(item.name),
						size: item.size,
						modified: item.modified
					};
				});
				$scope.fileList = files;
				if (files.length === 0) {
					$scope.state.selectedFile = null;
				} else {
					ensureSelection();
				}
				$scope.filesReady = files.length > 0;
				updateMeta();
				if ($scope.state.selectedFile && files.length > 0) {
					$scope.readFile($scope.state.selectedFile.name, { lines: lastLineCount, search: $scope.state.searchText });
				} else {
					$scope.logInfo = [];
					$scope.fileselect = false;
				}
			},
			function error(response) {
				console.log(response);
				console.log("Failed to load log file list");
			}
		).finally(function () {
			if (currentReq === fileRequestSeq) {
				$scope.filesLoading = false;
			}
		});
	};

	$scope.readFile = function (filename, opts) {
		var targetName = filename || ($scope.state.selectedFile && $scope.state.selectedFile.name);
		if (!targetName) {
			$scope.logInfo = [];
			$scope.fileselect = false;
			return;
		}
		targetName = String(targetName || '').trim();
		$scope.state.selectedFile = $scope.fileList.find(function (item) { return item.name === targetName; }) || { name: targetName };
		updateMeta();
		var rawLines = (opts && opts.lines !== undefined) ? opts.lines : $scope.state.lineCount;
		var lines = sanitizedLines(rawLines);
		var search = (opts && opts.search !== undefined) ? opts.search : ($scope.state.searchText || '');
		lastLineCount = lines;
		$scope.state.lineCount = lines;
		$scope.blockButtons = true;
		$scope.logInfo = [];
		var params = {
			file: targetName,
			lines: lines,
			search: search || '',
			ts: Date.now()
		};
		var currentReq = ++requestSeq;
		$http.get('/api/logs', { params: params }).then(
			function successCallback(response) {
				if (currentReq !== requestSeq) { return; }
				$scope.state.selectedFile = $scope.fileList.find(function (item) { return item.name === targetName; }) || { name: targetName };
				updateMeta();
				$scope.fileselect = true;
				var data = response.data || [];
				var trimmed = data.filter(function (line) {
					return line !== null && line !== undefined && String(line).trim() !== '';
				});
				$scope.logInfo = trimmed.slice(0, lines);
			},
			function errorCallback(response) {
				if (currentReq !== requestSeq) { return; }
				console.log(response);
				if (response && response.status === 401) {
					$location.path("/login");
				}
			}
		).finally(function () {
			if (currentReq === requestSeq) {
				$scope.blockButtons = false;
			}
		});
	};

	$scope.onFileSelectionChange = function (filename) {
		$scope.state.selectedFile = filename || $scope.state.selectedFile || null;
		updateMeta();
		$scope.readFile(($scope.state.selectedFile && $scope.state.selectedFile.name), { lines: lastLineCount, search: $scope.state.searchText || '' });
	};

	$scope.onLineCountChange = function (val) {
		var clean = sanitizedLines(val);
		lastLineCount = clean;
		$scope.state.lineCount = clean;
		$scope.readFile(($scope.state.selectedFile && $scope.state.selectedFile.name), { lines: clean, search: $scope.state.searchText || '' });
	};

	$scope.onSearchChange = function (val) {
		if (val === undefined) {
			val = $scope.state.searchText;
		}
		$scope.state.searchText = val || '';
		if (searchWatcherPromise) {
			$timeout.cancel(searchWatcherPromise);
		}
		searchWatcherPromise = $timeout(function () {
			$scope.readFile(($scope.state.selectedFile && $scope.state.selectedFile.name), { search: $scope.state.searchText || '', lines: lastLineCount });
		}, 300);
	};

	$scope.selectedFileMeta = function () {
		if (!$scope.state.selectedFile || !$scope.fileList || !$scope.fileList.length) return null;
		var currentName = $scope.state.selectedFile.name;
		return $scope.fileList.find(function (item) { return item.name === currentName; }) || null;
	};

	$scope.$on('$destroy', function () {
		if (searchWatcherPromise) {
			$timeout.cancel(searchWatcherPromise);
		}
	});

	$scope.$watch('fileList', function (val, oldVal) {
		if (!val || !val.length) return;
		ensureSelection();
		updateMeta();
		$scope.readFile(($scope.state.selectedFile && $scope.state.selectedFile.name), { lines: lastLineCount, search: $scope.state.searchText || '' });
	});

	$scope.fetchFiles();
});
