<?php

/**
 * @file plugins/importexport/portico/PorticoExportDom.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportDom
 * @ingroup plugins_importexport_portico
 *
 * @brief Portico export plugin DOM functions for export
 */

class PorticoExportDom {
	/** @var string DTD URL of the exported XML */
	private const PUBMED_DTD_URL = 'http://dtd.nlm.nih.gov/archiving/3.0/archivearticle3.dtd';

	/** @var string DTD ID of the exported XML */
	private const PUBMED_DTD_ID = '-//NLM//DTD Journal Publishing DTD v3.0 20080202//EN';

	/** @var Context Context */
	private $_context;

	/** @var Issue Issue */
	private $_issue;

	/** @var Submission Submission */
	private $_article;

	/** @var DOMElement Document node */
	private $_document;

	/**
	 * Constructor
	 * @param Context $journal
	 * @param Issue $issue
	 * @param Submission $article
	 */
	public function __construct(Journal $context, Issue $issue, Submission $article)
	{
		$this->_context = $context;
		$this->_issue = $issue;
		$this->_article = $article;
		$domImplementation = new DOMImplementation();
		$this->_document = $domImplementation->createDocument(
			'1.0',
			'',
			$domImplementation->createDocumentType('article', self::PUBMED_DTD_ID, self::PUBMED_DTD_URL)
		);
		$articleNode = $this->_buildArticle();
		$this->_document->appendChild($articleNode);
	}

	/**
	 * Serializes the document
	*/
	public function __toString() : string {
		return $this->_document->saveXML();
	}

	/**
	 * Generate the Article node.
	 * @return DOMElement
	 */
	private function _buildArticle() : DOMElement {
		$journal = $this->_context;
		$doc = $this->_document;
		$article = $this->_article;
		$issue = $this->_issue;

		/* --- Article --- */
		$root = $doc->createElement('article');
		$root->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');

		/* --- Front --- */
		$articleNode = $doc->createElement('front');
		$root->appendChild($articleNode);

		/* --- Journal --- */
		$journalMetaNode = $doc->createElement('journal-meta');
		$articleNode->appendChild($journalMetaNode);

		// journal-id
		if (($abbreviation = $journal->getLocalizedSetting('abbreviation')) != '') {
			$journalMetaNode->appendChild($doc->createElement('journal-id', $abbreviation));
		}

		//journal-title-group
		$journalTitleGroupNode = $doc->createElement('journal-title-group');
		$journalMetaNode->appendChild($journalTitleGroupNode);

		// journal-title
		$journalTitleGroupNode->appendChild($doc->createElement('journal-title', $journal->getLocalizedPageHeaderTitle()));

		// issn
		foreach (['printIssn', 'issn', 'onlineIssn'] as $name) {
			if ($issn = $journal->getSetting($name)) {
				$journalMetaNode->appendChild($doc->createElement('issn', $issn));
				break;
			}
		}

		// publisher
		$publisherNode = $doc->createElement('publisher');
		$journalMetaNode->appendChild($publisherNode);

		// publisher-name
		$publisherInstitution = $journal->getSetting('publisherInstitution');
		$publisherNode->appendChild($doc->createElement('publisher-name', $publisherInstitution));

		/* --- End Journal --- */

		/* --- Article-meta --- */
		$articleMetaNode = $doc->createElement('article-meta');
		$articleNode->appendChild($articleMetaNode);

		// article-id (DOI)
		if (($doi = $article->getStoredPubId('doi')) != '') {
			$doiNode = $doc->createElement('article-id', $doi);
			$doiNode->setAttribute('pub-id-type', 'doi');
			$articleMetaNode->appendChild($doiNode);
		}

		// article-title
		$titleGroupNode = $doc->createElement('title-group');
		$articleMetaNode->appendChild($titleGroupNode);
		$titleGroupNode->appendChild($doc->createElement('article-title', $article->getLocalizedTitle()));

		// authors
		$authorsNode = $this->_buildAuthors();
		$articleMetaNode->appendChild($authorsNode);

		if ($datePublished = $article->getDatePublished() ?: $issue->getDatePublished()) {
			$dateNode = $this->_buildPubDate(new DateTimeImmutable($datePublished));
			$articleMetaNode->appendChild($dateNode);
		}

		// volume, issue, etc.
		if ($v = $issue->getVolume()) $articleMetaNode->appendChild($doc->createElement('volume', $v));
		if ($n = $issue->getNumber()) $articleMetaNode->appendChild($doc->createElement('number', $n));
		$this->_buildPages($articleMetaNode);

		/* --- ArticleIdList --- */
		// Pubmed will accept two types of article identifier: pii and doi
		// how this is handled is journal-specific, and will require either
		// configuration in the plugin, or an update to the core code.
		// this is also related to DOI-handling within OJS
		if ($publisherId = $article->getStoredPubId('publisher-id')) {
			$articleIdListNode = $doc->createElement('ArticleIdList');
			$articleNode->appendChild($articleIdListNode);

			$articleIdNode = $articleIdListNode->appendChild($doc->createElement('article-id', $publisherId));
			$articleIdNode->setAttribute('pub-id-type', 'publisher');
		}

		// galley links
		$fileService = Services::get('file');
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		foreach ($article->getGalleys() as $galley) {
			if ($url = $galley->getRemoteURL()) {
				$selfUriNode = $doc->createElement('self-uri', $url);
				$selfUriNode->setAttribute('xlink:href', $url);
			} else {
				$submissionFile = $submissionFileDao->getById($galley->getData('submissionFileId'));
				$filePath = $fileService->getPath($submissionFile->getData('fileId'));
				$archivePath = $article->getId() . '/' . basename($filePath);
				$selfUriNode = $doc->createElement('self-uri', $archivePath);
				$selfUriNode->setAttribute('content-type', $fileService->fs->getMimetype($filePath));
				$selfUriNode->setAttribute('xlink:href', $archivePath);
			}
			$articleMetaNode->appendChild($selfUriNode);
		}

		/* --- Abstract --- */
		if ($abstract = strip_tags($article->getLocalizedAbstract())) {
			$abstractNode = $doc->createElement('abstract');
			$articleMetaNode->appendChild($abstractNode);
			$abstractNode->appendChild($doc->createElement('p', $abstract));
		}

		return $root;
	}

