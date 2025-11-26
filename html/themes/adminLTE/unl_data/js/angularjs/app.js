/* UNL App */
var app_main_unl = angular.module("unlMainApp", [
    "ui.router",
    "ui.select",
    "ui.bootstrap",
    "oc.lazyLoad",
    "ngSanitize",
    "ngAnimate",
    "ui.knob",
    "ngCookies"
]);

app_main_unl.run(function ($rootScope, themeService) {
    $rootScope.imgpath = '/themes/adminLTE/unl_data/img/';
    $rootScope.angularCtlrPath = '/themes/adminLTE/unl_data/angular/controllers';
    $rootScope.jspath = '/themes/adminLTE/unl_data/js/';
    $rootScope.csspath = '/themes/adminLTE/unl_data/css/';
    $rootScope.pagespath = '/themes/adminLTE/unl_data/pages/';
    $rootScope.bodyclass = 'sidebar-collapse';
    $rootScope.UIlegacy = 1;
    $rootScope.EVE_VERSION = "6.2.0-4";
    $rootScope.themeClass = function (darkClasses, lightClasses) {
        var theme = $rootScope.theme || themeService.read();
        return (theme === 'light') ? (lightClasses || '') : (darkClasses || '');
    };
    themeService.sync();
});

app_main_unl.directive('focusOn', function () {
    return function (scope, elem, attr) {
        scope.$on('focusOn', function (e, name) {
            if (name === attr.focusOn) {
                elem[0].focus();
            }
        });
    };
});

app_main_unl.factory('focus', function ($rootScope, $timeout) {
    return function (name) {
        $timeout(function () {
            $rootScope.$broadcast('focusOn', name);
        });
    }
});

app_main_unl.factory('loadingOverlayFactory', ['$timeout', function ($timeout) {
    return {
        bind: function (scope, property, options) {
            var prop = property || 'isLoading';
            var timeoutDuration = (options && options.timeout) || 60000;
            var activeCount = 0;
            var timeoutPromise = null;

            scope[prop] = scope[prop] || false;

            function scheduleTimeout() {
                if (!timeoutDuration) return;
                if (timeoutPromise) {
                    $timeout.cancel(timeoutPromise);
                }
                timeoutPromise = $timeout(function () {
                    activeCount = 0;
                    scope[prop] = false;
                    timeoutPromise = null;
                }, timeoutDuration);
            }

            function clearTimeoutHandle() {
                if (timeoutPromise) {
                    $timeout.cancel(timeoutPromise);
                    timeoutPromise = null;
                }
            }

            function start() {
                activeCount++;
                scope[prop] = true;
                scheduleTimeout();
            }

            function stop(force) {
                if (force) {
                    activeCount = 0;
                } else if (activeCount > 0) {
                    activeCount--;
                }

                if (activeCount === 0) {
                    scope[prop] = false;
                    clearTimeoutHandle();
                } else {
                    scheduleTimeout();
                }
            }

            scope.$on('$destroy', function () {
                clearTimeoutHandle();
                activeCount = 0;
            });

            return {
                start: start,
                stop: stop
            };
        }
    };
}]);

app_main_unl.factory('themeService', ['$rootScope', '$cookies', function ($rootScope, $cookies) {
    var DEFAULT_THEME = 'dark';

    function normalize(theme) {
        return (theme === 'light') ? 'light' : DEFAULT_THEME;
    }

    function readLocalStorage() {
        if (typeof localStorage === 'undefined') return null;
        try {
            var stored = localStorage.getItem('eve_theme_pref');
            if (stored) return stored;
        } catch (e) { }
        return null;
    }

    function cookieKey(user) {
        var username = user || $rootScope.username || 'shared';
        return 'eve_theme_' + username;
    }

    function readAnySaved() {
        var ls = readLocalStorage();
        if (ls) return ls;
        var all = ($cookies && $cookies.getAll && $cookies.getAll()) || {};
        var keys = Object.keys(all);
        for (var i = 0; i < keys.length; i++) {
            if (keys[i].indexOf('eve_theme_') === 0) {
                return all[keys[i]];
            }
        }
        return null;
    }

    function read(user) {
        var key = cookieKey(user);
        var saved = $cookies.get(key);
        if (!saved) {
            saved = readAnySaved();
        }
        return normalize(saved);
    }

    function applyTheme(theme, user) {
        var nextTheme = normalize(theme);
        $cookies.put(cookieKey(user), nextTheme, { path: '/' });
        try { localStorage.setItem('eve_theme_pref', nextTheme); } catch (e) { }
        $rootScope.theme = nextTheme;
        if (typeof document !== 'undefined' && document.documentElement) {
            document.documentElement.setAttribute('data-theme', nextTheme);
        }
        return nextTheme;
    }

    function sync(user) {
        return applyTheme(read(user), user);
    }

    function toggle() {
        var next = ($rootScope.theme === 'light') ? 'dark' : 'light';
        return applyTheme(next);
    }

    return {
        read: read,
        apply: applyTheme,
        sync: sync,
        toggle: toggle
    };
}]);

