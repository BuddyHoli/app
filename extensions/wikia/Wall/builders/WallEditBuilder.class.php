<?php

/**
 * Class WallEditBuilder builds newly edited version of Wall/Forum thread or reply
 */
class WallEditBuilder extends WallBuilder {

	/** @var string $messageText */
	private $messageText;
	/** @var User $editor*/
	private $editor;
	/** @var WallMessage $message */
	private $message;

	/** @var ArticleComment $articleComment */
	private $articleComment;

	/**
	 * Save the message with the newly provided text, and empty caches.
	 * @return WallEditBuilder
	 */
	public function editWallMessage(): WallEditBuilder {
		if ( !$this->message->canEdit( $this->editor ) ) {
			$this->throwException( 'User not allowed to edit message' );
		}

		/**
		 * Hook into MW transaction
		 * @see WallEditBuilder::updateCommentsIndexEntry()
		 */
		Hooks::register( 'ArticleDoEdit', [ $this, 'updateCommentsIndexEntry' ] );

		$result = $this->message->getArticleComment()->doSaveComment( $this->messageText, $this->editor );
		if ( !$result ) {
			$this->throwException( 'Failed to save edited message' );
		}

		if ( !$this->message->isMain() ) {
			// after changing reply invalidate thread cache
			$this->message->getThread()->invalidateCache();
		}

		$this->articleComment = $this->message->getArticleComment();
		return $this;
	}


	/**
	 * Update the entry of this message in comments_index table with the newly inserted revision ID
	 * In order to enforce data integrity, we have to use the same transaction as MediaWiki
	 *
	 * @see WikiPage::doEdit()
	 * @see https://wikia-inc.atlassian.net/browse/ZZZ-3225
	 *
	 * @param DatabaseBase $dbw DB connection used by MediaWiki to edit article comment
	 * @param Title $title title of edited article comment
	 * @param Revision $rev newly inserted revision
	 * @return bool true
	 */
	public function updateCommentsIndexEntry( DatabaseBase $dbw, Title $title, Revision $rev ): bool {
		$entry = CommentsIndex::getInstance()->entryFromId( $title->getArticleID() );
		$entry->setLastRevId( $rev->getId() );

		CommentsIndex::getInstance()->updateEntry( $entry, $dbw );

		return true;
	}

	/**
	 * Return newly parsed text of edited message
	 * @return string
	 */
	public function build() {
		$this->editWallMessage();

		$this->articleComment->setRawText( $this->messageText );
		return $this->articleComment->getTransformedParsedText();
	}

	/**
	 * Populate an exception with proper context for logging, and throw it
	 * @param string $message
	 * @throws WallBuilderException
	 */
	protected function throwException( string $message ) {
		$context = [
			'parentPageTitle' => $this->message->getArticleTitle()->getPrefixedText(),
			'parentPageId' => $this->message->getArticleTitle()->getArticleID(),
			'messageTitle' => $this->message->getTitle()->getPrefixedText(),
			'messageId' => $this->message->getTitle()->getArticleID()
		];

		throw new WallBuilderException( $message, $context );
	}

	/**
	 * @param string $messageText
	 * @return WallEditBuilder
	 */
	public function setMessageText( string $messageText ): WallEditBuilder {
		$this->messageText = $messageText;

		return $this;
	}

	/**
	 * @param User $editor
	 * @return WallEditBuilder
	 */
	public function setEditor( User $editor ): WallEditBuilder {
		$this->editor = $editor;

		return $this;
	}

	/**
	 * @param WallMessage $message
	 * @return WallEditBuilder
	 */
	public function setMessage( WallMessage $message ): WallEditBuilder {
		$this->message = $message;

		return $this;
	}

	/**
	 * @param ArticleComment $articleComment
	 * @return WallEditBuilder
	 */
	public function setArticleComment( ArticleComment $articleComment ): WallEditBuilder {
		$this->articleComment = $articleComment;

		return $this;
	}
}