<?php
/**
 * @copyright Copyright (c) 2016-2017 Lukas Reschke <lukas@statuscode.ch>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Richdocuments\Controller;

use OC\Files\View;
use OCA\Richdocuments\Db\Wopi;
use OCA\Richdocuments\AppConfig;
use OCA\Richdocuments\Db\WopiMapper;
use OCA\Richdocuments\TemplateManager;
use OCA\Richdocuments\TokenManager;
use OCA\Richdocuments\Helper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;

class WopiController extends Controller {
	/** @var IRootFolder */
	private $rootFolder;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var IConfig */
	private $config;
	/** @var AppConfig */
	private $appConfig;
	/** @var TokenManager */
	private $tokenManager;
	/** @var IUserManager */
	private $userManager;
	/** @var WopiMapper */
	private $wopiMapper;
	/** @var ILogger */
	private $logger;
	/** @var IUserSession */
	private $userSession;
	/** @var TemplateManager */
	private $templateManager;
	/** @var IManager */
	private $shareManager;

	// Signifies LOOL that document has been changed externally in this storage
	const LOOL_STATUS_DOC_CHANGED = 1010;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IRootFolder $rootFolder
	 * @param IURLGenerator $urlGenerator
	 * @param IConfig $config
	 * @param TokenManager $tokenManager
	 * @param IUserManager $userManager
	 * @param WopiMapper $wopiMapper
	 * @param ILogger $logger
	 * @param IUserSession $userSession
	 * @param TemplateManager $templateManager
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IRootFolder $rootFolder,
		IURLGenerator $urlGenerator,
		IConfig $config,
		AppConfig $appConfig,
		TokenManager $tokenManager,
		IUserManager $userManager,
		WopiMapper $wopiMapper,
		ILogger $logger,
		IUserSession $userSession,
		TemplateManager $templateManager,
		IManager $shareManager
	) {
		parent::__construct($appName, $request);
		$this->rootFolder = $rootFolder;
		$this->urlGenerator = $urlGenerator;
		$this->config = $config;
		$this->appConfig = $appConfig;
		$this->tokenManager = $tokenManager;
		$this->userManager = $userManager;
		$this->wopiMapper = $wopiMapper;
		$this->logger = $logger;
		$this->userSession = $userSession;
		$this->templateManager = $templateManager;
		$this->shareManager = $shareManager;
	}

	/**
	 * Returns general info about a file.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return JSONResponse
	 * @throws InvalidPathException
	 * @throws NotFoundException
	 */
	public function checkFileInfo($fileId, $access_token) {
		try {
			list($fileId, , $version) = Helper::parseFileId($fileId);

			$wopi = $this->wopiMapper->getWopiForToken($access_token);
			if ($wopi->isTemplateToken()) {
				$this->templateManager->setUserId($wopi->getOwnerUid());
				$file = $this->templateManager->get($wopi->getFileid());
			} else {
				$file = $this->getFileForWopiToken($wopi);
			}
			if(!($file instanceof File)) {
				throw new NotFoundException('No valid file found for ' . $fileId);
			}
		} catch (NotFoundException $e) {
			$this->logger->debug($e->getMessage(), ['app' => 'richdocuments', '']);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['app' => 'richdocuments']);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		$isPublic = $wopi->getEditorUid() === null;
		$guestUserId = 'Guest-' . \OC::$server->getSecureRandom()->generate(8);
		$user = $this->userManager->get($wopi->getEditorUid());
		$userDisplayName = $user !== null && !$isPublic ? $user->getDisplayName() : $wopi->getGuestDisplayname();
		$response = [
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => $version,
			'UserId' => !$isPublic ? $wopi->getEditorUid() : $guestUserId,
			'OwnerId' => $wopi->getOwnerUid(),
			'UserFriendlyName' => $userDisplayName,
			'UserExtraInfo' => [
			],
			'UserCanWrite' => $wopi->getCanwrite(),
			'UserCanNotWriteRelative' => \OC::$server->getEncryptionManager()->isEnabled() || $isPublic,
			'PostMessageOrigin' => $wopi->getServerHost(),
			'LastModifiedTime' => Helper::toISO8601($file->getMTime()),
			'SupportsRename' => true,
			'UserCanRename' => !$isPublic,
			'EnableInsertRemoteImage' => true,
			'EnableShare' => true,
			'HideUserList' => 'desktop',
			'DisablePrint' => $wopi->getHideDownload(),
			'DisableExport' => $wopi->getHideDownload(),
			'DisableCopy' => $wopi->getHideDownload(),
			'HideExportOption' => $wopi->getHideDownload(),
			'HidePrintOption' => $wopi->getHideDownload(),
			'DownloadAsPostMessage' => $wopi->getDirect(),
		];

		if ($wopi->isTemplateToken()) {
			$userFolder = $this->rootFolder->getUserFolder($wopi->getOwnerUid());
			$file = $userFolder->getById($wopi->getTemplateDestination())[0];
			$response['TemplateSaveAs'] = $file->getName();
		}

		if ($this->shouldWatermark($isPublic, $wopi->getEditorUid(), $fileId, $wopi)) {
			$replacements = [
				'userId' => $wopi->getEditorUid(),
				'date' => (new \DateTime())->format('Y-m-d H:i:s'),
				'themingName' => \OC::$server->getThemingDefaults()->getName(),

			];
			$watermarkTemplate = $this->appConfig->getAppValue('watermark_text');
			$response['WatermarkText'] = preg_replace_callback('/{(.+?)}/', function($matches) use ($replacements)
			{
				return $replacements[$matches[1]];
			}, $watermarkTemplate);
		}

		$user = $this->userManager->get($wopi->getEditorUid());
		if($user !== null && $user->getAvatarImage(32) !== null) {
			$response['UserExtraInfo']['avatar'] = $this->urlGenerator->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => $wopi->getEditorUid(), 'size' => 32]);
		}

		if ($wopi->getRemoteServer() !== '') {
			$response = $this->setFederationFileInfo($wopi, $response);
		}

		return new JSONResponse($response);
	}

	private function setFederationFileInfo($wopi, $response) {
		$remoteUserId = $wopi->getGuestDisplayname();
		$cloudID = \OC::$server->getCloudIdManager()->resolveCloudId($remoteUserId);
		$response['UserFriendlyName'] = $cloudID->getDisplayId();
		$response['UserExtraInfo']['avatar'] = $this->urlGenerator->linkToRouteAbsolute('core.avatar.getAvatar', ['userId' => explode('@', $remoteUserId)[0], 'size' => 32]);
		$cleanCloudId = str_replace(['http://', 'https://'], '', $cloudID->getId());
		$addressBookEntries = \OC::$server->getContactsManager()->search($cleanCloudId, ['CLOUD']);
		foreach ($addressBookEntries as $entry) {
			if (isset($entry['CLOUD'])) {
				foreach ($entry['CLOUD'] as $cloudID) {
					if ($cloudID === $cleanCloudId) {
						$response['UserFriendlyName'] = $entry['FN'];
						break;
					}
				}
			}
		}
		return $response;
	}

	private function shouldWatermark($isPublic, $userId, $fileId, Wopi $wopi) {
		if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_enabled', 'no') === 'no') {
			return false;
		}

		if ($isPublic) {
			if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_linkAll', 'no') === 'yes') {
				return true;
			}
			if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_linkRead', 'no') === 'yes' && !$wopi->getCanwrite()) {
				return true;
			}
			if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_linkSecure', 'no') === 'yes' && $wopi->getHideDownload()) {
				return true;
			}
			if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_linkTags', 'no') === 'yes') {
				$tags = $this->appConfig->getAppValueArray('watermark_linkTagsList');
				$fileTags = \OC::$server->getSystemTagObjectMapper()->getTagIdsForObjects([$fileId], 'files')[$fileId];
				foreach ($fileTags as $tagId) {
					if (in_array($tagId, $tags, true)) {
						return true;
					}
				}
			}
		} else {
			if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_shareAll', 'no') === 'yes') {
				return true;
			}
			if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_shareRead', 'no') === 'yes' && !$wopi->getCanwrite()) {
				return true;
			}
		}
		if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_allGroups', 'no') === 'yes') {
			$groups = $this->appConfig->getAppValueArray('watermark_allGroupsList');
			foreach ($groups as $group) {
				if (\OC::$server->getGroupManager()->isInGroup($userId, $group)) {
					return true;
				}
			}
		}
		if ($this->config->getAppValue(AppConfig::WATERMARK_APP_NAMESPACE, 'watermark_allTags', 'no') === 'yes') {
			$tags = $this->appConfig->getAppValueArray('watermark_allTagsList');
			$fileTags = \OC::$server->getSystemTagObjectMapper()->getTagIdsForObjects([$fileId], 'files');
			foreach ($fileTags as $tagId) {
				if (in_array($tagId, $tags)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Given an access token and a fileId, returns the contents of the file.
	 * Expects a valid token in access_token parameter.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return Http\Response
	 * @throws DoesNotExistException
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	public function getFile($fileId,
							$access_token) {
		list($fileId, , $version) = Helper::parseFileId($fileId);

		$wopi = $this->wopiMapper->getWopiForToken($access_token);

		if ((int)$fileId !== $wopi->getFileid()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// Template is just returned as there is no version logic
		if ($wopi->isTemplateToken()) {
			$this->templateManager->setUserId($wopi->getOwnerUid());
			$file = $this->templateManager->get($wopi->getFileid());
			$response = new StreamResponse($file->fopen('rb'));
			$response->addHeader('Content-Disposition', 'attachment');
			$response->addHeader('Content-Type', 'application/octet-stream');
			return $response;
		}

		try {
			/** @var File $file */
			$userFolder = $this->rootFolder->getUserFolder($wopi->getOwnerUid());
			$file = $userFolder->getById($fileId)[0];
			\OC_User::setIncognitoMode(true);
			if ($version !== '0') {
				$view = new View('/' . $wopi->getOwnerUid() . '/files');
				$relPath = $view->getRelativePath($file->getPath());
				$versionPath = '/files_versions/' . $relPath . '.v' . $version;
				$view = new View('/' . $wopi->getOwnerUid());
				if ($view->file_exists($versionPath)){
					$response = new StreamResponse($view->fopen($versionPath, 'rb'));
				}
				else {
					return new JSONResponse([], Http::STATUS_NOT_FOUND);
				}
			}
			else
			{
				$response = new StreamResponse($file->fopen('rb'));
			}
			$response->addHeader('Content-Disposition', 'attachment');
			$response->addHeader('Content-Type', 'application/octet-stream');
			return $response;
		} catch (\Exception $e) {
			$this->logger->logException($e, ['level' => ILogger::ERROR,	'app' => 'richdocuments', 'message' => 'getFile failed']);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}
	}

	/**
	 * Given an access token and a fileId, replaces the files with the request body.
	 * Expects a valid token in access_token parameter.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return JSONResponse
	 * @throws DoesNotExistException
	 */
	public function putFile($fileId,
							$access_token) {
		list($fileId, ,) = Helper::parseFileId($fileId);
		$isPutRelative = ($this->request->getHeader('X-WOPI-Override') === 'PUT_RELATIVE');
		$isRenameFile = ($this->request->getHeader('X-WOPI-Override') === 'RENAME_FILE');

		$wopi = $this->wopiMapper->getWopiForToken($access_token);
		if (!$wopi->getCanwrite()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		try {
			if ($isPutRelative) {
				// the new file needs to be installed in the current user dir
				$userFolder = $this->rootFolder->getUserFolder($wopi->getEditorUid());
				$file = $userFolder->getById($fileId)[0];

				$suggested = $this->request->getHeader('X-WOPI-SuggestedTarget');
				$suggested = iconv('utf-7', 'utf-8', $suggested);

				if ($suggested[0] === '.') {
					$path = dirname($file->getPath()) . '/New File' . $suggested;
				}
				else if ($suggested[0] !== '/') {
					$path = dirname($file->getPath()) . '/' . $suggested;
				}
				else {
					$path = $userFolder->getPath() . $suggested;
				}

				if ($path === '') {
					return new JSONResponse([
						'status' => 'error',
						'message' => 'Cannot create the file'
					]);
				}

				// create the folder first
				if (!$this->rootFolder->nodeExists(dirname($path))) {
					$this->rootFolder->newFolder(dirname($path));
				}

				// create a unique new file
				$path = $this->rootFolder->getNonExistingName($path);
				$this->rootFolder->newFile($path);
				$file = $this->rootFolder->get($path);
			} else {
				$file = $this->getFileForWopiToken($wopi);
				$wopiHeaderTime = $this->request->getHeader('X-LOOL-WOPI-Timestamp');
				if ($wopiHeaderTime !== null && $wopiHeaderTime !== Helper::toISO8601($file->getMTime())) {
					$this->logger->debug('Document timestamp mismatch ! WOPI client says mtime {headerTime} but storage says {storageTime}', [
						'headerTime' => $wopiHeaderTime,
						'storageTime' => Helper::toISO8601($file->getMTime())
					]);
					// Tell WOPI client about this conflict.
					return new JSONResponse(['LOOLStatusCode' => self::LOOL_STATUS_DOC_CHANGED], Http::STATUS_CONFLICT);
				}
			}

			$content = fopen('php://input', 'rb');

			// Set the user to register the change under his name
			$editor = $this->userManager->get($wopi->getEditorUid());
			if ($editor !== null) {
				$this->userSession->setUser($editor);
			}

			$file->putContent($content);

			if ($isPutRelative) {
				// generate a token for the new file (the user still has to be
				// logged in)
				list(, $wopiToken) = $this->tokenManager->getToken($file->getId(), null, $wopi->getEditorUid());

				$wopi = 'index.php/apps/richdocuments/wopi/files/' . $file->getId() . '_' . $this->config->getSystemValue('instanceid') . '?access_token=' . $wopiToken;
				$url = $this->urlGenerator->getAbsoluteURL($wopi);

				return new JSONResponse([ 'Name' => $file->getName(), 'Url' => $url ], Http::STATUS_OK);
			}

			return new JSONResponse(['LastModifiedTime' => Helper::toISO8601($file->getMTime())]);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['level' => ILogger::ERROR,	'app' => 'richdocuments', 'message' => 'getFile failed']);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Given an access token and a fileId, replaces the files with the request body.
	 * Expects a valid token in access_token parameter.
	 * Just actually routes to the PutFile, the implementation of PutFile
	 * handles both saving and saving as.* Given an access token and a fileId, replaces the files with the request body.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $fileId
	 * @param string $access_token
	 * @return JSONResponse
	 * @throws DoesNotExistException
	 */
	public function putRelativeFile($fileId,
					$access_token) {
		list($fileId, ,) = Helper::parseFileId($fileId);
		$wopi = $this->wopiMapper->getWopiForToken($access_token);
		$isRenameFile = ($this->request->getHeader('X-WOPI-Override') === 'RENAME_FILE');

		if (!$wopi->getCanwrite()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// Unless the editor is empty (public link) we modify the files as the current editor
		$editor = $wopi->getEditorUid();
		if ($editor === null || $wopi->getRemoteServer() !== '') {
			$editor = $wopi->getOwnerUid();
		}

		try {
			// the new file needs to be installed in the current user dir
			$userFolder = $this->rootFolder->getUserFolder($editor);

			if ($wopi->isTemplateToken()) {
				$this->templateManager->setUserId($wopi->getOwnerUid());
				$file = $userFolder->getById($wopi->getTemplateDestination())[0];
			} else if ($isRenameFile) {
				// the new file needs to be installed in the current user dir
				$userFolder = $this->rootFolder->getUserFolder($wopi->getEditorUid());
				$file = $userFolder->getById($fileId)[0];

				$suggested = $this->request->getHeader('X-WOPI-RequestedName');

				$suggested = iconv('utf-7', 'utf-8', $suggested) . '.' . $file->getExtension();

				if (strpos($suggested, '.') === 0) {
					$path = dirname($file->getPath()) . '/New File' . $suggested;
				}
				else if (strpos($suggested, '/') !== 0) {
					$path = dirname($file->getPath()) . '/' . $suggested;
				}
				else {
					$path = $userFolder->getPath() . $suggested;
				}

				if ($path === '') {
					return new JSONResponse([
						'status' => 'error',
						'message' => 'Cannot rename the file'
					]);
				}

				// create the folder first
				if (!$this->rootFolder->nodeExists(dirname($path))) {
					$this->rootFolder->newFolder(dirname($path));
				}

				// create a unique new file
				$path = $this->rootFolder->getNonExistingName($path);
				$file = $file->move($path);
			} else {
				$file = $userFolder->getById($fileId)[0];

				$suggested = $this->request->getHeader('X-WOPI-SuggestedTarget');
				$suggested = iconv('utf-7', 'utf-8', $suggested);

				if ($suggested[0] === '.') {
					$path = dirname($file->getPath()) . '/New File' . $suggested;
				} else if ($suggested[0] !== '/') {
					$path = dirname($file->getPath()) . '/' . $suggested;
				} else {
					$path = $userFolder->getPath() . $suggested;
				}

				if ($path === '') {
					return new JSONResponse([
						'status' => 'error',
						'message' => 'Cannot create the file'
					]);
				}

				// create the folder first
				if (!$this->rootFolder->nodeExists(dirname($path))) {
					$this->rootFolder->newFolder(dirname($path));
				}

				// create a unique new file
				$path = $this->rootFolder->getNonExistingName($path);
				$file = $this->rootFolder->newFile($path);
			}

			$content = fopen('php://input', 'rb');

			// Set the user to register the change under his name
			$editor = $this->userManager->get($wopi->getEditorUid());
			if ($editor !== null) {
				$this->userSession->setUser($editor);
			}

			$file->putContent($content);

			// generate a token for the new file (the user still has to be
			// logged in)
			list(, $wopiToken) = $this->tokenManager->getToken($file->getId(), null, $wopi->getEditorUid());

			$wopi = 'index.php/apps/richdocuments/wopi/files/' . $file->getId() . '_' . $this->config->getSystemValue('instanceid') . '?access_token=' . $wopiToken;
			$url = $this->urlGenerator->getAbsoluteURL($wopi);

			return new JSONResponse([ 'Name' => $file->getName(), 'Url' => $url ], Http::STATUS_OK);
		} catch (\Exception $e) {
			$this->logger->logException($e, ['level' => ILogger::ERROR,	'app' => 'richdocuments', 'message' => 'putRelativeFile failed']);
			return new JSONResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @param Wopi $wopi
	 * @return File|Folder|Node|null
	 * @throws NotFoundException
	 * @throws ShareNotFound
	 */
	private function getFileForWopiToken(Wopi $wopi) {
		$file = null;

		if ($wopi->getRemoteServer() !== '') {
			$share = $this->shareManager->getShareByToken($wopi->getEditorUid());
			$node = $share->getNode();
			if ($node instanceof Folder) {
				$file = $node->getById($wopi->getFileid())[0];
			} else {
				$file = $node;
			}
		} else {
			// Unless the editor is empty (public link) we modify the files as the current editor
			// TODO: add related share token to the wopi table so we can obtain the
			$editor = $wopi->getEditorUid();
			if ($editor === null) {
				$editor = $wopi->getOwnerUid();
			}

			$userFolder = $this->rootFolder->getUserFolder($editor);
			$file = $userFolder->getById($wopi->getFileid())[0];
		}
		return $file;
	}
}
