angular.module("unlMainApp").controller('sysstatController',function sysstatController($scope, $http, $rootScope, $interval, $location, $cookies, $timeout) {
        var translations = {
                en: {
                        heroEyebrow: 'Infrastructure health',
                        heroTitle: 'System status',
                        heroDescription: 'Monitor CPU, memory, storage, and hypervisors at a glance. Keep the platform healthy before provisioning the next lab.',
                        chipQemuLabel: 'QEMU version',
                        chipPlatformLabel: 'Platform build',
                        gaugeCpuLabel: 'CPU load',
                        gaugeCpuSub: 'Overall usage',
                        gaugeMemLabel: 'Memory',
                        gaugeMemSub: 'Allocated RAM',
                        gaugeSwapLabel: 'Swap',
                        gaugeSwapSub: 'Paging usage',
                        gaugeDiskLabel: 'Disk',
                        gaugeDiskSub: 'Datastore',
                        nodesTitle: 'Running nodes',
                        nodeIolDescription: 'Classic images',
                        nodeDynamipsDescription: 'Hardware emulated',
                        nodeQemuDescription: 'KVM based',
                        nodeDockerDescription: 'Container services',
                        nodeVpcsDescription: 'Lightweight hosts',
                        statusTitle: 'Kernel features',
                        uksmTitle: 'UKSM status',
                        uksmSubtitle: 'Ultra memory deduplication',
                        ksmTitle: 'KSM status',
                        ksmSubtitle: 'Kernel same-page merging',
                        cpulimitTitle: 'CPULimit status',
                        cpulimitSubtitle: 'Scheduler throttling'
                },
                ru: {
                        heroEyebrow: 'Состояние инфраструктуры',
                        heroTitle: 'Статус системы',
                        heroDescription: 'Следите за загрузкой CPU, памяти и хранилища. Поддерживайте платформу в порядке перед запуском новых лабораторий.',
                        chipQemuLabel: 'Версия QEMU',
                        chipPlatformLabel: 'Сборка платформы',
                        gaugeCpuLabel: 'Загрузка CPU',
                        gaugeCpuSub: 'Общее использование',
                        gaugeMemLabel: 'Память',
                        gaugeMemSub: 'Выделенная RAM',
                        gaugeSwapLabel: 'Swap',
                        gaugeSwapSub: 'Использование подкачки',
                        gaugeDiskLabel: 'Диск',
                        gaugeDiskSub: 'Хранилище',
                        nodesTitle: 'Запущенные узлы',
                        nodeIolDescription: 'Классические образы',
                        nodeDynamipsDescription: 'Эмуляция железа',
                        nodeQemuDescription: 'KVM-виртуальные',
                        nodeDockerDescription: 'Контейнерные сервисы',
                        nodeVpcsDescription: 'Легкие хосты',
                        statusTitle: 'Функции ядра',
                        uksmTitle: 'Статус UKSM',
                        uksmSubtitle: 'Оптимизация памяти',
                        ksmTitle: 'Статус KSM',
                        ksmSubtitle: 'Объединение страниц',
                        cpulimitTitle: 'Статус CPULimit',
                        cpulimitSubtitle: 'Ограничение планировщика'
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

        function applyGaugeTranslations() {
                if (!$scope.t) return;
                if ($scope.optionsCPU) $scope.optionsCPU.subText.text = $scope.t.gaugeCpuSub;
                if ($scope.optionsMem) $scope.optionsMem.subText.text = $scope.t.gaugeMemSub;
                if ($scope.optionsSwap) $scope.optionsSwap.subText.text = $scope.t.gaugeSwapSub;
                if ($scope.optionsDisk) $scope.optionsDisk.subText.text = $scope.t.gaugeDiskSub;
        }

        refreshTranslations();

        function initToggleSwitches() {
                $timeout(function () {
                        ['#ToggleUKSM', '#ToggleKSM', '#ToggleCPULIMIT'].forEach(function (selector) {
                                var $el = $(selector);
                                if ($el.length && !$el.closest('.ToggleSwitch').length) {
                                        $el.toggleSwitch({ width: "50px" });
                                }
                        });
                }, 0);
        }

        function setToggleState(selector, state) {
                var $el = $(selector);
                if ($el.length && $el.closest('.ToggleSwitch').length && $.isFunction($el.toggleCheckedState)) {
                        $el.toggleCheckedState(state);
                }
        }

        initToggleSwitches();
        $scope.$watch(function () { return $rootScope.lang; }, function (newVal, oldVal) {
                if (newVal && newVal !== oldVal) {
                        refreshTranslations();
                        applyGaugeTranslations();
                }
        });
	$scope.testAUTH("/sysstat"); //TEST AUTH
	$scope.versiondata='';
	$scope.serverstatus=[];
	$scope.valueCPU = 0;
	$scope.valueMem = 0;
	$scope.valueSwap = 0;
	$scope.valueDisk = 0;
$scope.optionsCPU = {
    unit: "%",
    readOnly: true,
    size: 175,
    subText: {
        enabled: true,
        text: 'CPU used',
        color: '#9ca3af',
        font: 'auto'
    },
    textColor: '#f8fafc',
    trackWidth: 11,
    barWidth: 18,
    trackColor: '#d1d5db',
    barColor: '#2563eb'
};
	
$scope.optionsMem = {
    unit: "%",
    readOnly: true,
    size: 175,
    subText: {
        enabled: true,
        text: 'Memory used',
        color: '#9ca3af',
        font: 'auto'
    },
    textColor: '#f8fafc',
    trackWidth: 11,
    barWidth: 18,
    trackColor: '#d1d5db',
    barColor: '#0ea5e9'
};
	
$scope.optionsSwap = {
    unit: "%",
    readOnly: true,
    size: 175,
    subText: {
        enabled: true,
        text: 'Swap used',
        color: '#9ca3af',
        font: 'auto'
    },
    textColor: '#f8fafc',
    trackWidth: 11,
    barWidth: 18,
    trackColor: '#d1d5db',
    barColor: '#f97316'
};
	
	
$scope.optionsDisk = {
    unit: "%",
    readOnly: true,
    size: 175,
    subText: {
        enabled: true,
        text: 'Disk used',
        color: '#9ca3af',
        font: 'auto'
    },
    textColor: '#f8fafc',
    trackWidth: 11,
    barWidth: 18,
    trackColor: '#d1d5db',
    barColor: '#ec4899'
};
        applyGaugeTranslations();
	$('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');
	$scope.systemstat = function(){
		$http.get('/api/status').then(
				function successCallback(response) {
					//console.log(response.data.data)
					$scope.serverstatus=response.data.data;
					$scope.valueCPU = $scope.serverstatus.cpu;
					$scope.valueMem = $scope.serverstatus.mem;
					$scope.valueSwap = $scope.serverstatus.swap;
					$scope.valueDisk = $scope.serverstatus.disk;
					$scope.versiondata="Current API version: "+response.data.data.version;
                                        window.uksm = false;
                                        window.ksm = false;
                                        window.cpulimit = false;
                                        if ( response.data.data.uksm == "unsupported" )  $("#pUKSM").addClass('hidden')
                                        if ( response.data.data.ksm == "unsupported" )  $("#pKSM").addClass('hidden')
                                        if ( response.data.data.uksm == "enabled" ) {
                                                window.uksm = true;
						setToggleState("#ToggleUKSM", true)
                                        }
                                        if ( response.data.data.ksm == "enabled" ) {
                                                window.ksm = true;
                                                setToggleState("#ToggleKSM", true)
                                        }
                                        if ( response.data.data.cpulimit == "enabled" ) {
                                                window.cpulimit = true;
                                                setToggleState("#ToggleCPULIMIT", true)
                                        }
					$.unblockUI();
				}, 
				function errorCallback(response) {
					$.unblockUI();
					console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");}	
		);
	}
	$scope.systemstat()
	var refreshPromise = $interval(function () {
			if ($location.path() == '/sysstat') $scope.systemstat()
    }, 2000);
        $scope.$on('$destroy', function () {
                if (refreshPromise) {
                        $interval.cancel(refreshPromise);
                }
        });
	        // Stop All Nodes //START
        //$app -> delete('/api/status', function() use ($app, $db) {
        $scope.stopAll = function() {
                $http({
                        method: 'DELETE',
                        url: '/api/status'})
                        .then(
                                function successCallback(response) {
                                        console.log(response)
                                },
                                function errorCallback(response) {
                                        console.log(response)
                                }
                        );
        }
        // Stop All Nodes //STOP
});
// set cpulimit
function setCpuLimit(bool) {
    var deferred = $.Deferred();
    var form_data = {};

    form_data['state'] = bool;

    var url = '/api/cpulimit';
    var type = 'POST';
    $.ajax({
        cache: false,
        timeout: 30000,
        type: type,
        url: encodeURI(url),
        dataType: 'json',
        data: JSON.stringify(form_data),
        success: function (data) {
            if (data['status'] == 'success') {
                deferred.resolve(data);
            } else {
                // Application error
                deferred.reject(data['message']);
            }
        },
        error: function (data) {
            // Server error
            var message = getJsonMessage(data['responseText']);
            deferred.reject(message);
        }
    });
    return deferred.promise();
}

// set uksm
function setUksm(bool) {
    var deferred = $.Deferred();
    var form_data = {};

    form_data['state'] = bool;

    var url = '/api/uksm';
    var type = 'POST';
    $.ajax({
        cache: false,
        timeout: 30000,
        type: type,
        url: encodeURI(url),
        dataType: 'json',
        data: JSON.stringify(form_data),
        success: function (data) {
            if (data['status'] == 'success') {
                deferred.resolve(data);
            } else {
                // Application error
                deferred.reject(data['message']);
            }

        },
        error: function (data) {
            // Server error
            var message = getJsonMessage(data['responseText']);
            deferred.reject(message);
        }
    });
    return deferred.promise();
}


// set ksm
function setKsm(bool) {
    var deferred = $.Deferred();
    var form_data = {};

    form_data['state'] = bool;

    var url = '/api/ksm';
    var type = 'POST';
    $.ajax({
        cache: false,
        timeout: 30000,
        type: type,
        url: encodeURI(url),
        dataType: 'json',
        data: JSON.stringify(form_data),
        success: function (data) {
            if (data['status'] == 'success') {
                deferred.resolve(data);
            } else {
                // Application error
                deferred.reject(data['message']);
            }

        },
        error: function (data) {
            // Server error
            var message = getJsonMessage(data['responseText']);
            deferred.reject(message);
        }
    });
    return deferred.promise();
}

// CPULIMIT Toggle

$(document).on('change','#ToggleCPULIMIT', function (e) {
 if  ( e.currentTarget.id == 'ToggleCPULIMIT' ) {
        var status=$('#ToggleCPULIMIT').prop('checked');
         if ( status != window.cpulimit ) setCpuLimit (status);
 }
});

// UKSM Toggle

$(document).on('change','#ToggleUKSM', function (e) {
 if  ( e.currentTarget.id == 'ToggleUKSM' ) {
        var status =$('#ToggleUKSM').prop('checked')
        if ( status != window.uksm ) setUksm(status);
 }
});

// KSM Toggle

$(document).on('change','#ToggleKSM', function (e) {
 if  ( e.currentTarget.id == 'ToggleKSM' ) {
        var status =$('#ToggleKSM').prop('checked')
        if ( status != window.ksm ) setKsm(status);
 }
});
