angular.module("unlMainApp").controller('mainController', function mainController($scope, $http, $location, $window, $uibModal, $log, $rootScope, FileUploader, focus, $timeout, $cookies, themeService) {
	$rootScope.openLaba = false;
	$scope.testAUTH("/main"); //TEST AUTH
	// шеринг
	$scope.labViewMode = 'collaborate';
	// шеринг
	$scope.path = ($rootScope.folder === undefined || $rootScope.folder == '') ? '/' : $rootScope.folder;
	$scope.folderData = { newElementName: '' };
	$scope.newElementToggle = false;
	$scope.fileSelected = false;
	$scope.allCheckedFlag = false;
	$scope.blockButtons = false;
	$scope.blockButtonsClass = '';
	$scope.fileManagerItem = [];
	$scope.checkboxArray = [];
	$scope.fileOrder = 'name';
	$scope.reverseSort = false;
	$scope.loading = false;
	$scope.showTable = true;
	$scope.labHasRunningNodes = false;
	$scope.runningNodesCount = 0;
	//Default variables ///END

	var translations = {
		en: {
			heroEyebrow: 'Workspace',
			heroTitle: 'File manager',
			heroDescription: 'Browse folders, import/export labs, and preview a topology before launching it into a POD.',
			pathLabel: 'Path',
			newLabButton: 'New lab',
			refreshButton: 'Refresh',
			labsCardTitle: 'Labs and folders',
			labsCardSubtitle: 'Select folders or .unl labs to rename, move, export, or delete.',
			topologyPreviewCardTitle: 'Topology preview',
			topologyPreviewCardSubtitle: '',
			orderButton: 'Order',
			orderTooltip: 'Toggle order',
			refreshTooltip: 'Refresh list',
			newFolderLabel: 'New folder',
			newFolderPlaceholder: 'Folder name',
			createButton: 'Create',
			toolbarSelect: 'Select',
			toolbarSelectTitle: 'Select all',
			toolbarClear: 'Clear',
			toolbarClearTitle: 'Deselect all',
			toolbarRename: 'Rename',
			toolbarRenameTitle: 'Rename selected',
			toolbarMove: 'Move',
			toolbarMoveTitle: 'Move selected',
			toolbarDelete: 'Delete',
			toolbarDeleteTitle: 'Delete selected',
			toolbarImport: 'Import',
			toolbarImportTitle: 'Import labs',
			toolbarExport: 'Export',
			toolbarExportTitle: 'Export labs',
			moveDialogEyebrow: 'Workspace',
			moveDialogTitle: 'Move items',
			moveDialogSubtitle: 'Select a destination folder for the chosen labs and directories.',
			moveSelectedLabel: 'Selected items',
			moveCurrentLocation: 'Current location',
			moveNewPathLabel: 'New path',
			moveNewPathPlaceholder: 'e.g. /labs/projects/',
			moveNewPathHint: 'Type a path and pick from suggestions below.',
			treeColumnName: 'Name',
			treeColumnUpdated: 'Updated',
			treeParentLabel: 'Back',
			renameFolderLabel: 'Rename folder',
			renameLabLabel: 'Rename lab',
			modalAddTitle: 'Add new lab',
			modalAddSubtitle: 'Define metadata, sharing options, and description for the new topology file.',
			modalWorkspaceLabel: 'Workspace',
			modalNameLabel: 'Name',
			modalVersionLabel: 'Version',
			modalTimeoutLabel: 'Script timeout (sec)',
			modalSharedLabel: 'Shared lab',
			modalSharedHint: 'Make this lab visible to other users.',
			modalCollaborateLabel: 'Collaborate allowed',
			modalCollaborateHint: 'Permit collaborators to edit the lab.',
			modalSharedWithLabel: 'Shared with (comma separated)',
			modalDescriptionLabel: 'Description',
			modalTasksLabel: 'Tasks',
			modalRequiredNote: 'Required fields',
			modalCancel: 'Cancel',
			modalCreate: 'Create',
			modalEditTitle: 'Edit lab',
			modalEditSubtitle: 'Update metadata, sharing controls, or descriptions for the selected topology.',
			modalSaveChanges: 'Save changes',
			modalNameHint: 'Use only letters, numbers, or hyphen.',
			modalVersionHint: 'Must be an integer value.',
			editBlockedRunning: 'Stop all running nodes before editing lab settings.',
			previewEnterButton: 'Enter',
			validationUserNotFound: 'User not found in system.',
			actionApply: 'Apply',
			actionRename: 'Rename',
			actionDelete: 'Delete',
			actionMove: 'Move',
			actionImport: 'Import',
			actionExport: 'Export',
			previewEditButton: 'Edit',
			previewCollaborateButton: 'Collaborate',
			uploaderColumnName: 'Name',
			uploaderColumnSize: 'Size',
			uploaderColumnProgress: 'Progress',
			uploaderColumnStatus: 'Status',
			uploaderColumnActions: 'Actions',
			uploadStatusSuccess: 'Success',
			uploadStatusCancel: 'Cancelled',
			uploadStatusError: 'Error',
			uploadActionUpload: 'Upload',
			uploadActionRemove: 'Remove',
			uploadRemoveTooltip: 'Remove from upload list',
			uploadQueueTitle: 'Upload queue',
			uploadQueueSubtitle: 'Drop .unl files here or use Import.',
			uploadAllButton: 'Upload all',
			cancelUploadButton: 'Cancel',
			clearUploadButton: 'Clear',
			uploadTotalProgress: 'Total progress',
			sizeUnit: 'MB',
			previewPlaceholderTitle: 'Select a lab',
			previewPlaceholderDescription: 'Choose a .unl file from the list to display its preview.',
			deleteDialogEyebrow: 'Confirm deletion',
			deleteDialogWarning: 'This action cannot be undone.',
			deleteDialogCancel: 'Cancel',
			deleteDialogDefaultAction: 'Delete',
			deleteFolderTitle: 'Delete folder',
			deleteFolderMessage: 'Deleting this folder removes all nested labs. This action cannot be undone.',
			deleteFolderItemPrefix: 'Folder: ',
			deleteLabTitle: 'Delete lab',
			deleteLabMessage: 'Deleting this lab permanently removes its definition.',
			deleteLabItemPrefix: 'Lab: ',
			deleteMultiTitle: 'Delete selected items',
			deleteMultiMessage: 'These folders and labs will be permanently removed.',
			deleteSummaryMore: '+{{count}} more…',
			deleteSelectionWarning: 'Please select items to delete.',
			warningTitle: 'Warning'
		},
		ru: {
			heroEyebrow: 'Рабочая зона',
			heroTitle: 'Файловый менеджер',
			heroDescription: 'Просматривайте каталоги, импортируйте/экспортируйте лаборатории и смотрите превью перед запуском в POD.',
			pathLabel: 'Путь',
			newLabButton: 'Новая лаборатория',
			refreshButton: 'Обновить',
			labsCardTitle: 'Лабы и папки',
			labsCardSubtitle: 'Выберите папки или .unl файлы, чтобы переименовать, переместить, экспортировать или удалить.',
			topologyPreviewCardTitle: 'Превью топологии',
			topologyPreviewCardSubtitle: '',
			orderButton: 'Сортировка',
			orderTooltip: 'Переключить порядок',
			refreshTooltip: 'Обновить список',
			newFolderLabel: 'Новая папка',
			newFolderPlaceholder: 'Название папки',
			createButton: 'Создать',
			toolbarSelect: 'Выбрать',
			toolbarSelectTitle: 'Выбрать все',
			toolbarClear: 'Снять выбор',
			toolbarClearTitle: 'Очистить выбор',
			toolbarRename: 'Переименовать',
			toolbarRenameTitle: 'Переименовать выбранные',
			toolbarMove: 'Переместить',
			toolbarMoveTitle: 'Переместить выбранные',
			toolbarDelete: 'Удалить',
			toolbarDeleteTitle: 'Удалить выбранные',
			toolbarImport: 'Импорт',
			toolbarImportTitle: 'Импорт лабораторий',
			toolbarExport: 'Экспорт',
			toolbarExportTitle: 'Экспорт лабораторий',
			moveDialogEyebrow: 'Рабочая зона',
			moveDialogTitle: 'Перемещение объектов',
			moveDialogSubtitle: 'Выберите целевую папку для выделенных лабораторий и директорий.',
			moveSelectedLabel: 'Выбранные элементы',
			moveCurrentLocation: 'Текущее расположение',
			moveNewPathLabel: 'Новый путь',
			moveNewPathPlaceholder: 'например, /labs/projects/',
			moveNewPathHint: 'Введите путь и выберите вариант из списка ниже.',
			treeColumnName: 'Имя',
			treeColumnUpdated: 'Обновлено',
			treeParentLabel: 'Назад',
			renameFolderLabel: 'Переименовать папку',
			renameLabLabel: 'Переименовать лабораторию',
			modalAddTitle: 'Добавить новую лабу',
			modalAddSubtitle: 'Укажите метаданные, параметры общего доступа и описание для новой топологии.',
			modalWorkspaceLabel: 'Рабочая область',
			modalNameLabel: 'Имя',
			modalVersionLabel: 'Версия',
			modalTimeoutLabel: 'Таймаут скрипта (сек)',
			modalSharedLabel: 'Общая лаба',
			modalSharedHint: 'Сделать эту лабу видимой другим пользователям.',
			modalCollaborateLabel: 'Разрешено редактирование',
			modalCollaborateHint: 'Разрешить другим редактировать лабу.',
			modalSharedWithLabel: 'Доступ (через запятую)',
			modalDescriptionLabel: 'Описание',
			modalTasksLabel: 'Задачи',
			modalRequiredNote: 'Обязательные поля',
			modalCancel: 'Отмена',
			modalCreate: 'Создать',
			modalEditTitle: 'Редактирование лаборатории',
			modalEditSubtitle: 'Обновите метаданные, доступ и описание выбранной топологии.',
			modalSaveChanges: 'Сохранить изменения',
			modalNameHint: 'Используйте только буквы, цифры или дефис.',
			modalVersionHint: 'Должно быть целое число.',
			editBlockedRunning: 'Остановите все запущенные устройства в лаборатории перед изменением настроек.',
			previewEnterButton: 'Войти',
			validationUserNotFound: 'Пользователь не найден в системе.',
			actionApply: 'Применить',
			actionRename: 'Переименовать',
			actionDelete: 'Удалить',
			actionMove: 'Переместить',
			actionImport: 'Импорт',
			actionExport: 'Экспорт',
			previewEditButton: 'Редактировать',
			previewCollaborateButton: 'Совместная работа',
			uploaderColumnName: 'Имя',
			uploaderColumnSize: 'Размер',
			uploaderColumnProgress: 'Прогресс',
			uploaderColumnStatus: 'Статус',
			uploaderColumnActions: 'Действия',
			uploadStatusSuccess: 'Готово',
			uploadStatusCancel: 'Отменено',
			uploadStatusError: 'Ошибка',
			uploadActionUpload: 'Загрузить',
			uploadActionRemove: 'Удалить',
			uploadRemoveTooltip: 'Убрать из очереди загрузки',
			uploadQueueTitle: 'Очередь загрузки',
			uploadQueueSubtitle: 'Перетащите файлы .unl сюда или воспользуйтесь импортом.',
			uploadAllButton: 'Загрузить все',
			cancelUploadButton: 'Отмена',
			clearUploadButton: 'Очистить',
			uploadTotalProgress: 'Совокупный прогресс',
			sizeUnit: 'МБ',
			previewPlaceholderTitle: 'Выберите лабораторию',
			previewPlaceholderDescription: 'Выберите .unl файл из списка, чтобы показать превью.',
			deleteDialogEyebrow: 'Подтверждение удаления',
			deleteDialogWarning: 'Действие необратимо.',
			deleteDialogCancel: 'Отмена',
			deleteDialogDefaultAction: 'Удалить',
			deleteFolderTitle: 'Удаление папки',
			deleteFolderMessage: 'Удаление папки также удалит вложенные лаборатории. Действие необратимо.',
			deleteFolderItemPrefix: 'Папка: ',
			deleteLabTitle: 'Удаление лаборатории',
			deleteLabMessage: 'Лаборатория будет полностью удалена.',
			deleteLabItemPrefix: 'Лаборатория: ',
			deleteMultiTitle: 'Удаление выбранных объектов',
			deleteMultiMessage: 'Эти папки и лаборатории будут удалены без возможности восстановления.',
			deleteSummaryMore: '+ ещё {{count}}',
			deleteSelectionWarning: 'Сначала выберите элементы для удаления.',
			warningTitle: 'Предупреждение'
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
		$scope.t = translations[$scope.lang] || translations['ru'];
		$rootScope.lang = $scope.lang;
	}

	function currentTranslations() {
		return $scope.t || translations[resolveLanguage()] || translations['ru'];
	}

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

    function openTailwindModal(config) {
        return $uibModal.open({
            animation: true,
            template: `
                <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="absolute inset-0 transition-colors duration-300" ng-class="modal.overlayClasses"></div>
                    <div class="relative w-full max-w-md rounded-3xl shadow-2xl p-8 space-y-6 border transition-colors duration-300" ng-class="modal.surfaceClasses">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-full shrink-0 flex items-center justify-center transition-colors duration-300" ng-class="modal.iconClasses">
                                <i class="{{modal.icon}}"></i>
                            </div>
                            <div>
                                <h4 class="text-xl font-semibold" ng-class="modal.textClasses" ng-bind="modal.title"></h4>
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
                                    ng-click="$dismiss()"
                                    ng-class="modal.cancelClasses"
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
                var t = currentTranslations();
                var defaults = {
                    icon: 'fa fa-info-circle',
                    iconClasses: $scope.themeClass('bg-blue-500/20 text-blue-200', 'bg-blue-500 text-white'),
                    confirmClasses: 'bg-blue-600 hover:bg-blue-500 text-white',
                    cancelClasses: $scope.themeClass('border-white/20 text-slate-200 hover:bg-white/10', 'border-slate-200 text-slate-800 hover:bg-slate-100'),
                    surfaceClasses: $scope.themeClass('border-white/10 bg-gradient-to-br from-slate-950 via-blue-950/70 to-slate-900 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
                    overlayClasses: 'theme-overlay',
                    mutedClasses: $scope.themeClass('text-slate-400', 'text-slate-600'),
                    textClasses: $scope.themeClass('text-slate-100', 'text-slate-900'),
                    cancelLabel: config.cancelLabel || t.deleteDialogCancel || 'Cancel',
                    confirmLabel: config.confirmLabel || t.deleteDialogDefaultAction || 'Confirm',
                    items: []
                };
                $scope.modal = angular.extend(defaults, config || {});
            }],
            size: 'md',
            backdrop: 'static',
            keyboard: false
        }).result;
    }

	refreshTranslations();

	$scope.$watch(function () { return $rootScope.lang; }, function (newVal, oldVal) {
		if (newVal && newVal !== oldVal) {
			refreshTranslations();
		}
	});

	//console.log('here')

	$scope.falseForSelAll = function () {
		$scope.allCheckedFlag = false;
	}

	$scope.setOrder = function(orderBy) {
		if ($scope.fileOrder === orderBy) {
			$scope.reverseSort = !$scope.reverseSort;
		} else {
			$scope.fileOrder = orderBy;
			$scope.reverseSort = false;
		}
	}

	$scope.openDeleteDialog = function (config) {
		var t = currentTranslations();
		config = config || {};
		openTailwindModal({
			icon: 'fa fa-exclamation-triangle',
			iconClasses: $scope.themeClass('bg-rose-500/20 text-rose-200', 'bg-rose-500 text-white'),
			title: config.title || t.deleteMultiTitle,
			body: config.message || t.deleteMultiMessage,
			surfaceClasses: $scope.themeClass('border-white/10 from-rose-950/80 via-rose-900/40 to-slate-900 text-slate-100', 'bg-white border-slate-200 text-slate-900'),
			overlayClasses: 'theme-overlay',
			confirmLabel: config.confirmLabel || t.deleteDialogDefaultAction,
			cancelLabel: t.deleteDialogCancel,
			confirmClasses: $scope.themeClass('bg-rose-600 hover:bg-rose-500 text-white', 'bg-rose-600 hover:bg-rose-500 text-white'),
			cancelClasses: $scope.themeClass('border-white/20 text-slate-200 hover:bg-white/10', 'border-slate-200 text-slate-800 hover:bg-slate-100'),
			mutedClasses: $scope.themeClass('text-slate-400', 'text-slate-600'),
			textClasses: $scope.themeClass('text-slate-100', 'text-slate-900'),
			items: (config.items || []).map(function(item) { return { value: item }; })
		}).then(function () {
			if (typeof config.action === 'function') {
				config.action();
			}
		});
	};

	$('body').removeClass().addClass('hold-transition skin-blue layout-top-nav');
	//Draw current position //START
	$scope.currentPosition = function () {
		var tempArray = $scope.path.split('/');
		var tempPathArray = []
		tempArray[0] = 'root';
		tempPathArray[0] = "/";
		if (tempArray[1] === "") { tempArray.splice(1, 1); }
		else {
			for (i = 1; i < tempArray.length; i++) {
				tempVal = '/' + tempArray[i];
				tempPathArray[i] = (i - 1 === 0) ? tempVal : tempPathArray[i - 1] + tempVal;
			}
		}
		//console.log(tempArray)
		//console.log(tempPathArray)
		$scope.splitPath = tempArray;
		$scope.splitPathArray = tempPathArray;

		//$scope.splitPathArray=tempArray.length;
	}
	$scope.currentPosition();
	//Draw current position //END
	////////////////////////////////
	//Drawing files tree ///START
	$scope.fileMngDraw = function (path, folder) {
		$scope.loading = true;
		$scope.rootDir = null;
		$scope.path = path;
		if (folder !== undefined) {
			$scope.fileManagerItem['Fo_' + folder]['img'] = true;
		}
		$http.get('/api/folders' + path).then(
			function successCallback(response) {
				$scope.checkboxArray = [];
				$scope.fileManagerItem = [];
				$scope.rootDir = response.data.data;

				if ($scope.rootDir && $scope.rootDir.labs) {
					var username = $rootScope.username || '';

					// Формируем словарь приватных копий.
					// Например, если имя приватной копии имеет вид "labname_username.unl",
					// то ключом будет "labname.unl".
					$scope.privateCopyMap = {};
					$scope.rootDir.labs.forEach(function (lab) {
						if (lab.file.indexOf('_' + username + '.unl') !== -1) {
							// Удаляем из конца строки "_username.unl", заменяя его на ".unl"
							var regex = new RegExp('_' + username + '\\.unl$', 'i');
							var originalFile = lab.file.replace(regex, '.unl');
							$scope.privateCopyMap[originalFile] = lab.file;
						}
					});

					// Фильтруем приватные копии из списка, чтобы в основном отображались только оригиналы.
					$scope.rootDir.labs = $scope.rootDir.labs.filter(function (lab) {
						return lab.file.indexOf('_' + username + '.unl') === -1;
					});
				} else {
					$scope.privateCopyMap = {};
				}

				$scope.currentPosition();
				$scope.loading = false;
				$scope.showTable = true;
			},
			function errorCallback(response) {
				$scope.loading = false;
				$scope.showTable = true;
				console.log("Unknown Error. Why did API doesn't respond?");
				$location.path("/login");
			}
		);

	}
	$scope.fileMngDraw($scope.path);
	//Drawing files tree ///END
	////////////////////////////////////
	//Get information about lab ///START
	$scope.getLabInfo = function (file, name) {
		var path = ($scope.path === '/') ? $scope.path : $scope.path + '/';
		if (name !== undefined) $scope.fileManagerItem['Fi_' + name].img = true;
		$http.get('/api/labs' + file, { params: { mode: $scope.labViewMode } }).then(
			function successCallback(response) {
				$scope.labInfo = response.data.data;
				$scope.fullPathToFile = file;
				$scope.selectedLab = name;
				if (name !== undefined) $scope.fileManagerItem['Fi_' + name].img = false;
				//console.log($scope.labInfo)
				$scope.fileSelected = true;
				$scope.previewFun(($scope.path == '/') ? $scope.path + $scope.labInfo.name + '.unl' : $scope.path + '/' + $scope.labInfo.name + '.unl')
			},
			function errorCallback(response) {
				console.log(response)
				console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");
				if (response.status == 400) { toastr["error"](response.data.message, "Error"); $scope.blockButtons = false; $scope.blockButtonsClass = ''; return; }
			}
		);
	}
	//Get information about lab ///END
	////////////////////////////////////////////////
	//Toggle view for input file/folder creations ///START
	$scope.elementToggleFun = function (thatCreate) {
		$scope.hideAllEdit()
		if (!$scope.newElementToggle) { $scope.newElementToggle = true; focus('foCreate'); $scope.thatCreate = thatCreate; return; }
		if ($scope.thatCreate == thatCreate && $scope.newElementToggle) { $scope.newElementToggle = false; }
		if (!$scope.newElementToggle) $scope.thatCreate = '';
		$scope.thatCreate = thatCreate;
	}
	//Toggle view for input file/folder creations ///END
	///////////////////////////////////////////////////
	//Create NEW Element Folder OR Lab //START
	$scope.createNewElement = function (elementType) {
		if ($scope.folderData.newElementName == '') {
            toastr["error"]("Folder name cannot be empty", "Error");
            return;
        }

		//Create NEW Folder //START
		if (elementType == 'Folder') {
			$scope.blockButtons = true;
			$scope.blockButtonsClass = 'm-progress';
			var newName = $scope.folderData.newElementName.replace(/[\\'#$,@"\\/,%\*,,\.\(\):;^&[\]|]/g, '');
			//$scope.newElementName = $scope.newElementName.replace(/[\\s]+/g, '_');
			$http({
				method: 'POST',
				url: '/api/folders',
				data: { "path": $scope.path, "name": newName }
			})
				.then(
					function successCallback(response) {

						$scope.fileMngDraw($scope.path);
						$scope.newElementToggle = false;
						$scope.folderData.newElementName = '';
					},
					function errorCallback(response) {
						console.log(response)
						if (response.status == 400) { 
                            toastr["error"](response.data.message || "Name already exist", "Error"); 
                        } else {
						    console.log("Unknown Error. Why did API doesn't respond?");
						    $location.path("/login");
                        }
					}
				).finally(function() {
                    $scope.blockButtons = false;
                    $scope.blockButtonsClass = '';
                });
		}
		//Create NEW Folder //END
		/////////////////////////
		//Create NEW Lab //START
		if (elementType == 'File') {
			$scope.folderData.newElementName = $scope.folderData.newElementName.replace(/[\\'#$,@"\\/,%\*,,\.\(\):;^&[\]|]/g, '');
			//$scope.newElementName = $scope.newElementName.replace(/[\\s]+/g, '_');
			$scope.openModal('addfile');
		}
		//Create NEW Lab //END
		////////////////////////
	}
	//Clone Lab//START

	$scope.cloneElement = function (elementName, event) {
		d = new Date();
		console.log('clone requested for ' + $scope.path + '/' + elementName.value + ' ' + d.getTime());
		form_data = {};
		form_data['name'] = elementName.value.slice(0, -4) + '_' + d.getTime();
		form_data['source'] = $scope.path + '/' + elementName.value;
		$http({
			method: 'POST',
			url: '/api/labs',
			data: form_data
		})
			.then(
				function successcallback(response) {
					//console.log(response)
					//$scope.filemngdraw($scope.path);
					$scope.fileMngDraw($scope.path);
					//$location.path("/login");
				},
				function errorcallback(response) {
					//console.log(response)
					console.log("unknown error. why did api doesn't respond?");
					$location.path("/login");
				}
			);


		event.stopPropagation();
	}

	//clone lab//end


	//Create NEW Element Folder OR Lab //END
	///////////////////////////////////////
	//Delete selected elements //START
	$scope.deleteElement = function (elementName, thatis, hide) {
		$scope.hideAllEdit()
		var skipConfirm = (hide !== undefined && hide !== null) ? true : false;
		var t = currentTranslations();
		//Delete folder//START
		if (thatis == 'Folder') {
			if (!skipConfirm) {
				var folderLabel = elementName || '';
				$scope.openDeleteDialog({
					title: t.deleteFolderTitle,
					message: t.deleteFolderMessage,
					items: [t.deleteFolderItemPrefix + folderLabel],
					confirmLabel: t.deleteDialogDefaultAction,
					action: function () {
						deleteFolderRequest(elementName);
					}
				});
				return;
			}
			deleteFolderRequest(elementName);
		}
		//Delete folder//END
		////////////////////
		//Delete file//START
		if (thatis == 'File') {
			if (!skipConfirm) {
				var labLabel = elementName || '';
				$scope.openDeleteDialog({
					title: t.deleteLabTitle,
					message: t.deleteLabMessage,
					items: [t.deleteLabItemPrefix + labLabel],
					confirmLabel: t.deleteDialogDefaultAction,
					action: function () {
						deleteLabRequest(elementName);
					}
				});
				return;
			}
			deleteLabRequest(elementName, hide);
		}
		//Delete file//END
		//$scope.fileMngDraw($scope.path); //recreate tree
	}

	$scope.deletePrivateLab = function (labname) {
		// Получаем путь и имя файла
		var lastSlashIndex = labname.lastIndexOf('/');
		var path = labname.substring(0, lastSlashIndex + 1);
		var filename = labname.substring(lastSlashIndex + 1);

		// Преобразуем имя файла в private-формат
		var parts = filename.split('.');
		var privateLabName = path + parts[0] + '_' + $scope.username + '.' + parts[1];

		$scope.deleteElement(privateLabName, 'File', 'true');
	};
	//Delete selected elements //END
	//////////////////////////////////////////////////
	function deleteFolderRequest(elementName) {
		if (!elementName) {
			return;
		}
		console.log('deleting folder ' + elementName);
		$http({
			method: 'DELETE',
			url: '/api/folders' + elementName + '\ '
		})
			.then(
				function successCallback() {
					$scope.fileMngDraw($scope.path);
				},
				function errorCallback() {
					console.log("Unknown Error. Why did API doesn't respond?");
					$location.path("/login");
				}
			);
	}

	function deleteLabRequest(elementName, hide) {
		if (!elementName) {
			return;
		}
		console.log('delete file');
		console.log(elementName);
		$http({
			method: 'DELETE',
			url: '/api/labs' + elementName
		})
			.then(
				function successCallback() {
					$scope.fileSelected = (hide === undefined || hide === false) ? $scope.fileSelected : false;
					$scope.fileMngDraw($scope.path);
				},
				function errorCallback() {
					console.log("Unknown Error. Why did API doesn't respond?");
					$location.path("/login");
				}
			);
	}

	//Delete ALL selected elements //START
	$scope.deleteALLElement = function () {
		var t = currentTranslations();
		var folderArray = [];
		var lastFolder = '';
		var lastFile = '';
		var fileArray = [];
		for (var key in $scope.checkboxArray) {
			//console.log($scope.fileManagerItem[key]);
			if ($scope.checkboxArray[key].checked) {
				var itemType = ($scope.checkboxArray[key].type == 'Folder') ? 'Fo_' : 'Fi_';
				if (itemType == 'Fo_') {
					folderArray[key.replace(itemType, '')] = $scope.path
				}
				if (itemType == 'Fi_') {
					fileArray[key.replace(itemType, '')] = $scope.path
				}
			}
		}
		if (ObjectLength(folderArray) == 0 && ObjectLength(fileArray) == 0) { toastr["warning"](t.deleteSelectionWarning, t.warningTitle); return; }
		var foldersToDelete = [];
		for (var foldername in folderArray) {
			var folderBase = folderArray[foldername];
			var fullpath = (folderBase != '/') ? folderBase + '/' + foldername : '/' + foldername;
			foldersToDelete.push({ label: t.deleteFolderItemPrefix + foldername, fullpath: fullpath });
		}

		var filesToDelete = [];
		for (var filename in fileArray) {
			var fullFilePath = ($scope.path === '/') ? $scope.path + filename : $scope.path + '/' + filename;
			filesToDelete.push({ label: t.deleteLabItemPrefix + filename, fullpath: fullFilePath });
		}

		var summary = [];
		var combined = foldersToDelete.map(function (item) { return item.label; }).concat(filesToDelete.map(function (item) { return item.label; }));
		for (var i = 0; i < Math.min(combined.length, 4); i++) {
			summary.push(combined[i]);
		}
		if (combined.length > 4) {
			summary.push(formatString(t.deleteSummaryMore, { count: combined.length - 4 }));
		}

		var foldersCopy = angular.copy(foldersToDelete);
		var filesCopy = angular.copy(filesToDelete);

		$scope.openDeleteDialog({
			title: t.deleteMultiTitle,
			message: t.deleteMultiMessage,
			items: summary,
			confirmLabel: t.deleteDialogDefaultAction,
			action: function () {
				angular.forEach(foldersCopy, function (folder) {
					deleteFolderRequest(folder.fullpath);
				});
				angular.forEach(filesCopy, function (file) {
					deleteLabRequest(file.fullpath, true);
				});
				$scope.allCheckedFlag = false;
			}
		});
	}
	//Delete ALL selected elements //END
	//////////////////////////////////////////
	//Select all elements //START
	$scope.selectAll = function () {
		if (!$scope.allCheckedFlag) {
			for (var key in $scope.checkboxArray) {
				//console.log($scope.fileManagerItem[key]);
				$scope.checkboxArray[key].checked = ($scope.checkboxArray[key].name != '..') ? true : false;
			}
			$scope.allCheckedFlag = true;
			return;
		}
		console.log($scope.allCheckedFlag);
		if ($scope.allCheckedFlag) {
			$scope.hideAllEdit()
			for (var key in $scope.checkboxArray) {
				$scope.checkboxArray[key].checked = false;
			}
			$scope.allCheckedFlag = false;
		}
	}
	//Select all elements //END
	///////////////////////////////////////////
	//Select element by clicking on <td> //START
	$scope.selectElbyTD = function (item) {
		//console.log(item)
		if (item.name == '..') return;
		var itemType = (item.type == 'Folder') ? 'Fo_' : 'Fi_';
		//console.log(itemType+item.name)
		//console.log($scope.checkboxArray[itemType+item.name])
		$scope.checkboxArray[itemType + item.name].checked = !$scope.checkboxArray[itemType + item.name].checked;
		$scope.falseForSelAll();
		$scope.hideAllEdit();
	}
	//Select element by clicking on <td> //END
	///////////////////////////////////////////////////////
	//Edit element //START
	/////
	$scope.editElementShow = function () {
		console.log($scope.checkboxArray);
		var trueCheckbox = 0;
		var tempArray = [];
		for (var key in $scope.checkboxArray) {
			console.log($scope.checkboxArray[key].checked)
			if ($scope.checkboxArray[key].checked === true) {
				tempArray['type'] = $scope.checkboxArray[key].type;
				tempArray['name'] = key;
				trueCheckbox++
			}
		}
		if (trueCheckbox == 0) { toastr["warning"]("Please select item to rename", "Warning"); return; }
		if (trueCheckbox > 1) { toastr["warning"]("You can rename only 1 item", "Warning"); return; }
		var itemType = (tempArray['type'] == 'Folder') ? 'Fo_' : 'Fi_';
		console.log(tempArray['name'])
		console.log($scope.fileManagerItem[tempArray['name']])
		$scope.openRename($scope.fileManagerItem[tempArray['name']])
	}
	$scope.hideAllEdit = function () {
		for (var key in $scope.fileManagerItem) {
			//console.log($scope.fileManagerItem[key]);
			$scope.fileManagerItem[key].visibleEdit = false;
			$scope.fileManagerItem[key].value = $scope.fileManagerItem[key].oldvalue;
			$scope.allCheckedFlag = false;
		}
	}
	$scope.uncheck_all = function () {
		$(".folder_check").prop("checked", false).trigger("change").trigger("unchecked");
	}

	$scope.openRename = function (item, $event) {
		if ($event != undefined) $event.stopPropagation();
		$scope.hideAllEdit()
		console.log(item)
		var itemType = (item.type == 'Folder') ? 'Fo_' : 'Fi_';
		if (itemType == 'Fi_') {
			$scope.fileManagerItem[itemType + item.oldvalue].value = $scope.fileManagerItem[itemType + item.oldvalue].value.replace(/.unl$/, "");
		}
		focus(itemType + $scope.fileManagerItem[itemType + item.oldvalue].oldvalue);
		$scope.fileManagerItem[itemType + item.oldvalue].visibleEdit = true;
	}
	$scope.editElementApply = function (item) {
		var tempPath = ($scope.path === '/') ? $scope.path : $scope.path + '/';
		var itemType = (item.type == 'Folder') ? 'Fo_' : 'Fi_';
		console.log($scope.fileManagerItem[itemType + item.oldvalue])
		var tempVal = $scope.fileManagerItem[itemType + item.oldvalue].value;
		tempVal = tempVal.replace(/[\\'#$,"\\/,%\*,,\.,!\(\ [\]\)\\{\}]/g, '')
		//tempVal=tempVal.replace(/[\\s]+/g, '_');
		$scope.blockButtons = true;
		$scope.blockButtonsClass = 'm-progress';
		$scope.fileManagerItem[itemType + item.oldvalue].value = tempVal;
		if ($scope.fileManagerItem[itemType + item.oldvalue].value === $scope.fileManagerItem[itemType + item.oldvalue].oldvalue) {
			$scope.hideAllEdit(); $scope.blockButtons = false;
			$scope.blockButtonsClass = '';
			return;
		}
		if ($scope.fileManagerItem[itemType + item.oldvalue].value === $scope.fileManagerItem[itemType + item.oldvalue].oldvalue.replace(/.unl$/, "")) {
			$scope.hideAllEdit(); $scope.blockButtons = false;
			$scope.blockButtonsClass = '';
			$scope.hideAllEdit();
			return;
		}
		if (itemType == 'Fo_') {
			$scope.blockButtons = true;
			$scope.blockButtonsClass = 'm-progress';
			console.log('Rename folder:' + $scope.fileManagerItem[itemType + item.oldvalue].oldvalue + ' to ' + $scope.fileManagerItem[itemType + item.oldvalue].value)
			$http({
				method: 'PUT',
				url: '/api/folders' + tempPath + $scope.fileManagerItem[itemType + item.oldvalue].oldvalue,
				data: { "path": tempPath + $scope.fileManagerItem[itemType + item.oldvalue].value }
			})
				.then(
					function successCallback(response) {
						//console.log(response)
						console.log('Rename successfull')
						$scope.blockButtons = false;
						$scope.blockButtonsClass = '';
						$scope.fileMngDraw($scope.path);
					},
					function errorCallback(response) {
						console.log(response)
						$scope.blockButtons = false;
						$scope.blockButtonsClass = '';
						if (response.status == 412 && response.data.status == "unauthorized") { $location.path("/login"); return; }
						console.log('Rename Error' + response.data.message)
						toastr["error"](response.data.message, "Error")
					}
				);
		}
		else if (itemType == 'Fi_') {
			console.log('Rename file:' + $scope.fileManagerItem[itemType + item.oldvalue].oldvalue.replace(/.unl$/, "") + ' to ' + $scope.fileManagerItem[itemType + item.oldvalue].value)

			$http({
				method: 'PUT',
				url: '/api/labs' + tempPath + $scope.fileManagerItem[itemType + item.oldvalue].oldvalue,
				data: { "name": $scope.fileManagerItem[itemType + item.oldvalue].value }
			})
				.then(
					function successCallback(response) {
						//console.log(response)
						console.log('Rename successfull')
						$scope.blockButtons = false;
						$scope.blockButtonsClass = '';
						$scope.fileMngDraw($scope.path);
						if ($scope.fileSelected && $scope.selectedLab == $scope.fileManagerItem[itemType + item.oldvalue].oldvalue) {
							$scope.labInfo.name = $scope.fileManagerItem[itemType + item.oldvalue].value
						}
					},
					function errorCallback(response) {
						console.log(response)
						$scope.blockButtons = false;
						$scope.blockButtonsClass = '';
						if (response.status == 412 && response.data.status == "unauthorized") { $location.path("/login"); return; }
						console.log('Rename Error' + response.data.message)
						toastr["error"](response.data.message, "Error")
					}
				);
		}
	}
	//Edit element //END 
	/////////////////////////////////////////////////////
	//Export lab //START
	$scope.exportFiles = function () {
		$(".content-wrapper").append("<div id='progress-loader'><label style='float:left'>Creating archive...</label><div class='loader'></div></div>")

		var fileExportArray = {};
		var tempPath = ($scope.path === '/') ? $scope.path : $scope.path + '/';
		var index = 0;
		for (var key in $scope.checkboxArray) {
			//console.log($scope.fileManagerItem[key]);
			if ($scope.checkboxArray[key].checked) {
				var itemType = ($scope.checkboxArray[key].type == 'Folder') ? 'Fo_' : 'Fi_';
				if (itemType == 'Fo_') {
					fileExportArray['"' + index + '"'] = tempPath + key.replace(itemType, '')
					index++
				}
				if (itemType == 'Fi_') {
					fileExportArray['"' + index + '"'] = tempPath + key.replace(itemType, '')
					index++
				}
			}
		}
		if (ObjectLength(fileExportArray) == 0) { toastr["warning"]("Please select items to export", "Warning"); return; }
		fileExportArray['path'] = $scope.path;
		$http({
			method: 'POST',
			url: '/api/export',
			data: fileExportArray
		})
			.then(
				function successCallback(response) {
					$("#progress-loader").remove()
					console.log(response.data.data)
					var a = document.createElement('a');
					a.href = response.data.data;
					a.target = '_blank';
					a.download = response.data.data
					document.body.appendChild(a);
					a.click();
				},
				function errorCallback(response) {
					$("#progress-loader").remove()
					console.log(response)
					console.log("Unknown Error. Why did API doesn't respond?");
					//$location.path("/login");
				}
			);
	}
	//Export lab //END
	//////////////////////////////////////////
	///Import lab //START
	var uploader = $scope.uploader = new FileUploader({
		url: '/api/import',
		autoUpload : true,
		removeAfterUpload : true
	});

	$scope.testFun = function () {
		console.log(uploader.queue)
	}

	$scope.selectOneFileUplad = function () {
		$('#oneFileUploadInput').click();
	}
	$scope.fileNameChanged = function () {
		//console.log('here')
		console.log(uploader.queue)
		//console.log($scope.uploader)
		uploader.onBeforeUploadItem = function (item) {
			//console.info('onBeforeUploadItem', item);
			item.formData.push({ 'path': $scope.path });
		};
		uploader.onCompleteAll = function() {
			$scope.fileMngDraw($scope.path);
		};
		uploader.onErrorItem = function (fileItem, response, status, headers) {
			//console.info('onErrorItem', fileItem, response, status, headers);
			if (status === 400) toastr["error"](response.message, "Error");
		};
	}
	///Import lab //END
	//////////////////////////////////////////
	///Move to function ///START
	$scope.moveto = function () {
		$scope.folderArrayToMove = [];
		$scope.fileArrayToMove = [];
		var fo = 0;
		var fi = 0;
		for (var key in $scope.checkboxArray) {
			//console.log($scope.fileManagerItem[key]);
			if ($scope.checkboxArray[key].checked) {
				var itemType = ($scope.checkboxArray[key].type == 'Folder') ? 'Fo_' : 'Fi_';
				if (itemType == 'Fo_') {
					$scope.folderArrayToMove[fo] = key.replace(itemType, '')
					fo++
				}
				if (itemType == 'Fi_') {
					$scope.fileArrayToMove[fi] = key.replace(itemType, '')
					fi++
				}
			}
		}
		if (ObjectLength($scope.folderArrayToMove) == 0 && ObjectLength($scope.fileArrayToMove) == 0) { toastr["warning"]("Please select items to move", "Warning"); return; }
		$scope.pathBeforeMove = $scope.path;
		$scope.openModal('moveto');
	}
	///Move to function ///END
	/////////////////////////////////////
	//Open Lab //START
	$scope.labopen = function (labname) {
		$rootScope.lab = labname;
		$location.path('/lab')
	}
	//Open Lab //END
	//Open Lagacy LAB//START
	$scope.legacylabopen = function (labname) {
		const baseLabName = labname;
		let finalLabName = labname;

		$http.get('/api/labs' + baseLabName + '?mode=' + $scope.labViewMode).then(function (response) {
			if ($scope.labViewMode === "private") {
				var parts = baseLabName.split('.');
				finalLabName = parts[0] + '_' + $scope.username + '.' + parts[1];
			}

			// Теперь, когда копия создана — запрашиваем топологию и переходим
			return $http.get('/api/labs' + finalLabName + '/topology');
		}).then(function (response) {
			// Всё готово — можно переходить
			$window.location.href = "legacy" + finalLabName + "/topology";
		}).catch(function (error) {
			console.error("Ошибка при открытии лабораторной:", error);
			alert("Ошибка при открытии лабораторной. Попробуйте еще раз.");
		});
	};
	//Open Lagacy LAB//END
	///////////////////////////////
	//More controllers //START
	ModalCtrl($scope, $uibModal, $log)
	//labViewCtrl ($scope)
	//More controllers //END
	//var testString='123123\'/	%#\s$123123 21*3123';
	//console.log(testString.replace(/[\\'#$,"\\/,%\*,,\.]/g, ''))

	$scope.openLabSettings = function () {
		if ($scope.labHasRunningNodes) {
			var t = currentTranslations();
			var warningMessage = t.editBlockedRunning || 'Stop all running nodes before editing lab settings.';
			var warningTitle = t.warningTitle || 'Warning';
			toastr["warning"](warningMessage, warningTitle);
			return;
		}
		$scope.openModal('editfile', null, 'megalg');
	};


	$scope.openLab = function (mode) {
		$scope.labViewMode = mode; // сохраняем выбранный режим

		if (mode === 'private') {
			// Логика для открытия в private режиме
			$scope.legacylabopen($scope.fullPathToFile);
		} else {
			// Логика для открытия в collaborate режиме
			$scope.legacylabopen($scope.fullPathToFile);
		}
	};


////////////////////////////////////////////	
////////////////////////////////////////////
///////PREVIEW FUNCTIONS////////////////START
////////////////////////////////////////////	
////////////////////////////////////////////
function isNodeRunning(node) {
	if (!node) {
		return false;
	}
	var status = parseInt(node.status, 10);
	return status === 2 || status === 3;
}

function setLabRunningState(nodes) {
	var runningCount = 0;
	angular.forEach(nodes, function (node) {
		if (isNodeRunning(node)) {
			runningCount++;
		}
	});
	$scope.runningNodesCount = runningCount;
	$scope.labHasRunningNodes = runningCount > 0;
}

var BASE_PREVIEW_SCALE = 5;
var PREVIEW_BASE_WIDTH = 1200;
var PREVIEW_BASE_HEIGHT = 800;
var PREVIEW_VIEWPORT_FALLBACK_WIDTH = 640;
var PREVIEW_VIEWPORT_FALLBACK_HEIGHT = 420;
var PREVIEW_NODE_WIDTH = 60;
var PREVIEW_NODE_HEIGHT = 60;
var PREVIEW_NETWORK_WIDTH = 60;
var PREVIEW_NETWORK_HEIGHT = 50;
var previewFitTimeout = null;
$scope.zeroNodes = false;
$scope.previewCanvasStyle = { width: PREVIEW_BASE_WIDTH + 'px', height: PREVIEW_BASE_HEIGHT + 'px' };
$scope.previewOffset = { x: 0, y: 0 };
$scope.scaleMenu = false;
$scope.nodelist = [];
$scope.networksList = [];
$scope.linkLinesArray = [];
$scope.lineList = [];
$scope.scale = BASE_PREVIEW_SCALE;
$scope.previewZoom = 1;
$scope.previewFitZoom = 1;
$scope.previewNodes = [];
$scope.previewNetworks = [];
$scope.previewLinks = [];

function updatePreviewZoom() {
	var denom = $scope.scale || BASE_PREVIEW_SCALE;
	var scaleZoom = BASE_PREVIEW_SCALE / denom;
	$scope.previewZoom = scaleZoom * ($scope.previewFitZoom || 1);
}

function schedulePreviewFit() {
	if (previewFitTimeout) {
		$timeout.cancel(previewFitTimeout);
	}
	previewFitTimeout = $timeout(function () {
		applyPreviewFit();
	}, 0);
}

function measurePreviewViewport() {
	var viewportElement = document.getElementById('divPreview');
	if (!viewportElement) {
		return {
			width: PREVIEW_VIEWPORT_FALLBACK_WIDTH,
			height: PREVIEW_VIEWPORT_FALLBACK_HEIGHT
		};
	}
	return {
		width: viewportElement.clientWidth || PREVIEW_VIEWPORT_FALLBACK_WIDTH,
		height: viewportElement.clientHeight || PREVIEW_VIEWPORT_FALLBACK_HEIGHT
	};
}

function applyPreviewFit() {
	var canvasWidth = parseFloat($scope.previewCanvasStyle.width) || PREVIEW_BASE_WIDTH;
	var canvasHeight = parseFloat($scope.previewCanvasStyle.height) || PREVIEW_BASE_HEIGHT;
	if (!canvasWidth || !canvasHeight) {
		$scope.previewFitZoom = 1;
		updatePreviewZoom();
		return;
	}
	var viewport = measurePreviewViewport();
	var zoomX = viewport.width / canvasWidth;
	var zoomY = viewport.height / canvasHeight;
	var fitZoom = Math.min(zoomX, zoomY);
	if (!isFinite(fitZoom) || fitZoom <= 0) {
		fitZoom = 1;
	}
	$scope.previewFitZoom = fitZoom;
	updatePreviewZoom();
}

function clearPreviewCollections() {
	$scope.previewNodes = [];
	$scope.previewNetworks = [];
	$scope.previewLinks = [];
}

function isNetworkVisible(network) {
	if (!network) {
		return true;
	}
	var visibility = network.visibility;
	if (visibility === undefined || visibility === null || visibility === '') {
		return true;
	}
	if (typeof visibility === 'string') {
		var normalized = visibility.trim().toLowerCase();
		if (normalized === '' || normalized === 'undefined') {
			return true;
		}
		return !(normalized === '0' || normalized === 'false');
	}
	if (typeof visibility === 'number') {
		return visibility !== 0;
	}
	if (typeof visibility === 'boolean') {
		return visibility;
	}
	return true;
}

function linkTouchesHiddenNetwork(link) {
	if (!link || !$scope.networksObject) {
		return false;
	}
	if (link.source && link.source.includes("network")) {
		var sourceNet = link.source.replace("network", '');
		if (!isNetworkVisible($scope.networksObject[sourceNet])) {
			return true;
		}
	}
	if (link.destination && link.destination.includes("network")) {
		var destNet = link.destination.replace("network", '');
		if (!isNetworkVisible($scope.networksObject[destNet])) {
			return true;
		}
	}
	return false;
}

function addVisibleNetworkId(netId) {
	if (!$scope.networksObject) {
		return;
	}
	var network = $scope.networksObject[netId];
	if (!network || !isNetworkVisible(network)) {
		return;
	}
	if ($scope.networksList.indexOf(netId) === -1) {
		$scope.networksList.push(netId);
	}
}

function toSortedArray(items, mapper) {
	var collection = [];
	if (!items) {
		return collection;
	}
	angular.forEach(items, function (value, key) {
		var mapped = mapper(value, key);
		if (mapped) {
			collection.push(mapped);
		}
	});
	collection.sort(function (a, b) {
		var left = (a.name || '').toLowerCase();
		var right = (b.name || '').toLowerCase();
		if (left < right) { return -1; }
		if (left > right) { return 1; }
		return 0;
	});
	return collection;
}

function endpointLabel(endpoint) {
	if (!endpoint) {
		return '';
	}
	if (endpoint.indexOf('node') === 0) {
		var nodeId = endpoint.replace('node', '');
		var node = $scope.nodelist[parseInt(nodeId)];
		return node ? node.name : ('Node ' + nodeId);
	}
	if (endpoint.indexOf('network') === 0) {
		var netId = endpoint.replace('network', '');
		if ($scope.networksObject && $scope.networksObject[netId]) {
			return $scope.networksObject[netId].name || ('Network ' + netId);
		}
		return 'Network ' + netId;
	}
	return endpoint;
}

function refreshPreviewCollections() {
	$scope.previewNodes = toSortedArray($scope.nodelist, function (node, id) {
		if (!node) {
			return null;
		}
		return {
			id: id,
			name: node.name || ('Node ' + id),
			description: node.template || node.image || node.type || ''
		};
	});
	$scope.previewNetworks = toSortedArray($scope.networksObject, function (network, id) {
		if (!network) {
			return null;
		}
		if (!isNetworkVisible(network)) {
			return null;
		}
		return {
			id: id,
			name: network.name || ('Network ' + id),
			description: network.type || ''
		};
	});
	var links = [];
	if ($scope.topologyObject && $scope.topologyObject.length) {
		for (var i = 0; i < $scope.topologyObject.length; i++) {
			var link = $scope.topologyObject[i];
			if (!link) {
				continue;
			}
			if (linkTouchesHiddenNetwork(link)) {
				continue;
			}
			var sourceLabel = endpointLabel(link.source);
			var destinationLabel = endpointLabel(link.destination);
			if (link.source_label) {
				sourceLabel += ' (' + link.source_label + ')';
			}
			if (link.destination_label) {
				destinationLabel += ' (' + link.destination_label + ')';
			}
			links.push({
				id: i + 1,
				name: sourceLabel + ' -> ' + destinationLabel
			});
		}
	}
	$scope.previewLinks = links;
}

updatePreviewZoom();
$scope.previewFun = function (path) {
	$(".btn-flat").addClass('disabled');
	$scope.pathToLab = path;
	$scope.nodelist = [];
	$scope.networksList = []
	$scope.lineList = []
	$scope.scale = BASE_PREVIEW_SCALE;
	clearPreviewCollections();
	updatePreviewZoom();
	//console.log(path)
	$scope.zeroNodes = false;
	setLabRunningState([]);
		///Get all nodes ///START
		$http.get('/api/labs' + path + '/nodes').then(
			function successCallback(response) {
				//console.log(response.data)
				//console.log(ObjectLength(response.data.data))
				if (ObjectLength(response.data.data) === 0) {
					$scope.zeroNodes = true;
					setLabRunningState([]);
					$scope.previewCanvasStyle = { width: PREVIEW_BASE_WIDTH + 'px', height: PREVIEW_BASE_HEIGHT + 'px' };
					$scope.previewOffset = { x: 0, y: 0 };
					$scope.previewFitZoom = 1;
					clearPreviewCollections();
					schedulePreviewFit();
					return;
				}
				$scope.nodelist = response.data.data;
				setLabRunningState($scope.nodelist);
				//console.log($scope.nodelist)
				//console.log(ObjectLength($scope.nodelist))
			},
			function errorCallback(response) {
				console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");
			}
		).finally(function () {
			$scope.getNetworkInfo(path)
		});
		///Get all nodes ///END
	};
	/////////////////////////////
	///Get all networks ///START
	$scope.getNetworkInfo = function (path) {
		$http.get('/api/labs' + path + '/networks').then(
			function successCallback(response) {
				//console.log(response.data.data)
				$scope.networksObject = response.data.data
			},
			function errorCallback(response) {
				console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");
			}
		).finally(function () {
			$scope.getTopologyInfo(path)
		});
	}
	///Get all networks ///END
	//////////////////////////////

	///Get all connection //START
function recalcPreviewCanvasSize() {
	var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
	var updateBounds = function (left, top, width, height) {
		if (left < minX) minX = left;
		if (top < minY) minY = top;
		if (left + width > maxX) maxX = left + width;
		if (top + height > maxY) maxY = top + height;
	};
	angular.forEach($scope.nodelist, function (node) {
		if (!node) { return; }
		var left = parseFloat(node.left) || 0;
		var top = parseFloat(node.top) || 0;
		updateBounds(left, top, PREVIEW_NODE_WIDTH, PREVIEW_NODE_HEIGHT);
	});
	if ($scope.networksObject) {
		angular.forEach($scope.networksObject, function (net) {
			if (!net) { return; }
			if (!isNetworkVisible(net)) { return; }
			var left = parseFloat(net.left) || 0;
			var top = parseFloat(net.top) || 0;
			updateBounds(left, top, PREVIEW_NETWORK_WIDTH, PREVIEW_NETWORK_HEIGHT);
		});
	}
	if (!isFinite(minX) || !isFinite(minY)) {
		$scope.previewOffset = { x: 10, y: 10 };
		$scope.previewCanvasStyle = {
			width: PREVIEW_BASE_WIDTH + 'px',
			height: PREVIEW_BASE_HEIGHT + 'px'
		};
		schedulePreviewFit();
		return;
	}
	var padding = 10;
	var width = (maxX - minX) + padding * 2;
	var height = (maxY - minY) + padding * 2;
	$scope.previewOffset = { x: padding - minX, y: padding - minY };
	$scope.previewCanvasStyle = {
		width: Math.max(width, 400) + 'px',
		height: Math.max(height, 300) + 'px'
	};
	schedulePreviewFit();
}

$scope.previewLeft = function (value) {
	return (parseFloat(value) || 0) + ($scope.previewOffset.x || 0);
};

$scope.previewTop = function (value) {
	return (parseFloat(value) || 0) + ($scope.previewOffset.y || 0);
};

$scope.previewCanvasTransform = function () {
	var zoom = $scope.previewZoom || 1;
	return angular.extend({}, $scope.previewCanvasStyle, {
		transform: 'scale(' + zoom + ')',
		'transform-origin': 'top left'
	});
};

function annotateRenderPositions() {
	angular.forEach($scope.nodelist, function (node) {
		if (!node) { return; }
		node.renderLeft = $scope.previewLeft(node.left);
		node.renderTop = $scope.previewTop(node.top);
	});
	if ($scope.networksObject) {
		angular.forEach($scope.networksObject, function (network) {
			if (!network) { return; }
			network.renderLeft = $scope.previewLeft(network.left);
			network.renderTop = $scope.previewTop(network.top);
		});
	}
}

function buildPreviewFromTopology() {
	$scope.linkLinesArray = [];
	$scope.lineList = [];
	$scope.networksList = [];
	if ($scope.networksObject) {
		angular.forEach($scope.networksObject, function (network, id) {
			addVisibleNetworkId(id);
		});
	}

	var hasNodes = ObjectLength($scope.nodelist) > 0;
	if (!hasNodes) {
		$scope.zeroNodes = true;
		$(".btn-flat.disabled").removeClass('disabled');
		$http({ method: 'DELETE', url: '/api/labs/close' });
		refreshPreviewCollections();
		return;
	}

	$scope.zeroNodes = false;
	recalcPreviewCanvasSize();
	annotateRenderPositions();

	var toCanvasX = function (val) { return (parseFloat(val) || 0) + ($scope.previewOffset.x || 0); };
	var toCanvasY = function (val) { return (parseFloat(val) || 0) + ($scope.previewOffset.y || 0); };
	var nodeCenterX = PREVIEW_NODE_WIDTH / 2;
	var nodeCenterY = PREVIEW_NODE_HEIGHT / 2;
	var networkCenterX = PREVIEW_NETWORK_WIDTH / 2;
	var networkCenterY = PREVIEW_NETWORK_HEIGHT / 2;

	var hasTopology = angular.isArray($scope.topologyObject) && $scope.topologyObject.length > 0;
	if (hasTopology) {
		var lineCounter = 0;
		for (var i = 0; i < $scope.topologyObject.length; i++) {
			var link = $scope.topologyObject[i];
			if (!link) {
				continue;
			}
			if (linkTouchesHiddenNetwork(link)) {
				continue;
			}
			$scope.lineList[lineCounter] = [];
			if (link.destination.includes("network")) {
				var netNum = link.destination.replace("network", '');
				addVisibleNetworkId(netNum);
				$scope.lineList[lineCounter]['x1'] = toCanvasX((parseFloat($scope.networksObject[netNum].left) || 0) + networkCenterX);
				$scope.lineList[lineCounter]['y1'] = toCanvasY((parseFloat($scope.networksObject[netNum].top) || 0) + networkCenterY);
			}
			if (link.destination.includes("node")) {
				var nodeDest = link.destination.replace("node", '');
				$scope.lineList[lineCounter]['x1'] = toCanvasX((parseFloat($scope.nodelist[parseInt(nodeDest)].left) || 0) + nodeCenterX);
				$scope.lineList[lineCounter]['y1'] = toCanvasY((parseFloat($scope.nodelist[parseInt(nodeDest)].top) || 0) + nodeCenterY);
			}
			if (link.source.includes("network")) {
				var netSource = link.source.replace("network", '');
				addVisibleNetworkId(netSource);
				$scope.lineList[lineCounter]['x2'] = toCanvasX((parseFloat($scope.networksObject[netSource].left) || 0) + networkCenterX);
				$scope.lineList[lineCounter]['y2'] = toCanvasY((parseFloat($scope.networksObject[netSource].top) || 0) + networkCenterY);
			}
			if (link.source.includes("node")) {
				var nodeSrc = link.source.replace("node", '');
				$scope.lineList[lineCounter]['y2'] = toCanvasY((parseFloat($scope.nodelist[parseInt(nodeSrc)].top) || 0) + nodeCenterY);
				$scope.lineList[lineCounter]['x2'] = toCanvasX((parseFloat($scope.nodelist[parseInt(nodeSrc)].left) || 0) + nodeCenterX);
			}
			lineCounter++;
		}
	}

	$(".btn-flat.disabled").removeClass('disabled');

	$http({ method: 'DELETE', url: '/api/labs/close' });
	refreshPreviewCollections();
}

	$scope.getTopologyInfo = function (path) {
		$http.get('/api/labs' + path + '/topology').then(
			function successCallback(response) {
				$scope.topologyObject = response.data.data || [];
			},
			function errorCallback(response) {
				console.log("Unknown Error. Why did API doesn't respond?"); $location.path("/login");
			}
		).finally(function () {
			buildPreviewFromTopology();
		});
	}
	///Get all connection //END
	////////////////////////////
	///Set scale //START
	$scope.schemecontrol = function (scale) {
		if ($scope.scale === scale) {
			return;
		}
		$scope.scale = scale;
		$scope.scaleMenu = false;
		updatePreviewZoom();
		recalcPreviewCanvasSize();
		buildPreviewFromTopology();
	}
	///Set scale //END
	////////////////////
	//Line calculator //START
	$scope.linkLinesAttr = function (x1, y1, x2, y2) {

		var length = Math.sqrt((x1 - x2) * (x1 - x2) + (y1 - y2) * (y1 - y2));
		var angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
		var transform = 'rotate(' + angle + 'deg)';
		return [length, transform]
	}
	//$scope.linkLines = function (x1,y1, x2,y2){
	//	var length = Math.sqrt((x1-x2)*(x1-x2) + (y1-y2)*(y1-y2));
	//	var angle  = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
	//	var transform = 'rotate('+angle+'deg)';
	//	$("div#topologyPreview").append("<div class=\"line\" style=\"position:absolute;transform: "+transform+";width: "+length
	//	+"px; top: "+y1+"px; left: "+x1+"px;"></div>");
	//}
	//Line calculator //END	


	// Stop All Nodes //START
	//$app -> delete('/api/status', function() use ($app, $db) {
	$scope.stopAll = function () {
		$http({
			method: 'DELETE',
			url: '/api/status'
		})
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

	$scope.selectedItemsCanBeModified = function () {
		for (var key in $scope.checkboxArray) {
			if ($scope.checkboxArray.hasOwnProperty(key) && $scope.checkboxArray[key].checked) {
				var item = $scope.checkboxArray[key];
				// Если для выбранного элемента не выполняется условие авторства и роль не admin, то возвращаем false
				if (!(item.author === $rootScope.username || $scope.role === 'admin')) {
					return false;
				}
			}
		}
		return true;
	};
});

function formatString(template, params) {
	if (!template) { return ''; }
	return template.replace(/\{\{(\w+)\}\}/g, function (_, key) {
		return (params && params[key] !== undefined) ? params[key] : '';
	});
}

function ObjectLength(object) {
	var length = 0;
	for (var key in object) {
		if (object.hasOwnProperty(key)) {
			++length;
		}
	}
	return length;
	////////////////////////////////////////////	
	////////////////////////////////////////////
	///////PREVIEW FUNCTIONS////////////////END
	////////////////////////////////////////////	
	////////////////////////////////////////////	

	var resizeHandler = function () {
		$scope.$evalAsync(function () {
			schedulePreviewFit();
		});
	};
	angular.element($window).on('resize', resizeHandler);
	$scope.$on('$destroy', function () {
		if (previewFitTimeout) {
			$timeout.cancel(previewFitTimeout);
		}
		angular.element($window).off('resize', resizeHandler);
	});
}