app_main_unl.directive('loadingOverlay', function () {
    return {
        restrict: 'E',
        scope: {
            isLoading: '=',
            title: '=?',
            subtitle: '=?'
        },
        template: '\n            <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-sm flex flex-col items-center justify-center gap-4 text-slate-100 z-10" ng-if="isLoading">\n                <div class="flex flex-col items-center gap-3 text-center">\n                    <div class="h-12 w-12 rounded-full border-2 border-white/20 border-t-blue-400 animate-spin"></div>\n                    <div>\n                        <p class="text-sm uppercase tracking-[0.35em] text-blue-200/80" ng-bind="title"></p>\n                        <p class="text-slate-300 text-sm max-w-md" ng-bind="subtitle"></p>\n                    </div>\n                </div>\n            </div>\n        '
    };
});

app_main_unl.directive('plumbItem', function () {
    return {
        controller: 'labController',
        link: function (scope, element, attrs) {
            jsPlumb.makeTarget(element);
        }
    };
});

app_main_unl.component('fancySelect', {
    bindings: {
        options: '<',
        model: '=',
        onChange: '&?',
        label: '@?',
        themeClass: '&?'
    },
    controller: ['$document', '$scope', function ($document, $scope) {
        var ctrl = this;
        ctrl.isOpen = false;

        ctrl.$onInit = function () {
            ctrl.options = ctrl.options || [];
        };

        ctrl.toggle = function ($event) {
            $event && $event.stopPropagation();
            ctrl.isOpen = !ctrl.isOpen;
        };

        ctrl.close = function () {
            if (ctrl.isOpen) {
                ctrl.isOpen = false;
                $scope.$applyAsync();
            }
        };

        ctrl.select = function (option) {
            ctrl.model = option.value;
            ctrl.isOpen = false;
            if (ctrl.onChange) {
                ctrl.onChange({ value: option.value });
            }
        };

        ctrl.applyTheme = function (darkClasses, lightClasses) {
            if (ctrl.themeClass) {
                return ctrl.themeClass({ darkClasses: darkClasses, lightClasses: lightClasses });
            }
            return darkClasses;
        };

        ctrl.currentLabel = function () {
            if (!ctrl.options) return '';
            for (var i = 0; i < ctrl.options.length; i++) {
                if (ctrl.options[i].value === ctrl.model) {
                    return ctrl.options[i].label;
                }
            }
            return '';
        };

        function outsideClick(evt) {
            var el = evt.target;
            if (!el.closest || !el.closest('.fancy-select')) {
                ctrl.close();
            }
        }

        $document.on('click', outsideClick);

        ctrl.$onDestroy = function () {
            $document.off('click', outsideClick);
        };
    }],
    template:
        '<div class="fancy-select relative inline-block w-full max-w-xs" ng-class="$ctrl.applyTheme(\'text-slate-100\', \'text-slate-900\')">' +
        '  <button type="button" class="w-full rounded-2xl border px-4 py-3 flex items-center justify-between gap-3 shadow-sm transition cursor-pointer"' +
        '          ng-class="$ctrl.applyTheme(\'bg-white/5 border-white/10 hover:bg-white/10\', \'bg-white border-slate-200 hover:bg-slate-50\')" ng-click="$ctrl.toggle($event)">' +
        '    <div class="flex flex-col text-left">' +
        '      <span class="text-[11px] uppercase tracking-[0.3em] theme-muted" ng-if="$ctrl.label" ng-bind="$ctrl.label"></span>' +
        '      <span class="font-semibold" ng-bind="$ctrl.currentLabel()"></span>' +
        '    </div>' +
        '    <span class="text-sm"><i class="fa" ng-class="{\'fa-chevron-up\': $ctrl.isOpen, \'fa-chevron-down\': !$ctrl.isOpen}"></i></span>' +
        '  </button>' +
        '  <div class="absolute mt-2 left-0 right-0 origin-top z-20" ng-class="$ctrl.isOpen ? \'opacity-100 scale-100\' : \'opacity-0 scale-95 pointer-events-none\'" style="transition: all .15s ease;">' +
        '    <div class="rounded-2xl border shadow-2xl overflow-hidden" ng-class="$ctrl.applyTheme(\'bg-slate-900/90 border-white/10\', \'bg-white border-slate-200\')">' +
        '      <ul class="max-h-56 overflow-auto divide-y" ng-class="$ctrl.applyTheme(\'divide-white/10\', \'divide-slate-200\')">' +
        '        <li class="px-4 py-3 cursor-pointer transition flex items-center justify-between gap-3"' +
        '            ng-class="$ctrl.applyTheme(\'hover:bg-white/10\', \'hover:bg-slate-100\')"' +
        '            ng-repeat="opt in $ctrl.options" ng-click="$ctrl.select(opt)">' +
        '          <div class="flex-1">' +
        '            <div class="flex items-center gap-2">' +
        '              <span class="font-semibold" ng-bind="opt.label"></span>' +
        '              <span class="text-xs uppercase tracking-[0.2em] theme-muted" ng-if="opt.hint" ng-bind="opt.hint"></span>' +
        '            </div>' +
        '          </div>' +
        '          <i class="fa fa-check text-emerald-400" ng-if="opt.value === $ctrl.model"></i>' +
        '        </li>' +
        '      </ul>' +
        '    </div>' +
        '  </div>' +
        '</div>'
});