	/**
	 * Generate the Author node DOM for the specified author.
	 * @param Author $author Author
	 * @return DOMElement
	 */
	private function _buildAuthor(Author $author) : DOMElement {
		$doc = $this->_document;
		$locale = AppLocale::getLocale();

		$root = $this->_document->createElement('contrib');
		$root->setAttribute('contrib-type', 'author');

		$nameNode = $this->_document->createElement('name');
		$root->appendChild($nameNode);

		$nameNode->appendChild($doc->createElement('surname', $author->getLocalizedFamilyName($locale)));
		$nameNode->appendChild($doc->createElement('given-names', $author->getLocalizedGivenName($locale)));

		$affiliation = $author->getLocalizedAffiliation();
		if (is_array($affiliation)) {
			$affiliation = reset($affiliation);
		}
		if ($affiliation) $root->appendChild($doc->createElement('aff', $affiliation));
		if ($url = $author->getUrl()) $root->appendChild($doc->createElement('uri', $url));
		if ($orcid = $author->getOrcid()) {
			$orcidNode = $root->appendChild($doc->createElement('contrib-id', $orcid));
			$orcidNode->setAttribute('contrib-id-type', 'orcid');
		}

		if ($email = $author->getEmail()) $root->appendChild($doc->createElement('email', $email));
		if ($bio = strip_tags($author->getLocalizedBiography())) {
			$bioNode = $doc->createElement('bio');
			$root->appendChild($bioNode);
			$bioNode->appendChild($doc->createElement('p', $bio));
		}
		
		if ($country = $author->getCountry()) {
			$addressNode = $this->_document->createElement('address');
			$addressNode->appendChild($doc->createElement('country', $country));
			$root->appendChild($addressNode);
		}

		return $root;
	}

	/**
	 * Creates pub-date node
	 * @param DateTimeImmutable $date
	 * @return DOMElement
	 */
	private function _buildPubDate(DateTimeImmutable $date) : DOMElement {
		$doc = $this->_document;
		$root = $this->_document->createElement('pub-date');

		$root->setAttribute('pub-type', 'epublish');
		$root->appendChild($doc->createElement('year', $date->format('Y')));
		$root->appendChild($doc->createElement('month', $date->format('m')));
		$root->appendChild($doc->createElement('day', $date->format('d')));

		return $root;
	}

	/**
	 * Creates the authors node
	 * @return DOMElement
	 */
	private function _buildAuthors() : DOMElement {
		$contribGroupNode = $this->_document->createElement('contrib-group');
		foreach ($this->_article->getAuthors() as $author) {
			$contribNode = $this->_buildAuthor($author);
			$contribGroupNode->appendChild($contribNode);
		}
		return $contribGroupNode;
	}

	/**
	 * Set the pages
	 * @param DOMElement $parentNode Parent node
	 */
	private function _buildPages(DOMElement $parentNode) : void {
		$article = $this->_article;
		/* --- fpage / lpage --- */
		// there is some ambiguity for online journals as to what
		// "page numbers" are; for example, some journals (eg. JMIR)
		// use the "e-location ID" as the "page numbers" in PubMed
		$pages = $article->getPages();
		$fpage = $lpage = null;
		if (PKPString::regexp_match_get('/([0-9]+)\s*-\s*([0-9]+)/i', $pages, $matches)) {
			// simple pagination (eg. "pp. 3-8")
			[, $fpage, $lpage] = $matches;
		} elseif (PKPString::regexp_match_get('/(e[0-9]+)\s*-\s*(e[0-9]+)/i', $pages, $matches)) {
			// e9 - e14, treated as page ranges
			[, $fpage, $lpage] = $matches;
		} elseif (PKPString::regexp_match_get('/(e[0-9]+)/i', $pages, $matches)) {
			// single elocation-id (eg. "e12")
			$fpage = $lpage = $matches[1];
		} else {
			// we need to insert something, so use the best ID possible
			$fpage = $lpage = $article->getBestArticleId($this->_context);
		}
		$parentNode->appendChild($this->_document->createElement('fpage', $fpage));
		$parentNode->appendChild($this->_document->createElement('lpage', $lpage));
	}
}
