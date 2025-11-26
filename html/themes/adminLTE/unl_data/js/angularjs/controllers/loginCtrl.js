angular.module("unlMainApp").controller('loginController', function loginController($scope, $http, $location, $rootScope, $cookies, themeService) {
		var translations = {
			en: {
				brandTitle: 'Welcome to EVE-NG Community',
				brandSubtitle: 'Build, validate, and share labs faster with the refreshed workspace.',
				brandBulletOne: 'Hands-on scenarios for networking, security, and automation.',
				brandBulletTwo: 'HTML5 access to every console directly from your browser.',
				cardTitle: 'Sign in',
				cardSubtitle: 'Use your EVE credentials to continue.',
				themeLabel: 'Theme',
				themeHint: 'Switch the dashboard appearance.',
				darkModeLabel: 'Dark',
				lightModeLabel: 'Light',
				usernameLabel: 'Username',
				usernamePlaceholder: 'admin',
				passwordLabel: 'Password',
				passwordPlaceholder: '******',
				consoleLabel: 'Console type',
				html5Console: 'HTML5 console',
				submitButton: 'Open dashboard',
				hintText: 'The default password is set during installation. If you lost it, follow the recovery procedure or contact your platform administrator.',
				languageLabel: 'Language',
				errors: {
					missingCredentials: 'Enter both username and password.',
					generic: 'Login failed. Try again later or contact your administrator.',
					serverUnavailable: 'Authorization service is unavailable. Check the connection and retry.',
					invalidCredentials: 'Incorrect username or password.'
				}
			},
			ru: {
				brandTitle: 'Добро пожаловать в EVE-NG Community',
				brandSubtitle: 'Создавайте, проверяйте и делитесь лабораториями быстрее благодаря обновленному рабочему пространству.',
				brandBulletOne: 'Практические сценарии для сетей, безопасности и автоматизации.',
				brandBulletTwo: 'HTML5-доступ к каждой консоли прямо из браузера.',
				cardTitle: 'Вход в систему',
				cardSubtitle: 'Используйте учетные данные EVE, чтобы продолжить.',
				themeLabel: 'Тема',
				themeHint: 'Переключите оформление панели.',
				darkModeLabel: 'Тёмная',
				lightModeLabel: 'Светлая',
				usernameLabel: 'Имя пользователя',
				usernamePlaceholder: 'admin',
				passwordLabel: 'Пароль',
				passwordPlaceholder: '******',
				consoleLabel: 'Тип консоли',
				html5Console: 'HTML5-консоль',
				submitButton: 'Войти в панель',
				hintText: 'Пароль по умолчанию задается при установке. Если вы его забыли, воспользуйтесь процедурой восстановления или обратитесь к администратору платформы.',
				languageLabel: 'Язык',
				errors: {
					missingCredentials: 'Введите имя пользователя и пароль.',
					generic: 'Не удалось выполнить вход. Попробуйте позже или обратитесь к администратору.',
					serverUnavailable: 'Сервер авторизации недоступен. Проверьте подключение и повторите попытку.',
					invalidCredentials: 'Неверное имя пользователя или пароль.'
				}
			}
		};

		$scope.languages = [
			{ key: 'ru', label: 'Русский' },
			{ key: 'en', label: 'English' }
		];
		$scope.languageOptions = angular.copy($scope.languages);
		$scope.themeOptions = [];
		$scope.consoleOptions = [];
		$scope.html5 = '1';

		function getValidLanguage(lang) {
			return translations[lang] ? lang : 'ru';
		}

		function currentTranslation() {
			return translations[$scope.lang] || translations.ru;
		}

		function hasCyrillic(text) {
			return /[А-Яа-яЁё]/.test(text || '');
		}

		function formatServerMessage(fallback, serverMessage) {
			if (!serverMessage) {
				return fallback;
			}
			if ($scope.lang === 'ru' || !hasCyrillic(serverMessage)) {
				return serverMessage;
			}
			var codeMatch = serverMessage.match(/\(\s*\d+\s*\)/);
			return codeMatch ? fallback + ' ' + codeMatch[0] : fallback;
		}

		function applyLanguage(lang) {
			var validLang = getValidLanguage(lang);
			$scope.lang = validLang;
			$scope.t = translations[validLang];
			$rootScope.lang = validLang;
			$cookies.put('eve_login_lang', validLang, { path: '/' });
			$scope.languageOptions = $scope.languages.map(function (item) { return { value: item.key, label: item.label }; });
			$scope.themeOptions = [
				{ value: 'dark', label: $scope.t.darkModeLabel },
				{ value: 'light', label: $scope.t.lightModeLabel }
			];
			$scope.consoleOptions = [
				{ value: '1', label: $scope.t.html5Console }
			];
		}

		var savedLang = $cookies.get('eve_login_lang');
		applyLanguage(getValidLanguage(savedLang || 'ru'));

		$scope.setLanguage = function (lang) {
			applyLanguage(lang);
		};
		$scope.onLanguageChange = function (value) {
			$scope.setLanguage(value);
		};
		$scope.onThemeChange = function (value) {
			$scope.setTheme(value);
		};
		$scope.onConsoleChange = function (value) {
			$scope.html5 = value;
		};

		$scope.eveversion = $rootScope.EVE_VERSION + "-Community";
		if ($scope.html5 == null) { $scope.html5 = '1'; }
		if ($cookies.get('unetlab_session')) {
			$scope.testAUTH("/main");
		}
		$scope.theme = themeService.sync($rootScope.username);
		$scope.themeClass = function (darkClasses, lightClasses) {
			return ($scope.theme === 'light') ? (lightClasses || '') : (darkClasses || '');
		};
		$scope.toggleTheme = function () {
			$scope.theme = themeService.toggle();
		};
		$scope.setTheme = function (value) {
			$scope.theme = themeService.apply(value, $rootScope.username);
		};
		$scope.$watch(function () { return $rootScope.theme; }, function (val) {
			if (val) { $scope.theme = val; }
		});
	$('body').removeClass().addClass('hold-transition login-page');
		$scope.tryLogin = function () {
			$scope.loginMessageInfo = "";
			if (!$scope.username || !$scope.password) {
				$scope.loginMessageInfo = currentTranslation().errors.missingCredentials;
				return;
			}
			$http({
				method: 'POST',
				url: '/api/auth/login',
				data: { "username": $scope.username, "password": $scope.password, "html5": $scope.html5 }
			})
				.then(
					function successCallback(response) {
						if (response.data && response.data.code === 200) {
							blockUI();
							$scope.testAUTH("/main");
						} else {
							var message = currentTranslation().errors.generic;
							$scope.loginMessageInfo = formatServerMessage(message, response.data && response.data.message);
						}
						$.unblockUI(); // Unblock UI after login attempt
					},
					function errorCallback(response) {
						var message = currentTranslation().errors.generic;
						if (response.status == 0) {
							message = currentTranslation().errors.serverUnavailable;
						} else if (response.status == 400 || response.status == 401) {
							message = currentTranslation().errors.invalidCredentials;
						}
						var serverMessage = response.data && response.data.message;
						$scope.loginMessageInfo = formatServerMessage(message, serverMessage);
						$.unblockUI(); // Unblock UI after login attempt
					}
				);
		}
	});