/* Configure ocLazyLoader(refer: https://github.com/ocombe/ocLazyLoad) */
app_main_unl.config(['$ocLazyLoadProvider', function ($ocLazyLoadProvider) {
    $ocLazyLoadProvider.config({
        // global configs go here
    });
}]);

app_main_unl.config(['$controllerProvider', function ($controllerProvider) {
    // this option might be handy for migrating old apps, but please don't use it
    // in new ones!
    //  $controllerProvider.allowGlobals();
}]);

app_main_unl.config(['$compileProvider', function ($compileProvider) {
    $compileProvider.aHrefSanitizationWhitelist(/^\s*(https?|ftp|telnet|vnc|rdp):/);
}]);

app_main_unl.config(['$httpProvider', function ($httpProvider) {
    $httpProvider.defaults.cache = false;
    if (!$httpProvider.defaults.headers.get) {
        $httpProvider.defaults.headers.get = {};
    }
    // disable IE ajax request caching
    $httpProvider.defaults.headers.get['If-Modified-Since'] = '0';
    //.....here proceed with your routes
}]);


app_main_unl.directive('myEnter', function () {
    return function (scope, element, attrs) {
        element.bind("keydown keypress", function (event) {
            if (event.which === 13) {
                scope.$apply(function () {
                    scope.$eval(attrs.myEnter);
                });

                event.preventDefault();
            }
        });
    };
});

