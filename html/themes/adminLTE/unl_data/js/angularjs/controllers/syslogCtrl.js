angular.module("unlMainApp").controller('syslogController',function syslogController($scope, $http, $rootScope, $cookies, $location) {
	var translations = {
		en: {
			heroEyebrow: 'Telemetry',
			heroTitle: 'System log viewer',
			heroDescription: 'Review platform events, kernel alerts, and daemon output in real time. Filter the feed, search incidents, and export the context you need.',
			chipSelectedLabel: 'Selected file',
			chipSelectedEmpty: 'not selected',
			chipLinesLabel: 'Max lines',
			filterFileLabel: 'Select log file',
			filterLinesLabel: 'Number of lines',
			filterSearchLabel: 'Search text',
			filterButton: 'View logs',
			outputTitle: 'Log output',
			linesSuffix: 'lines',
			choosePrompt: 'Choose log file',
			emptyPrompt: 'No entries found',
			placeholderLines: 'e.g. 200',
			placeholderSearch: 'pattern'
		},
		ru: {
			heroEyebrow: 'Телеметрия',
			heroTitle: 'Просмотр системных логов',
			heroDescription: 'Следите за событиями платформы, предупреждениями ядра и логами сервисов в реальном времени. Фильтруйте, ищите и сохраняйте нужный контекст.',
			chipSelectedLabel: 'Выбранный файл',
			chipSelectedEmpty: 'не выбран',
			chipLinesLabel: 'Максимум строк',
			filterFileLabel: 'Выберите файл',
			filterLinesLabel: 'Количество строк',
			filterSearchLabel: 'Поиск текста',
			filterButton: 'Показать логи',
			outputTitle: 'Содержимое файла',
			linesSuffix: 'строк',
			choosePrompt: 'Выберите файл логов',
			emptyPrompt: 'Записей не найдено',
			placeholderLines: 'например, 200',
			placeholderSearch: 'шаблон'
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
	$scope.testAUTH("/syslog"); //TEST AUTH
	$scope.role = null;
	$scope.roleResolved = false;
	$scope.$watch(function () { return $rootScope.role; }, function (role) {
		if (role) {
			$scope.role = role;
			$scope.roleResolved = true;
		}
	});
	$('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');
	$scope.fileselect=false;
	$scope.lineCount=20;
	$scope.searchText='';
	$scope.logInfo=[];
	$scope.accessLog=[];
	$scope.apiLog=[];
	$scope.errorLog=[];
	$scope.php_errorsLog=[];
	$scope.unl_wrapperLog=[];
	$scope.blockButtons=false;
	$scope.blockButtonsClass='';
	$scope.logfiles= ['access.txt', 'api.txt','error.txt','php_errors.txt','unl_wrapper.txt','cpulimit.log']
	
	$scope.readFile = function(filename){
		//console.log(filename)
		filename = (filename === undefined) ? $scope.fileSelection : filename
		$scope.blockButtons=true;
		$scope.blockButtonsClass='';
		$scope.logInfo=[];
		$http.get('/api/logs/'+filename+'/'+$scope.lineCount+'/'+$scope.searchText).then(
			function successCallback(response) {
				//console.log(response.data)
				$scope.fileselect=true;	
				$scope.logInfo=response.data;
				$scope.blockButtons=false;
				$scope.blockButtonsClass='';
			}, 
			function errorCallback(response) {
				console.log(response)
				console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");
				$scope.blockButtons=false;
				$scope.blockButtonsClass='';
				}	
		);
	}
	$scope.readFile('access.txt')
});
