<?php

/**
 * @file plugins/importexport/portico/PorticoExportPlugin.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University Library
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PorticoExportPlugin
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');

import('lib.pkp.classes.xml.XMLCustomWriter');

class PorticoExportPlugin extends \ImportExportPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$isRegistered = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();
		return $isRegistered;
	}

	/**
	 * @copydoc Plugin::getName()
	 */
	public function getName() {
		return __CLASS__;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.importexport.portico.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.importexport.portico.description.short');
	}

	/**
	 * @copydoc Plugin::getEnabled()
	 */
	public function getEnabled() {
		$context = $this->getRequest()->getContext();
		$contextId = $context ? $context->getId() : 0;
		return $this->getSetting($contextId, 'enabled');
	}

	/**
	 * @copydoc ImportExportPlugin::display()
	 */
	public function display($args, $request) {
		parent::display($args, $request);
		$templateMgr = \TemplateManager::getManager();
		$issueDao = \DAORegistry::getDAO('IssueDAO');

		$journal = $request->getContext();
		
		// set the issn and abbreviation template variables
		foreach (['onlineIssn', 'printIssn'] as $name) {
			if ($journal->getSetting($name)) {
				$templateMgr->assign('issn', $journal->getSetting($name));
				break;
		}
		}
		
		if ($journal->getLocalizedSetting('abbreviation')) {
			$templateMgr->assign('abbreviation', $journal->getLocalizedSetting('abbreviation'));
		}

		switch ($route = array_shift($args)) {
			case 'ftpIssues':
			case 'exportIssues':
				$issueIds = $request->getUserVar('issueId') ?? [];
				$issues = array_map(function ($issueId) use ($issueDao, $journal, $request) {
					return $issueDao->getById($issueId, $journal->getId()) ?? $request->redirect();
				}, $issueIds);
				switch($route == 'ftpIssues' ? 'ftp' : $request->getUserVar('export')) {
					case 'download':
					$this->exportIssues($journal, $issues);
					break;	
					case 'ftp':
					$this->ftpIssues($journal, $issues);
					break;
				}
				break;
			case 'ftpIssue':
			case 'exportIssue':
				$issueId = array_shift($args);
				if (!($issue = $issueDao->getById($issueId, $journal->getId()))) {
					$request->redirect();
				}
				if($route == 'exportIssue') {
					$this->exportIssue($journal, $issue);
				} else {
				$this->ftpIssue($journal, $issue);
				}
				break;
			case 'issues':
				// Display a list of issues for export
				$this->setBreadcrumbs([], true);
				\AppLocale::requireComponents(LOCALE_COMPONENT_PKP_EDITOR, LOCALE_COMPONENT_APP_EDITOR);
				$issues = $issueDao->getIssues($journal->getId(), \Handler::getRangeInfo($request, 'issues'));

				$templateMgr->assignByRef('issues', $issues);
				$templateMgr->display($this->getTemplateResource('issues.tpl'));
				break;
			case 'settings':
				$this->manage($args, $request);
				break;
			default:
				$this->setBreadcrumbs();
				$templateMgr->display($this->getTemplateResource('index.tpl'));
		}
	}

	public function exportIssues(&$journal, &$issues) {
		$this->import('PorticoExportDom');
		$doc = \XMLCustomWriter::createDocument('issues');
		$issuesNode = \XMLCustomWriter::createElement($doc, 'issues');
		\XMLCustomWriter::appendChild($doc, $issuesNode);

		// create zip file
		$zipName = $journal->getLocalizedSetting('acronym') . '_batch_' . date('Y-m-d') . '.zip';
		$zip = new \ZipArchive();
		$zip->open($zipName, \ZipArchive::CREATE);

		$sectionDao = \DAORegistry::getDAO('SectionDAO');
		$publishedArticleDao = \DAORegistry::getDAO('PublishedArticleDAO');
		$galleyDao = \DAORegistry::getDAO('ArticleGalleyDAO');
		$submissionFileDAO = \DAORegistry::getDAO('SubmissionFileDAO');
		$genreDao = \DAORegistry::getDAO('GenreDAO');

		foreach ($issues as $issue) {		
			// add submission XML
			foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
				$articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
				$section = $sectionDao->getById($article->getSectionId());
				$doc = \XMLCustomWriter::createDocument('article', \PUBMED_DTD_ID, \PUBMED_DTD_URL);
				$articleNode = \PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
				\XMLCustomWriter::appendChild($doc, $articleNode);
				$zip->addFromString($articlePathName, \XMLCustomWriter::getXML($doc));
								
				// add galleys
				import('lib.pkp.classes.file.SubmissionFileManager');
				$submissionFileManager = new \SubmissionFileManager($article->getContextId(), $article->getId());
				
				foreach ($galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray() as $galley) {
					$galleyId = $galley->getId();

					$galley = $journal->getSetting('enablePublicGalleyId') 
						? $galleyDao->getByBestGalleyId($galleyId, $article->getId())
						: $galleyDao->getById($galleyId, $article->getId());

					if ($article && $galley) {
						$submissionFile = $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
				
						if (isset($submissionFile)) {
							$fileType = $submissionFile->getFileType();
							$filePath = $submissionFile->getFilePath();
							if(file_exists($filePath))
							{
								$filename = $submissionFile->getName(null);
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}

				// add Supplementary Files
				$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();

				foreach ($suppFiles as $sup) {
					$suppId = $sup->getFileId();
					$suppFile = $journal->getSetting('rtSupplementaryFiles') 
						? $submissionFileDAO->getLatestRevision((int) $suppId, null, $article->getId())
						: $submissionFileDAO->getByBestId((int) $suppId, $article->getId());
					if ($article && $suppFile) {
						import('lib.pkp.classes.file.SubmissionFileManager');
						$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
						
						$articleFile = $submissionFileManager->_getFile($suppFile->getFileId());
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath)) {
								$filename = $suppFile->getOriginalFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			}
		}
		$zip->close();
		header('Content-Type: application/zip');
		header('Content-disposition: attachment; filename=' . $zipName);
		header('Content-Length: ' . filesize($zipName));
		readfile($zipName);
		unlink($zipName);
		return true;
	}

	function exportIssue(&$journal, &$issue) {
		$this->import('PorticoExportDom');
		
		// create zip file

		// build zipName filename
		$zipName = $journal->getLocalizedSetting('acronym');
		if ($issue->getVolume()) {
			$zipName .= '_' . $issue->getVolume();
		}
		if ($issue->getNumber()) {
			$zipName .= '_' . $issue->getNumber();
		}
		if ($issue->getYear()){
			$zipName .= '_' . $issue->getYear();
		}
		$zipName .= '_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);
		
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		
		// add submission XML
		foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
			$articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
			$section = $sectionDao->getById($article->getSectionId());
			$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
			$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
			XMLCustomWriter::appendChild($doc, $articleNode);
			$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
			// add galleys
			import('lib.pkp.classes.file.SubmissionFileManager');
			$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			
			foreach ($galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray() as $galley) {
				$galleyId = $galley->getId();
				
				if ($journal->getSetting('enablePublicGalleyId')) {
					$galley =& $galleyDao->getByBestGalleyId($galleyId, $article->getId());
				} else {
					$galley =& $galleyDao->getById($galleyId, $article->getId());
				}

				if ($article && $galley) {
					$submissionFileDAO =& DAORegistry::getDAO('SubmissionFileDAO');
					$submissionFile =& $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
				
					if (isset($submissionFile)) {
						$fileType = $submissionFile->getFileType();
						$filePath = $submissionFile->getFilePath();
						if(file_exists($filePath))
						{
							$filename = $submissionFile->getName(null);
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
			
			// add Supplementary Files
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
			
			foreach ($suppFiles as $sup) {
				$suppId = $sup->getFileId();
				$suppFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
				
				if ($journal->getSetting('rtSupplementaryFiles')) {
					$suppFile =& $suppFileDao->getLatestRevision((int) $suppId, null, $article->getId());
				} else {
					$suppFile =& $suppFileDao->getByBestId((int) $suppId, $article->getId());
				}
				
				if ($article && $suppFile) {
					import('lib.pkp.classes.file.SubmissionFileManager');
					$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
					
					$articleFile =& $submissionFileManager->_getFile($suppFile->getFileId());
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath)) {
							$filename = $suppFile->getOriginalFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
		}
		$zip->close();
		header('Content-Type: application/zip');
		header('Content-disposition: attachment; filename=' . $zipName);
		header('Content-Length: ' . filesize($zipName));
		readfile($zipName);
		unlink($zipName);
		return true;
	}

	function ftpIssue(&$journal, &$issue) {
		// FTP credentials
		$journalId = $journal->getId();
		
		$ftp_server = $this->getSetting($journalId, 'porticoHost');
		$ftp_user_name = $this->getSetting($journalId, 'porticoUsername');
		$ftp_user_pass = $this->getSetting($journalId, 'porticoPassword');
		
		$this->import('PorticoExportDom');
		
		// create zip file
		$zipName = $journal->getLocalizedSetting('acronym');
		if ($issue->getVolume()) {
			$zipName .= '_' . $issue->getVolume();
		}
		if ($issue->getNumber()) {
			$zipName .= '_' . $issue->getNumber();
		}
		if ($issue->getYear()){
			$zipName .= '_' . $issue->getYear();
		}
		$zipName .= '_' . date('Y-m-d') . '.zip';

		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);
		
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');
		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		
		// add submission XML
		foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
			$articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
			$section = $sectionDao->getById($article->getSectionId());
			$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
			$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
			XMLCustomWriter::appendChild($doc, $articleNode);
			$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
			// add galleys
			import('lib.pkp.classes.file.SubmissionFileManager');
			$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			
			foreach ($article->getGalleys() as $galley) {
				$galleyId = $galley->getId();
				
				if ($journal->getSetting('enablePublicGalleyId')) {
					$galley =& $galleyDao->getByBestGalleyId($galleyId, $article->getId());
				} else {
					$galley =& $galleyDao->getById($galleyId, $article->getId());
				}

				if ($article && $galley) {
					$submissionFileDAO =& DAORegistry::getDAO('SubmissionFileDAO');
					$submissionFile =& $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
					
					if (isset($submissionFile)) {
						$fileType = $submissionFile->getFileType();
						$filePath = $submissionFile->getFilePath();
						if(file_exists($filePath))
						{
							$filename = $submissionFile->getName(null);
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
			
			// add Supplementary Files
			$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
			$genreDao =& DAORegistry::getDAO('GenreDAO');
			$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
			
			foreach ($suppFiles as $sup) {
				$suppId = $sup->getFileId();
				$suppFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
				
				if ($journal->getSetting('rtSupplementaryFiles')) {
					$suppFile =& $suppFileDao->getLatestRevision((int) $suppId, null, $article->getId());
				} else {
					$suppFile =& $suppFileDao->getByBestId((int) $suppId, $article->getId());
				}
				
				if ($article && $suppFile) {
					import('lib.pkp.classes.file.SubmissionFileManager');
					$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
					
					$articleFile =& $submissionFileManager->_getFile($suppFile->getFileId());
					if (isset($articleFile)) {
						$fileType = $articleFile->getFileType();
						$filePath = $articleFile->getFilePath();
						if(file_exists($filePath)) {
							$filename = $suppFile->getOriginalFileName();
							$zip->addFile($filePath, $article->getId() . '/' . $filename);
						}
					}
				}
			}
		}
		$zip->close();
		
		// set up basic connection 
		$conn_id = ftp_connect($ftp_server); 

		// login with username and password 
		$login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass); 
		
		ftp_pasv($conn_id, true);

		// upload a file
		$templateMgr =& TemplateManager::getManager();
		if (ftp_put($conn_id, $zipName, $zipName, FTP_BINARY)) { 
			return $templateMgr->display($this->getTemplateResource('exportSuccess.tpl'));
			exit; 
		} else { 
			return $templateMgr->display($this->getTemplateResource('exportFailure.tpl'));
			exit; 
		} 
		// close the connection 
		ftp_close($conn_id);
		unlink($zipName);
		return true;
	}
	
	function ftpIssues(&$journal, &$issues) {
		// FTP credentials
		$journalId = $journal->getId();
		
		$ftp_server = $this->getSetting($journalId, 'porticoHost');
		$ftp_user_name = $this->getSetting($journalId, 'porticoUsername');
		$ftp_user_pass = $this->getSetting($journalId, 'porticoPassword');
		
		$this->import('PorticoExportDom');
		$doc =& XMLCustomWriter::createDocument('issues');
		$issuesNode =& XMLCustomWriter::createElement($doc, 'issues');
		XMLCustomWriter::appendChild($doc, $issuesNode);

		// create zip file
		$zipName = $journal->getLocalizedSetting('acronym') . '_batch_' . date('Y-m-d') . '.zip';
		$zip = new ZipArchive();
		$zip->open($zipName, ZipArchive::CREATE);

		$sectionDao =& DAORegistry::getDAO('SectionDAO');
		$publishedArticleDao =& DAORegistry::getDAO('PublishedArticleDAO');

		foreach ($issues as $issue) {
			// add submission XML
			foreach ($publishedArticleDao->getPublishedArticles($issue->getId()) as $article) {
				$articlePathName = $article->getId() . '/' . $article->getId() . '.xml';
				$section = $sectionDao->getById($article->getSectionId());
				$doc =& XMLCustomWriter::createDocument('article', PUBMED_DTD_ID, PUBMED_DTD_URL);
				$articleNode =& PorticoExportDom::generateArticleDom($doc, $journal, $issue, $section, $article);
				XMLCustomWriter::appendChild($doc, $articleNode);
				$zip->addFromString($articlePathName, XMLCustomWriter::getXML($doc));
			
				// add galleys
				import('lib.pkp.classes.file.SubmissionFileManager');
				$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				
				foreach ($galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray() as $galley) {
					$galleyId = $galley->getId();
					
					if ($journal->getSetting('enablePublicGalleyId')) {
						$galley =& $galleyDao->getByBestGalleyId($galleyId, $article->getId());
					} else {
						$galley =& $galleyDao->getById($galleyId, $article->getId());
					}

					if ($article && $galley) {
						$submissionFileDAO =& DAORegistry::getDAO('SubmissionFileDAO');
						$submissionFile =& $submissionFileDAO->getByPubId($galley->getFileId(), null, $article->getId());
				
						if (isset($submissionFile)) {
							$fileType = $submissionFile->getFileType();
							$filePath = $submissionFile->getFilePath();
							if(file_exists($filePath))
							{
								$filename = $submissionFile->getName(null);
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			
				// add Supplementary Files
				$galleyDao =& DAORegistry::getDAO('ArticleGalleyDAO');
				$genreDao =& DAORegistry::getDAO('GenreDAO');
				$suppFiles = $galleyDao->getBySubmissionId($article->getId(), $article->getContextId())->toArray();
				
				foreach ($suppFiles as $sup) {
					$suppId = $sup->getFileId();
					$suppFileDao =& DAORegistry::getDAO('SubmissionFileDAO');
					
					if ($journal->getSetting('rtSupplementaryFiles')) {
						$suppFile =& $suppFileDao->getLatestRevision((int) $suppId, null, $article->getId());
					} else {
						$suppFile =& $suppFileDao->getByBestId((int) $suppId, $article->getId());
					}
					
					if ($article && $suppFile) {
						import('lib.pkp.classes.file.SubmissionFileManager');
						$submissionFileManager = new SubmissionFileManager($article->getContextId(), $article->getId());
						
						$articleFile =& $submissionFileManager->_getFile($suppFile->getFileId());
						if (isset($articleFile)) {
							$fileType = $articleFile->getFileType();
							$filePath = $articleFile->getFilePath();
							if(file_exists($filePath)) {
								$filename = $suppFile->getOriginalFileName();
								$zip->addFile($filePath, $article->getId() . '/' . $filename);
							}
						}
					}
				}
			}
		}
		$zip->close();
				
		// set up basic connection 
		$conn_id = ftp_connect($ftp_server);

		// login with username and password 
		$login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass);
		
		ftp_pasv($conn_id, true);

		// upload a file
		$templateMgr =& TemplateManager::getManager();
		if (ftp_put($conn_id, $zipName, $zipName, FTP_BINARY)) {
			return $templateMgr->display($this->getTemplateResource('exportSuccess.tpl'));
			exit;
		} else {
			return $templateMgr->display($this->getTemplateResource('exportFailure.tpl'));
			exit; 
		}
		// close the connection 
		ftp_close($conn_id);
		unlink($zipName);
		return true;
	}
	
 	/**
 	 * Execute a management verb on this plugin
 	 * @param $verb string
 	 * @param $args array
	 * @param $message string Result status message
	 * @param $messageParams array Parameters for status message
	 * @return boolean
	 */
	function manage($args, $request) {
		$returner = true;
		$journal = $request->getJournal();
		$this->addLocaleData();

				AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON,  LOCALE_COMPONENT_PKP_MANAGER);
				$templateMgr =& TemplateManager::getManager();

				$this->import('PorticoSettingsForm');
				$form = new PorticoSettingsForm($this, $journal->getId());

		if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
				$request->redirect(null, null, null, array('plugin', $this->getName()));
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}

		return $returner;
	}
	
	/**
	 * Set the page's breadcrumbs, given the plugin's tree of items
	 * to append.
	 * @param $crumbs Array ($url, $name, $isTranslated)
	 * @param $subclass boolean
	 */
	function setBreadcrumbs($crumbs = array(), $isSubclass = false) {
		$request = $this->getRequest();
		$templateMgr = TemplateManager::getManager();
		$pageCrumbs = [
			[$request->url(null, 'user'), 'navigation.user'],
			[$request->url(null, 'manager'), 'user.role.manager'],
			[$request->url(null, 'manager', 'importexport'), 'manager.importExport']
		];
		if ($isSubclass) {
			$pageCrumbs[] = [
				$request->url(null, 'manager', 'importexport', ['plugin', $this->getName()]),
				$this->getDisplayName(),
				true
			];
		}
		
		$templateMgr->assign('pageHierarchy', array_merge($pageCrumbs, $crumbs));
	}
	
	/**
	 * Execute import/export tasks using the command-line interface.
	 * @param $scriptName The name of the command-line script (displayed as usage info)
	 * @param $args Parameters to the plugin
	 */
	function executeCLI($scriptName, &$args){
	}
	
	/**
	 * Display the command-line usage information
	 * @param $scriptName string
	 */
	function usage($scriptName){
	}
}