/* Setup App Main Controller */
app_main_unl.controller('unlMainController', ['$scope', '$rootScope', '$http', '$location', '$cookies', 'themeService', function ($scope, $rootScope, $http, $location, $cookies, themeService) {
    $.get('/themes/adminLTE/VERSION?' + Date.now(), function (data) {
        if (data.trim() != $rootScope.EVE_VERSION) window.location.reload(true);
    });
    $scope.testAUTH = function (path) {
        $scope.userfolder = 'none';
        $http.get('/api/auth').then(
            function successCallback(response) {
                if (response.status == '200' && response.statusText == 'OK') {
                    $rootScope.username = response.data.data.username;
                    $rootScope.folder = (response.data.data.folder === null) ? '/' : response.data.data.folder;
                    $rootScope.email = response.data.data.email;
                    $rootScope.role = response.data.data.role;
                    $rootScope.name = response.data.data.name;
                    if (path != "/lab") $rootScope.lab = response.data.data.lab;
                    var cookieLang = $cookies.get('eve_login_lang');
                    var serverLang = response.data.data.lang;
                    var effectiveLang = serverLang || cookieLang || 'ru';
                    $rootScope.lang = effectiveLang;
                    if (cookieLang !== effectiveLang) {
                        $cookies.put('eve_login_lang', effectiveLang, { path: '/' });
                    }
                    themeService.sync($rootScope.username);
                    $rootScope.tenant = response.data.data.tenant;
                    $scope.userfolder = response.data.folder;

                    if (path === '/syslog' && $rootScope.role !== 'admin') {
                        $location.path('/main');
                        $.unblockUI();
                        return;
                    }

                    if ($rootScope.role !== 'editor') {
                        localStorage.clear();
                        getGuacTokenFromAPI();
                    }

                    function getGuacTokenFromAPI() {
                        $http.get('/api/token').then(function (tokenResponse) {
                            if (tokenResponse.data.code === 200) {
                                $rootScope.guacToken = tokenResponse.data.data.token;
                            }
                        }, function (tokenError) {
                            console.log("Ошибка получения guacamole токена:", tokenError);
                        });
                    }

                    // Preview need to get back to legacy UI
                    if ($rootScope.UIlegacy == 1) {
                        if ($rootScope.lab === null) { $location.path(path) } else { location.href = '/legacy/' };
                    } else {
                        if ($rootScope.lab === null) { $location.path(path) } else { $location.path('/lab') };
                    }
                    $.unblockUI(); // Unblock UI on successful authentication
                }
            },
            function errorCallback(response) {
                if (response.status == '401' && response.statusText == 'Unauthorized') {
                    $location.path("/login");
                }
                else { console.log("Unknown Error. Why did API doesn't respond?") }
                $.unblockUI(); // Unblock UI on authentication error
            });
    }
}]);

