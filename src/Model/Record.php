<?php

namespace App\Model;

use App\Repository\WikiRepository;
use DateTime;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class Record {

	protected array $data;
	protected string $username;
	protected ?int $editCount;
	protected bool $pageExists;
	protected bool $userPageExists;
	protected bool $userTalkExists;
	protected array $wikiProjects;

	/**
	 * @param array $row From the CopyPatrol database.
	 * @param int|null $editCount
	 * @param bool $pageExists
	 * @param bool $userPageExists
	 * @param bool $userTalkExists
	 * @param array $wikiProjects
	 */
	public function __construct(
		array $row,
		int $editCount = null,
		bool $pageExists = false,
		bool $userPageExists = false,
		bool $userTalkExists = false,
		array $wikiProjects = []
	) {
		$this->data = $row;
		$this->editCount = $editCount;
		$this->pageExists = $pageExists;
		$this->userPageExists = $userPageExists;
		$this->userTalkExists = $userTalkExists;
		// Remove any null values.
		$this->wikiProjects = array_filter( $wikiProjects );
	}

	/** REPORT ATTRIBUTES */

	/**
	 * Get the submission ID.
	 *
	 * @return string UUID or stringified integer for older submissions.
	 */
	public function getSubmissionId(): string {
		return (string)$this->data['submission_id'];
	}

	/**
	 * Get the copyvio sources associated with the edit.
	 *
	 * @return array
	 */
	public function getSources(): array {
		return $this->data['sources'];
	}

	/** PAGE / REVISION */

	/**
	 * Get the page title.
	 *
	 * @param bool $underscored Whether to include underscores (we do for links, but not for display).
	 * @return string
	 */
	public function getPageTitle( bool $underscored = false ): string {
		$nsName = (int)$this->data['page_namespace'] === WikiRepository::NS_ID_DRAFTS ? 'Draft:' : '';
		$pageTitle = $nsName . $this->data['page_title'];
		if ( !$underscored ) {
			// Remove underscores for display purposes.
			$pageTitle = str_replace( '_', ' ', $pageTitle );
		}
		return $pageTitle;
	}

	/**
	 * Get the URL to the page.
	 *
	 * @return string
	 */
	public function getPageUrl(): string {
		return $this->getUrl( $this->getPageTitle( true ) );
	}

	/**
	 * Is the page dead (i.e. nonexistent or deleted)?
	 *
	 * @return bool
	 */
	public function isPageDead(): bool {
		return !$this->pageExists;
	}

	/**
	 * Is this edit a page creation?
	 *
	 * @return bool
	 */
	public function isNewPage(): bool {
		return $this->getRevParentId() === 0;
	}

	/**
	 * Get the URL to the revision history of the page.
	 *
	 * @return string
	 */
	public function getPageHistoryUrl(): string {
		return $this->getUrl( 'Special:PageHistory/' . $this->getPageTitle( true ) );
	}

	/**
	 * Get the diff ID.
	 *
	 * @return int
	 */
	public function getDiffId(): int {
		return (int)$this->data['diff_id'];
	}

	/**
	 * Get the URL to the diff page for the revision.
	 *
	 * @return string
	 */
	public function getDiffUrl(): string {
		return $this->getUrl( 'Special:Diff/' . $this->data['rev_id'] );
	}

	/**
	 * Get the timestamp of the diff
	 *
	 * @return string
	 */
	public function getDiffTimestamp(): string {
		return $this->formatTimestamp( $this->data['rev_timestamp'] );
	}

	/**
	 * Get the size of the diff.
	 *
	 * @return int|null
	 */
	public function getDiffSize(): ?int {
		if ( isset( $this->data['length_change'] ) ) {
			return (int)$this->data['length_change'];
		}
		return null;
	}

	/**
	 * Get the edit summary, parsed as HTML.
	 *
	 * @return string
	 */
	public function getSummary(): string {
		return $this->parseWikitext( $this->getRawSummary() );
	}

	/**
	 * Get the raw edit summary as wikitext.
	 *
	 * @return string
	 */
	public function getRawSummary(): string {
		return $this->data['comment'] ?? '';
	}

	/**
	 * Get the tags associated with this edit.
	 *
	 * @return string[]
	 */
	public function getTags(): array {
		return $this->data['tags'] ?? [];
	}

	/**
	 * Get the labels for the change tags associated with this edit.
	 *
	 * @return string[]
	 */
	public function getTagLabels(): array {
		return array_map( function ( $tag ) {
			return $this->parseWikitext( $tag, true );
		}, $this->data['tags_labels'] ?? [] );
	}

	/**
	 * Parse a wikitext string into safe HTML.
	 * Borrowed from XTools; License: GPL 3.0 or later
	 *
	 * @see https://github.com/x-tools/xtools/blob/4795fb88dd392bb0474219be3ef9a1fc019a228b/src/Model/Edit.php#L336
	 * @param string $wikitext
	 * @param bool $includeExternalLinks Whether to include masked external links as part of parsing.
	 * @return string
	 */
	public function parseWikitext( string $wikitext, bool $includeExternalLinks = false ): string {
		$wikitext = htmlspecialchars( html_entity_decode( $wikitext ), ENT_NOQUOTES );
		// Hold a list of tokens so that we don't end up replacing the same thing twice.
		$tokenList = [];

		// This regex is from https://stackoverflow.com/a/6041965/604142
		// This should only have one capture group: the whole URL.
		// Ensure all other groups are (?:non-capturing).
		$urlRegex = '\b((?:[\w-]+://?|www[.])[^\s()<>]+(?:\([\w\d]+\)|(?:[^[:punct:]\s]|/)))';

		// Process masked external links, if requested.
		// This goes before we process raw links, so that we don't convert both.
		if ( $includeExternalLinks ) {
			$wikitext = preg_replace_callback(
				"%\[$urlRegex ([^]]+)]%s",
				static function ( $matches ) use ( &$tokenList, $urlRegex ) {
					// Do not convert if label URL match is `1` (is a URL) or
					// `false` (failure), for safety
					if ( preg_match( "%$urlRegex%s", $matches[2] ) !== 0 ) {
						return $matches[0];
					}

					do {
						$id = rand();
					} while ( isset( $tokenList[$id] ) );
					$token = '<!--copypatrol:token:' . $id . '-->';
					$tokenList[$id] = "<a target='_blank' rel='nofollow' href='${matches[1]}'>${matches[2]}</a>";
					return $token;
				},
				$wikitext
			);
		}

		// Link raw URLs.
		$wikitext = preg_replace_callback(
			"%$urlRegex%s",
			static function ( $matches ) use ( &$tokenList ) {
				do {
					$id = rand();
				} while ( isset( $tokenList[$id] ) );
				$token = '<!--copypatrol:token:' . $id . '-->';
				$tokenList[$id] = "<a target='_blank' rel='nofollow' href='${matches[1]}'>${matches[1]}</a>";
				return $token;
			},
			$wikitext
		);

		// Replace all tokens from previous two steps.
		foreach ( $tokenList as $id => $replacement ) {
			$wikitext = str_replace( '<!--copypatrol:token:' . $id . '-->', $replacement, $wikitext );
		}

		$sectionMatch = null;
		$isSection = preg_match_all( "/^\/\* (.*?) \*\//", $wikitext, $sectionMatch );
		$pageUrl = $this->getPageUrl();

		if ( $isSection ) {
			$sectionTitle = $sectionMatch[1][0];
			// Must have underscores for the link to properly go to the section.
			$sectionTitleLink = htmlspecialchars( str_replace( ' ', '_', $sectionTitle ) );
			$sectionWikitext = "<a target='_blank' href='$pageUrl#$sectionTitleLink'>" .
				"<em class='text-muted'>&rarr;" . htmlspecialchars( $sectionTitle ) . ":</em></a> ";
			$wikitext = str_replace( $sectionMatch[0][0], $sectionWikitext, $wikitext );
		}

		$linkMatch = null;

		while ( preg_match_all( "/\[\[:?(.*?)]]/", $wikitext, $linkMatch ) ) {
			$wikiLinkParts = explode( '|', $linkMatch[1][0] );
			$wikiLinkPath = htmlspecialchars( $wikiLinkParts[0] );
			$wikiLinkText = htmlspecialchars(
				$wikiLinkParts[1] ?? $wikiLinkPath
			);

			// Use normalized page title (underscored, capitalized).
			$pageUrl = $this->getUrl( ucfirst( str_replace( ' ', '_', $wikiLinkPath ) ) );

			$link = "<a target='_blank' href='$pageUrl'>$wikiLinkText</a>";
			$wikitext = str_replace( $linkMatch[0][0], $link, $wikitext );
		}

		return $wikitext;
	}

	/**
	 * Get the WikiProjects associated with the page.
	 *
	 * @return array
	 */
	public function getWikiProjects(): array {
		return $this->wikiProjects;
	}

	/**
	 * Get the ID of the revision.
	 *
	 * @return int
	 */
	public function getRevId(): int {
		return (int)$this->data['rev_id'];
	}

	/**
	 * Get the parent ID of the revision.
	 *
	 * @return int
	 */
	public function getRevParentId(): int {
		return (int)$this->data['rev_parent_id'];
	}

	/** EDITOR / USER */

	/**
	 * Get the username of the editor.
	 *
	 * @return string
	 */
	public function getEditor(): string {
		return $this->data['rev_user_text'];
	}

	/**
	 * Get the URL to the userpage of the editor.
	 *
	 * @return string
	 */
	public function getUserPageUrl(): string {
		return $this->getUrl( 'User:' . $this->data['rev_user_text'] );
	}

	/**
	 * Is the user page dead? (i.e. nonexistent or deleted)
	 *
	 * @return bool
	 */
	public function isUserPageDead(): bool {
		return !$this->userPageExists;
	}

	/**
	 * Get the edit count of the user.
	 *
	 * @return int|null
	 */
	public function getEditCount(): ?int {
		return $this->editCount;
	}

	/**
	 * Get the URL to the user's talk page.
	 *
	 * @return string
	 */
	public function getUserTalkPageUrl(): string {
		return $this->getUrl( 'User_talk:' . $this->data['rev_user_text'] );
	}

	/**
	 * Is the user talk page dead? (i.e. nonexistent or deleted)
	 *
	 * @return bool
	 */
	public function isUserTalkPageDead(): bool {
		return !$this->userTalkExists;
	}

	/**
	 * Get the URL to the user's contributions.
	 *
	 * @return string
	 */
	public function getUserContribsUrl(): string {
		return $this->getUrl( 'Special:Contribs/' . $this->data['rev_user_text'] );
	}

	/**
	 * Get the URL to undo the edit.
	 *
	 * @return string
	 */
	public function getUndoUrl(): string {
		return $this->getUrl( $this->getPageTitle( true ) . '?' . http_build_query( [
			'action' => 'edit',
			'undoafter' => $this->getRevParentId(),
			'undo' => $this->getRevId(),
		] ) );
	}

	/**
	 * Get a URL to Special:RevisionDelete for this revision, and with the top source URL pre-filled in.
	 *
	 * @return string
	 */
	public function getRevdelUrl(): string {
		return $this->getUrl( 'Special:RevisionDelete?' . http_build_query( [
			'type' => 'revision',
			'ids' => $this->getRevId(),
			'wpHidePrimary' => '1',
			'wpReason' => $this->getSources()[0]['url'] ?? '',
			// FIXME: hard-coded for enwiki; for other wikis, it will fallback to 'Other reason'
			'wpRevDeleteReasonList' => '[[WP:RD1|RD1]]: Violations of ' .
				'[[Wikipedia:Copyright violations|copyright policy]]'
		] ) );
	}

	/**
	 * Get a URL to delete the page, with the top source URL pre-filled in.
	 *
	 * @return string
	 */
	public function getDeleteUrl(): string {
		return $this->getPageUrl() . '?' . http_build_query( [
			'action' => 'delete',
			// FIXME: hard-coded for enwiki; for other wikis, it will fallback to 'Other reason'
			'wpDeleteReasonList' => '[[WP:CSD#G12|G12]]: Unambiguous [[WP:CV|copyright infringement]]',
			'wpReason' => $this->getSources()[0]['url'] ?? '',
			'wpDeleteTalk' => '1',
		] );
	}

	/** REVIEW ATTRIBUTES */

	/**
	 * Get the status of the report.
	 *
	 * @return int On the CopyPatrolRepository::STATUS_ constants.
	 */
	public function getStatus(): int {
		return (int)$this->data['status'];
	}

	/**
	 * Get the username of who made the last status change.
	 *
	 * @return string|null
	 */
	public function getStatusUser(): ?string {
		return $this->data['status_user_text'] ?? null;
	}

	/**
	 * Get the timestamp of the last status change.
	 *
	 * @return string|null
	 */
	public function getStatusTimestamp(): ?string {
		return $this->formatTimestamp( $this->data['status_timestamp'] ?? null );
	}

	/**
	 * Get the URL to the userpage of who did the review.
	 *
	 * @return string|null
	 */
	public function getReviewedByUrl(): ?string {
		return $this->getStatusUser()
			? $this->getUrl( 'User:' . $this->getStatusUser() )
			: null;
	}

	/** UTIL */

	/**
	 * @param string $target
	 * @return string
	 */
	private function getUrl( string $target ): string {
		return "https://{$this->getProject()}/wiki/$target";
	}

	/**
	 * Get the project domain,
	 *
	 * @return string
	 */
	public function getProject(): string {
		return "{$this->data['lang']}.{$this->data['project']}.org";
	}

	/**
	 * Format a timestamp in ISO 8601.
	 *
	 * @param string|null $timestamp
	 * @return string|null
	 */
	public function formatTimestamp( ?string $timestamp ): ?string {
		if ( !$timestamp ) {
			return $timestamp;
		}
		return ( new DateTime( $timestamp ) )->format( 'Y-m-d H:i' );
	}

	/**
	 * Get JSON representation of the Record that is needed by the frontend.
	 *
	 * @return array
	 */
	public function getStatusJson(): array {
		return [
			'user' => $this->getStatusUser(),
			'userpage' => $this->getUrl( 'User:' . $this->getStatusUser() ),
			'timestamp' => $this->getStatusTimestamp(),
			'status' => $this->getStatus(),
		];
	}

	public function toArray(): array {
		static $serializer;
		if ( !$serializer ) {
			$serializer = new Serializer(
				[ new ObjectNormalizer( null, new CamelCaseToSnakeCaseNameConverter() ) ],
				[ new JsonEncoder() ]
			);
		}
		return $serializer->normalize(
			$this,
			'json',
			[
				AbstractNormalizer::ATTRIBUTES => [
					'submissionId',
					'sources',
					'pageTitle',
					'pageDead',
					'newPage',
					'diffId',
					'diffTimestamp',
					'diffSize',
					'summary',
					'tags',
					'wikiProjects',
					'revId',
					'revParentId',
					'editor',
					'editCount',
					'status',
					'statusUser',
					'statusTimestamp',
					'project'
				]
			]
		);
	}
}