/* Setup Layout Part - Header */
app_main_unl.controller('HeaderController', ['$scope', '$http', '$location', '$rootScope', '$cookies', 'themeService', function ($scope, $http, $location, $rootScope, $cookies, themeService) {
    var translations = {
        en: {
            menuMain: 'Main',
            menuManagement: 'Management',
            menuSystem: 'System',
            menuUserMgmt: 'User management',
            menuNodeMgmt: 'Node management',
            menuCloudMgmt: 'Cloud management',
            menuSysStatus: 'System status',
            menuSyslog: 'System logs',
            menuGuac: 'Guacamole',
            logout: 'Sign out'
        },
        ru: {
            menuMain: 'Главная',
            menuManagement: 'Управление',
            menuSystem: 'Система',
            menuUserMgmt: 'Пользователи',
            menuNodeMgmt: 'Узлы',
            menuCloudMgmt: 'Облака',
            menuSysStatus: 'Статус системы',
            menuSyslog: 'Системные логи',
            menuGuac: 'Guacamole',
            logout: 'Выход'
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

    $scope.activeClass = 'active';
    $scope.emptyClass = '';
    $scope.currentPath = $location.path();
    $scope.logout = function () {
        $http.get('/api/auth/logout').then(
            function successCallback(response) {
                if (response.status == '200' && response.statusText == 'OK') {
                    $location.path("/login");
                }
            },
            function errorCallback(response) {
                console.log("Unknown Error. Why did API doesn't respond?")
                $location.path("/login");
            });
    }
    $scope.$on('$locationChangeSuccess', function () {
        $scope.currentPath = $location.path();
    });

    $scope.theme = themeService.sync($rootScope.username);
    $scope.themeClass = function (darkClasses, lightClasses) {
        return ($scope.theme === 'light') ? (lightClasses || '') : (darkClasses || '');
    };
    var themeLabels = {
        light: { en: 'Light theme', ru: 'Светлая тема' },
        dark: { en: 'Dark theme', ru: 'Тёмная тема' }
    };
    var themeHint = { en: 'Personal preference saved on this device', ru: 'Настройка для пользователя на этом устройстве' };
    $scope.themeLabel = function () {
        var lang = $rootScope.lang || 'en';
        return themeLabels[$scope.theme || 'dark'][lang] || themeLabels.dark.en;
    };
    $scope.themeHint = function () {
        var lang = $rootScope.lang || 'en';
        return themeHint[lang] || themeHint.en;
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
    $scope.$watch(function () { return $rootScope.username; }, function (val, oldVal) {
        if (val && val !== oldVal) {
            $scope.theme = themeService.sync(val);
        }
    });
    $scope.$watch(function () { return $rootScope.lang; }, function () {
        $scope.themeLabel();
    });

    $scope.activeLinks = {
        'main': '/main',
        'usermgmt': '/usermgmt',
        'syslog': '/syslog',
        'sysstat': '/main',
        'nodemgmt': '/nodemgmt',
        'cloudmgmt': '/cloudmgmt',
    }
}]);



/* Setup Rounting For All Pages */
app_main_unl.config(['$stateProvider', '$urlRouterProvider', function ($stateProvider, $urlRouterProvider, $scope) {
    // Redirect any unmatched url
    $urlRouterProvider.otherwise("/login");

    $stateProvider

        // LOGIN
        .state('login', {
            url: "/login",
            templateUrl: "/themes/adminLTE/unl_data/pages/login.html",
            data: { pageTitle: 'Login' },
            controller: "loginController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/loginCtrl.js',
                            '/themes/adminLTE/unl_data/css/custom_unl.css',
                        ]
                    });
                }]
            }
        })

        // MAIN_LAYOUT
        .state('main', {
            url: "/main",
            templateUrl: "/themes/adminLTE/unl_data/pages/main.html",
            data: { pageTitle: 'Main menu' },
            controller: "mainController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/mainCtrl.js',
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/modalCtrl.js',
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/labviewCtrl.js',
                            '/themes/adminLTE/plugins/angularJS/plugins/angular-file-upload/angular-file-upload.min.js',
                            '/themes/adminLTE/dist/css/skins/skin-blue.min.css',
                            '/themes/adminLTE/dist/js/app.min.js',
                        ]
                    });
                }]
            }
        })
        // USER MANAGEMENT
        .state('usermgmt', {
            url: "/usermgmt",
            templateUrl: "/themes/adminLTE/unl_data/pages/usermgmt.html",
            data: { pageTitle: 'User management' },
            controller: "usermgmtController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/usermgmtCtrl.js',
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/modalCtrl.js'
                        ]
                    });
                }]
            }
        })
        // NODE MANAGEMENT
        .state('nodemgmt', {
            url: "/nodemgmt",
            templateUrl: "/themes/adminLTE/unl_data/pages/nodemgmt.html",
            data: { pageTitle: 'Node Management' },
            controller: "nodemgmtController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/nodemgmtCtrl.js',
                        ]
                    });
                }]
            }
        })
        // CLOUD MANAGEMENT
        .state('cloudmgmt', {
            url: "/cloudmgmt",
            templateUrl: "/themes/adminLTE/unl_data/pages/cloudmgmt.html",
            data: { pageTitle: 'Cloud Management' },
            controller: "cloudmgmtController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/cloudmgmtCtrl.js',
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/modalCtrl.js'
                        ]
                    });
                }]
            }
        })
        // SYSTEM LOG
        .state('syslog', {
            url: "/syslog",
            templateUrl: "/themes/adminLTE/unl_data/pages/syslog.html",
            data: { pageTitle: 'System logs' },
            controller: "syslogController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/syslogCtrl.js'
                        ]
                    });
                }]
            }
        })
        // SYSTEM STAT
        .state('sysstat', {
            url: "/sysstat",
            templateUrl: "/themes/adminLTE/unl_data/pages/sysstat.html",
            data: { pageTitle: 'System status' },
            controller: "sysstatController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/sysstatCtrl.js',
                            '/themes/adminLTE/plugins/ng-knob/d3.min.js'
                        ]
                    });
                }]
            }
        })
        //LAB LAYOUT
        .state('labnew', {
            url: "/lab",
            templateUrl: "/themes/adminLTE/unl_data/pages/lab/lab.html",
            data: { pageTitle: 'Lab' },
            controller: "labController",
            resolve: {
                deps: ['$ocLazyLoad', function ($ocLazyLoad) {
                    return $ocLazyLoad.load({
                        name: 'app_main_unl',
                        insertBefore: '#load_files_before',
                        files: [
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/lab/labCtrl.js',
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/lab/sidebarCtrl.js',
                            '/themes/adminLTE/dist/css/skins/skin-blue.min.css',
                            '/themes/adminLTE/plugins/ng-knob/d3.min.js',
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/lab/modalCtrl.js',
                            '/themes/adminLTE/unl_data/js/angularjs/controllers/lab/contextMenu.js',
                            '/themes/adminLTE/plugins/bootstrap-select/css/bootstrap-select.css',
                            '/themes/adminLTE/plugins/bootstrap-select/js/bootstrap-select.js',
                        ]
                    });
                }]
            }
        })
}]);

/* Init global settings and run the app */
app_main_unl.run(["$rootScope", "$state", function ($rootScope, $state) {
    $rootScope.$state = $state; // state to be accessed from view
}]);
